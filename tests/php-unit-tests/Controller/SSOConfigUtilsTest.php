<?php

namespace Combodo\iTop\HybridAuth\Test\Controller;

use Combodo\iTop\HybridAuth\Controller\SSOConfigUtils;
use Combodo\iTop\HybridAuth\Repository\SssConfigRepository;
use Combodo\iTop\Test\UnitTest\ItopDataTestCase;

class SSOConfigUtilsTest extends ItopDataTestCase {
	private $oSsConfigRepository;

	protected function setUp(): void {
		parent::setUp();

		$this->RequireOnceItopFile('env-production/combodo-hybridauth/vendor/autoload.php');
		$this->oSsConfigRepository = $this->createMock(SssConfigRepository::class);
	}

	public function GetTwigConfigInternalProvider() {
		return [
			'init: no provider' => [
				'aCombodoHybridAuthConf' => [],
				'aExpectedRes' => [
					'providers' => [
						'Google' => [
							'ssoEnabled' => false,
				            'ssoSP' => 'Google',
				            'ssoSpId' => '',
				            'ssoSpSecret' => '',
				            'ssoUserSync' => false,
				            'ssoUserOrg' => null,
						],
					],
					'selectedSp' => 'Google',
					'ssoSpList' => ['Google', 'MicrosoftGraph'],
					'org' => ["org1", "org2", "org3"],
				],
			],
			'MicrosoftGraph configured sans synchro' => [
				'aCombodoHybridAuthConf' => [
					'providers' => [
						'MicrosoftGraph' => [
							'keys' =>
								array (
									'id' => 'ID',
									'secret' => 'SECRET',
								),
							'enabled' => true,
						],
					],
				],
				'aExpectedRes' => [
					'providers' => [
						'MicrosoftGraph' => [
							'ssoEnabled' => true,
							'ssoSP' => 'MicrosoftGraph',
							'ssoSpId' => 'ID',
							'ssoSpSecret' => 'SECRET',
							'ssoUserSync' => false,
							'ssoUserOrg' => null,
						],
					],
					'selectedSp' => 'MicrosoftGraph',
					'ssoSpList' => ['Google', 'MicrosoftGraph'],
					'org' => ["org1", "org2", "org3"],
				],
			],
			'MicrosoftGraph + Google configured WITH synchro' => [
				'aCombodoHybridAuthConf' => [
					'providers' => [
						'Google' => [
							'keys' =>
								array (
									'id' => 'ID',
									'secret' => 'SECRET',
								),
							'enabled' => true,
							'synchronize_user_contact' => true,
							'default_organization' => "org1",
						],
						'MicrosoftGraph' => [
							'keys' =>
								array (
									'id' => 'ID2',
									'secret' => 'SECRET2',
								),
							'enabled' => false,
							'synchronize_user_contact' => true,
							'default_organization' => "org3",
						],
					],
				],
				'aExpectedRes' => [
					'providers' => [
						'Google' => [
							'ssoEnabled' => true,
							'ssoSP' => 'Google',
							'ssoSpId' => 'ID',
							'ssoSpSecret' => 'SECRET',
							'ssoUserSync' => true,
							'ssoUserOrg' => "org1",
						],
						'MicrosoftGraph' => [
							'ssoEnabled' => false,
							'ssoSP' => 'MicrosoftGraph',
							'ssoSpId' => 'ID2',
							'ssoSpSecret' => 'SECRET2',
							'ssoUserSync' => true,
							'ssoUserOrg' => "org3",
						],
					],
					'selectedSp' => 'Google',
					'ssoSpList' => ['Google', 'MicrosoftGraph'],
					'org' => ["org1", "org2", "org3"],
				],
			],
			'configured SP not available by default in UI + org not visible by current user' => [
				'aCombodoHybridAuthConf' => [
					'providers' => [
						'XXX_SP' => [
							'keys' =>
								array (
									'id' => 'ID3',
									'secret' => 'SECRET3',
								),
							'enabled' => true,
							'synchronize_user_contact' => true,
							'default_organization' => "org4",
						],
					],
				],
				'aExpectedRes' => [
					'providers' => [
						'XXX_SP' => [
							'ssoEnabled' => true,
							'ssoSP' => 'XXX_SP',
							'ssoSpId' => 'ID3',
							'ssoSpSecret' => 'SECRET3',
							'ssoUserSync' => true,
							'ssoUserOrg' => "org4",
						],
					],
					'selectedSp' => 'XXX_SP',
					'ssoSpList' => ['Google', 'MicrosoftGraph', 'XXX_SP'],
					'org' => ["org1", "org2", "org3", "org4"],
				],
			],
		];
	}

