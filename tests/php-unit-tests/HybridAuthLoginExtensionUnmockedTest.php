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

class HybridAuthLoginExtensionUnmockedTest  extends ItopDataTestCase {
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

	protected function setUp(): void
	{
		parent::setUp();

		$this->RequireOnceItopFile('sources/Application/Helper/Session.php');
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
		$_SESSION = [];
		$this->oOrg = $this->CreateTestOrganization();

		$oProfile = MetaModel::GetObjectFromOQL("SELECT URP_Profiles WHERE name = :name",
			array('name' => 'Configuration Manager'), true);
		$this->sEmail = "SSOTest" . uniqid() . "@youpi.fr";

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

		@chmod($this->oiTopConfig->GetLoadedFile(), 0770);
		$this->oiTopConfig->WriteToFile();
		@chmod($this->oiTopConfig->GetLoadedFile(), 0440);
	}

	protected function tearDown(): void {
		if (! is_null($this->sProvisionedUserPersonEmail)) {
			$aCreatedObjects = $this->GetNonPublicProperty($this, 'aCreatedObjects');
			$aCreatedObjects[] = LoginWebPage::FindPerson($this->sProvisionedUserPersonEmail);
			$aCreatedObjects[] = LoginWebPage::FindUser($this->sProvisionedUserPersonEmail, false);
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

	public function LoginProvider(){
		return [
			//'wrong AppRoot URL requested' => [ 'sUri' => null ],
			//'correct URL with ?' => [ 'sUri' => '/pages/UI.php?c[menu]=WelcomeMenuPage' ],
			'iTop main page' => [ 'sUri' => '/pages/UI.php' ],
			//'correct URL without ?' => [ 'sUri' => '/pages/audit.php' ],
		];
	}

	protected function CallItopUrl(array $aAdditionalFields, $sUri){
		$ch = curl_init();
		//curl_setopt($ch, CURLOPT_COOKIE, "XDEBUG_SESSION=phpstorm");

		$sUrl = $this->oiTopConfig->Get('app_root_url') . "/$sUri";
		curl_setopt($ch, CURLOPT_URL, $sUrl);
		curl_setopt($ch, CURLOPT_POST, 1);// set post data to true
		$aPostFields = array_merge($aAdditionalFields, ['login_mode' => $this->sLoginMode]);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $aPostFields);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$sOutput = curl_exec($ch);
		curl_close ($ch);

		return $sOutput;
	}

	/**
	 * @dataProvider LoginProvider
	 */
	public function testLogin_RedirectToServiceProvider_NoUserProvisioning_OK($sUri) {
		$aData = ['profile_email' => $this->sEmail];
		file_put_contents(ServiceProviderMock::GetFileConfPath(), json_encode($aData));
		$sOutput = $this->CallItopUrl([], $sUri);
		$this->assertTrue(false !== strpos($sOutput, $this->sEmail), "user logged in => his login . " . $this->sEmail . " . should appear in the welcome page :" . $sOutput);
	}
}
