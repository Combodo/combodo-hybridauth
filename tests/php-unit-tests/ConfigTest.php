<?php

namespace Combodo\iTop\HybridAuth\Test;

use Combodo\iTop\HybridAuth\Config;
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
}
