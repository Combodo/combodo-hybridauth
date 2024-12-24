<?php

namespace Combodo\iTop\HybridAuth;

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

	/**
	 * Configure OpenID specific provider. if needed, it adds/removes allowed login mode.
	 *
	 * @param array $aProvidersConfig Complete "providers" setting for the module
	 * @param string $sSelectedSP Service provider to enable/disable
	 * @param bool $bEnabled Whether $sSelectedSP must be enabled or disabled
	 *
	 * @since 1.2.0
	 */
	public static function SetHybridConfig(array $aProvidersConfig, string $sSelectedSP, bool $bEnabled)
	{
		IssueLog::Info('SetHybridConfig', HybridAuthLoginExtension::LOG_CHANNEL,
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
	 * @param string|null $sLoginMode
	 *
	 * @return bool
	 */
	public static function IsLoginModeSupported(?string $sLoginMode) : bool
	{
		if (is_null($sLoginMode) || ! utils::StartsWith($sLoginMode, 'hybridauth-')){
			return false;
		}

		$aAllowedModes = MetaModel::GetConfig()->GetAllowedLoginTypes();
		if (! in_array($sLoginMode, $aAllowedModes)){
			IssueLog::Warning("Login mode not allowed in ".ITOP_APPLICATION_SHORT." configuration", HybridAuthLoginExtension::LOG_CHANNEL, ['sLoginMode' => $sLoginMode]);
			return false;
		}

		$aConfiguredModes = static::ListProviders();
		foreach ($aConfiguredModes as $sProvider => $bEnabled)
		{
			$sConfiguredMode = "hybridauth-$sProvider";
			if ($sConfiguredMode === $sLoginMode){
				if ($bEnabled){
					return true;
				}

				//login_mode forced and not enabled. exit to stop login automata
				IssueLog::Error("Allowed login_mode forced without being properly properly enabled. Please check combodo-hybridauth section in iTop configuration."
					, HybridAuthLoginExtension::LOG_CHANNEL, ['sLoginMode' => $sLoginMode]);
				throw new \Exception("Login modes configuration needs to be fixed.");
			}
		}

		//login_mode forced and not configured. exit to stop login automata
		IssueLog::Error("Allowed login_mode forced forced without being configured. Please check combodo-hybridauth section in iTop configuration.",
			HybridAuthLoginExtension::LOG_CHANNEL, ['sLoginMode' => $sLoginMode]);
		throw new \Exception("Login modes configuration needs to be fixed.");
	}

	public static function GetProviderConf(?string $sLoginMode) : ?array {
		$aProviderConfList = static::Get('providers');
		foreach ($aProviderConfList as $sProvider => $aCurrentConf)
		{
			$sConfiguredMode = "hybridauth-$sProvider";
			if ($sConfiguredMode === $sLoginMode){
				return $aCurrentConf;
			}
		}
		return null;
	}

	public static function GetDebug(string $sProviderName) : bool {
		if (static::Get('debug')){
			return true;
		}

		$aCurrentProviderConf = self::GetProviderConf("hybridauth-$sProviderName");
		if (is_null($aCurrentProviderConf)){
			return false;
		}

		return $aCurrentProviderConf['debug'] ?? false;
	}

	public static function IsUserSynchroEnabled(string $sLoginMode) : bool {
		if (static::Get('synchronize_user')){
			return true;
		}

		$aCurrentProviderConf = self::GetProviderConf($sLoginMode);
		if (is_null($aCurrentProviderConf)){
			return false;
		}

		return $aCurrentProviderConf['synchronize_user'] ?? false;
	}

	public static function GetSynchroProfile(string $sLoginMode) : string {
		$aCurrentProviderConf = self::GetProviderConf($sLoginMode);
		if (null !== $aCurrentProviderConf){
			$sDefautProfile = $aCurrentProviderConf['default_profile'] ?? null;
			if (utils::IsNotNullOrEmptyString($sDefautProfile)){
				return $sDefautProfile;
			}
		}

		return static::Get('default_profile', 'Portal User');
	}

	public static function IsContactSynchroEnabled(string $sLoginMode) : bool {
		if (static::Get('synchronize_contact')){
			return true;
		}

		$aCurrentProviderConf = self::GetProviderConf($sLoginMode);
		if (is_null($aCurrentProviderConf)){
			return false;
		}

		return $aCurrentProviderConf['synchronize_contact'] ?? false;
	}

	public static function GetDefaultOrg(string $sLoginMode) {
		$aCurrentProviderConf = self::GetProviderConf($sLoginMode);
		if (null !== $aCurrentProviderConf){
			$sDefaultOrg = $aCurrentProviderConf['default_organization'] ?? null;
			if (utils::IsNotNullOrEmptyString($sDefaultOrg)){
				return $sDefaultOrg;
			}
		}

		return static::Get('default_organization');
	}
}
