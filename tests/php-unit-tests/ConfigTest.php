<?php

namespace Combodo\iTop\HybridAuth\Test;

use Combodo\iTop\Application\Helper\Session;
use Combodo\iTop\HybridAuth\Config;
use Combodo\iTop\HybridAuth\Service\HybridauthService;
use Combodo\iTop\Test\UnitTest\ItopDataTestCase;
use MetaModel;
use utils;

class ConfigTest extends ItopDataTestCase{

	public function testGetHybridConfig(){
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', ['ga' => 'bu']);

		$aExpected = [
			'callback' => utils::GetAbsoluteUrlModulesRoot().'combodo-hybridauth/landing.php',
			'providers' => ['ga' => 'bu'],
		];
		$this->assertEquals($aExpected, Config::GetHybridConfig());
	}

	public function GetProvidersProvider(){
		return [
			'no provider' => [
				'aExpected' => [],
				'aProviderConf' => [],
			],
			'one provider wrongly configured' => [
				'aExpected' => ['ga' => false],
				'aProviderConf' => [ 'ga' => []],
			],
			'all provider disabled' => [
				'aExpected' => ['ga' => false, 'bu' => false],
				'aProviderConf' => [
					'ga' => ['enabled' => false],
					'bu' => ['enabled' => false],
				],
			],
			'one provider enabled' => [
				'aExpected' => ['ga' => false, 'bu' => true, 'zo' => false, 'meu' => false],
				'aProviderConf' => [
					'ga' => ['enabled' => false],
					'bu' => ['enabled' => true],
					'zo' => ['enabled' => false],
					'meu' => ['enabled' => false],
				],
			],
			'some provider enabled' => [
				'aExpected' => ['ga' => false, 'bu' => true, 'zo' => false, 'meu' => true],
				'aProviderConf' => [
					'ga' => ['enabled' => false],
					'bu' => ['enabled' => true],
					'zo' => ['enabled' => false],
					'meu' => ['enabled' => true],
				],
			],
		];
	}

	/**
	 * @dataProvider GetProvidersProvider
	 */
	public function testGetProviders(array $aExpected, array $aProviderConf){
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', $aProviderConf);

		$this->assertEquals($aExpected, Config::ListProviders());
	}

	public function IsLoginModeSupportedProvider(){
		return [
			'disabled login mode not starting with hybridauth' => [
				'sLoginMode' => 'disabled-loginmode',
				'bExpected' => false,
			],
			'enabled login mode not starting with hybridauth' => [
				'sLoginMode' => 'enabled-loginmode',
				'bExpected' => false,
			],
			'disabled login mode' => [
				'sLoginMode' => 'hybridauth-disabled-loginmode',
				'bExpected' => false,
				'bThrowException' => true,
			],
			'nominal case: enabled login mode' => [
				'sLoginMode' => 'hybridauth-enabled-loginmode',
				'bExpected' => true,
			],
			'not configured sLoginMode provider starting with hybridauth' => [
				'sLoginMode' => 'hybridauth-unconfigured-provider',
				'bExpected' => false,
				'bThrowException' => true,
			],
			'not allowed sLoginMode provider starting with hybridauth' => [
				'sLoginMode' => 'hybridauth-unallowed-loginmode',
				'bExpected' => false,
				'bThrowException' => false,
				'bLoginModeAllowed' => false,
			],
		];
	}

	/**
	 * @dataProvider IsLoginModeSupportedProvider
	 */
	public function testIsLoginModeSupported(string $sLoginMode, bool $bExpected, bool $bThrowException = false, bool $bLoginModeAllowed = true) {
		$aProviderConf = [
			'disabled-loginmode' => ['enabled' => false ],
			'enabled-loginmode' => ['enabled' => true ],
		];

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', $aProviderConf);

		if ($bLoginModeAllowed) {
			$aAllowedLoginTypes = MetaModel::GetConfig()->GetAllowedLoginTypes();
			if (!in_array($sLoginMode, $aAllowedLoginTypes)) {
				$aAllowedLoginTypes[] = $sLoginMode;
				MetaModel::GetConfig()->SetAllowedLoginTypes($aAllowedLoginTypes);
			}
		}

		if ($bThrowException){
			$this->expectException(\Exception::class);
			$this->expectExceptionMessage("SSO configuration needs to be fixed.");
		}

		$this->assertEquals($bExpected, Config::IsLoginModeSupported($sLoginMode));
	}

