<?php

namespace Combodo\iTop\HybridAuth\Service;

use CMDBObject;
use Combodo\iTop\HybridAuth\Config;
use Combodo\iTop\HybridAuth\HybridAuthLoginExtension;
use Combodo\iTop\HybridAuth\HybridProvisioningAuthException;
use DBObjectSearch;
use DBObjectSet;
use Dict;
use Hybridauth\User\Profile;
use IssueLog;
use LoginWebPage;
use MetaModel;
use Person;
use Session;
use URP_UserProfile;
use UserExternal;

class ProvisioningService {
	private static ProvisioningService $oInstance;

	protected function __construct()
	{
	}

	final public static function GetInstance(): ProvisioningService
	{
		if (!isset(static::$oInstance)) {
			static::$oInstance = new static();
		}

		return static::$oInstance;
	}

	final public static function SetInstance(?ProvisioningService $oInstance): void
	{
		static::$oInstance = $oInstance;
	}

	/**
	 * @param string $sLoginMode: SSO login mode
	 * @param string $sEmail: login/email of user being currently provisioned
	 * @param \Hybridauth\User\Profile $oUserProfile : hybridauth GetUserInfo object response
	 *
	 * @return array
	 * @throws \Combodo\iTop\HybridAuth\HybridProvisioningAuthException
	 */
	public function DoProvisioning(string $sLoginMode, string $sEmail, Profile $oUserProfile) : array {
		$oPerson = ProvisioningService::GetInstance()->DoPersonProvisioning($sLoginMode, $sEmail, $oUserProfile);
		$oUser = ProvisioningService::GetInstance()->DoUserProvisioning($sLoginMode, $sEmail, $oPerson, $oUserProfile);
		return [$oPerson, $oUser];
	}

	/**
	 * @param string $sLoginMode: SSO login mode
	 * @param string $sEmail: login/email of user being currently provisioned
	 * @param \Hybridauth\User\Profile $oUserProfile : hybridauth GetUserInfo object response (coming from Oauth2 IdP provider)
	 *
	 * @return \Person|null
	 * @throws \Combodo\iTop\HybridAuth\HybridProvisioningAuthException
	 */
	public function DoPersonProvisioning(string $sLoginMode, string $sEmail, Profile $oUserProfile) : Person
	{
		$oPerson = LoginWebPage::FindPerson($sEmail);
		if (! is_null($oPerson)) {
			return $oPerson;
		}

		if (! Config::IsContactSynchroEnabled($sLoginMode)) {
			throw new HybridProvisioningAuthException("Cannot find Person and no automatic Contact provisioning (synchronize_contact)", 0, null,
				['login_mode' => $sLoginMode, 'email' => $sEmail]); // No automatic Contact provisioning
		}

		// Create the person
		$sFirstName = $oUserProfile->firstName ?? $sEmail;
		$sLastName = $oUserProfile->lastName ?? $sEmail;
		$sOrganization = $this->GetOrganizationForProvisioning($sLoginMode, $oUserProfile->data["organization"] ?? null);
		$aAdditionalParams = ['phone' => $oUserProfile->phone];

		IssueLog::Info("OpenID Person provisioning", HybridAuthLoginExtension::LOG_CHANNEL,
			[
				'first_name' => $sFirstName,
				'last_name' => $sLastName,
				'email' => $sEmail,
				'org' => $sOrganization,
				'addition_params' => $aAdditionalParams,
			]
		);

		return LoginWebPage::ProvisionPerson($sFirstName, $sLastName, $sEmail, $sOrganization, $aAdditionalParams);
	}

	private function GetOrganizationForProvisioning(string $sLoginMode, ?string $sIdPOrgName): string
	{
		if (is_null($sIdPOrgName)) {
			return Config::GetDefaultOrg($sLoginMode);
		}

		$oOrg = MetaModel::GetObjectByName('Organization', $sIdPOrgName, false);
		if (!is_null($oOrg)) {
			return $sIdPOrgName;
		}

		IssueLog::Error(Dict::S('UI:Login:Error:WrongOrganizationName', null, ['idp_organization' => $sIdPOrgName]));

		return Config::GetDefaultOrg($sLoginMode);
	}

