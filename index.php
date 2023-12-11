<?php
use Combodo\iTop\HybridAuth\Controller\SSOConfigController;

require_once(APPROOT . 'application/startup.inc.php');
require_once(APPROOT . '/application/loginwebpage.class.inc.php');
LoginWebPage::DoLoginEx(null, false, LoginWebPage::EXIT_HTTP_401); // Check user rights and exits with "401 Not authorized" if not already logged in
session_write_close();

$oController = new SSOConfigController(__DIR__ . '/templates', 'combodo-hybridauth');
$oController->SetDefaultOperation('Main');
$oController->SetMenuId('SSOConfig');

$oController->HandleOperation();
