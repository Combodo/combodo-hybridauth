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

	public function GetTwigConfig() : array {
		return $this->GetTwigConfigInternal(Config::GetProviders());
	}

	public function GetTwigConfigInternal(array $aCombodoHybridAuthConf) {
		$aOrg = $this->GetConfigRepository()->GetOrganizations();
		$aSpList = ['Google', 'MicrosoftGraph'];

		$aConfig = [
			'providers' => [],
		];

		if (sizeof($aCombodoHybridAuthConf) === 0){
			$aConfig['providers']['Google'] = [
					'ssoEnabled' => false,
					'ssoSP' => 'Google',
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
				$aConfig['providers'][$sProviderName] = [
					'ssoEnabled' => $aProviderConf['enabled'] ?? false,
					'ssoSP' => $sProviderName,
					'ssoSpId' => $aKeys['id'] ?? '',
					'ssoSpSecret' => $aKeys['secret'] ?? '',
					'ssoUserSync' => $aProviderConf['synchronize_user_contact'] ?? false,
					'ssoUserOrg' => $sDefaultOrg,
				];
				$sAdapter = $aProviderConf['adapter'] ?? null;
				if (! is_null($sAdapter)){
					$aConfig['providers'][$sProviderName]['adapter'] = $sAdapter;
				}
			}
		}

		$aConfig['ssoSpList'] = $aSpList;
		$aConfig['org'] = $aOrg;
		return $aConfig;
	}

	public function GenerateHybridConfFromTwigVars(array $aTwigVars) : array {
		$aConf = [];
		foreach ($aTwigVars['providers'] as $sProviderName => $aProviderVars){
			$aProviderConf = [];
			if (array_key_exists('adapter', $aProviderVars)){
				$aProviderConf['adapter'] = $aProviderVars['adapter'];
			}
			$aProviderConf['keys'] = [
				'id' => $aProviderVars['ssoSpId'] ?? '',
				'secret' => $aProviderVars['ssoSpSecret'] ?? '',
			];
			$aProviderConf['enabled'] = $aProviderVars['ssoEnabled'] ?? false;
			$bSynchroUser = $aProviderVars['ssoUserSync'] ?? false;
			if ($bSynchroUser){
				$aProviderConf['synchronize_user_contact'] = $bSynchroUser;
				$aProviderConf['default_organization'] = $aProviderVars['ssoUserOrg'] ?? '';
			}
			$aConf[$sProviderName] = $aProviderConf;
		}
		return $aConf;
	}
}
