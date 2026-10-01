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

class ProvisioningService
{
	private static ProvisioningService $oInstance;

	protected function __construct()
	{
	}

	final public static function GetInstance(): ProvisioningService
	{
		if (!isset(self::$oInstance)) {
			self::$oInstance = new ProvisioningService();
		}

		return self::$oInstance;
	}

	final public static function SetInstance(?ProvisioningService $oInstance): void
	{
		self::$oInstance = $oInstance;
	}

	/**
	 * @param string $sLoginMode: SSO login mode
	 * @param string $sAuthUser: login/email of user being currently provisioned
	 * @param \Hybridauth\User\Profile $oUserProfile : hybridauth GetUserInfo object response
	 *
	 * @return array
	 * @throws \Combodo\iTop\HybridAuth\HybridProvisioningAuthException
	 */
	public function DoProvisioning(string $sLoginMode, string $sAuthUser, Profile $oUserProfile): array
	{
		$oPerson = ProvisioningService::GetInstance()->DoPersonProvisioning($sLoginMode, $sAuthUser, $oUserProfile);
		$oUser = ProvisioningService::GetInstance()->DoUserProvisioning($sLoginMode, $sAuthUser, $oPerson, $oUserProfile);
		return [$oPerson, $oUser];
	}

	/**
	 * @param string $sLoginMode : SSO login mode
	 * @param string $sAuthUser : login/email of user being currently provisioned
	 * @param \Hybridauth\User\Profile $oUserProfile : hybridauth GetUserInfo object response (coming from Oauth2 IdP provider)
	 *
	 * @return \Person|null
	 * @throws \Combodo\iTop\HybridAuth\HybridProvisioningAuthException
	 * @throws \CoreException
	 */
	public function DoPersonProvisioning(string $sLoginMode, string $sAuthUser, Profile $oUserProfile): ?Person
	{
		$bSynchronizeContact = Config::IsOptionEnabled($sLoginMode, 'synchronize_contact');
		$bRefreshContact = Config::IsOptionEnabled($sLoginMode, 'refresh_existing_contact');
		if (!$bRefreshContact && !$bSynchronizeContact) {
				return null;
		}
		//HybridAuthProvisioning class comes from datamodel
		//By default Person is found based on email search (\LoginWebPage::FindPerson)
		//For tricky reconciliations please extend datamodel
		$oHybridAuthProvisioning = new HybridAuthProvisioning();
		$oPerson = $oHybridAuthProvisioning->FindPerson($sLoginMode, $sAuthUser, $oUserProfile);
		$bRefresh = false;
		if (! is_null($oPerson)) {
			if (!$bRefreshContact) {
				return $oPerson;
			}

			$bRefresh = true;
		} else {
			/** @var Person $oPerson */
			if (!$bSynchronizeContact) {
				return null;
			}
			$oPerson = MetaModel::NewObject('Person');
		}

		// Create the person
		if ($bRefresh) {
			$sFirstName = $oUserProfile->firstName ?? $oPerson->Get('first_name');
			$sLastName = $oUserProfile->lastName ?? $oPerson->Get('name');
		} else {
			$sFirstName = $oUserProfile->firstName ?? $sAuthUser;
			$sLastName = $oUserProfile->lastName ?? $sAuthUser;
		}

		$serviceProviderProfileKey = Config::GetIdpSearchKey($sLoginMode, 'org_idp_key', 'organization');
		$sSeparator = Config::GetIdpKey($sLoginMode, 'allowed_orgs_idp_separator', null);
		$aProviderConf = Config::GetProviderConf($sLoginMode);
		$aMatchingTable = $aProviderConf['groups_to_orgs'] ?? null;
		$oIdpMatchingTable = new IdpMatchingTable($sLoginMode, $aMatchingTable, 'groups_to_orgs', $serviceProviderProfileKey, $sSeparator);
		$aRequestedOrgNames = $oIdpMatchingTable->GetObjectNamesFromIdpMatchingTable($sAuthUser, $oUserProfile);
		$sIdPOrgName = null;
		if (! is_null($aRequestedOrgNames)) {
			$sIdPOrgName = $aRequestedOrgNames[0] ?? null;
		}

		$sOrganization = $this->GetOrganizationForProvisioning($sLoginMode, $sIdPOrgName);
		$aPersonParams = [
			'first_name' => $sFirstName,
			'name' => $sLastName,
			'email' => $oUserProfile->email,
			'phone' => $oUserProfile->phone,
		];

		//HybridAuthProvisioning class comes from datamodel
		//By default CompletePersonAdditionalParamsBeforeDbWrite is doing nothing
		//if someone wants to extend person provisioning it can be done via DM...
		$oHybridAuthProvisioning = new HybridAuthProvisioning();
		$oHybridAuthProvisioning->CompletePersonAdditionalParamsBeforeDbWrite($sLoginMode, $sAuthUser, $oPerson, $oUserProfile, $aPersonParams);

		IssueLog::Info("Person saved with OpenID provisioning info", HybridAuthLoginExtension::LOG_CHANNEL, $aPersonParams);
		self::SavePerson($oPerson, $sOrganization, $aPersonParams);
		return $oPerson;
	}

