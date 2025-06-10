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
use DBObjectSet;
use MetaModel;
use URP_UserProfile;
use UserExternal;

class ProvisioningServiceTest extends ItopDataTestCase
{
	protected $sConfigTmpBackupFile;
	protected $sEmail;
	protected $sProvisionedUserPersonEmail;
	protected $oUser;
	protected $oOrg;
	protected $sLoginMode;
	protected $oiTopConfig;
	protected $sUniqId;

	protected function setUp(): void
	{
		parent::setUp();

		$this->RequireOnceItopFile('env-production/combodo-hybridauth/vendor/autoload.php');
		$this->RequireOnceItopFile('env-production/combodo-oauth2-client/vendor/autoload.php');
		$this->RequireOnceUnitTestFile('Provider/ServiceProviderMock.php');

		$sConfigPath = MetaModel::GetConfig()->GetLoadedFile();

		clearstatcache();
		echo sprintf("rights via ls on %s:\n %s \n", $sConfigPath, exec("ls -al $sConfigPath"));
		$sFilePermOutput = substr(sprintf('%o', fileperms('/etc/passwd')), -4);
		echo sprintf("rights via fileperms on %s:\n %s \n", $sConfigPath, $sFilePermOutput);

		$this->sConfigTmpBackupFile = tempnam(sys_get_temp_dir(), "config_");
		MetaModel::GetConfig()->WriteToFile($this->sConfigTmpBackupFile);

		$this->oiTopConfig = new Config($sConfigPath);

		$sPath = __DIR__.'/Provider/ServiceProviderMock.php';
		$this->oiTopConfig->SetModuleSetting('combodo-hybridauth', 'oauth_test_class_path', $sPath);

		$_SESSION = [];
		$this->sUniqId = "OpenID".uniqid();
		$this->oOrg = $this->CreateOrganization($this->sUniqId);

		$oProfile = MetaModel::GetObjectFromOQL("SELECT URP_Profiles WHERE name = :name",
			['name' => 'Configuration Manager'], true);
		$this->sEmail = $this->sUniqId."@test.fr";

		/** @var Person $oPerson */
		$oPerson = $this->createObject('Person', [
			'name' => $this->sEmail,
			'first_name' => $this->sEmail,
			'email' => $this->sEmail,
			'org_id' => $this->oOrg->GetKey(),
		]);

		$oUserProfile = new URP_UserProfile();
		$oUserProfile->Set('profileid', $oProfile->GetKey());
		$oUserProfile->Set('reason', 'UNIT Tests');
		$oSet = DBObjectSet::FromObject($oUserProfile);
		/** @var \UserExternal $oUser */
		$this->oUser = $this->createObject(UserExternal::class, [
			'login' => $this->sEmail,
			'contactid' => $oPerson->GetKey(),
			'language' => 'EN US',
			'profile_list' => $oSet,
		]);

		$sSsoMode = 'ServiceProviderMock';

		$aCurrentModuleSettings = $this->oiTopConfig->GetModuleSetting('combodo-hybridauth', 'providers', []);
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

		$this->oiTopConfig->SetModuleSetting('combodo-hybridauth', 'providers', $aServiceProviderConf);
		$this->sLoginMode = "hybridauth-".$sSsoMode;
		$this->InitLoginMode($this->sLoginMode);

		//no provisioning
		$this->oiTopConfig->SetModuleSetting('combodo-hybridauth', 'synchronize_user', false);
		$this->oiTopConfig->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', false);
		$this->SaveItopConfFile();

	}

	protected function InitLoginMode($sLoginMode)
	{
		$aAllowedLoginTypes = $this->oiTopConfig->GetAllowedLoginTypes();
		if (!in_array($sLoginMode, $aAllowedLoginTypes)) {
			$aAllowedLoginTypes[] = $sLoginMode;
			$this->oiTopConfig->SetAllowedLoginTypes($aAllowedLoginTypes);
		}
	}

	protected function tearDown(): void
	{
		parent::tearDown();

		if (!is_null($this->sConfigTmpBackupFile) && is_file($this->sConfigTmpBackupFile)) {
			//put config back
			$sConfigPath = $this->oiTopConfig->GetLoadedFile();
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

	private function SaveItopConfFile()
	{
		@chmod($this->oiTopConfig->GetLoadedFile(), 0770);
		$this->oiTopConfig->WriteToFile();
		@chmod($this->oiTopConfig->GetLoadedFile(), 0440);
	}

	public function testDoPersonProvisioning_PersonAlreadyExists(){}
	public function testDoPersonProvisioning_SynchroDisabled(){}
	public function testDoPersonProvisioning_CreationOK(){}

	public function GetOrganizationForProvisioningProvider()
	{
		$sDefaultOrgName = 'IDP_ORG1'.uniqid();
		$sOrgName2 = 'IDP_ORG2'.uniqid();

		return [
			'no org returned by IdP' => [$sDefaultOrgName, $sOrgName2, null, $sDefaultOrgName],
			'unknown org returned by IdP' => [$sDefaultOrgName, $sOrgName2, "unknown_IDP_Org", $sDefaultOrgName],
			'use IdP org name' => [$sDefaultOrgName, $sOrgName2, $sOrgName2, $sOrgName2],
		];
	}

	/**
	 * @dataProvider GetOrganizationForProvisioningProvider
	 */
	public function testGetOrganizationForProvisioning(string $sDefaultOrgName, string $sOrgName2, ?string $sIdpOrgName, string $sExpectedOrgReturned)
	{
		$this->oiTopConfig->SetModuleSetting('combodo-hybridauth', 'synchronize_user', true);
		$this->oiTopConfig->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', true);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName);

		$this->oiTopConfig->SetModuleSetting('combodo-hybridauth', 'default_profile', "Configuration Manager");

		$this->SaveItopConfFile();
		$this->CreateOrganization($sDefaultOrgName);
		$this->CreateOrganization($sOrgName2);


		$sOrgName = $this->InvokeNonPublicMethod(ProvisioningService::class, 'GetOrganizationForProvisioning', ProvisioningService::GetInstance(), [$this->sLoginMode, $sIdpOrgName]);
		$this->assertEquals($sExpectedOrgReturned, $sOrgName);
	}

	public function testDoUserProvisioning_UserCreationOK() { }
	public function testDoUserProvisioning_UserUpdateOK() { }

	public function testSynchronizeProfiles() { }
}
