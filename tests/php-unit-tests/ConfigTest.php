<?php

namespace Combodo\iTop\HybridAuth\Test;

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

		$this->assertEquals($aExpected, Config::GetProviders());
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

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'ui-proposed-provider', null);
		$this->assertEquals(['Google', 'MicrosoftGraph', 'etc...'], Config::GetProposedSpList($oHybridauthService));
	}

	public function testGetProposedSpList(){
		$oHybridauthService = $this->createMock(HybridauthService::class);
		$oHybridauthService->expects($this->never())
			->method('ListProviders');
		;

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'ui-proposed-providers', ['Google']);
		$this->assertEquals(['Google'], Config::GetProposedSpList($oHybridauthService));
	}

}