	/**
	 * Provisioning API: Create a person
	 *
	 * @api
	 *
	 * @param Person $oPerson
	 * @param string $sOrganization
	 * @param array $aPersonParams
	 *
	 * @return void
	 */
	public static function SavePerson(Person $oPerson, $sOrganization, array $aPersonParams): void
	{
		CMDBObject::SetTrackOrigin('custom-extension');
		$sInfo = 'External User provisioning';
		if (Session::IsSet('login_mode')) {
			$sInfo .= " (".Session::Get('login_mode').")";
		}
		CMDBObject::SetTrackInfo($sInfo);

		$oOrg = MetaModel::GetObjectByName('Organization', $sOrganization, false);
		if (is_null($oOrg)) {
			throw new Exception(Dict::S('UI:Login:Error:WrongOrganizationName'));
		}
		$oPerson->Set('org_id', $oOrg->GetKey());
		foreach ($aPersonParams as $sAttCode => $sValue) {
			$oPerson->Set($sAttCode, $sValue);
		}

		$oPerson->DBWrite();
	}

	private function GetOrganizationForProvisioning(string $sLoginMode, ?string $sIdPOrgName): string
	{
		if (is_null($sIdPOrgName)) {
			return Config::GetDefaultOrg($sLoginMode);
		}

		$sOrgOqlSearchField = Config::GetIdpSearchKey($sLoginMode, 'org_oql_search_field', 'name');
		/** @var ?\Organization $oOrg */
		$oOrg = MetaModel::GetObjectByColumn('Organization', $sOrgOqlSearchField, $sIdPOrgName, false, true);
		if (!is_null($oOrg)) {
			return $oOrg->Get('name');
		}

		IssueLog::Error(Dict::S('UI:Login:Error:WrongOrganizationName'), null, ['idp_organization' => $sIdPOrgName]);

		return Config::GetDefaultOrg($sLoginMode);
	}

