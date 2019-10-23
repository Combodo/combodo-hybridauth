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
use iLoginUIExtension;
use iLogoutExtension;
use IssueLog;
use LoginBlockData;
use LoginTwigData;
use LoginWebPage;
use MetaModel;
use utils;

class HybridAuthLoginExtension extends AbstractLoginFSMExtension implements iLogoutExtension, iLoginUIExtension
{
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
			$aLoginModes[] = "hybridauth-$sProvider";
		}
		return $aLoginModes;
	}

	protected function OnStart(&$iErrorCode)
	{
		unset($_SESSION['HYBRIDAUTH::STORAGE']);
		$sOriginURL = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
		if (!utils::StartsWith($sOriginURL, utils::GetAbsoluteUrlAppRoot()))
		{
			// If the found URL does not start with the configured AppRoot URL
			$sOriginURL = utils::GetAbsoluteUrlAppRoot().'pages/UI.php';
		}
		$_SESSION['login_original_page'] = $sOriginURL;
		return LoginWebPage::LOGIN_FSM_CONTINUE;
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
		if (utils::StartsWith($_SESSION['login_mode'], 'hybridauth-'))
		{
			if (!isset($_SESSION['auth_user']))
			{
				try
				{
                    if (!isset($_SESSION['login_will_redirect']))
                    {
                        // we are about to be redirected to the SSO provider
                        $_SESSION['login_will_redirect'] = true;
                    }
                    else
                    {
                        if (empty(utils::ReadParam('login_hybridauth')))
                        {
                            unset($_SESSION['login_will_redirect']);
                            $iErrorCode = LoginWebPage::EXIT_CODE_MISSINGLOGIN;
                            return LoginWebPage::LOGIN_FSM_ERROR;
                        }
                    }
                    // Proceed and sign in (redirect to provider and exit)
                    self::ConnectHybridAuth();
				}
				catch (Exception $e)
				{
					$iErrorCode = LoginWebPage::EXIT_CODE_WRONGCREDENTIALS;
					return LoginWebPage::LOGIN_FSM_ERROR;
				}
			}
		}

		return LoginWebPage::LOGIN_FSM_CONTINUE;
	}

	protected function OnCheckCredentials(&$iErrorCode)
	{
		if (utils::StartsWith($_SESSION['login_mode'], 'hybridauth-'))
		{
			if (!isset($_SESSION['auth_user']))
			{
				$iErrorCode = LoginWebPage::EXIT_CODE_WRONGCREDENTIALS;
				return LoginWebPage::LOGIN_FSM_ERROR;
			}
			self::DoUserProvisioning();
		}
		return LoginWebPage::LOGIN_FSM_CONTINUE;
	}

	protected function OnCredentialsOK(&$iErrorCode)
	{
		if (utils::StartsWith($_SESSION['login_mode'], 'hybridauth-'))
		{
			$sAuthUser = $_SESSION['auth_user'];
			if (!LoginWebPage::CheckUser($sAuthUser))
			{
				$iErrorCode = LoginWebPage::EXIT_CODE_WRONGCREDENTIALS;
				return LoginWebPage::LOGIN_FSM_ERROR;
			}
			LoginWebPage::OnLoginSuccess($sAuthUser, 'external', $_SESSION['login_mode']);
		}
		return LoginWebPage::LOGIN_FSM_CONTINUE;
	}

	protected function OnError(&$iErrorCode)
	{
		if (utils::StartsWith($_SESSION['login_mode'], 'hybridauth-'))
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
		return LoginWebPage::LOGIN_FSM_CONTINUE;
	}

	protected function OnConnected(&$iErrorCode)
	{
		if (utils::StartsWith($_SESSION['login_mode'], 'hybridauth-'))
		{
			$_SESSION['can_logoff'] = true;
			return LoginWebPage::CheckLoggedUser($iErrorCode);
		}
		return LoginWebPage::LOGIN_FSM_CONTINUE;
	}

	private static function GetProviderName()
	{
		$sLoginMode = $_SESSION['login_mode'];
		$sProviderName = substr($sLoginMode, strlen('hybridauth-'));
		return $sProviderName;
	}

	/**
	 * Execute all actions to log out properly
	 */
	public function LogoutAction()
	{
		if (utils::StartsWith($_SESSION['login_mode'], 'hybridauth-'))
		{
            $oAuthAdapter = self::ConnectHybridAuth();
            // Does not redirect...
            // and actually just clears the session variable,
            // almost useless we can log again without any further user interaction
            // At least it disconnects from iTop
			$oAuthAdapter->disconnect();
		}
	}

	private function DoUserProvisioning()
    {
        try
        {
            if (!Config::Get('synchronize_user'))
            {
                return; // No automatic User provisioning
            }
            $sEmail = $_SESSION['auth_user'];
            if (LoginWebPage::FindUser($sEmail, false))
            {
                return; // User already present
            }
            $oAuthAdapter = HybridAuthLoginExtension::ConnectHybridAuth();
            $oUserProfile = $oAuthAdapter->getUserProfile();
            if ($oUserProfile == null)
            {
                return; // No data available for this user
            }
            $oPerson = LoginWebPage::FindPerson($sEmail);
            if ($oPerson == null)
            {
                if (!Config::Get('synchronize_contact'))
                {
                    return; // No automatic Contact provisioning
                }
                // Create the person
                $sFirstName = $oUserProfile->firstName;
                $sLastName = $oUserProfile->lastName;
                $sOrganization = Config::Get('default_organization');
                $aAdditionalParams = array('phone' => $oUserProfile->phone);
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

    /**
     * @return LoginTwigData
     */
    public function GetTwigBlockData()
    {
        $sPath = APPROOT.'env-'.utils::GetCurrentEnvironment().'/combodo-hybridauth/view';
        $oLoginData = new LoginTwigData(array(), $sPath);

        $aData = array();
        $aAllowedModes = MetaModel::GetConfig()->GetAllowedLoginTypes();
        foreach (Config::GetProviders() as $sProvider)
        {
            if (in_array("hybridauth-$sProvider", $aAllowedModes))
            {
                $aData[] = array(
                    'sLoginMode' => "hybridauth-$sProvider",
                    'sLabel' => Dict::Format('HybridAuth:Login:SignIn', $sProvider),
                    'sTooltip' => Dict::Format('HybridAuth:Login:SignInTooltip', $sProvider),
                    'sFaImage' => "fa-$sProvider",
                );
            }
        }

        $oBlockData = new LoginBlockData('hybridauth_sso_button.html.twig', $aData);

        $oLoginData->AddBlockData('login_sso_buttons', $oBlockData);
        $oLoginData->AddBlockData('css', new LoginBlockData('hybridauth_css.css.twig'));

        return $oLoginData;
    }

    /**
     * If not connected to the SSO provider, redirect and exit.
     * If already connected, just get the info from the SSO provider and return.
     *
     * @return \Hybridauth\Adapter\AdapterInterface
     *
     * @throws \Hybridauth\Exception\InvalidArgumentException
     * @throws \Hybridauth\Exception\RuntimeException
     * @throws \Hybridauth\Exception\UnexpectedValueException
     */
    public static function ConnectHybridAuth()
    {
        $oLogger = (Config::Get('debug')) ? new Logger(Logger::DEBUG, APPROOT.'log/hybridauth.log') : null;
        $aConfig = Config::GetHybridConfig();
        $oHybridAuth = new Hybridauth($aConfig, null, null, $oLogger);
        $oAuthAdapter = $oHybridAuth->authenticate(self::GetProviderName());
        return $oAuthAdapter;
    }
}
