<?php

namespace Combodo\iTop\HybridAuth\Test;

/**
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @backupGlobals disabled
 *
 */

use Combodo\iTop\HybridAuth\Service\ProvisioningService;
use Combodo\iTop\HybridAuth\Test\Provider\ServiceProviderMock;
use Combodo\iTop\Test\UnitTest\ItopDataTestCase;
use Config;
use DBObjectSearch;
use DBObjectSet;
use Hybridauth\User\Profile;
use LoginWebPage;
use MetaModel;
use Person;
use UserExternal;

class AbstractHybridauthTest extends ItopDataTestCase
{
	protected $sConfigTmpBackupFile;
	protected $sLoginMode;
	protected $sUniqId;

	protected function setUp(): void
	{
		parent::setUp();

		$this->RequireOnceItopFile('env-production/combodo-hybridauth/vendor/autoload.php');
		$this->RequireOnceItopFile('env-production/combodo-oauth2-client/vendor/autoload.php');
		$this->RequireOnceUnitTestFile('../Provider/ServiceProviderMock.php');

		$sConfigPath = MetaModel::GetConfig()->GetLoadedFile();

		clearstatcache();
		echo sprintf("rights via ls on %s:\n %s \n", $sConfigPath, exec("ls -al $sConfigPath"));
		$sFilePermOutput = substr(sprintf('%o', fileperms('/etc/passwd')), -4);
		echo sprintf("rights via fileperms on %s:\n %s \n", $sConfigPath, $sFilePermOutput);

		$this->sConfigTmpBackupFile = tempnam(sys_get_temp_dir(), "config_");
		MetaModel::GetConfig()->WriteToFile($this->sConfigTmpBackupFile);

		$sPath = __DIR__.'/Provider/ServiceProviderMock.php';
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'oauth_test_class_path', $sPath);

		$_SESSION = [];
		$this->sUniqId = "OpenID".uniqid();

		$sSsoMode = 'ServiceProviderMock';

		$aCurrentModuleSettings = MetaModel::GetConfig()->GetModuleSetting('combodo-hybridauth', 'providers', []);
		$aServiceProviderConf = array_merge($aCurrentModuleSettings,
			[
				"$sSsoMode" => [
					'adapter' => 'Combodo\iTop\HybridAuth\Test\Provider\ServiceProviderMock',
					'keys' => [
						'id' => 'ID',
						'secret' => 'SECRET',
					],
					'enabled' => true,
				],
			]
		);

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', $aServiceProviderConf);
		$this->sLoginMode = "hybridauth-".$sSsoMode;
		$this->InitLoginMode($this->sLoginMode);

