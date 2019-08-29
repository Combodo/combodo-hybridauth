<?php
/**
 * @copyright   Copyright (C) 2010-2019 Combodo SARL
 * @license     https://www.combodo.com/documentation/combodo-software-license.html
 *
 */

namespace Combodo\iTop\Extension\HybridAuth;

use LoginWebPage;
use utils;

/**
 *  Return from SSO Provider after a successful login
 */
require_once('../../approot.inc.php');
require_once (APPROOT.'bootstrap.inc.php');
require_once (APPROOT.'application/startup.inc.php');

// Get the info from provider
$oAuthAdapter = HybridAuthLoginExtension::ConnectHybridAuth();
$oUserProfile = $oAuthAdapter->getUserProfile();
$_SESSION['auth_user'] = $oUserProfile->email;

// Already redirected to SSO provider
unset($_SESSION['login_will_redirect']);

$sURL = $_SESSION['login_original_page'];
if (empty($sURL))
{
    $sURL = utils::GetAbsoluteUrlAppRoot().'pages/UI.php';
}

// Continue Login FSM
LoginWebPage::HTTPRedirect("$sURL?login_hybridauth=connected");
