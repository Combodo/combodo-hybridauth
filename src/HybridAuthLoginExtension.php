<?php
/**
 * @copyright   Copyright (C) 2010-2019 Combodo SARL
 * @license     https://www.combodo.com/documentation/combodo-software-license.html
 *
 */

namespace Combodo\iTop\Extension\HybridAuth;

use AbstractLoginFSMExtension;
use Dict;
use Exception;
use Hybridauth\Hybridauth;
use Hybridauth\Logger\Logger;
use iLogoutExtension;
use IssueLog;
use LoginWebPage;
use MetaModel;
use UserRights;
use utils;

class HybridAuthLoginExtension extends AbstractLoginFSMExtension implements iLogoutExtension
{
    private $oUserProfile;

	/**
	 * Return the list of supported login modes for this plugin
	 *
	 * @return array of supported login modes
	 */
	public function ListSupportedLoginModes()
	{
		$aLoginModes = array();
		foreach (Config::GetProviders() as $sProvider)
		{
			$aLoginModes[] = "hybridauth/$sProvider";
		}
		return $aLoginModes;
	}

	protected function OnStart(&$iErrorCode)
	{
		unset($_SESSION['HYBRIDAUTH::STORAGE']);
		$sOriginURL = $_SESSION['OriginalPage'] = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
		if (!utils::StartsWith($sOriginURL, utils::GetAbsoluteUrlAppRoot()))
		{
			// If the found URL does not start with the configured AppRoot URL
			$sOriginURL = utils::GetAbsoluteUrlAppRoot().'pages/UI.php';
		}
		$_SESSION['OriginalPage'] = $sOriginURL;
		return LoginWebPage::LOGIN_FSM_RETURN_CONTINUE;
	}

	protected function OnReadCredentials(&$iErrorCode)
	{
		if (!isset($_SESSION['login_mode']))
		{
			$aAllowedModes = MetaModel::GetConfig()->GetAllowedLoginTypes();
			$aSupportedLoginModes = self::ListSupportedLoginModes();
			foreach ($aAllowedModes as $sLoginMode)
			{
				if (in_array($sLoginMode, $aSupportedLoginModes))
				{
					$_SESSION['login_mode'] = $sLoginMode;
					break;
				}
			}
		}
		if (utils::StartsWith($_SESSION['login_mode'], 'hybridauth/'))
		{
			if (!isset($_SESSION['auth_user']))
			{
				try
				{
					if (!isset($_SESSION['auth_user']))
					{
						if (!isset($_SESSION['hybridauth_count']))
						{
							$_SESSION['hybridauth_count'] = 1;
						}
						else
						{
							$_SESSION['hybridauth_count'] += 1;
						}
						if ($_SESSION['hybridauth_count'] > 2)
						{
							unset($_SESSION['hybridauth_count']);
							$iErrorCode = LoginWebPage::EXIT_CODE_MISSINGLOGIN;
							return LoginWebPage::LOGIN_FSM_RETURN_ERROR;
						}
					}

					$oLogger = (Config::Get('debug')) ? new Logger(Logger::DEBUG, APPROOT.'log/hybridauth.log') : null;
					$aConfig = Config::GetHybridConfig();
					$oHybridauth = new Hybridauth($aConfig, null, null, $oLogger);

					//Then we can proceed and sign in
					//Attempt to authenticate users with a provider by name
					$oAdapter = $oHybridauth->authenticate(self::GetProviderName());

                    $this->oUserProfile = $oAdapter->getUserProfile();
					$_SESSION['auth_user'] = $this->oUserProfile->email;
					unset($_SESSION['hybridauth_count']);
				}
				catch (Exception $e)
				{
					$iErrorCode = LoginWebPage::EXIT_CODE_WRONGCREDENTIALS;
					return LoginWebPage::LOGIN_FSM_RETURN_ERROR;
				}
			}
		}

		return LoginWebPage::LOGIN_FSM_RETURN_CONTINUE;
	}

	protected function OnCheckCredentials(&$iErrorCode)
	{
		if (utils::StartsWith($_SESSION['login_mode'], 'hybridauth/'))
		{
			if (!isset($_SESSION['auth_user']))
			{
				$iErrorCode = LoginWebPage::EXIT_CODE_WRONGCREDENTIALS;
				return LoginWebPage::LOGIN_FSM_RETURN_ERROR;
			}
			self::DoUserProvisioning();
		}
		return LoginWebPage::LOGIN_FSM_RETURN_CONTINUE;
	}

