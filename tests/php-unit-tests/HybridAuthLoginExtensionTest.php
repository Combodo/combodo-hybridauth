<?php

namespace Combodo\iTop\HybridAuth\Test;

use Combodo\iTop\HybridAuth\Config;
use Combodo\iTop\HybridAuth\Test\Provider\ServiceProviderMock;
use Combodo\iTop\Test\UnitTest\ItopDataTestCase;
use MetaModel;
use utils;
use \LoginWebPage;
use UserExternal;
use Person;


/**
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @backupGlobals disabled
 *
 */
class HybridAuthLoginExtensionTest  extends ItopDataTestCase {
	//iTop called from outside
	//users need to be persisted in DB
	const USE_TRANSACTION = false;

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
		$this->RequireOnceUnitTestFile('Provider/ServiceProviderMock.php');

		$sConfigPath = MetaModel::GetConfig()->GetLoadedFile();

		clearstatcache();
		echo sprintf("rights via ls on %s:\n %s \n", $sConfigPath, exec("ls -al $sConfigPath"));
		$sFilePermOutput = substr(sprintf('%o', fileperms('/etc/passwd')), -4);
		echo sprintf("rights via fileperms on %s:\n %s \n", $sConfigPath, $sFilePermOutput);

		$this->sConfigTmpBackupFile = tempnam(sys_get_temp_dir(), "config_");
		MetaModel::GetConfig()->WriteToFile($this->sConfigTmpBackupFile);

		$this->oiTopConfig = new \Config($sConfigPath);

		$sPath = __DIR__ . '/Provider/ServiceProviderMock.php';
		$this->oiTopConfig->SetModuleSetting('combodo-hybridauth', 'oauth_test_class_path', $sPath);

		$_SESSION = [];
		$this->sUniqId = "OpenID" . uniqid();
		$this->oOrg = $this->CreateOrganization($this->sUniqId);

		$oProfile = MetaModel::GetObjectFromOQL("SELECT URP_Profiles WHERE name = :name",
			array('name' => 'Configuration Manager'), true);
		$this->sEmail = $this->sUniqId . "@test.fr";

		/** @var Person $oPerson */
		$oPerson = $this->createObject('Person', array(
			'name' => $this->sEmail,
			'first_name' => $this->sEmail,
			'email' => $this->sEmail,
			'org_id' => $this->oOrg->GetKey(),
		));

		$oUserProfile = new \URP_UserProfile();
		$oUserProfile->Set('profileid', $oProfile->GetKey());
		$oUserProfile->Set('reason', 'UNIT Tests');
		$oSet = \DBObjectSet::FromObject($oUserProfile);
		/** @var \UserExternal $oUser */
		$this->oUser = $this->createObject(UserExternal::class, array(
			'login' => $this->sEmail,
			'contactid' => $oPerson->GetKey(),
			'language' => 'EN US',
			'profile_list' => $oSet,
		));

		$sSsoMode = 'ServiceProviderMock';

