<?php

namespace Combodo\iTop\HybridAuth;

use Combodo\iTop\HybridAuth\Service\HybridauthService;
use MetaModel;
use utils;
use IssueLog;

class Config
{
	public static function GetHybridConfig()
	{
		$aConfig = array();
        $aConfig['callback'] = utils::GetAbsoluteUrlModulesRoot().'combodo-hybridauth/landing.php';
		$aConfig['providers'] = self::Get('providers');
		return $aConfig;
	}

	public static function SetHybridConfig($aProvidersConfig, $sSelectedSP, $bEnabled)
	{
		IssueLog::Info('SetHybridConfig', null,
			[
				'aProviderConf' => $aProvidersConfig,
				'sSelectedSP' => $sSelectedSP,
				'bEnabled' => $bEnabled]
		);

		utils::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', $aProvidersConfig);

		$sLoginMode = "hybridauth-$sSelectedSP";
		$aAllowedLoginTypes = MetaModel::GetConfig()->GetAllowedLoginTypes();
		if (in_array($sLoginMode, $aAllowedLoginTypes)){
			if (! $bEnabled){
				//remove login mode
				foreach ($aAllowedLoginTypes as $i => $sCurrentLoginMode){
					if ($sCurrentLoginMode === $sLoginMode){
						unset($aAllowedLoginTypes[$i]);
						break;
					}
				}
				MetaModel::GetConfig()->SetAllowedLoginTypes($aAllowedLoginTypes);
			}
		} else {
			if ($bEnabled){
				//add login mode
				$aAllowedLoginTypes[]=$sLoginMode;
				MetaModel::GetConfig()->SetAllowedLoginTypes($aAllowedLoginTypes);
			}
		}
	}

	public static function Get($sName, $default=[])
	{
		return MetaModel::GetModuleSetting('combodo-hybridauth', $sName, $default);
	}

	public static function GetProviders()
	{
		$aLoginModules = [];
		$aProviders = self::Get('providers');
		foreach ($aProviders as $sName => $aProvider)
		{
			$aLoginModules[$sName] = $aProvider['enabled'] ?? false;
		}
		return $aLoginModules;
	}

	/**
	 * @param string $sLoginMode
	 *
	 * @return bool
	 */
	public static function IsLoginModeSupported($sLoginMode)
	{
		if (! utils::StartsWith($sLoginMode, 'hybridauth-')){
			return false;
		}

		$aAllowedModes = MetaModel::GetConfig()->GetAllowedLoginTypes();
		if (! in_array($sLoginMode, $aAllowedModes)){
			IssueLog::Warning("SSO mode not allowed in iTop configuration", null, ['sLoginMode' => $sLoginMode]);
			return false;
		}

		$aConfiguredModes = Config::GetProviders();
		foreach ($aConfiguredModes as $sProvider => $bEnabled)
		{
			$sConfiguredMode = "hybridauth-$sProvider";
			if ($sConfiguredMode === $sLoginMode){
				if ($bEnabled){
					return true;
				} else {
					//login_mode forced and not enabled. exit to stop login automata
					IssueLog::Error("Allowed login_mode forced without being properly properly enabled. Please check combodo-hybridauth section in iTop configuration.", null, ['sLoginMode' => $sLoginMode]);
					throw new \Exception("SSO configuration needs to be fixed.");
				}
			}
		}

		//login_mode forced and not configured. exit to stop login automata
		IssueLog::Error("Allowed login_mode forced forced without being configured. Please check combodo-hybridauth section in iTop configuration.", null, ['sLoginMode' => $sLoginMode]);
		throw new \Exception("SSO configuration needs to be fixed.");
	}

	public static function GetProposedSpList($oHybridauthService=null) : array {
		$aList = self::Get('ui-proposed-providers', null);
		if (null !== $aList){
			return $aList;
		}

		if (is_null($oHybridauthService)){
			$oHybridauthService = new HybridauthService();
		}
		return $oHybridauthService->ListProviders();
	}
}
