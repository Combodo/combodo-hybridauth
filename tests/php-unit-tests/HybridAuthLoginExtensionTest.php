<?php

namespace Combodo\iTop\HybridAuth\Test;

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
	protected $sEmail;
	protected $sProvisionedUserPersonEmail;
	protected $oUser;
	protected $oOrg;
	protected $sSsoMode;
	protected $oAdapterInterface;
	protected $oHybridauthService;

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

		$sAppRoot = utils::GetAbsoluteUrlAppRoot();
		if (preg_match('/(.*):\/\/(.*)/', $sAppRoot, $aMatches)){
			$_SERVER['REQUEST_SCHEME'] = $aMatches[1];
			$_SERVER['HTTP_HOST'] = $aMatches[2];
		} else {
			$_SERVER['REQUEST_SCHEME'] = $sAppRoot;
			$_SERVER['HTTP_HOST'] = $sAppRoot;
		}
		$_SERVER['REQUEST_URI'] = '/fake';

		$this->sSsoMode = 'Google';

		$aServiceProviderConf = ['id' => 'ID', 'secret' => 'SECRET', 'enabled' => true ];
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers',
			[ $this->sSsoMode => $aServiceProviderConf ]);
		$sLoginMode = "hybridauth-".$this->sSsoMode;
		$this->InitLoginMode($sLoginMode);
		$_REQUEST['login_mode'] = $sLoginMode;

		//no provisioning
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_user', false);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', false);

		$this->oAdapterInterface = $this->createMock(AdapterInterface::class);
		$this->oHybridauthService = $this->createMock(HybridauthService::class);

		HybridAuthLoginExtension::SetHybridauthService($this->oHybridauthService);
	}

	protected function tearDown(): void {
		if (! is_null($this->sProvisionedUserPersonEmail)) {
			$aCreatedObjects = $this->GetNonPublicProperty($this, 'aCreatedObjects');
			$aCreatedObjects[] = LoginWebPage::FindPerson($this->sProvisionedUserPersonEmail);
			$aCreatedObjects[] = LoginWebPage::FindUser($this->sProvisionedUserPersonEmail, false);
		}

		parent::tearDown();

		HybridAuthLoginExtension::SetHybridauthService(null);
		if (! is_null($this->sConfigTmpBackupFile) && is_file($this->sConfigTmpBackupFile)){
			//put config back
			$sConfigPath = MetaModel::GetConfig()->GetLoadedFile();
			@chmod($sConfigPath, 0770);
			$oConfig = new \Config($this->sConfigTmpBackupFile);
			$oConfig->WriteToFile($sConfigPath);
			@chmod($sConfigPath, 0440);
		}

		$_SESSION = [];
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

	public function testLogin_RedirectToServiceProvider_NoUserProvisioning_OK() {
		$this->oAdapterInterface->expects($this->never())
			->method('getUserProfile');

		$this->oAdapterInterface->expects($this->exactly(1))
			->method('disconnect');

		//twice: 1 for login + 1 for logout
		$count=0;
		$oAdapterInterface = $this->oAdapterInterface;
		$this->oHybridauthService->expects($this->exactly(2))
			->method('authenticate')
			->with($this->sSsoMode)
			->willReturnCallback(function () use ($oAdapterInterface, &$count) {
				if ($count === 0) {
					$count++;
					//OnReadCredentials: redirection to SP
					//simulate callback to landing.php
					Session::Set('auth_user', $this->sEmail);
					Session::Set('login_hybridauth', 'connected');
					Session::Unset('login_will_redirect');
				}
				$count++;
				return $oAdapterInterface;
			});

		LoginWebPage::DoLogin(false, false, LoginWebPage::EXIT_PROMPT);
		$this->assertEquals($this->sEmail, \UserRights::GetUser());

		$aPluginList = LoginWebPage::GetLoginPluginList('iLogoutExtension');

		/** @var iLogoutExtension $oLogoutExtension */
		foreach ($aPluginList as $oLogoutExtension)
		{
			var_dump(get_class($oLogoutExtension));
			$oLogoutExtension->LogoutAction();
		}
	}

	public function testLogin_RedirectToServiceProvider_WithUserProvisioning_UnknownUser() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_user', true);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', true);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $this->oOrg->Get('name'));
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Portal User');

		$oUserProfile = new Profile();
		$oUserProfile->email = $this->sEmail;
		$oUserProfile->firstName = "firstName";
		$oUserProfile->lastName = "lastName";
		$oUserProfile->phone = "965478";
		$this->oAdapterInterface->expects($this->exactly(1))
			->method('getUserProfile')
			->willReturn($oUserProfile);

		$this->oAdapterInterface->expects($this->never())
			->method('disconnect');

		$this->sProvisionedUserPersonEmail = 'unknown_' . uniqid() . '@titi.fr';
		//twice: 1 for login + 1 for landing.php call back + 1 for provisioning
		$count=0;
		$oAdapterInterface = $this->oAdapterInterface;
		$this->oHybridauthService->expects($this->exactly(2))
			->method('authenticate')
			->with($this->sSsoMode)
			->willReturnCallback(function () use ($oAdapterInterface, &$count) {
				if ($count === 0) {
					$count++;
					//OnReadCredentials: redirection to SP
					//simulate callback to landing.php
					Session::Set('auth_user', $this->sProvisionedUserPersonEmail);
					Session::Set('login_hybridauth', 'connected');
					Session::Unset('login_will_redirect');
				}
				$count++;
				return $oAdapterInterface;
			});

		LoginWebPage::DoLogin(false, false, LoginWebPage::EXIT_PROMPT);
	}

	public function testLogin_RedirectToServiceProvider_NoUserProvisioning_UnknownUser() {
		$this->oAdapterInterface->expects($this->never())
			->method('getUserProfile');

		$this->oAdapterInterface->expects($this->exactly(1))
			->method('disconnect');

		//twice: 1 for login
		$count=0;
		$oAdapterInterface = $this->oAdapterInterface;
		$this->oHybridauthService->expects($this->exactly(2))
			->method('authenticate')
			->with($this->sSsoMode)
			->willReturnCallback(function () use ($oAdapterInterface, &$count) {
				if ($count === 0) {
					$count++;
					//OnReadCredentials: redirection to SP
					//simulate callback to landing.php
					Session::Set('auth_user', 'unknown_' . uniqid() . '@titi.fr');
					Session::Set('login_hybridauth', 'connected');
					Session::Unset('login_will_redirect');
				}
				$count++;
				return $oAdapterInterface;
			});

		LoginWebPage::DoLogin(false, false, LoginWebPage::EXIT_PROMPT);
	}

	public function testExit() {
		$this->oAdapterInterface->expects($this->never())
			->method('getUserProfile');

		$this->oAdapterInterface->expects($this->exactly(1))
			->method('disconnect');

		exit();
	}
}
