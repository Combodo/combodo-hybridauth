<?php

namespace Combodo\iTop\HybridAuth\Test;

use Combodo\iTop\HybridAuth\Config;
use Combodo\iTop\Test\UnitTest\ItopDataTestCase;
use Exception;
use MetaModel;
use utils;

class ConfigTest extends ItopDataTestCase
{

	public function testGetHybridConfig()
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', ['ga' => 'bu']);

		$aExpected = [
			'callback' => utils::GetAbsoluteUrlModulesRoot().'combodo-hybridauth/landing.php',
			'providers' => ['ga' => 'bu'],
		];
		$this->assertEquals($aExpected, Config::GetHybridConfig());
	}

	public function GetProvidersProvider()
	{
		return [
			'no provider' => [
				'aExpected' => [],
				'aProviderConf' => [],
			],
			'one provider wrongly configured' => [
				'aExpected' => ['ga' => false],
				'aProviderConf' => ['ga' => []],
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
	public function testGetProviders(array $aExpected, array $aProviderConf)
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', $aProviderConf);

		$this->assertEquals($aExpected, Config::ListProviders());
	}

	public function IsLoginModeSupportedProvider()
	{
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
	public function testIsLoginModeSupported(string $sLoginMode, bool $bExpected, bool $bThrowException = false, bool $bLoginModeAllowed = true)
	{
		$aProviderConf = [
			'disabled-loginmode' => ['enabled' => false],
			'enabled-loginmode' => ['enabled' => true],
		];

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', $aProviderConf);

		if ($bLoginModeAllowed) {
			$aAllowedLoginTypes = MetaModel::GetConfig()->GetAllowedLoginTypes();
			if (!in_array($sLoginMode, $aAllowedLoginTypes)) {
				$aAllowedLoginTypes[] = $sLoginMode;
				MetaModel::GetConfig()->SetAllowedLoginTypes($aAllowedLoginTypes);
			}
		}

		if ($bThrowException) {
			$this->expectException(Exception::class);
			$this->expectExceptionMessage("Login modes configuration needs to be fixed.");
		}

		$this->assertEquals($bExpected, Config::IsLoginModeSupported($sLoginMode));
	}

	public function testGetProviderConf()
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers',
			[
				'Google' => ['ga' => 'bu'],
			]
		);

		$this->assertEquals(['ga' => 'bu'], Config::GetProviderConf('hybridauth-Google'));
		$this->assertEquals(null, Config::GetProviderConf('hybridauth-MS'));
		$this->assertEquals(null, Config::GetProviderConf(null));
	}

	public function IsUserSynchroEnabledProvider()
	{
		return [
			'synchronize_user missing in conf' => [
				'bExpectedRes' => false,
				'aProviderConf' => [],
				'bOverallOption' => false,
			],
			'synchronize_user disabled in provider' => [
				'bExpectedRes' => false,
				'aProviderConf' => ['synchronize_user' => false],
				'bOverallOption' => false,
			],
			'synchronize_user enabled in provider' => [
				'bExpectedRes' => true,
				'aProviderConf' => ['synchronize_user' => true],
				'bOverallOption' => false,
			],
			'synchronize_user enabled globally' => [
				'bExpectedRes' => true,
				'aProviderConf' => ['synchronize_user' => false],
				'bOverallOption' => true,
			],
		];
	}

	/**
	 * @dataProvider IsUserSynchroEnabledProvider
	 */
	public function testIsUserSynchroEnabled($bExpectedRes, $aProviderConf, $bOverallOption)
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers',
			[
				'Google' => $aProviderConf,
			]
		);

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_user', $bOverallOption);

		$this->assertEquals($bExpectedRes, Config::IsUserSynchroEnabled('hybridauth-Google'));
	}

	public function IsContactSynchroEnabledProvider()
	{
		return [
			'synchronize_user missing in conf' => [
				'bExpectedRes' => false,
				'aProviderConf' => [],
				'bOverallOption' => false,
			],
			'synchronize_user disabled in provider' => [
				'bExpectedRes' => false,
				'aProviderConf' => ['synchronize_contact' => false],
				'bOverallOption' => false,
			],
			'synchronize_user enabled in provider' => [
				'bExpectedRes' => true,
				'aProviderConf' => ['synchronize_contact' => true],
				'bOverallOption' => false,
			],
			'synchronize_user enabled globally' => [
				'bExpectedRes' => true,
				'aProviderConf' => ['synchronize_contact' => false],
				'bOverallOption' => true,
			],
		];
	}

	/**
	 * @dataProvider IsContactSynchroEnabledProvider
	 */
	public function testIsContactSynchroEnabled($bExpectedRes, $aProviderConf, $bOverallOption)
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers',
			[
				'Google' => $aProviderConf,
			]
		);

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', $bOverallOption);

		$this->assertEquals($bExpectedRes, Config::IsContactSynchroEnabled('hybridauth-Google'));
	}

	public function GetSynchroProfileProvider()
	{
		return [
			'default_profile missing in conf' => [
				'bExpectedRes' => 'Portal User',
				'aProviderConf' => [],
				'bOverallOption' => null,
			],
			'synchronize_user set in provider' => [
				'bExpectedRes' => 'SuperUser',
				'aProviderConf' => ['default_profile' => 'SuperUser'],
				'bOverallOption' => null,
			],
			'synchronize_user set globally' => [
				'bExpectedRes' => 'SuperUser',
				'aProviderConf' => [],
				'bOverallOption' => 'SuperUser',
			],
		];
	}

	/**
	 * @dataProvider GetSynchroProfileProvider
	 */
	public function testGetSynchroProfile($bExpectedRes, $aProviderConf, $bOverallOption)
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers',
			[
				'Google' => $aProviderConf,
			]
		);

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', $bOverallOption);

		$this->assertEquals($bExpectedRes, Config::GetSynchroProfile('hybridauth-Google'));
	}

	public function GetDebugProvider()
	{
		return [
			'debug missing in conf' => [
				'bExpectedRes' => false,
				'aProviderConf' => [],
				'bOverallOption' => false,
			],
			'debug disabled in provider' => [
				'bExpectedRes' => false,
				'aProviderConf' => ['debug' => false],
				'bOverallOption' => false,
			],
			'debug enabled in provider' => [
				'bExpectedRes' => true,
				'aProviderConf' => ['debug' => true],
				'bOverallOption' => false,
			],
			'debug enabled globally' => [
				'bExpectedRes' => true,
				'aProviderConf' => ['debug' => false],
				'bOverallOption' => true,
			],
		];
	}

	/**
	 * @dataProvider GetDebugProvider
	 */
	public function testGetDebug($bExpectedRes, $aProviderConf, $bOverallOption)
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers',
			[
				'Google' => $aProviderConf,
			]
		);

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'debug', $bOverallOption);

		$this->assertEquals($bExpectedRes, Config::GetDebug('Google'));
	}


	public function testGetDefaultOrg()
	{
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


	public function SetHybridConfigProvider()
	{
		return [
			'enable + allowed loginmode untouched' => [
				"bEnabled" => true,
				"aAllowedLoginTypes" => ['form', 'external', 'basic', 'hybridauth-MicrosoftGraph', 'hybridauth-Google'],
				"aExpectedAllowedLoginTypes" => ['form', 'external', 'basic', 'hybridauth-MicrosoftGraph', 'hybridauth-Google'],
			],
			'enable + shoud add login mode' => [
				"bEnabled" => true,
				"aAllowedLoginTypes" => ['form', 'external', 'basic', 'hybridauth-Google'],
				"aExpectedAllowedLoginTypes" => ['form', 'external', 'basic', 'hybridauth-Google', 'hybridauth-MicrosoftGraph'],
			],
			'disabled + allowed loginmode untouched' => [
				"bEnabled" => false,
				"aAllowedLoginTypes" => ['form', 'external', 'basic', 'hybridauth-Google'],
				"aExpectedAllowedLoginTypes" => ['form', 'external', 'basic', 'hybridauth-Google'],
			],
			'enable + should remove login mode' => [
				"bEnabled" => false,
				"aAllowedLoginTypes" => ['form', 'external', 'basic', 'hybridauth-MicrosoftGraph', 'hybridauth-Google'],
				"aExpectedAllowedLoginTypes" => ['form', 'external', 'basic', 'hybridauth-Google'],
			],
		];
	}

	/**
	 * @dataProvider SetHybridConfigProvider
	 */
	public function testSetHybridConfig(bool $bEnabled, array $aAllowedLoginTypes, array $aExpectedAllowedLoginTypes)
	{
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

	public static function DisableConsentModeConfigurationProvider()
	{
		$aUseCases = [];
		foreach (['MicrosoftGraph', 'Google'] as $sProvider) {
			$aProviderConf = [
				$sProvider => [
					'keys' => [
						'id' => 'ID2',
						'secret' => 'SECRET2',
					],
					'enabled' => true,
				],
			];
			$aExpected = [
				$sProvider => [
					'keys' => [
						'id' => 'ID2',
						'secret' => 'SECRET2',
					],
					'enabled' => true,
					'authorize_url_parameters' => [
						'prompt' => '',
					],
				],
			];
			$aUseCases["provider $sProvider : consent mode should be disabled"] = [$aProviderConf, $aExpected];

			$aProviderConf = [
				$sProvider => [
					'keys' => [
						'id' => 'ID2',
						'secret' => 'SECRET2',
					],
					'enabled' => true,
					'authorize_url_parameters' => [],
				],
			];
			$aExpected = [
				$sProvider => [
					'keys' => [
						'id' => 'ID2',
						'secret' => 'SECRET2',
					],
					'enabled' => true,
					'authorize_url_parameters' => [],
				],
			];
			$aUseCases["provider $sProvider : authorize_url_parameters configured do not touch that part of the conf"] = [$aProviderConf, $aExpected];

			$aProviderConf = [
				"My-$sProvider" => [
					'keys' => [
						'id' => 'ID2',
						'secret' => 'SECRET2',
					],
					'enabled' => true,
					'adapter' => "Hybridauth\\Provider\\$sProvider",
				],
			];
			$aExpected = [
				"My-$sProvider" => [
					'keys' => [
						'id' => 'ID2',
						'secret' => 'SECRET2',
					],
					'enabled' => true,
					'adapter' => "Hybridauth\\Provider\\$sProvider",
					'authorize_url_parameters' => [
						'prompt' => '',
					],
				],
			];

			$aUseCases["adapter $sProvider : consent mode should be disabled"] = [$aProviderConf, $aExpected];
			$aProviderConf = [
				"My-$sProvider" => [
					'keys' => [
						'id' => 'ID2',
						'secret' => 'SECRET2',
					],
					'enabled' => true,
					'adapter' => "Hybridauth\\Provider\\$sProvider",
					'authorize_url_parameters' => [],
				],
			];
			$aExpected = [
				"My-$sProvider" => [
					'keys' => [
						'id' => 'ID2',
						'secret' => 'SECRET2',
					],
					'enabled' => true,
					'adapter' => "Hybridauth\\Provider\\$sProvider",
					'authorize_url_parameters' => [],
				],
			];
			$aUseCases["adapter $sProvider : authorize_url_parameters configured do not touch that part of the conf"] = [$aProviderConf, $aExpected];
		}

		$aProviderConf = [
			"Keycloak" => [
				'keys' => [
					'id' => 'ID2',
					'secret' => 'SECRET2',
				],
				'enabled' => true,
			],
		];
		$aExpected = [
			"Keycloak" => [
				'keys' => [
					'id' => 'ID2',
					'secret' => 'SECRET2',
				],
				'enabled' => true,
			],
		];
		$aUseCases["provider Keycloak : do not touch the conf"] = [$aProviderConf, $aExpected];

		$aProviderConf = [
			"My-Keycloak" => [
				'keys' => [
					'id' => 'ID2',
					'secret' => 'SECRET2',
				],
				'enabled' => true,
				'adapter' => "Hybridauth\\Provider\\Keycloak",
			],
		];
		$aExpected = [
			"My-Keycloak" => [
				'keys' => [
					'id' => 'ID2',
					'secret' => 'SECRET2',
				],
				'enabled' => true,
				'adapter' => "Hybridauth\\Provider\\Keycloak",
			],
		];
		$aUseCases["adapter Keycloak : do not touch the conf"] = [$aProviderConf, $aExpected];

		return $aUseCases;
	}

	/**
	 * @dataProvider DisableConsentModeConfigurationProvider
	 */
	public function testDisableConsentModeConfiguration_FixConsentMode($aProviderConf, $aExpected)
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', $aProviderConf);

		$aResult = Config::GetAuthenticatedHybridConfig();
		$this->assertEquals(utils::GetAbsoluteUrlModulesRoot().'combodo-hybridauth/landing.php', $aResult['callback'] ?? null);
		$this->assertEquals($aExpected, $aResult['providers'] ?? null);
	}
}
