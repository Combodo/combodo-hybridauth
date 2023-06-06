<?php
/**
 * @copyright   Copyright (C) 2010-2021 Combodo SARL
 * @license     https://www.combodo.com/documentation/combodo-software-license.html
 *
 */

namespace Combodo\iTop\HybridAuth;

use Combodo\iTop\Application\Helper\Session;
use Combodo\iTop\HybridAuth\HybridAuthLoginExtension;
use IssueLog;
use LoginWebPage;
use MetaModel;
use utils;

/**
 *  Return from SSO Provider after a successful login
 */
require_once('../../approot.inc.php');
require_once (APPROOT.'bootstrap.inc.php');
require_once (APPROOT.'application/startup.inc.php');

// iTop 2.7 compatibility
if (!class_exists('Combodo\iTop\Application\Helper\Session')) {
	require_once 'src/Legacy/Session.php';
}

Session::Start();

$bLoginDebug = MetaModel::GetConfig()->Get('login_debug');
if ($bLoginDebug) {
	IssueLog::Info('---------------------------------');
	IssueLog::Info($_SERVER['REQUEST_URI']);
	IssueLog::Info("--> Entering Hybrid Auth landing page");
	$sSessionLog = session_id().' '.utils::GetSessionLog();
	IssueLog::Info("SESSION: $sSessionLog");
}

// Get the info from provider
$oAuthAdapter = HybridAuthLoginExtension::ConnectHybridAuth();
$oUserProfile = $oAuthAdapter->getUserProfile();
Session::Set('auth_user', $oUserProfile->email);

// Already redirected to SSO provider
Session::Unset('login_will_redirect');

$sURL = Session::Get('login_original_page');
if (empty($sURL)) {
	$sURL = utils::GetAbsoluteUrlAppRoot().'pages/UI.php?login_hybridauth=connected';
} else {
	if (strpos($sURL, '?') !== false) {
		$sURL = "$sURL&login_hybridauth=connected";
	} else {
		$sURL = "$sURL?login_hybridauth=connected";
	}
}

if ($bLoginDebug) {
	$sSessionLog = session_id().' '.utils::GetSessionLog();
	IssueLog::Info("SESSION: $sSessionLog");
}

Session::WriteClose();

// Continue Login FSM
LoginWebPage::HTTPRedirect("$sURL");
