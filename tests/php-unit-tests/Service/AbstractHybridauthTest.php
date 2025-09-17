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
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'refresh_existing_users', false);

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
		}

		if (is_file(ServiceProviderMock::GetFileConfPath())) {
			@unlink(ServiceProviderMock::GetFileConfPath());
		}

		$_SESSION = [];
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

	protected function InitializeGroupsToProfile(string $sLoginMode, $groupToProfilesValue) {
		$aProviderConf = \Combodo\iTop\HybridAuth\Config::GetProviderConf($sLoginMode);
		$aProviderConf['groups_to_profiles'] = $groupToProfilesValue;

		$aProviders = \Combodo\iTop\HybridAuth\Config::Get('providers');
		$aProviders[str_replace("hybridauth-", "", $sLoginMode)]=$aProviderConf;

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', $aProviders);
	}

	protected function CreateExternalUserWithProfiles(string $sEmail, array $aProfileNames) : \UserExternal
	{
		$oProfilesSet = new \ormLinkSet(\UserExternal::class, 'profile_list', \DBObjectSet::FromScratch(\URP_UserProfile::class));
		foreach ($aProfileNames as $sProfileName){
			$oLink = MetaModel::NewObject('URP_UserProfile', array('profileid' => self::$aURP_Profiles[$sProfileName], 'reason' => 'UNIT Tests'));
			$oProfilesSet->AddItem($oLink);
		}

		/** @var \UserExternal $oUser */
		$oUser = $this->createObject(UserExternal::class, [
			'login'        => $sEmail,
			'profile_list' => $oProfilesSet,
			'language' => 'FR FR',
		]);

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

	protected function ValidateSynchronizeProfiles_FallbackToDefaultProfileUse(Profile $oUserProfile, $aExpectedProfile=['Portal user']) {
		$sEmail = $this->sUniqId."@test.fr";
		/** @var UserExternal $oUser */
		$oUser = MetaModel::NewObject('UserExternal');
		$oUser->Set('login', $sEmail);

		ProvisioningService::GetInstance()->SynchronizeProfiles($this->sLoginMode, $sEmail , $oUser, $oUserProfile, "");
		$this->assertUserProfiles($oUser, $aExpectedProfile);
	}
}