	/**
	 * @param string $sLoginMode: SSO login mode
	 * @param string $sAuthUser: login/email of user being currently provisioned
	 * @param \Person|null $oPerson : Person object attached to user
	 * @param \Hybridauth\User\Profile $oUserProfile : hybridauth GetUserInfo object response (coming from Oauth2 IdP provider)
	 *
	 * @return \UserExternal
	 * @throws \Combodo\iTop\HybridAuth\HybridProvisioningAuthException
	 */
	public function DoUserProvisioning(string $sLoginMode, string $sAuthUser, ?Person $oPerson, Profile $oUserProfile): UserExternal
	{
		if (!MetaModel::IsValidClass('URP_Profiles')) {
			throw new HybridProvisioningAuthException(
				"URP_Profiles is not a valid class. Automatic creation of Users is not supported in this context, sorry.",
				0,
				null,
				['login_mode' => $sLoginMode, 'auth_user' => $sAuthUser]
			);
		}

		CMDBObject::SetTrackOrigin('custom-extension');
		$sInfo = "External User provisioning ($sLoginMode)";
		CMDBObject::SetTrackInfo($sInfo);

		//HybridAuthProvisioning class comes from datamodel
		//By default UserExternal is found based on email===login
		//For tricky reconciliations please extend datamodel
		$oHybridAuthProvisioning = new HybridAuthProvisioning();
		/** @var ?UserExternal $oUser */
		$oUser = $oHybridAuthProvisioning->FindUserExternal($sLoginMode, $sAuthUser, $oUserProfile);

		if (! is_null($oUser) && ! Config::IsOptionEnabled($sLoginMode, 'refresh_existing_user')) {
			return $oUser;
		}

		if (is_null($oUser)) {
			$oUser = MetaModel::NewObject('UserExternal');
			$oUser->Set('login', $sAuthUser);
			$oUser->Set('language', MetaModel::GetConfig()->GetDefaultLanguage());
			IssueLog::Info(
				"User saved with OpenID provisioning info",
				HybridAuthLoginExtension::LOG_CHANNEL,
				['login' => $sAuthUser, 'language' => MetaModel::GetConfig()->GetDefaultLanguage()]
			);
		}

		$oUser->Set('contactid', $oPerson?->GetKey() ?? 0);

		$aProviderConf = Config::GetProviderConf($sLoginMode);
		$this->SynchronizeProfiles($sLoginMode, $sAuthUser, $oUser, $oUserProfile, $aProviderConf, $sInfo);
		$this->SynchronizeAllowedOrgs($sLoginMode, $sAuthUser, $oUser, $oUserProfile, $aProviderConf, $sInfo, $oPerson?->Get('org_id'));

		//HybridAuthProvisioning class comes from datamodel
		//By default CompleteUserProvisioningBeforeDbWrite is doing nothing
		//if someone wants to extend user provisioning it can be done via DM...
		$oHybridAuthProvisioning = new HybridAuthProvisioning();
		$oHybridAuthProvisioning->CompleteUserProvisioningBeforeDbWrite($sLoginMode, $sAuthUser, $oPerson, $oUser, $oUserProfile, $sInfo);

		if ($oUser->IsModified()) {
			$oUser->DBWrite();
		}
		return $oUser;
	}

