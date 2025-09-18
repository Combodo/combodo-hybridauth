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

		$aProviderConf = Config::GetProviderConf($sLoginMode);
		$this->SynchronizeProfiles($sLoginMode, $sEmail, $oUser, $oUserProfile, $aProviderConf, $sInfo);
		$this->SynchronizeAllowedOrgs($sLoginMode, $sEmail, $oUser, $oUserProfile, $aProviderConf, $sInfo);

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
	 * @param array $aProviderConf : itop provider configuration
	 * @param string $sInfo : metadata added in the history of any iTop object being created/updated (profiles here)
	 *
	 * @return void
	 * @throws \Combodo\iTop\HybridAuth\HybridProvisioningAuthException
	 */
	public function SynchronizeProfiles(string $sLoginMode, string $sEmail, UserExternal &$oUser, Profile $oUserProfile, array $aProviderConf, string $sInfo)
	{
		$sServiceProviderProfileKey = Config::GetIdpKey($sLoginMode, 'profiles', 'groups');
		$aMatchingTable = $aProviderConf['groups_to_profiles'] ?? null;
		$aRequestedProfileNames = $this->GetObjectNamesFromIdpMatchingTable($sLoginMode, $sEmail, $oUserProfile, $aMatchingTable, 'groups_to_profiles', $sServiceProviderProfileKey);
		if (is_null($aRequestedProfileNames)){
			$aRequestedProfileNames = Config::GetSynchroProfiles($sLoginMode);
		} else if (count($aRequestedProfileNames)==0){
			throw new HybridProvisioningAuthException("No sp group/profile matching found and no valid URP_Profile to attach to user");
		}

		IssueLog::Info("OpenID Profile provisioning", HybridAuthLoginExtension::LOG_CHANNEL, ['login_mode' => $sLoginMode, 'email' => $sEmail, 'profiles' => $aRequestedProfileNames]);

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

	/**
	 * @param string $sLoginMode: SSO login mode
	 * @param string $sEmail : login/email of user to provision (create/update)
	 * @param \UserExternal $oUser : current user being created
	 * @param \Hybridauth\User\Profile $oUserProfile : hybridauth GetUserInfo object response
	 * @param array $aProviderConf : itop provider configuration
	 * @param string $sInfo : metadata added in the history of any iTop object being created/updated (profiles here)
	 *
	 * @return void
	 * @throws \Combodo\iTop\HybridAuth\HybridProvisioningAuthException
	 */
	public function SynchronizeAllowedOrgs(string $sLoginMode, string $sEmail, UserExternal &$oUser, Profile $oUserProfile, array $aProviderConf, string $sInfo)
	{
		$sServiceProviderProfileKey = Config::GetIdpKey($sLoginMode, 'allowed_orgs', 'allowed_orgs');
		$aMatchingTable = $aProviderConf['groups_to_orgs'] ?? null;
		$aRequestedOrgNames = $this->GetObjectNamesFromIdpMatchingTable($sLoginMode, $sEmail, $oUserProfile, $aMatchingTable, 'groups_to_orgs', $sServiceProviderProfileKey);
		if (is_null($aRequestedOrgNames)){
			$aRequestedOrgNames = Config::GetDefaultAllowedOrgs($sLoginMode);
		} else if (count($aRequestedOrgNames)==0){
			//do nothing
			return;
		}

		IssueLog::Info("OpenID AllowedOrgs provisioning", HybridAuthLoginExtension::LOG_CHANNEL, ['login_mode' => $sLoginMode, 'email' => $sEmail, 'orgs' => $aRequestedOrgNames]);

		// read all the existing profiles
		$oProfilesSearch = new DBObjectSearch('Organization');
		$oProfilesSearch->AllowAllData();
		$oProfilesSet = new DBObjectSet($oProfilesSearch);
		$aIdByNamesTable = [];
		while ($oProfile = $oProfilesSet->Fetch())
		{
			$aIdByNamesTable[mb_strtolower($oProfile->GetName())] = $oProfile->GetKey();
		}

		$oAllowedOrgSet = new \ormLinkSet(\User::class, 'allowed_org_list', \DBObjectSet::FromScratch(\URP_UserOrg::class));
		$iCount=0;

		foreach ($aRequestedOrgNames as $sRequestedName)
		{
			$sNameToFind = mb_strtolower($sRequestedName);
			if (isset($aIdByNamesTable[$sNameToFind]))
			{
				$iOrgId = $aIdByNamesTable[$sNameToFind];
				$oLink = MetaModel::NewObject('URP_UserOrg', ['allowed_org_id' => $iOrgId, 'reason' => $sInfo]);
				$oAllowedOrgSet->AddItem($oLink);
				$iCount++;
			} else {
				IssueLog::Warning("Cannot add allowed_org to user", null, ['requested_org_from_service_provider' => $sRequestedName, 'login' => $sEmail]);
			}
		}

		if ($iCount==0) {
			\IssueLog::Warning("no valid URP_UserOrg to attach to user", HybridAuthLoginExtension::LOG_CHANNEL, ['login_mode' => $sLoginMode, 'email' => $sEmail, 'sp_org_names' => $aRequestedOrgNames]);
		}

		$oUser->Set('allowed_org_list', $oAllowedOrgSet);
	}

	/**
	 * Use IdP response to compute matching table and return a list of names
	 *
	 * @param string $sLoginMode
	 * @param string $sEmail
	 * @param \Hybridauth\User\Profile $oUserProfile
	 * @param mixed $aMatchingTable: matching definition between idp response and itop object names. should be an array or no matching applied
	 * @param string $sMatchingTableConfigurationKey: used here only for supportability (logging/exception messages)
	 * @param string $sServiceProviderProfileKey: key to fetch in IdP response
	 *
	 * @return array|null: return null when matching is not possible somehow. either it is not configured either IdP response does not fit
	 * @throws \Combodo\iTop\HybridAuth\HybridProvisioningAuthException
	 */
	public function GetObjectNamesFromIdpMatchingTable(string $sLoginMode, string $sEmail, Profile $oUserProfile, mixed $aMatchingTable, string $sMatchingTableConfigurationKey, string $sServiceProviderProfileKey) : ?array {
		if (is_null($aMatchingTable)) {
			return null;
		}

		\IssueLog::Debug(__METHOD__ . ": use matching table", HybridAuthLoginExtension::LOG_CHANNEL, [$sMatchingTableConfigurationKey => $aMatchingTable]);

		if (! is_array($aMatchingTable)) {
			\IssueLog::Warning("Configuration issue with $sMatchingTableConfigurationKey section", null, ['login_mode' => $sLoginMode, $sMatchingTableConfigurationKey => $aMatchingTable]);
			return null;
		}

		$aCurrentProfilesName=[];
		$aSpGroupsIds = $oUserProfile->data[$sServiceProviderProfileKey] ?? null;
		if (!is_array($aSpGroupsIds)) {
			\IssueLog::Warning("Service provider $sServiceProviderProfileKey not an array", null, [$sServiceProviderProfileKey => $aSpGroupsIds]);
			return null;
		}

		\IssueLog::Debug("Service provider contains proper $sServiceProviderProfileKey value", null, [$sServiceProviderProfileKey => $aSpGroupsIds]);

		foreach ($aSpGroupsIds as $sSpGroupId) {
			$profileName = $aMatchingTable[$sSpGroupId] ?? null;
			if (is_null($profileName)) {
				\IssueLog::Warning("Service provider ID does not match any configured iTop name",
					HybridAuthLoginExtension::LOG_CHANNEL, ['sp_id' => $sSpGroupId, $sMatchingTableConfigurationKey => $aMatchingTable]);
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
				'idp_key' => $sServiceProviderProfileKey,
				'sp_ids' => $aSpGroupsIds,
				$sMatchingTableConfigurationKey => $aMatchingTable,
			];

			\IssueLog::Error("No matching between IdP $sServiceProviderProfileKey response and configured table ($sMatchingTableConfigurationKey)",
				HybridAuthLoginExtension::LOG_CHANNEL, $aContext);
		}

		return $aCurrentProfilesName;
	}
}