	protected function OnCredentialsOK(&$iErrorCode)
	{
		if (utils::StartsWith($_SESSION['login_mode'], 'hybridauth/'))
		{
			$sAuthUser = $_SESSION['auth_user'];
			if (!LoginWebPage::CheckUser($sAuthUser, ''))
			{
				$iErrorCode = LoginWebPage::EXIT_CODE_WRONGCREDENTIALS;
				return LoginWebPage::LOGIN_FSM_RETURN_ERROR;
			}
			LoginWebPage::OnLoginSuccess($sAuthUser, 'external', $_SESSION['login_mode']);
		}
		return LoginWebPage::LOGIN_FSM_RETURN_CONTINUE;
	}

	protected function OnError(&$iErrorCode)
	{
		if (utils::StartsWith($_SESSION['login_mode'], 'hybridauth/'))
		{
			unset($_SESSION['HYBRIDAUTH::STORAGE']);
			unset($_SESSION['hybridauth_count']);
			if ($iErrorCode != LoginWebPage::EXIT_CODE_MISSINGLOGIN)
			{
				$oLoginWebPage = new LoginWebPage();
				$oLoginWebPage->DisplayLogoutPage(false, Dict::S('HybridAuth:Error:UserNotAllowed'));
				exit();
			}
		}
		return LoginWebPage::LOGIN_FSM_RETURN_CONTINUE;
	}

	protected function OnConnected(&$iErrorCode)
	{
		if (utils::StartsWith($_SESSION['login_mode'], 'hybridauth/'))
		{
			$_SESSION['can_logoff'] = true;
			return LoginWebPage::CheckLoggedUser($iErrorCode);
		}
		return LoginWebPage::LOGIN_FSM_RETURN_CONTINUE;
	}

	private static function GetProviderName()
	{
		$sLoginMode = $_SESSION['login_mode'];
		$sProviderName = substr($sLoginMode, strlen('hybridauth/'));
		return $sProviderName;
	}

	/**
	 * Execute all actions to log out properly
	 */
	public function LogoutAction()
	{
		if (utils::StartsWith($_SESSION['login_mode'], 'hybridauth/'))
		{
			$oLogger = (Config::Get('debug')) ? new Logger(Logger::DEBUG, APPROOT.'log/hybridauth.log') : null;
			$aConfig = Config::GetHybridConfig();
			$oHybridauth = new Hybridauth($aConfig, null, null, $oLogger);
			$oAdapter = $oHybridauth->authenticate(self::GetProviderName());
			$oAdapter->disconnect(); // Does not redirect... and actually just clears the session variable, almost useless we can log again without any further user interaction
		}
	}

	public function GetSocialButtons()
	{
		return array(
			array(
				'login_mode' => 'hybrid',
				'label' => 'Sign-in with GitHub',
				'twig' => 'github_button.twig',
				'tooltip' => 'Here is a HybridAuth specific tooltip',
			),
		);
	}

	private function DoUserProvisioning()
    {
        if (Config::Get('synchronize_user'))
        {
            try
            {
                $sEmail = $_SESSION['auth_user'];
                if (LoginWebPage::FindUser($sEmail, false))
                {
                    return;
                }
                if ($this->oUserProfile == null)
                {
                    return;
                }
                $oPerson = LoginWebPage::FindPerson($sEmail);
                if ($oPerson == null)
                {
                    if (!Config::Get('synchronize_contact'))
                    {
                        return;
                    }
                    // Create the person
                    $sFirstName = $this->oUserProfile->firstName;
                    $sLastName = $this->oUserProfile->lastName;
                    $sOrganization = Config::Get('default_organization');
                    $aAdditionalParams = array('phone' => $this->oUserProfile->phone);
                    $oPerson = LoginWebPage::ProvisionPerson($sFirstName, $sLastName, $sEmail, $sOrganization, $aAdditionalParams);
                }
                $sProfile = Config::Get('default_profile');
                $aProfiles = array($sProfile);
                LoginWebPage::ProvisionUser($sEmail, $oPerson, $aProfiles);
            }
            catch (Exception $e)
            {
                IssueLog::Error($e->getMessage());
            }
        }
    }
}
