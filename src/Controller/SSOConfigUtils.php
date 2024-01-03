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

	public function GetTwigConfig(?string $sSelectedSPFromUI) : array {
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

	public function GenerateHybridConfFromTwigVars(array $aTwigVars) : array {
		$aProviderConf = [];
		foreach ($aTwigVars['providers'] as $sProviderName => $aProviderVars){
			$aCurrentProviderConf = [];
			if (array_key_exists('adapter', $aProviderVars)){
				$aCurrentProviderConf['adapter'] = $aProviderVars['adapter'];
			}
			$aCurrentProviderConf['keys'] = [
				'id' => $aProviderVars['ssoSpId'] ?? '',
				'secret' => $aProviderVars['ssoSpSecret'] ?? '',
			];
			$aCurrentProviderConf['enabled'] = $aProviderVars['ssoEnabled'] ?? false;
			$bSynchroUser = $aProviderVars['ssoUserSync'] ?? false;
			if ($bSynchroUser){
				$aCurrentProviderConf['synchronize_user_contact'] = $bSynchroUser;
				$aCurrentProviderConf['default_organization'] = $aProviderVars['ssoUserOrg'] ?? '';
			}
			$aProviderConf[$sProviderName] = $aCurrentProviderConf;
		}
		return ['providers' => $aProviderConf];
	}
}