		//no provisioning
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_user', false);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', false);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'refresh_existing_user', false);

	}

	protected function InitLoginMode($sLoginMode)
	{
		$aAllowedLoginTypes = MetaModel::GetConfig()->GetAllowedLoginTypes();
		if (!in_array($sLoginMode, $aAllowedLoginTypes)) {
			$aAllowedLoginTypes[] = $sLoginMode;
			MetaModel::GetConfig()->SetAllowedLoginTypes($aAllowedLoginTypes);
		}
	}

	protected function tearDown(): void
	{
		parent::tearDown();

		if (!is_null($this->sConfigTmpBackupFile) && is_file($this->sConfigTmpBackupFile)) {
			//put config back
			$sConfigPath = MetaModel::GetConfig()->GetLoadedFile();
			@chmod($sConfigPath, 0770);
			$oConfig = new Config($this->sConfigTmpBackupFile);
			$oConfig->WriteToFile($sConfigPath);
			@chmod($sConfigPath, 0440);
			@unlink($this->sConfigTmpBackupFile);
		}

		if (is_file(ServiceProviderMock::GetFileConfPath())) {
			@unlink(ServiceProviderMock::GetFileConfPath());
		}

		$_SESSION = [];
	}

	protected function CreateOrgAndGetName(?string $sCode=null) : string {
		$sOrgName = $this->sUniqId . '_' . microtime();

		if (is_null($sCode)) {
			/** @var \Organization $oObj */
			$this->createObject('Organization', array(
				'name' => $sOrgName,
			));
		} else {
			/** @var \Organization $oObj */
			$this->createObject('Organization', array(
				'name' => $sOrgName,
				'code' => $sCode,
			));
		}

		return $sOrgName;
	}

	protected function CreatePersonByEmail($sEmail) : Person {
		$oOrg = $this->CreateOrganization($this->sUniqId);

		/** @var Person $oPerson */
		$oPerson = $this->createObject('Person', [
			'name' => $sEmail,
			'first_name' => $sEmail,
			'email' => $sEmail,
			'org_id' => $oOrg->GetKey(),
		]);

		return $oPerson;
	}

	protected function Configure(string $sLoginMode, string $sKey, $value) {
		$aProviderConf = \Combodo\iTop\HybridAuth\Config::GetProviderConf($sLoginMode);
		$aProviderConf[$sKey] = $value;

		$aProviders = \Combodo\iTop\HybridAuth\Config::Get('providers');
		$aProviders[str_replace("hybridauth-", "", $sLoginMode)]=$aProviderConf;

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', $aProviders);
	}

	protected function InitializeGroupsToProfile(string $sLoginMode, $value) {
		$this->Configure($sLoginMode, 'groups_to_profiles', $value);
	}

	protected function InitializeGroupsToOrgs(string $sLoginMode, $value) {
		$this->Configure($sLoginMode, 'groups_to_orgs', $value);
	}

	protected function CreateExternalUserWithProfilesAndAllowedOrgs(string $sEmail, array $aProfileNames, array $aAllowedOrgIds=[]) : \UserExternal
	{
		$oProfilesSet = new \ormLinkSet(\UserExternal::class, 'profile_list', \DBObjectSet::FromScratch(\URP_UserProfile::class));
		foreach ($aProfileNames as $sProfileName){
			$oLink = MetaModel::NewObject('URP_UserProfile', array('profileid' => self::$aURP_Profiles[$sProfileName], 'reason' => 'UNIT Tests'));
			$oProfilesSet->AddItem($oLink);
		}


		if (count($aAllowedOrgIds) > 0){
			$oAllowedOrgSet = new \ormLinkSet(UserExternal::class, 'allowed_org_list', \DBObjectSet::FromScratch(\URP_UserOrg::class));

			foreach ($aAllowedOrgIds as $iOrgId)
			{
				$oLink = MetaModel::NewObject('URP_UserOrg', ['allowed_org_id' => $iOrgId, 'reason' => "WhateverReason"]);
				$oAllowedOrgSet->AddItem($oLink);
			}

			/** @var \UserExternal $oUser */
			$oUser = $this->createObject(UserExternal::class, [
				'login'        => $sEmail,
				'profile_list' => $oProfilesSet,
				'language' => 'FR FR',
				'allowed_org_list' => $oAllowedOrgSet
			]);
		} else {
			/** @var \UserExternal $oUser */
			$oUser = $this->createObject(UserExternal::class, [
				'login'        => $sEmail,
				'profile_list' => $oProfilesSet,
				'language' => 'FR FR',
			]);
		}

		return $oUser;
	}

	protected function assertUserProfiles(UserExternal $oUser, $aExpectedProfiles)
	{
		$oProfilesSearch = new DBObjectSearch('URP_Profiles');
		$oProfilesSearch->AllowAllData();
		$oProfilesSet = new DBObjectSet($oProfilesSearch);
		$aAllProfilNamesById = [];
		while ($oProfile = $oProfilesSet->Fetch())
		{
			$aAllProfilNamesById[$oProfile->GetKey()] = $oProfile->GetName();
		}

		$oProfilesSet = $oUser->Get('profile_list');

		$aFoundProfileNames=[];
		while ($oProfile = $oProfilesSet->Fetch())
		{
			$aFoundProfileNames[]=$aAllProfilNamesById[$oProfile->Get('profileid')];
		}
		sort($aExpectedProfiles);
		sort($aFoundProfileNames);
		$this->assertEquals($aExpectedProfiles, $aFoundProfileNames);
	}

	protected function CallProfileSynchronizationAndValidateProfilesAttachedAfterwhile(Profile $oUserProfile, $aExpectedProfile=['Portal user']) {
		$sEmail = $this->sUniqId."@test.fr";
		$oUser = $this->CreateExternalUserWithProfilesAndAllowedOrgs($sEmail, ['Service Desk Agent']);

		$aProviderConf = \Combodo\iTop\HybridAuth\Config::GetProviderConf($this->sLoginMode);
		ProvisioningService::GetInstance()->SynchronizeProfiles($this->sLoginMode, $sEmail , $oUser, $oUserProfile, $aProviderConf, "");
		$oUser->DBWrite();
		$this->assertUserProfiles($oUser, $aExpectedProfile);
	}

	protected function CallAllowedOrgSynchronizationAndValidateAfterwhile(Profile $oUserProfile, $aExpectedOrgNames=[], $sPersonOrgId='-1') {
		$sEmail = $this->sUniqId."@test.fr";
		$oUser = $this->CreateExternalUserWithProfilesAndAllowedOrgs($sEmail, ['Service Desk Agent']);

		$aProviderConf = \Combodo\iTop\HybridAuth\Config::GetProviderConf($this->sLoginMode);
		ProvisioningService::GetInstance()->SynchronizeAllowedOrgs($this->sLoginMode, $sEmail , $oUser, $oUserProfile, $aProviderConf, "");
		$oUser->DBWrite();
		$this->assertAllowedOrg($oUser, $aExpectedOrgNames);
	}

	protected function assertAllowedOrg(UserExternal $oUser, $aExpectedAllowedOrgs)
	{
		$oOrgSet = $oUser->Get('allowed_org_list');

		$aFoundOrgNames=[];
		while ($oOrg = $oOrgSet->Fetch())
		{
			$iOrgId = $oOrg->Get('allowed_org_id');
			$oOrg = MetaModel::GetObject(\Organization::class, $iOrgId);
			$aFoundOrgNames[]= $oOrg->Get('name');
		}

		sort($aFoundOrgNames);
		sort($aExpectedAllowedOrgs);
		$this->assertEquals($aExpectedAllowedOrgs, $aFoundOrgNames);
	}
}


