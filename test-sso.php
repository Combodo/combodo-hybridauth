<?php
require_once('../../approot.inc.php');
require_once(APPROOT . 'application/startup.inc.php');
require_once(APPROOT . '/application/loginwebpage.class.inc.php');

$sLoginMode = \Combodo\iTop\HybridAuth\HybridAuthLoginExtension::GetLoginModeFromHttp();
if (is_null($sLoginMode)){
	$ssoUiUrl = utils::GetAbsoluteUrlModulePage(self::EXTENSION_NAME, 'index.php');
	LoginWebPage::HTTPRedirect($ssoUiUrl);
}

$aPluginList = LoginWebPage::GetLoginPluginList('iLogoutExtension');

$bLoginDebug = MetaModel::GetConfig()->Get('login_debug');
if ($bLoginDebug)
{
	IssueLog::Info("---------------------------------");
	if (isset($sAuthUser))
	{
		IssueLog::Info("--> Logout user: [$sAuthUser]");
	}
	else
	{
		IssueLog::Info("--> Logout");
	}
	$sSessionLog = session_id().' '.utils::GetSessionLog();
	IssueLog::Info("SESSION: $sSessionLog");
}

/** @var iLogoutExtension $oLogoutExtension */
foreach ($aPluginList as $oLogoutExtension)
{
	if ($bLoginDebug)
	{
		$sCurrSessionLog = session_id().' '.utils::GetSessionLog();
		if ($sCurrSessionLog != $sSessionLog)
		{
			$sSessionLog = $sCurrSessionLog;
			IssueLog::Info("SESSION: $sSessionLog");
		}
		IssueLog::Info("Logout call: ".get_class($oLogoutExtension));
	}

	$oLogoutExtension->LogoutAction();
}

if ($bLoginDebug)
{
	$sCurrSessionLog = session_id().' '.utils::GetSessionLog();
	if ($sCurrSessionLog != $sSessionLog)
	{
		$sSessionLog = $sCurrSessionLog;
		IssueLog::Info("SESSION: $sSessionLog");
	}
	IssueLog::Info("--> Display logout page");
}

LoginWebPage::ResetSession(true);
$sURL = utils::GetAbsoluteUrlAppRoot() . "/pages/UI.php?login_mode=$sLoginMode";
LoginWebPage::HTTPRedirect($sURL);
