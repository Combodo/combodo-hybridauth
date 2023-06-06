<?php


namespace Combodo\iTop\HybridAuth;


use MetaModel;
use utils;

class Config
{
	public static function GetHybridConfig()
	{
		$aConfig = array();
        $aConfig['callback'] = utils::GetAbsoluteUrlModulesRoot().'combodo-hybridauth/landing.php';
		$aConfig['providers'] = self::Get('providers');
		return $aConfig;
	}

	public static function Get($sName)
	{
		$sValue = MetaModel::GetModuleSetting('combodo-hybridauth', $sName, []);
		return $sValue;
	}

	public static function GetProviders()
	{
		$aLoginModules = array();
		$aProviders = self::Get('providers');
		foreach ($aProviders as $sName => $aProvider)
		{
			if (isset($aProvider['enabled']) && $aProvider['enabled'])
			{
				$aLoginModules[] = $sName;
			}
		}
		return $aLoginModules;
	}
}
