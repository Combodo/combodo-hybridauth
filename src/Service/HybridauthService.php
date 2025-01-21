<?php

namespace Combodo\iTop\HybridAuth\Service;

use Combodo\iTop\HybridAuth\Config;
use Combodo\iTop\HybridAuth\HybridAuthLoginExtension;
use Hybridauth\Adapter\AdapterInterface;
use Hybridauth\Hybridauth;
use Hybridauth\Logger\Logger;
use IssueLog;

class HybridauthService {
	public function __construct() {
	}

	/**
	 * @param string $sName
	 *
	 * @return \Hybridauth\Adapter\AdapterInterface
	 * @throws \Hybridauth\Exception\InvalidArgumentException
	 * @throws \Hybridauth\Exception\RuntimeException
	 * @throws \Hybridauth\Exception\UnexpectedValueException
	 */
	public function authenticate(string $sName)
	{
		$aConfig = Config::GetHybridConfig();
		$sOauthClassPath = Config::Get('oauth_test_class_path', null);
		if (! is_null($sOauthClassPath)){
			IssueLog::Info("loading service provider class (from conf oauth_test_class_path): " . $sOauthClassPath, HybridAuthLoginExtension::LOG_CHANNEL);
			require_once $sOauthClassPath;
		}

		$bDebug = Config::GetDebug($sName);
		$oLogger = ($bDebug) ? new Logger(Logger::DEBUG, APPROOT.'log/hybridauth.log') : null;
		if ($bDebug){
			\IssueLog::Info("Conf passed to HybdridAuth", HybridAuthLoginExtension::LOG_CHANNEL, [ 'conf' => $aConfig ]);
		}
		$oHybridAuth = new Hybridauth($aConfig, null, null, $oLogger);
		$oAuthAdapter = $oHybridAuth->authenticate($sName);
		return $oAuthAdapter;
	}
}
