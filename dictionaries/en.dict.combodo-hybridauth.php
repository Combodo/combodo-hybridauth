<?php
/**
 * Localized data
 *
 * @copyright   Copyright (C) 2013 XXXXX
 * @license     http://opensource.org/licenses/AGPL-3.0
 */

Dict::Add('EN US', 'English', 'English', array(
	'HybridAuth:Error:UserNotAllowed' => 'User not allowed',
    'HybridAuth:Login:SignIn' => 'Sign in with %1$s',
    'HybridAuth:Login:SignInTooltip' => 'Click here to authenticate yourself with %1$s',

	'Menu:SSOConfig' => 'SSO Configuration',
	'Menu:SSOConfig+' => 'Configure Single Sign-On',
	'UI:SSOConfig' => 'SSO Configuration',
	'combodo-hybridauth/Operation:Main/Title' => 'SSO Configuration',
	'combodo-hybridauth:SSOSettings' => 'Single Sign-On Settings',
	'combodo-hybridauth:SSOConnection' => 'SSO Connection',
	'combodo-hybridauth:SSOEnabled' => 'SSO Enabled',
	'combodo-hybridauth:ServiceProvider' => 'Service Provider',
	'combodo-hybridauth:ServiceProviderToolTip' => 'Service Provider that will handle authentication outside iTop',
	'combodo-hybridauth:ServiceProviderId' => 'Service Provider Id',
	'combodo-hybridauth:ServiceProviderSecret' => 'Service Provider Secret',
	'combodo-hybridauth:Save' => 'Save',
	'combodo-hybridauth:TestConnection' => 'Save And Test Connection',
	'combodo-hybridauth:TestConnectionToolTip' => 'Warning: you will be disconnected from iTop before authenticating via SSO',
	'combodo-hybridauth:UserSyncEnabling' => 'User Synchronization Via SSO',
	'combodo-hybridauth:SSOUserSync' => 'Enable User Provisioning Via SSO',
	'combodo-hybridauth:SSOUserSyncToolTip' => 'With this option enabled, missing user will be automatically created inside iTop after successfull SSO authentication (on Service Provider side). Otherwise you have to manually create them inside iTop to let them log in.',
	'combodo-hybridauth:SSOUserOrganization' => 'Default Organization',
	'combodo-hybridauth:SSOUserOrganizationToolTip' => 'Users automatically created after successfull SSO authentication (on Service Provider side) will belong to this organization',
	'combodo-hybridauth:LandingUrlTitle' => 'SSO Callback URL',
	'combodo-hybridauth:LandingUrlMessage' => 'Copy and configure this URL on Service Provider side:<p><a>%1$s</a></p>'
));