	/**
	 * @param string $sLoginMode: SSO login mode
	 * @param string $sEmail: login/email of user being currently provisioned
	 * @param \Person $oPerson : Person object attached to user
	 * @param \Hybridauth\User\Profile $oUserProfile : hybridauth GetUserInfo object response (coming from Oauth2 IdP provider)
	 *
	 * @return \UserExternal
	 * @throws \Combodo\iTop\HybridAuth\HybridProvisioningAuthException
	 */
	public function DoUserProvisioning(string $sLoginMode, string $sEmail, Person $oPerson, Profile $oUserProfile) : UserExternal
	{
		if (!MetaModel::IsValidClass('URP_Profiles')) {
			throw new HybridProvisioningAuthException("URP_Profiles is not a valid class. Automatic creation of Users is not supported in this context, sorry.", 0, null,
				['login_mode' => $sLoginMode, 'email' => $sEmail]);
		}

		CMDBObject::SetTrackOrigin('custom-extension');
		$sInfo = "External User provisioning ($sLoginMode)";
		CMDBObject::SetTrackInfo($sInfo);

		/** @var UserExternal $oUser */
		$oUser = MetaModel::GetObjectByName('UserExternal', $sEmail, false);

		if (! is_null($oUser) && ! Config::IsUserRefreshEnabled($sLoginMode)) {
			return $oUser;
		}

		if (is_null($oUser)) {
			$oUser = MetaModel::NewObject('UserExternal');
			$oUser->Set('login', $sEmail);
			$oUser->Set('language', MetaModel::GetConfig()->GetDefaultLanguage());
		}

		$oUser->Set('contactid', $oPerson->GetKey());

		$this->SynchronizeProfiles($sLoginMode, $sEmail, $oUser, $oUserProfile, $sInfo);

		if ($oUser->IsModified())
		{
			$oUser->DBWrite();
		}
		return $oUser;
	}

