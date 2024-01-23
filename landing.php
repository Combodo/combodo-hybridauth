<?php
/**
 * @copyright   Copyright (C) 2010-2021 Combodo SARL
 * @license     https://www.combodo.com/documentation/combodo-software-license.html
 *
 */

namespace Combodo\iTop\HybridAuth;

use Combodo\iTop\HybridAuth\HybridAuthLoginExtension;
use IssueLog;
use LoginWebPage;
use MetaModel;
use utils;

/**
 *  Return from OpenID Provider after a successful login
 */
require_once('../../approot.inc.php');
require_once (APPROOT.'bootstrap.inc.php');
require_once (APPROOT.'application/startup.inc.php');

$oHybridAuthLoginExtension= new HybridAuthLoginExtension();
try{
	$sURL = $oHybridAuthLoginExtension->HandleServiceProviderCallback();

	// Continue Login FSM
	LoginWebPage::HTTPRedirect("$sURL");
} catch(\Exception $e){
	//already logged
	$oLoginWebPage = new LoginWebPage();
	$oLoginWebPage->DisplayLogoutPage(false, $e->getMessage());
}
