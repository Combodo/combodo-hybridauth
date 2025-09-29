<?php

namespace Combodo\iTop\HybridAuth\Test;

use Combodo\iTop\HybridAuth\Config;
use Combodo\iTop\Test\UnitTest\ItopDataTestCase;
use Exception;
use MetaModel;
use utils;

class ConfigTest extends ItopDataTestCase
{
	/** @var string[] */
	private array $aAllowedLoginTypes;

	protected function setUp(): void
	{
		parent::setUp();
		$this->aAllowedLoginTypes = MetaModel::GetConfig()->GetAllowedLoginTypes();
	}

	protected function tearDown(): void
	{
		parent::tearDown();
		MetaModel::GetConfig()->SetAllowedLoginTypes($this->aAllowedLoginTypes);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', null);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', null);
	}

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
	public function testListProvidersShouldReturnConfiguredProviders(array $aExpected, array $aProviderConf)
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', $aProviderConf);

		$this->assertEquals($aExpected, Config::ListProviders());
	}

	public function testIsLoginModeSupportedShouldReturnTrueWhenProviderIsEnabled()
	{
		$aProviderConf = [
			'provider' => ['enabled' => true],
		];

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', $aProviderConf);

		$aAllowedLoginTypes = MetaModel::GetConfig()->GetAllowedLoginTypes();
		$sLoginMode = 'hybridauth-provider';
		if (!in_array($sLoginMode, $aAllowedLoginTypes)) {
			$aAllowedLoginTypes[] = $sLoginMode;
			MetaModel::GetConfig()->SetAllowedLoginTypes($aAllowedLoginTypes);
		}

		$this->assertEquals(true, Config::IsLoginModeSupported($sLoginMode));
	}

	public function testIsLoginModeSupportedShouldReturnFalseWhenLoginModeIsNotAllowed()
	{
		$aProviderConf = [
			'provider' => ['enabled' => true],
		];

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', $aProviderConf);

		$this->assertEquals(false, Config::IsLoginModeSupported('hybridauth-provider'));
	}

	public function testIsLoginModeSupportedShouldThrowExceptionWhenProviderIsDisabledAndLoginModeIsForced()
	{
		$aProviderConf = [
			'provider' => ['enabled' => false],
		];

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', $aProviderConf);

		$aAllowedLoginTypes = MetaModel::GetConfig()->GetAllowedLoginTypes();
		$sLoginMode = 'hybridauth-provider';
		if (!in_array($sLoginMode, $aAllowedLoginTypes)) {
			$aAllowedLoginTypes[] = $sLoginMode;
			MetaModel::GetConfig()->SetAllowedLoginTypes($aAllowedLoginTypes);
		}

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Login modes configuration needs to be fixed.');
		Config::IsLoginModeSupported($sLoginMode);
	}

	public function testIsLoginModeSupportedShouldReturnFalseWhenLoginModeNotStartingWithHybridauth()
	{
		$aProviderConf = [
			'provider' => ['enabled' => true],
		];

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', $aProviderConf);

		$aAllowedLoginTypes = MetaModel::GetConfig()->GetAllowedLoginTypes();
		$sLoginMode = 'provider';
		if (!in_array($sLoginMode, $aAllowedLoginTypes)) {
			$aAllowedLoginTypes[] = $sLoginMode;
			MetaModel::GetConfig()->SetAllowedLoginTypes($aAllowedLoginTypes);
		}

		$this->assertEquals(false, Config::IsLoginModeSupported($sLoginMode));
	}

	public function testIsLoginModeSupportedShouldThrowExceptionWhenProviderIsNotConfigured()
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', []);

		$aAllowedLoginTypes = MetaModel::GetConfig()->GetAllowedLoginTypes();
		$sLoginMode = 'hybridauth-provider';
		if (!in_array($sLoginMode, $aAllowedLoginTypes)) {
			$aAllowedLoginTypes[] = $sLoginMode;
			MetaModel::GetConfig()->SetAllowedLoginTypes($aAllowedLoginTypes);
		}

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Login modes configuration needs to be fixed.');
		Config::IsLoginModeSupported($sLoginMode);
	}

	public function testGetProviderConfShouldReturnTheCorrespondingValue()
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers',
			[
				'Google' => ['ga' => 'bu'],
			]
		);

		$this->assertEquals(['ga' => 'bu'], Config::GetProviderConf('hybridauth-Google'), 'Existing provider configuration should be returned');
		$this->assertEquals(null, Config::GetProviderConf('hybridauth-MS'), 'Non existing provider configuration should return null');
		$this->assertEquals(null, Config::GetProviderConf(null), 'null provider name should return null');
	}

	public function IsUserSynchroEnabledProvider()
	{
		return [
			'synchronize_user missing in conf' => [
				'bExpectedRes' => false,
				'aProviderConf' => [],
				'bOverallOption' => false,
				'When synchronize_user is missing in provider configuration, then default configured value should be used',
			],
			'synchronize_user disabled in provider' => [
				'bExpectedRes' => false,
				'aProviderConf' => ['synchronize_user' => false],
				'bOverallOption' => false,
				'When synchronize_user is disabled in provider configuration and the default value is false, the return should be false',
			],
			'synchronize_user enabled in provider' => [
				'bExpectedRes' => true,
				'aProviderConf' => ['synchronize_user' => true],
				'bOverallOption' => false,
				'When synchronize_user is enabled in provider configuration and the default value is false, the return should be true',
			],
			'synchronize_user enabled globally' => [
				'bExpectedRes' => true,
				'aProviderConf' => ['synchronize_user' => false],
				'bOverallOption' => true,
				'When synchronize_user is disabled in provider configuration but the default value is true, the return should be true',
			],
		];
	}

	/**
	 * @dataProvider IsUserSynchroEnabledProvider
	 */
	public function testIsOptionEnabled_CheckThatUserSynchroShouldMatchConfiguration($bExpectedRes, $aProviderConf, $bOverallOption, $sMessage)
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers',
			[
				'Google' => $aProviderConf,
			]
		);

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_user', $bOverallOption);

		$this->assertEquals($bExpectedRes, Config::IsOptionEnabled('hybridauth-Google', 'synchronize_user'), $sMessage);
	}

	public function PrepareSynchroProfileConfiguration($aProviderProfiles, $sProviderProfile, $aGlobalProfiles, $sGlobalProviderProfile) {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers',
			[
				'Google' => ['default_profiles' => $aProviderProfiles, 'default_profile' => $sProviderProfile],
			]
		);

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', $aGlobalProfiles);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', $sGlobalProviderProfile);
	}

	public function testGetSynchroProfileProvider_FurtherProfilesFromProviderLevel() {
		$this->PrepareSynchroProfileConfiguration(['SuperUser', 'Administrator'], null, null, null);
		$this->assertEquals(['SuperUser', 'Administrator'], Config::GetSynchroProfiles('hybridauth-Google'));
	}

	public function testGetSynchroProfileProvider_EmptyProfileListFromProviderLevel() {
		$this->PrepareSynchroProfileConfiguration([], null, null, null);
		$this->assertEquals([], Config::GetSynchroProfiles('hybridauth-Google'));
	}

	public function testGetSynchroProfileProvider_FurtherProfilesFromGlobalLevel() {
		$this->PrepareSynchroProfileConfiguration(null, null, ['SuperUser', 'Administrator'], null);
		$this->assertEquals(['SuperUser', 'Administrator'], Config::GetSynchroProfiles('hybridauth-Google'));
	}

	public function testGetSynchroProfileProvider_EmptyProfileListFromGlobalLevel() {
		$this->PrepareSynchroProfileConfiguration(null, null, [], null);
		$this->assertEquals([], Config::GetSynchroProfiles('hybridauth-Google'));
	}

	public function testGetSynchroProfileProvider_OneSingleProfileAtProviderLevel() {
		$this->PrepareSynchroProfileConfiguration(null, 'SuperUser', null, null);
		$this->assertEquals(['SuperUser'], Config::GetSynchroProfiles('hybridauth-Google'));
	}

	public function testGetSynchroProfileProvider_OneSingleProfileAtGlobalLevel() {
		$this->PrepareSynchroProfileConfiguration(null, null, null, 'SuperUser');
		$this->assertEquals(['SuperUser'], Config::GetSynchroProfiles('hybridauth-Google'));
	}

	public function testGetSynchroProfileProvider_NoDefaultProfileConfigured() {
		$this->PrepareSynchroProfileConfiguration(null, null, null, null);
		$this->assertEquals(['Portal User'], Config::GetSynchroProfiles('hybridauth-Google'));
	}

	public function testGetSynchroProfileProvider_NoDefaultProfileConfigured_EmptyStringValue() {
		$this->PrepareSynchroProfileConfiguration(null, '', null, null);
		$this->assertEquals(['Portal User'], Config::GetSynchroProfiles('hybridauth-Google'));

		$this->PrepareSynchroProfileConfiguration(null, null, null, '');
		$this->assertEquals(['Portal User'], Config::GetSynchroProfiles('hybridauth-Google'));
	}

	public function GetDebugProvider()
	{
		return [
			'debug missing in conf' => [
				'bExpectedRes' => false,
				'aProviderConf' => [],
				'bOverallOption' => false,
				'When the debug is missing in configuration the default option should be used',
			],
			'debug disabled in provider' => [
				'bExpectedRes' => false,
				'aProviderConf' => ['debug' => false],
				'bOverallOption' => false,
				'When the debug is disabled in configuration the return should be false',
			],
			'debug enabled in provider' => [
				'bExpectedRes' => true,
				'aProviderConf' => ['debug' => true],
				'bOverallOption' => false,
				'When the debug is enabled in configuration the return should be true',
			],
			'debug enabled globally' => [
				'bExpectedRes' => true,
				'aProviderConf' => ['debug' => false],
				'bOverallOption' => true,
				'When the debug is disabled in configuration the return should be false, even if the default is true',
			],
		];
	}

	/**
	 * @dataProvider GetDebugProvider
	 */
	public function testGetDebugShouldMatchTheConfigurationForTheProvider($bExpectedRes, $aProviderConf, $bOverallOption, $sMessage)
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers',
			[
				'Google' => $aProviderConf,
			]
		);

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'debug', $bOverallOption);

		$this->assertEquals($bExpectedRes, Config::GetDebug('Google'), $sMessage);
	}


	public function testGetDefaultOrgShouldMatchPerProviderConfiguration()
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers',
			[
				'Google' => ['default_organization' => 'provider-org'],
				'NoDefaultOrg' => [],
			]
		);

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', 'overall-org');

		$this->assertEquals('provider-org', Config::GetDefaultOrg('hybridauth-Google'), 'The default organization should be the one configured for the provider');
		$this->assertEquals('overall-org', Config::GetDefaultOrg('hybridauth-NoDefaultOrg'), 'The default organization should be the global default when no organization is configured for the provider');
		$this->assertEquals('overall-org', Config::GetDefaultOrg('hybridauth-MissingProvider'), 'The default organization should be the global default when the provider is not present in the configuration');
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
			$aUseCases["provider $sProvider : consent mode should be disabled when not present in the configuration"] = [$aProviderConf, $aExpected];

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
			$aUseCases["provider $sProvider : authorize_url_parameters configured should not touch authorize_url_parameters if present in the configuration"] = [$aProviderConf, $aExpected];

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

			$aUseCases["adapter $sProvider : consent mode should be disabled when not present in configuration"] = [$aProviderConf, $aExpected];
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
			$aUseCases["adapter $sProvider : authorize_url_parameters configured should not touch that part of the conf"] = [$aProviderConf, $aExpected];
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
		$aUseCases["provider Keycloak : should not touch the conf"] = [$aProviderConf, $aProviderConf];

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
		$aUseCases["adapter Keycloak : should not touch the conf"] = [$aProviderConf, $aProviderConf];

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