	/**
	 * @dataProvider GetTwigConfigInternalProvider
	 */
	public function testGetTwigConfigInternal(array $aCombodoHybridAuthConf, array $aExpectedRes){
		$this->oSsConfigRepository->expects($this->exactly(1))
			->method('GetOrganizations')
			->willReturn(
				[ "org1", "org2", "org3" ]
			);

		$oSSOConfigUtils = new SSOConfigUtils([ 'Google', 'MicrosoftGraph' ]);
		$oSSOConfigUtils->SetConfigRepository($this->oSsConfigRepository);
		$aTwigVars = $oSSOConfigUtils->GetTwigConfigInternal($aCombodoHybridAuthConf);
		$this->assertEquals($aExpectedRes, $aTwigVars, 'twig var generation:' . var_export($aTwigVars, true));
	}


	public function GetTwigConfigInternalWithParamProvider() {
		return [
			'init: no provider' => [
				'aCombodoHybridAuthConf' => [],
				'aExpectedRes' => [
					'providers' => [
						'MicrosoftGraph' => [
							'ssoEnabled' => false,
							'ssoSP' => 'MicrosoftGraph',
							'ssoSpId' => '',
							'ssoSpSecret' => '',
							'ssoUserSync' => false,
							'ssoUserOrg' => null,
						],
					],
					'selectedSp' => 'MicrosoftGraph',
					'ssoSpList' => ['Google', 'MicrosoftGraph'],
					'org' => ["org1", "org2", "org3"],
				],
			],
			'MicrosoftGraph + Google configured WITH synchro' => [
				'aCombodoHybridAuthConf' => [
					'providers' => [
						'Google' => [
							'keys' =>
								array (
									'id' => 'ID',
									'secret' => 'SECRET',
								),
							'enabled' => true,
							'synchronize_user_contact' => true,
							'default_organization' => "org1",
						],
						'MicrosoftGraph' => [
							'keys' =>
								array (
									'id' => 'ID2',
									'secret' => 'SECRET2',
								),
							'enabled' => false,
							'synchronize_user_contact' => true,
							'default_organization' => "org3",
						],
					],
				],
				'aExpectedRes' => [
					'providers' => [
						'Google' => [
							'ssoEnabled' => true,
							'ssoSP' => 'Google',
							'ssoSpId' => 'ID',
							'ssoSpSecret' => 'SECRET',
							'ssoUserSync' => true,
							'ssoUserOrg' => "org1",
						],
						'MicrosoftGraph' => [
							'ssoEnabled' => false,
							'ssoSP' => 'MicrosoftGraph',
							'ssoSpId' => 'ID2',
							'ssoSpSecret' => 'SECRET2',
							'ssoUserSync' => true,
							'ssoUserOrg' => "org3",
						],
					],
					'selectedSp' => 'MicrosoftGraph',
					'ssoSpList' => ['Google', 'MicrosoftGraph'],
					'org' => ["org1", "org2", "org3"],
				],
			],
			'configured SP not available by default in UI + org not visible by current user' => [
				'aCombodoHybridAuthConf' => [
					'providers' => [
						'XXX_SP' => [
							'keys' =>
								array (
									'id' => 'ID3',
									'secret' => 'SECRET3',
								),
							'enabled' => true,
							'synchronize_user_contact' => true,
							'default_organization' => "org4",
						],
					],
				],
				'aExpectedRes' => [
					'providers' => [
						'XXX_SP' => [
							'ssoEnabled' => true,
							'ssoSP' => 'XXX_SP',
							'ssoSpId' => 'ID3',
							'ssoSpSecret' => 'SECRET3',
							'ssoUserSync' => true,
							'ssoUserOrg' => "org4",
						],
						'MicrosoftGraph' => [
							'ssoEnabled' => false,
							'ssoSP' => 'MicrosoftGraph',
							'ssoSpId' => '',
							'ssoSpSecret' => '',
							'ssoUserSync' => false,
							'ssoUserOrg' => null,
						],
					],
					'selectedSp' => 'MicrosoftGraph',
					'ssoSpList' => ['Google', 'MicrosoftGraph', 'XXX_SP'],
					'org' => ["org1", "org2", "org3", "org4"],
				],
			],
		];
	}

	/**
	 * @dataProvider GetTwigConfigInternalWithParamProvider
	 */
	public function testGetTwigConfigInternalWithParam(array $aCombodoHybridAuthConf, array $aExpectedRes){
		$this->oSsConfigRepository->expects($this->exactly(1))
			->method('GetOrganizations')
			->willReturn(
				[ "org1", "org2", "org3" ]
			);

		$oSSOConfigUtils = new SSOConfigUtils([ 'Google', 'MicrosoftGraph' ]);
		$oSSOConfigUtils->SetConfigRepository($this->oSsConfigRepository);

		$aTwigVars = $oSSOConfigUtils->GetTwigConfigInternal($aCombodoHybridAuthConf, "MicrosoftGraph");
		$this->assertEquals($aExpectedRes, $aTwigVars, 'twig var generation:' . var_export($aTwigVars, true));
	}

