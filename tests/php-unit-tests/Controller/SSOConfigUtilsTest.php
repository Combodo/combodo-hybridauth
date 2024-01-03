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
						]
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
						]
					]
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
						]
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
					]
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
							'adapter' => 'namespace/XXX',
							'keys' =>
								array (
									'id' => 'ID3',
									'secret' => 'SECRET3',
								),
							'enabled' => true,
							'synchronize_user_contact' => true,
							'default_organization' => "org4",
						],
					]
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
							'adapter' => 'namespace/XXX',
						]
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

		$oSSOConfigUtils = new SSOConfigUtils();
		$oSSOConfigUtils->SetConfigRepository($this->oSsConfigRepository);
		$aTwigVars = $oSSOConfigUtils->GetTwigConfigInternal($aCombodoHybridAuthConf);
		$this->assertEquals($aExpectedRes, $aTwigVars, 'twig var generation:' . var_export($aTwigVars, true));

		$aGeneratedConf = $oSSOConfigUtils->GenerateHybridConfFromTwigVars($aTwigVars);

		$aExpectedNewConf = $aCombodoHybridAuthConf;
		if (sizeof($aCombodoHybridAuthConf)===0){
			$aExpectedNewConf = [
				'providers' => [
					'Google' => [
						'keys' =>
							array (
								'id' => '',
								'secret' => '',
							),
						'enabled' => false,
					]
				]
			];
		}

		$this->assertEquals($aExpectedNewConf, $aGeneratedConf, 'hybrid conf generation:' . var_export($aGeneratedConf, true));
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
						]
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
					]
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
							'adapter' => 'namespace/XXX',
							'keys' =>
								array (
									'id' => 'ID3',
									'secret' => 'SECRET3',
								),
							'enabled' => true,
							'synchronize_user_contact' => true,
							'default_organization' => "org4",
						],
					]
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
							'adapter' => 'namespace/XXX',
						],
						'MicrosoftGraph' => [
							'ssoEnabled' => false,
							'ssoSP' => 'MicrosoftGraph',
							'ssoSpId' => '',
							'ssoSpSecret' => '',
							'ssoUserSync' => false,
							'ssoUserOrg' => null,
						]
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

		$oSSOConfigUtils = new SSOConfigUtils();
		$oSSOConfigUtils->SetConfigRepository($this->oSsConfigRepository);

		$aTwigVars = $oSSOConfigUtils->GetTwigConfigInternal($aCombodoHybridAuthConf, "MicrosoftGraph");
		$this->assertEquals($aExpectedRes, $aTwigVars, 'twig var generation:' . var_export($aTwigVars, true));
	}
}
