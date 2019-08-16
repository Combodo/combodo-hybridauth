<?php


namespace Combodo\iTop\Extension\HybridAuth;


use MetaModel;

class Config
{
	public static function Get($sName)
	{
		$sValue = MetaModel::GetModuleSetting('combodo-hybridauth', $sName, '');
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