	public function GenerateHybridProviderConfWithoutUserSyncProvider() {
		return [
			'first sso conf + enabled' => [
				'aFormData' => [
					'ssoEnabled' => 'true',
					'ssoSpId' => 'ssoSpIdXXX',
					'ssoSpSecret' => 'ssoSpSecretYYY',
				],
				'aProvidersConfig' => [
				],
				'bExpectedEnabled' => true,
				'bExpectedProvidersConfig' => [
					'Google' => [
						'keys' => [
							'id' => 'ssoSpIdXXX',
							'secret' => 'ssoSpSecretYYY',
						],
						'enabled' => true,
						'synchronize_user_contact' => false,
					]
				]
			],
			'first sso conf + enabled + user sync' => [
				'aFormData' => [
					'ssoEnabled' => 'true',
					'ssoSpId' => 'ssoSpIdXXX',
					'ssoSpSecret' => 'ssoSpSecretYYY',
					'ssoUserSync' => 'true',
					'ssoUserOrg' => 'Org1',
				],
				'aProvidersConfig' => [
				],
				'bExpectedEnabled' => true,
				'bExpectedProvidersConfig' => [
					'Google' => [
						'keys' => [
							'id' => 'ssoSpIdXXX',
							'secret' => 'ssoSpSecretYYY',
						],
						'enabled' => true,
						'synchronize_user_contact' => true,
						'default_organization' => 'Org1',
					]
				]
			],
			'first sso conf + disabled' => [
				'aFormData' => [
					'ssoEnabled' => 'false',
					'ssoSpId' => 'ssoSpIdXXX',
					'ssoSpSecret' => 'ssoSpSecretYYY',
				],
				'aProvidersConfig' => [
				],
				'bExpectedEnabled' => false,
				'bExpectedProvidersConfig' => [
					'Google' => [
						'keys' => [
							'id' => 'ssoSpIdXXX',
							'secret' => 'ssoSpSecretYYY',
						],
						'enabled' => false,
						'synchronize_user_contact' => false,
					]
				]
			],
			'edit sso conf + disable user sync + pwd untouched' => [
				'aFormData' => [
					'ssoEnabled' => 'false',
					'ssoSpId' => 'ssoSpIdXXX',
					'ssoSpSecret' => '●●●●●●●●●',
					'ssoUserSync' => 'false',
					'ssoUserOrg' => 'Org2',
				],
				'aProvidersConfig' => [
					'Google' => [
						'any_key_not_configurable_in_ui' => 'val',
						'keys' => [
							'id' => 'ssoSpIdXXX',
							'secret' => 'ssoSpSecretYYY',
						],
						'enabled' => true,
						'synchronize_user_contact' => true,
						'default_organization' => 'Org1',

					]
				],
				'bExpectedEnabled' => true,
				'bExpectedProvidersConfig' => [
					'Google' => [
						'any_key_not_configurable_in_ui' => 'val',
						'keys' => [
							'id' => 'ssoSpIdXXX',
							'secret' => 'ssoSpSecretYYY',
						],
						'enabled' => false,
						'synchronize_user_contact' => false,
						'default_organization' => 'Org1',
					]
				]
			],
			'edit sso conf + change user sync org + pwd touched' => [
				'aFormData' => [
					'ssoEnabled' => 'true',
					'ssoSpId' => 'ssoSpIdXXX',
					'ssoSpSecret' => 'ssoSpSecretYYY123',
					'ssoUserSync' => 'true',
					'ssoUserOrg' => 'Org2',
				],
				'aProvidersConfig' => [
					'Google' => [
						'any_key_not_configurable_in_ui' => 'val',
						'keys' => [
							'id' => 'ssoSpIdXXX',
							'secret' => 'ssoSpSecretYYY',
						],
						'enabled' => false,
						'synchronize_user_contact' => true,
						'default_organization' => 'Org1',

					]
				],
				'bExpectedEnabled' => true,
				'bExpectedProvidersConfig' => [
					'Google' => [
						'any_key_not_configurable_in_ui' => 'val',
						'keys' => [
							'id' => 'ssoSpIdXXX',
							'secret' => 'ssoSpSecretYYY123',
						],
						'enabled' => true,
						'synchronize_user_contact' => true,
						'default_organization' => 'Org2',
					]
				]
			],
		];
	}

	/**
	 * @dataProvider GenerateHybridProviderConf
	 */
	public function testGenerateHybridProviderConf(array $aFormData, array $aProvidersConfig,
		bool $bExpectedEnabled, array $bExpectedProvidersConfig){
		$oSSOConfigUtils = new SSOConfigUtils([]);
		$sSelectedSp = 'Google';
		$bEnabled = $oSSOConfigUtils->GenerateHybridProviderConf($aFormData, $aProvidersConfig, $sSelectedSp);
		$this->assertEquals($bExpectedEnabled, $bEnabled);
		$this->assertEquals($bExpectedProvidersConfig, $aProvidersConfig);

	}
}
