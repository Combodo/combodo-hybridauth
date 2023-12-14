<?php

namespace Combodo\iTop\HybridAuth\Service;

use Combodo\iTop\HybridAuth\Config;
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
		$oLogger = (Config::Get('debug')) ? new Logger(Logger::DEBUG, APPROOT.'log/hybridauth.log') : null;
		$oHybridAuth = new Hybridauth($aConfig, null, null, $oLogger);
		$oAuthAdapter = $oHybridAuth->authenticate($sName);
		return $oAuthAdapter;
	}
}