	/**
	 * @param string $sLoginMode: SSO login mode
	 * @param string $sAuthUser : login/email of user to provision (create/update)
	 * @param \UserExternal $oUser : current user being created
	 * @param \Hybridauth\User\Profile $oUserProfile : hybridauth GetUserInfo object response
	 * @param array $aProviderConf : itop provider configuration
	 * @param string $sInfo : metadata added in the history of any iTop object being created/updated (profiles here)
	 *
	 * @return void
	 * @throws \Combodo\iTop\HybridAuth\HybridProvisioningAuthException
	 */
	public function SynchronizeProfiles(string $sLoginMode, string $sAuthUser, UserExternal &$oUser, Profile $oUserProfile, array $aProviderConf, string $sInfo)
	{
		$serviceProviderProfileKey = Config::GetIdpSearchKey($sLoginMode, 'profiles_idp_key', 'groups');
		$sSeparator = Config::GetIdpKey($sLoginMode, 'profiles_idp_separator', null);
		$aMatchingTable = $aProviderConf['groups_to_profiles'] ?? null;

		$oIdpMatchingTable = new IdpMatchingTable($sLoginMode, $aMatchingTable, 'groups_to_profiles', $serviceProviderProfileKey, $sSeparator);
		$aRequestedProfileNames = $oIdpMatchingTable->GetObjectNamesFromIdpMatchingTable($sAuthUser, $oUserProfile);
		if (is_null($aRequestedProfileNames)) {
			$aRequestedProfileNames = Config::GetSynchroProfiles($sLoginMode);
		}

		$exceptionToRaise = null;
		if (count($aRequestedProfileNames) == 0) {
			$exceptionToRaise = new HybridProvisioningAuthException("No sp group/profile matching found and no valid URP_Profile to attach to user");
			if ($oUser->IsNew()) {
				throw $exceptionToRaise;
			}

			//fallback to default profiles: user looses his previous profiles
			$aRequestedProfileNames = Config::GetSynchroProfiles($sLoginMode);
		}

		IssueLog::Debug("OpenID Profile matching between IdP and iTop", HybridAuthLoginExtension::LOG_CHANNEL, ['login_mode' => $sLoginMode, 'email' => $sAuthUser, 'profiles' => $aRequestedProfileNames]);

		$oSet = $this->GetOqlProfileSet($aRequestedProfileNames);
		$aIdsToAttach = [];
		$aNamesToAttach = [];
		while ($oCurrentProfile = $oSet->Fetch()) {
			$aIdsToAttach [] = $oCurrentProfile->GetKey();
			$aNamesToAttach [] = $oCurrentProfile->Get('name');
		}

		$aUnfoundNames = array_diff($aRequestedProfileNames, $aNamesToAttach);
		if (count($aUnfoundNames) > 0) {
			\IssueLog::Warning(
				"Cannot add some unfound profiles",
				HybridAuthLoginExtension::LOG_CHANNEL,
				['login_mode' => $sLoginMode, 'auth_user' => $sAuthUser, 'unfound profiles' => $aUnfoundNames ]
			);
		}

		if (count($aIdsToAttach) == 0) {
			\IssueLog::Error("no valid URP_Profile to attach to user", HybridAuthLoginExtension::LOG_CHANNEL, ['login_mode' => $sLoginMode, 'email' => $sAuthUser, 'aRequestedProfileNames' => $aRequestedProfileNames]);

			$exceptionToRaise = new HybridProvisioningAuthException(
				"no valid URP_Profile to attach to user",
				0,
				null,
				['login_mode' => $sLoginMode, 'auth_user' => $sAuthUser, 'aRequestedProfileNames' => $aRequestedProfileNames]
			);

			if ($oUser->IsNew()) {
				throw $exceptionToRaise;
			}

			//fallback to default profiles: user looses his previous profiles
			$aRequestedProfileNames = Config::GetSynchroProfiles($sLoginMode);
			$oSet = $this->GetOqlProfileSet($aRequestedProfileNames);
			while ($oCurrentProfile = $oSet->Fetch()) {
				$aIdsToAttach [] = $oCurrentProfile->GetKey();
			}

			if (count($aIdsToAttach) == 0) {
				throw $exceptionToRaise;
			}
		}

		$oProfilesSet = new \ormLinkSet(\UserExternal::class, 'profile_list', \DBObjectSet::FromScratch(\URP_UserProfile::class));

		foreach ($aIdsToAttach as $iProfileId) {
			$oLink = MetaModel::NewObject('URP_UserProfile', ['profileid' => $iProfileId, 'reason' => $sInfo]);
			$oProfilesSet->AddItem($oLink);
		}

		$oUser->Set('profile_list', $oProfilesSet);

		if (! is_null($exceptionToRaise)) {
			if ($oUser->IsModified()) {
				$oUser->DBWrite();
			}
			throw $exceptionToRaise;
		}
	}

	private function GetOqlProfileSet(array $aRequestedProfileNames): DBObjectSet
	{
		// read all the matching profiles
		$sInSubquery = '"'.implode('","', $aRequestedProfileNames).'"';
		$oSearch = DBObjectSearch::FromOQL("SELECT URP_Profiles WHERE name IN ($sInSubquery)");
		$oSearch->AllowAllData();

		$oSet = new DBObjectSet($oSearch);
		$oSet->OptimizeColumnLoad(['URP_Profiles' => ['name']]);
		return $oSet;
	}

