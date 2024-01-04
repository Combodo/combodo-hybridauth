<?php

namespace Combodo\iTop\HybridAuth\Controller;

use Combodo\iTop\HybridAuth\Config;
use Combodo\iTop\HybridAuth\Repository\SssConfigRepository;

class SSOConfigUtils {
	/** @var SssConfigRepository $oSssConfigRepository */
	private $oSssConfigRepository;

	public function __construct(){
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
		$aSpList = [ 'Google', 'MicrosoftGraph' ];

		$aConfig = [
			'providers' => [],
		];

		if (sizeof($aCombodoHybridAuthConf) === 0){
			$sDefaultSpWhenNoSSoYet = is_null($sSelectedSPFromUI) ? 'Google' : $sSelectedSPFromUI;
			$aConfig['providers'][$sDefaultSpWhenNoSSoYet] = [
					'ssoEnabled' => false,
					'ssoSP' => $sDefaultSpWhenNoSSoYet,
					'ssoSpId' => '',
					'ssoSpSecret' => '',
					'ssoUserSync' => false,
					'ssoUserOrg' => null,
			];
		} else {
			foreach ($aCombodoHybridAuthConf as $sProviderName => $aProviderConf){
				if (! in_array($sProviderName, $aSpList)){
					$aSpList []= $sProviderName;
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
				} else if (is_null($sSelectedSPFromUI) && is_null($sSelectedSp) && $sEnabled){
					$sSelectedSp = $sProviderName;
				}
				$sAdapter = $aProviderConf['adapter'] ?? null;
				if (! is_null($sAdapter)){
					$aConfig['providers'][$sProviderName]['adapter'] = $sAdapter;
				}
			}
		}

		if (is_null($sSelectedSp)){
			if (null !== $sSelectedSPFromUI){
				//force selected SP from UI and add it as well
				$aConfig['providers'][$sSelectedSPFromUI] = [
					'ssoEnabled' => false,
					'ssoSP' => $sSelectedSPFromUI,
					'ssoSpId' => '',
					'ssoSpSecret' => '',
					'ssoUserSync' => false,
					'ssoUserOrg' => null,
				];
				$sSelectedSp = $sSelectedSPFromUI;
			} else {
				//first sp in the list
				$sSelectedSp = $aSpList[0];
			}
		}

		$aConfig['ssoSpList'] = $aSpList;
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
		if (strlen($ssoSpSecret)!==0 || $ssoSpSecret !== "●●●●●●●●●") {
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
