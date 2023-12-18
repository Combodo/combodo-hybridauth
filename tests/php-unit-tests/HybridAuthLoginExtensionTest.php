<?php

namespace Combodo\iTop\HybridAuth\Test;

use Combodo\iTop\Application\Helper\Session;
use Combodo\iTop\HybridAuth\HybridAuthLoginExtension;
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
	protected $sEmail;
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
		parent::tearDown();
		HybridAuthLoginExtension::SetHybridauthService(null);

		$_SESSION = [];
	}

	protected function InitLoginMode($sLoginMode){
		$aAllowedLoginTypes = MetaModel::GetConfig()->GetAllowedLoginTypes();
		if (! in_array($sLoginMode, $aAllowedLoginTypes)){
			$aAllowedLoginTypes[] = $sLoginMode;
			MetaModel::GetConfig()->SetAllowedLoginTypes($aAllowedLoginTypes);
		}
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
}
