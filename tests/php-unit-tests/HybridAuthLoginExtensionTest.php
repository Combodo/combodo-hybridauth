<?php

namespace Combodo\iTop\HybridAuth;
use Combodo\iTop\Application\Helper\Session;
use Combodo\iTop\HybridAuth\Service\HybridauthService;
use Hybridauth\User\Profile;
use Combodo\iTop\Test\UnitTest\ItopDataTestCase;
use Hybridauth\Adapter\AdapterInterface;
use MetaModel;
use utils;
use \LoginWebPage;
use UserExternal;
use Person;

class HybridAuthLoginExtensionTest  extends ItopDataTestCase {
	protected $sConfigTmpBackupFile;
	protected $sLogin;
	protected $sEmail;
	protected $oUser;

	protected function setUp(): void
	{
		parent::setUp();

		$this->RequireOnceItopFile('sources/Application/Helper/Session.php');
		$this->RequireOnceItopFile('env-production/combodo-hybridauth/vendor/autoload.php');

		$sConfigPath = MetaModel::GetConfig()->GetLoadedFile();

		clearstatcache();
		echo sprintf("rights via ls on %s:\n %s \n", $sConfigPath, exec("ls -al $sConfigPath"));
		$sFilePermOutput = substr(sprintf('%o', fileperms('/etc/passwd')), -4);
		echo sprintf("rights via fileperms on %s:\n %s \n", $sConfigPath, $sFilePermOutput);

		$this->sConfigTmpBackupFile = tempnam(sys_get_temp_dir(), "config_");
		MetaModel::GetConfig()->WriteToFile($this->sConfigTmpBackupFile);

		$this->CreateTestOrganization();

		$oProfile = MetaModel::GetObjectFromOQL("SELECT URP_Profiles WHERE name = :name",
			array('name' => 'Configuration Manager'), true);
		$this->sLogin = "SSOTest" . uniqid() . "@youpi.fr";
		$this->sEmail = $this->sLogin;

		/** @var Person $oPerson */
		$oPerson = $this->createObject('Person', array(
			'name' => $this->sLogin,
			'first_name' => $this->sLogin,
			'email' => $this->sEmail,
			'org_id' => $this->getTestOrgId(),
		));

		$oUserProfile = new \URP_UserProfile();
		$oUserProfile->Set('profileid', $oProfile->GetKey());
		$oUserProfile->Set('reason', 'UNIT Tests');
		$oSet = \DBObjectSet::FromObject($oUserProfile);
		/** @var \UserExternal $oUser */
		$this->oUser = $this->createObject(UserExternal::class, array(
			'login' => $this->sLogin,
			'contactid' => $oPerson->GetKey(),
			'language' => 'EN US',
			'profile_list' => $oSet,
		));

		$sAppRoot = utils::GetAbsoluteUrlAppRoot();
		if (preg_match('/(.*):\/\/(.*)/', $sAppRoot, $aMatches)){
			$_SERVER['REQUEST_SCHEME'] = $aMatches[1];
			$_SERVER['HTTP_HOST'] = $aMatches[2];
		} else {
			$_SERVER['REQUEST_SCHEME'] = $sAppRoot;
			$_SERVER['HTTP_HOST'] = $sAppRoot;
		}
		$_SERVER['REQUEST_URI'] = '/fake';
		$_SESSION = [];
	}

	protected function tearDown(): void {
		parent::tearDown();

		if (! is_null($this->sConfigTmpBackupFile) && is_file($this->sConfigTmpBackupFile)){
			//put config back
			$sConfigPath = MetaModel::GetConfig()->GetLoadedFile();
			@chmod($sConfigPath, 0770);
			$oConfig = new \Config($this->sConfigTmpBackupFile);
			$oConfig->WriteToFile($sConfigPath);
			@chmod($sConfigPath, 0440);
		}
	}

	protected function InitLoginMode($sLoginMode){
		$aAllowedLoginTypes = MetaModel::GetConfig()->GetAllowedLoginTypes();
		if (! in_array($sLoginMode, $aAllowedLoginTypes)){
			$aAllowedLoginTypes[] = $sLoginMode;
			MetaModel::GetConfig()->SetAllowedLoginTypes($aAllowedLoginTypes);
			MetaModel::GetConfig()->WriteToFile();
		}
	}

	public function LoginProvider(){
		return [
			//'wrong AppRoot URL requested' => [ 'sUri' => null ],
			'correct URL with ?' => [ 'sUri' => '/pages/UI.php?c[menu]=WelcomeMenuPage' ],
			//'correct URL without ?' => [ 'sUri' => '/pages/audit.php' ],
		];
	}

	/**
	 * @dataProvider LoginProvider
	 */
	public function testLogin_RedirectToServiceProvider(?string $sUri){
		if (is_null($sUri)){
			$_SERVER['REQUEST_SCHEME'] = uniqid();

		} else {
			$_SERVER['REQUEST_URI'] = $sUri;
		}

		$sSsoMode = 'Google';
		error_reporting(E_ERROR);

		$aServiceProviderConf = ['id' => 'ID', 'secret' => 'SECRET', 'enabled' => true ];
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers',
			[ $sSsoMode => $aServiceProviderConf ]);
		$sLoginMode = "hybridauth-".$sSsoMode;
		$this->InitLoginMode($sLoginMode);

		$oAdapterInterface = $this->createMock(AdapterInterface::class);

		$oUserProfile = new Profile();
		$oUserProfile->email = $this->sEmail;

		$oAdapterInterface->expects($this->exactly(0))
			->method('getUserProfile')
			->willReturnCallback($oUserProfile);

		$oHybridauthService = $this->createMock(HybridauthService::class);
		HybridAuthLoginExtension::SetHybridauthService($oHybridauthService);

		//$sExceptionMsg = "Redirection to ServiceProvider trigger $sSsoMode";
		$oHybridauthService->expects($this->exactly(1))
			->method('authenticate')
			->with($sSsoMode)
			->willReturnCallback(function () {
				Session::Set('auth_user', $this->sEmail);
				Session::Unset('login_will_redirect');
				return null;
			});

		$_REQUEST['login_mode'] = $sLoginMode;

		var_dump(
			[
				'REQUEST_SCHEME' => $_SERVER['REQUEST_SCHEME'],
				'HTTP_HOST' => $_SERVER['HTTP_HOST'],
				'REQUEST_URI' => $_SERVER['REQUEST_URI'],
				'login_mode' => $_REQUEST['login_mode']
			]
		);

		$iRet = LoginWebPage::DoLogin(false, false, LoginWebPage::EXIT_PROMPT);
		/*$this->assertEquals(LoginWebPage::EXIT_CODE_OK, $iRet);

		if (is_null($sUri)){
			$this->assertEquals(utils::GetAbsoluteUrlAppRoot() .'pages/UI.php' , Session::Get('login_original_page'));
		} else {
			$this->assertEquals(utils::GetAbsoluteUrlAppRoot() . $sUri, Session::Get('login_original_page'));
		}*/

		$this->assertEquals($this->sEmail, \UserRights::GetUser());
	}

}
