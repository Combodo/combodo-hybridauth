<?php

namespace Combodo\iTop\HybridAuth\Service;

use Combodo\iTop\HybridAuth\Config;
use Combodo\iTop\HybridAuth\Controller\SSOConfigController;
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
			IssueLog::Info("loading service provider class (from conf oauth_test_class_path): " . $sOauthClassPath, SSOConfigController::LOG_CHANNEL);
			require_once $sOauthClassPath;
		}

		$bDebug = Config::Get('debug');
		$oLogger = ($bDebug) ? new Logger(Logger::DEBUG, APPROOT.'log/hybridauth.log') : null;
		if ($bDebug){
			\IssueLog::Info("Conf passed to HybdridAuth", SSOConfigController::LOG_CHANNEL, [ 'conf' => $aConfig ]);
		}
		$oHybridAuth = new Hybridauth($aConfig, null, null, $oLogger);
		$oAuthAdapter = $oHybridAuth->authenticate($sName);
		return $oAuthAdapter;
	}

	public function ListProviders() : array {
		$aList = [];
		$sPath = __DIR__ . '/../../vendor/hybridauth/hybridauth/src/Provider/';
		$oFilesystemIterator = new \FilesystemIterator($sPath);
		/** @var \SplFileInfo $file */
		foreach ($oFilesystemIterator as $file) {
			if (!$file->isDir()) {
				$sProvider = strtok($file->getFilename(), '.');
				$sClass = sprintf('Hybridauth\\Provider\\%s', $sProvider);
				$oReflectionClass = new \ReflectionClass($sClass);
				if ($oReflectionClass->implementsInterface(AdapterInterface::class)) {
					$aList [] = $sProvider;
				}
			}
		}
		return $aList;
	}
}