	/**
	 * @param string $sLoginMode: SSO login mode
	 * @param string $sEmail : login/email of user to provision (create/update)
	 * @param \UserExternal $oUser : current user being created
	 * @param \Hybridauth\User\Profile $oUserProfile : hybridauth GetUserInfo object response
	 * @param string $sInfo : metadata added in the history of any iTop object being created/updated (profiles here)
	 * @param string $sServiceProviderGroupToProfileKey : use another key than "groups" if data is coming from some other part of JSON provider response
	 *
	 * @return void
	 * @throws \Combodo\iTop\HybridAuth\HybridProvisioningAuthException
	 */
	public function SynchronizeProfiles(string $sLoginMode, string $sEmail, UserExternal &$oUser, Profile $oUserProfile, string $sInfo, string $sServiceProviderGroupToProfileKey="groups")
	{
		$aProviderConf = Config::GetProviderConf($sLoginMode);
		$aGroupsToProfiles = $aProviderConf['groups_to_profiles'] ?? null;
		$aDefaultProfileNames = Config::GetSynchroProfiles($sLoginMode);
		$aRequestedProfileNames = $aDefaultProfileNames;

		\IssueLog::Debug(__METHOD__, HybridAuthLoginExtension::LOG_CHANNEL, ['groups_to_profiles' => $aGroupsToProfiles]);
		if (is_array($aGroupsToProfiles)) {
			$aCurrentProfilesName=[];
			$aSpGroupsIds = $oUserProfile->data[$sServiceProviderGroupToProfileKey] ?? null;
			if (is_array($aSpGroupsIds)) {
				\IssueLog::Debug(__METHOD__, HybridAuthLoginExtension::LOG_CHANNEL, [$sServiceProviderGroupToProfileKey => $aSpGroupsIds]);
				foreach ($aSpGroupsIds as $sSpGroupId) {
					$profileName = $aGroupsToProfiles[$sSpGroupId] ?? null;
					if (is_null($profileName)) {
						\IssueLog::Warning("Service provider group id does not match any configured iTop profile", HybridAuthLoginExtension::LOG_CHANNEL, ['sp_group_id' => $sSpGroupId, 'groups_to_profile' => $aGroupsToProfiles]);
						continue;
					}

					if (is_array($profileName)){
						foreach ($profileName as $sProfileName){
							$aCurrentProfilesName[] = $sProfileName;
						}
					} else {
						$aCurrentProfilesName[] = $profileName;
					}
				}

				if (count($aCurrentProfilesName) == 0) {
					\IssueLog::Error("No sp group/profile matching found. User profiles updated with default profiles", HybridAuthLoginExtension::LOG_CHANNEL, ['login_mode' => $sLoginMode, 'email' => $sEmail, 'sp_group_id' => $sSpGroupId, 'groups_to_profile' => $aGroupsToProfiles]);
				} else {
					$aRequestedProfileNames = $aCurrentProfilesName;
					}
		} else {
				\IssueLog::Warning("Service provider $sServiceProviderGroupToProfileKey not an array", null, [$sServiceProviderGroupToProfileKey => $aSpGroupsIds]);
			}
		} else {
			\IssueLog::Warning("Configuration issue with groups_to_profiles section", null, ['login_mode' => $sLoginMode, 'email' => $sEmail, 'groups_to_profiles' => $aGroupsToProfiles]);
		}

		IssueLog::Info("OpenID User provisioning", HybridAuthLoginExtension::LOG_CHANNEL, ['login_mode' => $sLoginMode, 'email' => $sEmail, 'profiles' => $aRequestedProfileNames]);

		// read all the existing profiles
		$oProfilesSearch = new DBObjectSearch('URP_Profiles');
		$oProfilesSearch->AllowAllData();
		$oProfilesSet = new DBObjectSet($oProfilesSearch);
		$aAllProfilIDs = [];
		while ($oProfile = $oProfilesSet->Fetch())
		{
			$aAllProfilIDs[mb_strtolower($oProfile->GetName())] = $oProfile->GetKey();
		}

		$oProfilesSet = DBObjectSet::FromScratch('URP_UserProfile');
		$iCount=0;

		foreach ($aRequestedProfileNames as $sRequestedProfileName)
		{
			$sRequestedProfileName = mb_strtolower($sRequestedProfileName);
			if (isset($aAllProfilIDs[$sRequestedProfileName]))
			{
				$iProfileId = $aAllProfilIDs[$sRequestedProfileName];
				$oLink = new URP_UserProfile();
				$oLink->Set('profileid', $iProfileId);
				$oLink->Set('reason', $sInfo);
				$oProfilesSet->AddObject($oLink);
				$iCount++;
			} else {
				IssueLog::Warning("Cannot add profile to user", null, ['requested_profile_from_service_provider' => $sRequestedProfileName, 'login' => $sEmail]);
			}
		}

		if ($iCount==0) {
			\IssueLog::Error("no valid URP_Profile to attach to user", HybridAuthLoginExtension::LOG_CHANNEL, ['login_mode' => $sLoginMode, 'email' => $sEmail, 'aRequestedProfileNames' => $aRequestedProfileNames]);

			if ($oUser->GetKey() != -1) {
				//no profiles update. we let user connect with previous profiles
				return;
			}

			throw new HybridProvisioningAuthException("no valid URP_Profile to attach to user", 0, null,
				['login_mode' => $sLoginMode, 'email' => $sEmail, 'aRequestedProfileNames' => $aRequestedProfileNames]);
		}

		$oUser->Set('profile_list', $oProfilesSet);
	}


}
