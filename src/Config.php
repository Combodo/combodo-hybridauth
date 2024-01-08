<?php

namespace Combodo\iTop\HybridAuth;

use Combodo\iTop\Application\Helper\Session;
use Combodo\iTop\HybridAuth\Controller\SSOConfigController;
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
		IssueLog::Info('SetHybridConfig', SSOConfigController::LOG_CHANNEL,
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

	public static function ListProviders()
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
			IssueLog::Warning("SSO mode not allowed in iTop configuration", SSOConfigController::LOG_CHANNEL, ['sLoginMode' => $sLoginMode]);
			return false;
		}

		$aConfiguredModes = Config::ListProviders();
		foreach ($aConfiguredModes as $sProvider => $bEnabled)
		{
			$sConfiguredMode = "hybridauth-$sProvider";
			if ($sConfiguredMode === $sLoginMode){
				if ($bEnabled){
					return true;
				} else {
					//login_mode forced and not enabled. exit to stop login automata
					IssueLog::Error("Allowed login_mode forced without being properly properly enabled. Please check combodo-hybridauth section in iTop configuration."
						, SSOConfigController::LOG_CHANNEL, ['sLoginMode' => $sLoginMode]);
					throw new \Exception("SSO configuration needs to be fixed.");
				}
			}
		}

		//login_mode forced and not configured. exit to stop login automata
		IssueLog::Error("Allowed login_mode forced forced without being configured. Please check combodo-hybridauth section in iTop configuration.",
			SSOConfigController::LOG_CHANNEL, ['sLoginMode' => $sLoginMode]);
		throw new \Exception("SSO configuration needs to be fixed.");
	}

	public static function GetProviderConf(?string $sLoginMode) : ?array {
		$aProviderConfList = self::Get('providers');
		foreach ($aProviderConfList as $sProvider => $aCurrentConf)
		{
			$sConfiguredMode = "hybridauth-$sProvider";
			if ($sConfiguredMode === $sLoginMode){
				return $aCurrentConf;
			}
		}
		return null;
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

	public static function UserSynchroEnabled(?string $sLoginMode) : bool {
		if (Config::Get('synchronize_user')){
			return true;
		}
		if (Config::Get('synchronize_contact')){
			return true;
		}

		$aCurrentProviderConf = self::GetProviderConf($sLoginMode);
		if (is_null($aCurrentProviderConf)){
			return false;
		}

		return $aCurrentProviderConf['synchronize_user_contact'] ?? false;
	}

	public static function GetDefaultOrg(?string $sLoginMode) {
		$aCurrentProviderConf = self::GetProviderConf($sLoginMode);
		if (null !== $aCurrentProviderConf){
			$sDefaultOrg = $aCurrentProviderConf['default_organization'] ?? null;
			if (null !== $sDefaultOrg){
				return $sDefaultOrg;
			}
		}

		return Config::Get('default_organization');
	}
}