	public function testGetProposedSpListWithNoConf(){
		$oHybridauthService = $this->createMock(HybridauthService::class);
		$oHybridauthService->expects($this->once())
			->method('ListProviders')
			->willReturn(['Google', 'MicrosoftGraph', 'etc...']);
		;

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'ui_proposed_providers', null);
		$this->assertEquals(['Google', 'MicrosoftGraph', 'etc...'], Config::GetProposedSpList($oHybridauthService));
	}

	public function testGetProposedSpList(){
		$oHybridauthService = $this->createMock(HybridauthService::class);
		$oHybridauthService->expects($this->never())
			->method('ListProviders');
		;

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'ui_proposed_providers', ['Google']);
		$this->assertEquals(['Google'], Config::GetProposedSpList($oHybridauthService));
	}

	public function testGetProviderConf(){
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers',
			[
				'Google' => [ 'ga' => 'bu'],
			]
		);

		$this->assertEquals([ 'ga' => 'bu'], Config::GetProviderConf('hybridauth-Google'));
		$this->assertEquals(null, Config::GetProviderConf('hybridauth-MS'));
		$this->assertEquals(null, Config::GetProviderConf(null));
	}

	public function UserSynchroEnabled_BackwardCompatibilityProvider(){
		return [
			'disabled' => [
				'bExpectedRes' => false,
				'synchronize_user' => false,
				'synchronize_contact' => false,
			],
			'synchronize_user' => [
				'bExpectedRes' => true,
				'synchronize_user' => true,
				'synchronize_contact' => false,
			],
			'synchronize_contact' => [
				'bExpectedRes' => true,
				'synchronize_user' => false,
				'synchronize_contact' => true,
			],
			'both' => [
				'bExpectedRes' => true,
				'synchronize_user' => true,
				'synchronize_contact' => true,
			],
		];
	}

	/**
	 * @dataProvider UserSynchroEnabled_BackwardCompatibilityProvider
	 */
	public function testUserSynchroEnabled_BackwardCompatibility($bExpectedRes, $bSynchroUser, $bSynchroContact){
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', []);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_user', $bSynchroUser);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', $bSynchroContact);

		$this->assertEquals($bExpectedRes, Config::UserSynchroEnabled('hybridauth-anyprovider'));
	}

	public function UserSynchroEnabledProvider(){
		return [
			'synchronize_user_contact missing in conf' => [
				'bExpectedRes' => false,
				'aConf' => [],
			],
			'disabled' => [
				'bExpectedRes' => false,
				'aConf' => [ 'synchronize_user_contact' => false ],
			],
			'enabled' => [
				'bExpectedRes' => true,
				'aConf' => [ 'synchronize_user_contact' => true ],
			],
		];
	}

	/**
	 * @dataProvider UserSynchroEnabledProvider
	 */
	public function testUserSynchroEnabled($bExpectedRes, $aConf){
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers',
			[
				'Google' => $aConf,
			]
		);

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_user', false);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', false);

		$this->assertEquals($bExpectedRes, Config::UserSynchroEnabled('hybridauth-Google'));
	}

	public function testUserSynchroEnabledNoConf(){
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', []);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_user', false);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', false);

		$this->assertEquals(false, Config::UserSynchroEnabled('hybridauth-anyprovider'));
	}

	public function testGetDefaultOrg(){
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers',
			[
				'Google' => ['default_organization' => 'provider-org'],
				'NoDefaultOrg' => [],
			]
		);

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', 'overall-org');

		$this->assertEquals('provider-org', Config::GetDefaultOrg('hybridauth-Google'));
		$this->assertEquals('overall-org', Config::GetDefaultOrg('hybridauth-NoDefaultOrg'));
		$this->assertEquals('overall-org', Config::GetDefaultOrg('hybridauth-MissingProvider'));
	}

	public function SetHybridConfigProvider() {
		return [
			'enable + allowed loginmode untouched' => [
				"bEnabled" => true,
				"aAllowedLoginTypes" => [ 'form', 'external', 'basic', 'hybridauth-MicrosoftGraph', 'hybridauth-Google' ],
				"aExpectedAllowedLoginTypes" => [ 'form', 'external', 'basic', 'hybridauth-MicrosoftGraph', 'hybridauth-Google' ],
			],
			'enable + shoud add login mode' => [
				"bEnabled" => true,
				"aAllowedLoginTypes" => [ 'form', 'external', 'basic', 'hybridauth-Google' ],
				"aExpectedAllowedLoginTypes" => [ 'form', 'external', 'basic', 'hybridauth-Google', 'hybridauth-MicrosoftGraph' ],
			],
			'disabled + allowed loginmode untouched' => [
				"bEnabled" => false,
				"aAllowedLoginTypes" => [ 'form', 'external', 'basic', 'hybridauth-Google' ],
				"aExpectedAllowedLoginTypes" => [ 'form', 'external', 'basic', 'hybridauth-Google' ],
			],
			'enable + should remove login mode' => [
				"bEnabled" => false,
				"aAllowedLoginTypes" => [ 'form', 'external', 'basic', 'hybridauth-MicrosoftGraph', 'hybridauth-Google' ],
				"aExpectedAllowedLoginTypes" => [ 'form', 'external', 'basic', 'hybridauth-Google' ],
			],
		];
	}

	/**
	 * @dataProvider SetHybridConfigProvider
	 */
	public function testSetHybridConfig(bool $bEnabled, array $aAllowedLoginTypes, array $aExpectedAllowedLoginTypes) {
		MetaModel::GetConfig()->SetAllowedLoginTypes($aAllowedLoginTypes);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', ['Google' => []]);

		$sSelectedSP = 'MicrosoftGraph';
		$aProvidersConfig = [
			'Google' => [],
			$sSelectedSP => [],
		];
		Config::SetHybridConfig($aProvidersConfig, $sSelectedSP, $bEnabled);

		$this->assertEquals($aProvidersConfig,
			MetaModel::GetConfig()->GetModuleSetting('combodo-hybridauth', 'providers', [])
		);

		$this->assertEquals($aExpectedAllowedLoginTypes, MetaModel::GetConfig()->GetAllowedLoginTypes());
	}

}
