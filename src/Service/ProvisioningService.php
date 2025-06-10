<?php

namespace Combodo\iTop\HybridAuth\Service;

use CMDBObject;
use Combodo\iTop\HybridAuth\Config;
use Combodo\iTop\HybridAuth\HybridAuthLoginExtension;
use Combodo\iTop\HybridAuth\HybridProvisioningAuthException;
use Dict;
use Hybridauth\User\Profile;
use IssueLog;
use LoginWebPage;
use MetaModel;
use Person;
use Session;
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

	public function DoPersonProvisioning(string $sLoginMode, string $sEmail, Profile $oUserProfile) : Person
	{
		$oPerson = LoginWebPage::FindPerson($sEmail);
		if (! is_null($oPerson)) {
			return $oPerson;
		}

		if (!Config::IsContactSynchroEnabled($sLoginMode)) {
			throw new HybridProvisioningAuthException("Cannot find Person and no automatic Contact provisioning", 0, null,
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

	public function DoUserProvisioning(string $sLoginMode, string $sEmail, Person $oPerson, Profile $oUserProfile) : UserExternal
	{
		if (!MetaModel::IsValidClass('URP_Profiles'))
		{
			throw new HybridProvisioningAuthException("URP_Profiles is not a valid class. Automatic creation of Users is not supported in this context, sorry.", 0, null,
				['login_mode' => $sLoginMode, 'email' => $sEmail]);
		}

		CMDBObject::SetTrackOrigin('custom-extension');
		$sInfo = "External User provisioning ($sLoginMode)";
		CMDBObject::SetTrackInfo($sInfo);

		/** @var UserExternal $oUser */
		$oUser = MetaModel::GetObjectByName('UserExternal', $sEmail, false);
		if (is_null($oUser))
		{
			$oUser = MetaModel::NewObject('UserExternal');
			$oUser->Set('login', $sEmail);
			$oUser->Set('contactid', $oPerson->GetKey());
			$oUser->Set('language', MetaModel::GetConfig()->GetDefaultLanguage());
		}

		$this->SynchronizeProfiles($sLoginMode, $sEmail, $oUser, $oPerson, $oUserProfile, $sInfo);

		if ($oUser->IsModified())
		{
			$oUser->DBWrite();
		}
		return $oUser;
	}

	private function SynchronizeProfiles(string $sLoginMode, string $sEmail, UserExternal &$oUser, Person $oPerson, Profile $oUserProfile, string $sInfo)
	{
		if (array_key_exists('groups', $oUserProfile->data)) {
			$aProviderConf = Config::GetProviderConf($sLoginMode);
			$aGroupsToProfiles = $aProviderConf['groups_to_profiles'];

			$aRequestedProfileNames = [];
			foreach ($oUserProfile->data['groups'] as $groupName) {
				if (array_key_exists($groupName, $aGroupsToProfiles)) {
					foreach ($aGroupsToProfiles[$groupName] as $profileName)
						$aRequestedProfileNames[] = $profileName;
				}
			}
		} else {
			$sProfile = Config::GetSynchroProfile($sLoginMode);
			$aRequestedProfileNames = [$sProfile];
		}

		IssueLog::Info("OpenID User provisioning", HybridAuthLoginExtension::LOG_CHANNEL, ['login' => $sEmail, 'profiles' => $aRequestedProfileNames, 'contact_id' => $oPerson->GetKey()]);

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
			throw new HybridProvisioningAuthException("no valid URP_Profile to attach to user", 0, null,
				['login_mode' => $sLoginMode, 'email' => $sEmail, 'aRequestedProfileNames' => $aRequestedProfileNames]);
		}

		$oUser->Set('profile_list', $oProfilesSet);
	}
}