	/**
	 * @param string $sLoginMode: SSO login mode
	 * @param string $sAuthUser : login/email of user to provision (create/update)
	 * @param \UserExternal $oUser : current user being created
	 * @param \Hybridauth\User\Profile $oUserProfile : hybridauth GetUserInfo object response
	 * @param array $aProviderConf : itop provider configuration
	 * @param string $sInfo : metadata added in the history of any iTop object being created/updated (profiles here)
	 * @param string|null $sPersonOrgId : org_id of attached contact. null when no person linked to user
	 *
	 * @return void
	 * @throws \Combodo\iTop\HybridAuth\HybridProvisioningAuthException
	 */
	public function SynchronizeAllowedOrgs(string $sLoginMode, string $sAuthUser, UserExternal &$oUser, Profile $oUserProfile, array $aProviderConf, string $sInfo, ?string $sPersonOrgId = null)
	{
		$serviceOrgsKey = Config::GetIdpSearchKey($sLoginMode, 'allowed_orgs_idp_key', 'allowed_orgs');
		$sSeparator = Config::GetIdpKey($sLoginMode, 'allowed_orgs_idp_separator', null);
		$sOrgOqlSearchField = Config::GetIdpKey($sLoginMode, 'allowed_orgs_oql_search_field', 'name');
		$aMatchingTable = $aProviderConf['groups_to_orgs'] ?? null;

		$oIdpMatchingTable = new IdpMatchingTable($sLoginMode, $aMatchingTable, 'groups_to_orgs', $serviceOrgsKey, $sSeparator);
		$aRequestedOrgNames = $oIdpMatchingTable->GetObjectNamesFromIdpMatchingTable($sAuthUser, $oUserProfile);
		if (is_null($aRequestedOrgNames)) {
			$aRequestedOrgNames = Config::GetDefaultAllowedOrgs($sLoginMode);
		}

		IssueLog::Info("OpenID (Allowed) Organization matching between IdP and iTop", HybridAuthLoginExtension::LOG_CHANNEL, ['login_mode' => $sLoginMode, 'email' => $sAuthUser, 'orgs' => $aRequestedOrgNames]);

		$iCount = 0;
		$aOrgsIdsToAttach = [];
		if (count($aRequestedOrgNames) > 0) {
			// read all the matching orgs
			$sInSubquery = '"'.implode('","', $aRequestedOrgNames).'"';
			$oSearch = DBObjectSearch::FromOQL("SELECT Organization WHERE $sOrgOqlSearchField IN ($sInSubquery)");
			$oSearch->AllowAllData();

			$oOrgSet = new DBObjectSet($oSearch);
			$oOrgSet->OptimizeColumnLoad(['Organization' => [$sOrgOqlSearchField]]);

			$aOrgsNamesToAttach = [];
			while ($oCurrenOrg = $oOrgSet->Fetch()) {
				$aOrgsIdsToAttach [] = $oCurrenOrg->GetKey();
				$aOrgsNamesToAttach [] = $oCurrenOrg->Get($sOrgOqlSearchField);
				$iCount++;
			}

			$aUnfoundOrgNames = array_diff($aRequestedOrgNames, $aOrgsNamesToAttach);
			if (count($aUnfoundOrgNames) > 0) {
				\IssueLog::Warning("Cannot add some unfound allowed organization", HybridAuthLoginExtension::LOG_CHANNEL, ['login_mode' => $sLoginMode, 'email' => $sAuthUser, 'unfound orgs' => $aUnfoundOrgNames]);
			}
		}

		$oAllowedOrgSet = new \ormLinkSet(\UserExternal::class, 'allowed_org_list', \DBObjectSet::FromScratch(\URP_UserOrg::class));
		if ($iCount > 0) {
			if (! is_null($sPersonOrgId) && ! in_array($sPersonOrgId, $aOrgsIdsToAttach)) {
				//put person organization first to make provisioning work without below check issue: Class:User/Error:AllowedOrgsMustContainUserOrg
				array_unshift($aOrgsIdsToAttach, $sPersonOrgId);
			}

			foreach ($aOrgsIdsToAttach as $iOrgId) {
				$oLink = MetaModel::NewObject('URP_UserOrg', ['allowed_org_id' => $iOrgId, 'reason' => $sInfo]);
				$oAllowedOrgSet->AddItem($oLink);
			}
		} else {
			\IssueLog::Warning("no valid URP_UserOrg to attach to user", HybridAuthLoginExtension::LOG_CHANNEL, ['login_mode' => $sLoginMode, 'email' => $sAuthUser, 'sp_org_names' => $aRequestedOrgNames]);
		}

		$oUser->Set('allowed_org_list', $oAllowedOrgSet);
	}
}
