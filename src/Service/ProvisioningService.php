<?php

namespace Combodo\iTop\HybridAuth\Service;

use CMDBObject;
use Combodo\iTop\HybridAuth\Config;
use Combodo\iTop\HybridAuth\HybridAuthLoginExtension;
use Combodo\iTop\HybridAuth\HybridProvisioningAuthException;
use DBObjectSearch;
use DBObjectSet;
use Exception;
use HybridAuthProvisioning;
use Dict;
use Hybridauth\User\Profile;
use IssueLog;
use LoginWebPage;
use MetaModel;
use Person;
use Combodo\iTop\Application\Helper\Session;
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
		//HybridAuthProvisioning class comes from datamodel
		//By default Person is found based on email search (\LoginWebPage::FindPerson)
		//For tricky reconciliations please extend datamodel
		$oHybridAuthProvisioning = new HybridAuthProvisioning();
		$oPerson = $oHybridAuthProvisioning->FindPerson($sLoginMode, $sEmail, $oUserProfile);
		$bRefresh = false;
		if (! is_null($oPerson)){
			if (! Config::IsUserRefreshEnabled($sLoginMode)) {
				return $oPerson;
			}

			$bRefresh = true;
		}

		if (! Config::IsContactSynchroEnabled($sLoginMode)) {
			throw new HybridProvisioningAuthException("Cannot find Person and no automatic Contact provisioning (synchronize_contact)", 0, null,
				['login_mode' => $sLoginMode, 'email' => $sEmail]); // No automatic Contact provisioning
		}

		// Create the person
		if ($bRefresh){
			$sFirstName = $oUserProfile->firstName ?? $oPerson->Get('first_name');
			$sLastName = $oUserProfile->lastName ?? $oPerson->Get('last_name');
		} else {
			$sFirstName = $oUserProfile->firstName ?? $sEmail;
			$sLastName = $oUserProfile->lastName ?? $sEmail;
		}

		$sServiceProviderOrganizationKey = Config::GetIdpKey($sLoginMode, 'profiles', 'organization');
		$sOrganization = $this->GetOrganizationForProvisioning($sLoginMode, $oUserProfile->data[$sServiceProviderOrganizationKey] ?? null);
		$aPersonParams = [
			'first_name' => $sFirstName,
			'name' => $sLastName,
			'email' => $sEmail,
			'phone' => $oUserProfile->phone
		];

		//HybridAuthProvisioning class comes from datamodel
		//By default CompletePersonAdditionalParamsBeforeDbWrite is doing nothing
		//if someone wants to extend person provisioning it can be done via DM...
		$oHybridAuthProvisioning = new HybridAuthProvisioning();
		$oHybridAuthProvisioning->CompletePersonAdditionalParamsBeforeDbWrite($sLoginMode, $sEmail, $oPerson, $oUserProfile, $aPersonParams);

		IssueLog::Info("OpenID Person provisioning", HybridAuthLoginExtension::LOG_CHANNEL, $aPersonParams);
		return self::SavePerson($oPerson, $sOrganization, $aPersonParams);
	}

	/**
	 * Provisioning API: Create a person
	 *
	 * @api
	 *
	 * @param Person|null $oPerson
	 * @param string $sOrganization
	 * @param array $aPersonParams
	 *
	 * @return \Person
	 */
	public static function SavePerson(?Person $oPerson, $sOrganization, array $aPersonParams)
	{
		CMDBObject::SetTrackOrigin('custom-extension');
		$sInfo = 'External User provisioning';
		if (Session::IsSet('login_mode'))
		{
			$sInfo .= " (".Session::Get('login_mode').")";
		}
		CMDBObject::SetTrackInfo($sInfo);

		if (is_null($oPerson)){
			$oPerson = MetaModel::NewObject('Person');
		}
		$oOrg = MetaModel::GetObjectByName('Organization', $sOrganization, false);
		if (is_null($oOrg))
		{
			throw new Exception(Dict::S('UI:Login:Error:WrongOrganizationName'));
		}
		$oPerson->Set('org_id', $oOrg->GetKey());
		foreach ($aPersonParams as $sAttCode => $sValue)
		{
			$oPerson->Set($sAttCode, $sValue);
		}

		$oPerson->DBWrite();
		return $oPerson;
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

		//HybridAuthProvisioning class comes from datamodel
		//By default UserExternal is found based on email===login
		//For tricky reconciliations please extend datamodel
		$oHybridAuthProvisioning = new HybridAuthProvisioning();
		/** @var UserExternal $oUser */
		$oUser = $oHybridAuthProvisioning->FindUserExternal($sLoginMode, $sEmail, $oUserProfile);

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

		//HybridAuthProvisioning class comes from datamodel
		//By default CompleteUserProvisioningBeforeDbWrite is doing nothing
		//if someone wants to extend user provisioning it can be done via DM...
		$oHybridAuthProvisioning = new HybridAuthProvisioning();
		$oHybridAuthProvisioning->CompleteUserProvisioningBeforeDbWrite($sLoginMode, $sEmail, $oPerson, $oUser, $oUserProfile, $sInfo);

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
	 *
	 * @return void
	 * @throws \Combodo\iTop\HybridAuth\HybridProvisioningAuthException
	 */
	public function SynchronizeProfiles(string $sLoginMode, string $sEmail, UserExternal &$oUser, Profile $oUserProfile, string $sInfo)
	{
		$aProviderConf = Config::GetProviderConf($sLoginMode);
		$aDefaultProfileNames = Config::GetSynchroProfiles($sLoginMode);
		$aRequestedProfileNames = $this->GetProfileNamesFromIdpResponse($sLoginMode, $sEmail, $oUserProfile, $aProviderConf, $aDefaultProfileNames);

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

		$oProfilesSet = new \ormLinkSet(\UserExternal::class, 'profile_list', \DBObjectSet::FromScratch(\URP_UserProfile::class));
		$iCount=0;

		foreach ($aRequestedProfileNames as $sRequestedProfileName)
		{
			$sRequestedProfileName = mb_strtolower($sRequestedProfileName);
			if (isset($aAllProfilIDs[$sRequestedProfileName]))
			{
				$iProfileId = $aAllProfilIDs[$sRequestedProfileName];
				$oProfilesSet->AddItem(MetaModel::NewObject('URP_UserProfile', array('profileid' => $iProfileId, 'reason' => $sInfo)));
				$iCount++;
			} else {
				IssueLog::Warning("Cannot add profile to user", null, ['requested_profile_from_service_provider' => $sRequestedProfileName, 'login' => $sEmail]);
			}
		}

		if ($iCount==0) {
			\IssueLog::Error("no valid URP_Profile to attach to user", HybridAuthLoginExtension::LOG_CHANNEL, ['login_mode' => $sLoginMode, 'email' => $sEmail, 'aRequestedProfileNames' => $aRequestedProfileNames]);
			throw new HybridProvisioningAuthException("no valid URP_Profile to attach to user", 0, null,
				['login_mode' => $sLoginMode, 'email' => $sEmail, 'aRequestedProfileNames' => $aRequestedProfileNames]);
		}

		$oUser->Set('profile_list', $oProfilesSet);
	}

	public function GetProfileNamesFromIdpResponse(string $sLoginMode, string $sEmail, Profile $oUserProfile, array $aProviderConf, array $aDefaultProfileNames) : array {
		$aGroupsToProfiles = $aProviderConf['groups_to_profiles'] ?? null;
		$sServiceProviderProfileKey = Config::GetIdpKey($sLoginMode, 'profiles', 'groups');

		\IssueLog::Debug(__METHOD__, HybridAuthLoginExtension::LOG_CHANNEL, ['groups_to_profiles' => $aGroupsToProfiles]);
		if (! is_array($aGroupsToProfiles)) {
			\IssueLog::Warning("Configuration issue with groups_to_profiles section", null, ['login_mode' => $sLoginMode, 'groups_to_profiles' => $aGroupsToProfiles]);
			return $aDefaultProfileNames;
		}

		$aCurrentProfilesName=[];
		$aSpGroupsIds = $oUserProfile->data[$sServiceProviderProfileKey] ?? null;
		if (!is_array($aSpGroupsIds)) {
			\IssueLog::Warning("Service provider $sServiceProviderProfileKey not an array", null, [$sServiceProviderProfileKey => $aSpGroupsIds]);
			return $aDefaultProfileNames;
		}

		\IssueLog::Debug(__METHOD__, HybridAuthLoginExtension::LOG_CHANNEL, [$sServiceProviderProfileKey => $aSpGroupsIds]);
		foreach ($aSpGroupsIds as $sSpGroupId) {
			$profileName = $aGroupsToProfiles[$sSpGroupId] ?? null;
			if (is_null($profileName)) {
				\IssueLog::Warning("Service provider group id does not match any configured iTop profile",
					HybridAuthLoginExtension::LOG_CHANNEL, ['sp_group_id' => $sSpGroupId, 'groups_to_profile' => $aGroupsToProfiles]);
				continue;
			}

			if (is_array($profileName)) {
				foreach ($profileName as $sProfileName) {
					$aCurrentProfilesName[] = $sProfileName;
				}
			} else {
				$aCurrentProfilesName[] = $profileName;
			}
		}

		if (count($aCurrentProfilesName) == 0) {
			$aContext = [
				'login_mode' => $sLoginMode,
				'email' => $sEmail,
				'sp_group_id' => $aSpGroupsIds,
				'groups_to_profile' => $aGroupsToProfiles
			];

			\IssueLog::Error("No sp group/profile matching found",
				HybridAuthLoginExtension::LOG_CHANNEL, $aContext);

			throw new HybridProvisioningAuthException("No sp group/profile matching found and no valid URP_Profile to attach to user", 0, null, $aContext);
		}

		return $aCurrentProfilesName;
	}
}
