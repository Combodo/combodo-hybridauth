<?php

namespace Combodo\iTop\HybridAuth\Controller;

use Combodo\iTop\HybridAuth\Config;
use Combodo\iTop\HybridAuth\Repository\SssConfigRepository;

/**
 * Class dedicated to handle convert sso conf to twig variables and vice versa
 */
class SSOConfigUtils {
	/** @var SssConfigRepository $oSssConfigRepository */
	private $oSssConfigRepository;

	/** @var array $aSpList */
	private $aSpList;

	public function __construct(array $aSpList){
		$this->aSpList = $aSpList;
	}

	public function GetConfigRepository(): SssConfigRepository {
		if (is_null($this->oSssConfigRepository)){
			$this->oSssConfigRepository = new SssConfigRepository();
		}
		return $this->oSssConfigRepository;
	}

	public function SetConfigRepository(SssConfigRepository $oSssConfigRepository): void {
		$this->oSssConfigRepository = $oSssConfigRepository;
	}

	public function GetTwigConfig(?string $sSelectedSPFromUI=null) : array {
		return $this->GetTwigConfigInternal(Config::GetHybridConfig(), $sSelectedSPFromUI);
	}

	public function GetTwigConfigInternal(array $aCombodoHybridAuthConf, ?string $sSelectedSPFromUI=null) {
		$sSelectedSp = null;
		$aCombodoHybridAuthConf = $aCombodoHybridAuthConf['providers'] ?? [];

		$aOrg = $this->GetConfigRepository()->GetOrganizations();

		$aConfig = [
			'providers' => [],
		];

		if (sizeof($aCombodoHybridAuthConf) === 0){
			$sDefaultSpWhenNoSSoYet = is_null($sSelectedSPFromUI) ? $this->aSpList[0] : $sSelectedSPFromUI;
			$aConfig['providers'][$sDefaultSpWhenNoSSoYet] = [
					'ssoEnabled' => false,
					'ssoSP' => $sDefaultSpWhenNoSSoYet,
					'ssoSpId' => '',
					'ssoSpSecret' => '',
					'ssoUserSync' => false,
					'ssoUserOrg' => null,
			];
			$sSelectedSp = $sDefaultSpWhenNoSSoYet;
			if (! in_array($sDefaultSpWhenNoSSoYet, $this->aSpList)){
				$this->aSpList[] = $sDefaultSpWhenNoSSoYet;
			}
		} else {
			if (null !== $sSelectedSPFromUI && ! array_key_exists($sSelectedSPFromUI, $aCombodoHybridAuthConf)){
				$aConfig['providers'][$sSelectedSPFromUI] = [
					'ssoEnabled' => false,
					'ssoSP' => $sSelectedSPFromUI,
					'ssoSpId' => '',
					'ssoSpSecret' => '',
					'ssoUserSync' => false,
					'ssoUserOrg' => null,
				];
				$sSelectedSp = $sSelectedSPFromUI;
				if (! in_array($sSelectedSPFromUI, $this->aSpList)){
					$this->aSpList[] = $sSelectedSPFromUI;
				}
			}

			$sFirstEnabledProvider = null;
			foreach ($aCombodoHybridAuthConf as $sProviderName => $aProviderConf){
				if (! in_array($sProviderName, $this->aSpList)){
					$this->aSpList []= $sProviderName;
				}

				$sDefaultOrg = $aProviderConf['default_organization'] ?? '';
				if (strlen($sDefaultOrg) !== 0 && ! in_array($sDefaultOrg, $aOrg)){
					$aOrg []= $sDefaultOrg;
				}

				$aKeys = $aProviderConf['keys'] ?? [];
				$sEnabled = $aProviderConf['enabled'] ?? false;
				$aConfig['providers'][$sProviderName] = [
					'ssoEnabled' => $sEnabled,
					'ssoSP' => $sProviderName,
					'ssoSpId' => $aKeys['id'] ?? '',
					'ssoSpSecret' => $aKeys['secret'] ?? '',
					'ssoUserSync' => $aProviderConf['synchronize_user_contact'] ?? false,
					'ssoUserOrg' => $sDefaultOrg,
				];

				if (null !== $sSelectedSPFromUI && $sProviderName===$sSelectedSPFromUI){
					$sSelectedSp = $sSelectedSPFromUI;
				}

				if (is_null($sSelectedSp) && is_null($sFirstEnabledProvider) && $sEnabled){
					$sFirstEnabledProvider = $sProviderName;
				}
			}

			if (is_null($sSelectedSp)){
				if (null !== $sFirstEnabledProvider){
					$sSelectedSp = $sFirstEnabledProvider;
				} else {
					//first sp in the list
					$sSelectedSp = $this->aSpList[0];
				}
			}
		}

		sort($this->aSpList);
		$aConfig['ssoSpList'] = $this->aSpList;

		$aConfig['selectedSp'] = $sSelectedSp;
		$aConfig['org'] = $aOrg;
		return $aConfig;
	}

	/**
	 * Generate current provider conf and indicate if it is enabled
	 */
	public function GenerateHybridProviderConf(array $aFormData, array &$aProvidersConfig, string $sSelectedSP) : bool {
		$bEnabled = ($aFormData['ssoEnabled'] === 'true');

		if (array_key_exists($sSelectedSP, $aProvidersConfig)) {
			$aCurrentProviderConf = $aProvidersConfig[$sSelectedSP];
			$aCurrentProviderConf['enabled'] = $bEnabled;
			$aCurrentProviderConf['keys']['id'] = $aFormData['ssoSpId'] ?? '';
		} else {
			$aCurrentProviderConf = [
				'keys' => [
					'id' => $aFormData['ssoSpId'] ?? '',
					'secret' => '',
				],
				'enabled' => $bEnabled,
			];
		}

		$ssoSpSecret = $aFormData['ssoSpSecret'];
		if (strlen($ssoSpSecret)!==0 && $ssoSpSecret !== "●●●●●●●●●") {
			$aCurrentProviderConf['keys']['secret'] = $ssoSpSecret;
		}

		$bSynchroUser = ($aFormData['ssoUserSync'] === 'true');
		$aCurrentProviderConf['synchronize_user_contact'] = $bSynchroUser;
		if ($bSynchroUser){
			$aCurrentProviderConf['default_organization'] = $aFormData['ssoUserOrg'] ?? '';
		}

		$aProvidersConfig[$sSelectedSP] = $aCurrentProviderConf;
		return $bEnabled;
	}
}