		$aServiceProviderConf = array_merge($this->oiTopConfig->GetModuleSetting('combodo-hybridauth', 'providers'),
			[ "$sSsoMode" => [
				'adapter' => 'Combodo\iTop\HybridAuth\Test\Provider\ServiceProviderMock',
				'keys' => [
					'id' => 'ID',
					'secret' => 'SECRET',
				],
				'enabled' => true,
			]
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

	private function SaveItopConfFile(){
		@chmod($this->oiTopConfig->GetLoadedFile(), 0770);
		$this->oiTopConfig->WriteToFile();
		@chmod($this->oiTopConfig->GetLoadedFile(), 0440);
	}

	protected function tearDown(): void {
		if (! is_null($this->sProvisionedUserPersonEmail)) {
			try{
				$oExpectedUser = MetaModel::GetObjectByColumn("UserExternal", "login", $this->sProvisionedUserPersonEmail);
				$oExpectedUser->DBDelete();
				$oExpectedPerson = MetaModel::GetObjectByColumn("Person", "email", $this->sProvisionedUserPersonEmail);
				$oExpectedPerson->DBDelete();
			} catch(\Exception $e){
				IssueLog($e->getMessage());
			}
		}

		parent::tearDown();

		if (! is_null($this->sConfigTmpBackupFile) && is_file($this->sConfigTmpBackupFile)){
			//put config back
			$sConfigPath = $this->oiTopConfig->GetLoadedFile();
			@chmod($sConfigPath, 0770);
			$oConfig = new \Config($this->sConfigTmpBackupFile);
			$oConfig->WriteToFile($sConfigPath);
			@chmod($sConfigPath, 0440);
		}

		if (is_file(ServiceProviderMock::GetFileConfPath())){
			@unlink(ServiceProviderMock::GetFileConfPath());
		}

		$_SESSION = [];
	}

	protected function InitLoginMode($sLoginMode){
		$aAllowedLoginTypes = $this->oiTopConfig->GetAllowedLoginTypes();
		if (! in_array($sLoginMode, $aAllowedLoginTypes)){
			$aAllowedLoginTypes[] = $sLoginMode;
			$this->oiTopConfig->SetAllowedLoginTypes($aAllowedLoginTypes);
		}
	}

	protected function CallItopUrl($sUri, $bXDebugEnabled=false, ?array $aPostFields=null){
		$ch = curl_init();
		if ($bXDebugEnabled){
			curl_setopt($ch, CURLOPT_COOKIE, "XDEBUG_SESSION=phpstorm");
		}

		$sUrl = $this->oiTopConfig->Get('app_root_url') . "/$sUri";
		curl_setopt($ch, CURLOPT_URL, $sUrl);
		curl_setopt($ch, CURLOPT_POST, 1);// set post data to true
		if (! is_array($aPostFields)){
			$aPostFields = ['login_mode' => $this->sLoginMode];
			var_dump($aPostFields);
		}
		curl_setopt($ch, CURLOPT_POSTFIELDS, $aPostFields);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$sOutput = curl_exec($ch);
		//\IssueLog::Info("$sUrl error code:", null, ['error' => curl_error($ch)]);
		curl_close ($ch);

		return $sOutput;
	}

	public function test_SSOConnectedAlready_NoiTopUserProvisioning_OK() {
		$aData = ['profile_email' => $this->sEmail];
		file_put_contents(ServiceProviderMock::GetFileConfPath(), json_encode($aData));

		$sOutput = $this->CallItopUrl("/pages/UI.php");
		$this->assertFalse(strpos($sOutput, "login-body"), "user logged in => no login page:" . $sOutput);
		$this->assertTrue(false !== strpos($sOutput, $this->sEmail), "user logged (and email) in => his login . " . $this->sEmail . " . should appear in the welcome page :" . $sOutput);
	}

	public function test_SSOConnectedAlready_NoiTopUserProvisioning_UnknownUser() {
		$aData = ['profile_email' => 'unknown_' . $this->sUniqId . '@titi.fr'];
		file_put_contents(ServiceProviderMock::GetFileConfPath(), json_encode($aData));

		$sOutput = $this->CallItopUrl("/pages/UI.php");
		$this->assertTrue(false !== strpos($sOutput, "login-body"), "user logged in => login page:" . $sOutput);
		$this->assertFalse(strpos($sOutput, $this->sEmail), "user not logged in => name should not appear . " . $this->sEmail . " . should appear in the welcome page :" . $sOutput);
	}

	public function ProfileProvider(){
		return [
			'portal user' => [ 'sProfile' => "Portal user", 'bPortalPage' => true ],
			'config manager' => [ 'sProfile' => "Configuration Manager"],
		];
	}

	/**
	 * @dataProvider ProfileProvider
	 */
	public function test_SSOConnectedAlready_WithiTopUserProvisioning_OK($sProfile, $bPortalPage = false) {
		$this->oiTopConfig->SetModuleSetting('combodo-hybridauth', 'synchronize_user', true);
		$this->oiTopConfig->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', true);
		$this->oiTopConfig->SetModuleSetting('combodo-hybridauth', 'default_organization', $this->oOrg->Get('name'));
		$this->oiTopConfig->SetModuleSetting('combodo-hybridauth', 'default_profile', $sProfile);

		$this->SaveItopConfFile();

		$this->sProvisionedUserPersonEmail = 'usercontacttoprovision_' .$this->sUniqId. '@test.fr';
		$sFirstName = $this->sUniqId . "_firstName";
		$sLatName = $this->sUniqId . "_lastName";
		$sPhone = "123456789";
		$aData = [
			'profile_email' => $this->sProvisionedUserPersonEmail,
			'profile_firstName' => $sFirstName,
			'profile_lastName' => $sLatName,
			'profile_phone' => $sPhone,
		];
		file_put_contents(ServiceProviderMock::GetFileConfPath(), json_encode($aData));
		$sOutput = $this->CallItopUrl("/pages/UI.php");

		if (! $bPortalPage) {
			$this->assertFalse(strpos($sOutput, "login-body"), "user logged in => no login page:".$sOutput);
			$this->assertTrue(false !== strpos($sOutput, $sFirstName),
				"user logged in => his firstname . ".$sFirstName." . should appear in the welcome page :".$sOutput);
			$this->assertTrue(false !== strpos($sOutput, $sLatName),
				"user logged in => his lastname . ".$sLatName." . should appear in the welcome page :".$sOutput);
		}

		$oExpectedPerson = MetaModel::GetObjectByColumn("Person", "email", $this->sProvisionedUserPersonEmail);
		$this->assertNotNull($oExpectedPerson);
		$this->assertEquals($sFirstName, $oExpectedPerson->Get('first_name'));
		$this->assertEquals($sPhone, $oExpectedPerson->Get('phone'));
		$this->assertEquals($sLatName, $oExpectedPerson->Get('name'));
		$this->assertEquals($this->oOrg->GetKey(), $oExpectedPerson->Get('org_id'));

		$oExpectedUser = MetaModel::GetObjectByColumn("UserExternal", "login", $this->sProvisionedUserPersonEmail);
		$this->assertNotNull($oExpectedUser);
		$this->assertEquals($oExpectedPerson->GetKey(), $oExpectedUser->Get('contactid'));
		$oProfilesSet = $oExpectedUser->Get('profile_list');
		$this->assertEquals(1, $oProfilesSet->Count());
		while ($oProfile = $oProfilesSet->Fetch()) {
			$this->assertEquals($sProfile, $oProfile->Get('profile'));
		}
	}

	public function testLandingPage(){
		$aData = ['profile_email' => $this->sEmail];
		file_put_contents(ServiceProviderMock::GetFileConfPath(), json_encode($aData));
		$sOutput = $this->CallItopUrl("/env-" . $this->GetTestEnvironment() . "/combodo-hybridauth/landing.php?login_mode=" . $this->sLoginMode, false, []);
		$this->assertFalse(strpos($sOutput, "login-body"), "user logged in => no login page:" . $sOutput);
		$this->assertFalse(strpos($sOutput, "An error occurred"), "An error occurred should NOT appear in output: " . $this->sEmail . " . should appear in the welcome page :" . $sOutput);
	}

	public function testLandingPageFailureNoLoginModeProvided(){
		$aData = ['profile_email' => $this->sEmail];
		file_put_contents(ServiceProviderMock::GetFileConfPath(), json_encode($aData));
		$sOutput = $this->CallItopUrl("/env-" . $this->GetTestEnvironment() . "/combodo-hybridauth/landing.php", false, []);
		$this->assertTrue(false !== strpos($sOutput, "login-body"), "user logged in => login page:" . $sOutput);
		$this->assertTrue(false !== strpos($sOutput, "No login_mode specified by service provider"), "An error occurred should appear in output: " . $this->sEmail . " . should appear in the welcome page :" . $sOutput);
	}

	public function testLandingPageFailureInvalidSSOLoginMode(){
		$aData = ['profile_email' => $this->sEmail];
		file_put_contents(ServiceProviderMock::GetFileConfPath(), json_encode($aData));
		$sOutput = $this->CallItopUrl("/env-" . $this->GetTestEnvironment() . "/combodo-hybridauth/landing.php?login_mode=hybridauth-badlyconfigured", false, []);
		$this->assertTrue(false !== strpos($sOutput, "login-body"), "user logged in => login page:" . $sOutput);
	}
}
