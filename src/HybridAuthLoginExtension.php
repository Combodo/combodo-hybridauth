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
use LoginWebPage;
use MetaModel;
use UserRights;
use utils;

class HybridAuthLoginExtension extends AbstractLoginFSMExtension
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
			$aLoginModes[] = "hybridauth/$sProvider";
		}
		return $aLoginModes;
	}

	protected function OnStart(&$iErrorCode)
	{
		unset($_SESSION['HYBRIDAUTH::STORAGE']);
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
				$aConfig = array();
				$aConfig['callback'] = utils::GetAbsoluteUrlAppRoot().'pages/UI.php';
				$aConfig['providers'] = Config::Get('providers');
				try
				{
					if (Config::Get('debug'))
					{
						$oLogger = new Logger(Logger::DEBUG, APPROOT.'log/hybridauth.log');
					}
					else
					{
						$oLogger = null;
					}
					$oHybridauth = new Hybridauth($aConfig, null, null, $oLogger);

					//Then we can proceed and sign in
					//Attempt to authenticate users with a provider by name
					$oAdapter = $oHybridauth->authenticate(self::GetProviderName());

					$oUserProfile = $oAdapter->getUserProfile();
					$_SESSION['auth_user'] = $oUserProfile->email;
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
		}
		return LoginWebPage::LOGIN_FSM_RETURN_CONTINUE;
	}

	protected function OnCredentialsOK(&$iErrorCode)
	{
		if (utils::StartsWith($_SESSION['login_mode'], 'hybridauth/'))
		{
			$sAuthUser = $_SESSION['auth_user'];
			if (!UserRights::CheckCredentials($sAuthUser, '', $_SESSION['login_mode'], 'external'))
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
			echo "<p>".Dict::S('HybridAuth:Error:UserNotAllowed')."</p>";
			exit();
		}
		return LoginWebPage::LOGIN_FSM_RETURN_CONTINUE;
	}

	protected function OnConnected(&$iErrorCode)
	{
		if (utils::StartsWith($_SESSION['login_mode'], 'hybridauth/'))
		{
			$_SESSION['can_logoff'] = false;
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
	 * {@inheritDoc}
	 * @see iLoginExtension::BeforeLogin()
	 */
	public function BeforeLogin()
	{
		if ((utils::ReadParam('login_mode', '') === '') && utils::ReadParam('code', false) && utils::ReadParam('state', false))
		{
			try
			{
				$oLogger = new Logger(Logger::DEBUG, APPROOT.'log/hybridauth.log');
				$oGitHub = new \Hybridauth\Provider\GitHub($this->GetConfig(), null, null, $oLogger);
				
				$oGitHub->authenticate(); // May redirect, or pass through if the user is already authenticated (SSO)
				$oUserProfile = $oGitHub->getUserProfile();
				$sAuthUser = $oUserProfile->email;
				return new LoginSuccess($sAuthUser, $this->GetLoginClass(), 'hybrid');
			}
			catch (\Exception $e)
			{
				return null;
			}
		}
		return null;
	}
	
	/**
	 * {@inheritDoc}
	 * @see iLoginExtension::DisplayLoginForm()
	 */
	public function DisplayLoginForm(LoginWebPage $param, $sLoginType, $bFailedLogin)
	{
		return false;
	}
	
	/**
	 * {@inheritDoc}
	 * @see iLoginExtension::OnCredentialNotValid()
	 */
	public function OnCredentialNotValid($login, $sAuthPwd, $sLoginMode, $sAuthentication)
	{
		return null;
	}
	
	/**
	 * {@inheritDoc}
	 * @see iLoginExtension::OnCredentialValid()
	 */
	public function OnCredentialValid($sAuthUser, $sAuthentication, $sLoginMode)
	{
		return null;
	}
	
	/**
	 * {@inheritDoc}
	 * @see iLoginExtension::ResetSession()
	 */
	public function ResetSession()
	{
	}

	public function OnLogin($sLoginMode)
	{
		if ($sLoginMode == 'hybrid')
		{
			try
			{
				$oLogger = new Logger(Logger::DEBUG, APPROOT.'data/hybridauth.log');
				$oGitHub = new \Hybridauth\Provider\GitHub($this->GetConfig(), null, null, $oLogger);
				
				$oGitHub->authenticate(); // May redirect, or pass through if the user is already authenticated (SSO)
				$oUserProfile = $oGitHub->getUserProfile();
				$sAuthUser = $oUserProfile->email;
				return new LoginSuccess($sAuthUser, $this->GetLoginClass(), $sLoginMode);
			}
			catch (\Exception $e)
			{
				IssueLog::Info('HybridAuthLoginExtension::OnLogin, exception: ' . $e->getMessage());
			}
		}
		return false;
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
	
	public function CanLogOff($sLoginMode)
	{
		if ($sLoginMode == 'hybrid')
		{
			return false;
		}
		return null;
	}
	
	public function OnLogOff($sLoginMode)
	{
		if ($sLoginMode == 'hybrid')
		{
			$oLogger = new Logger(Logger::DEBUG, APPROOT.'data/hybridauth.log');
			$oGitHub = new \Hybridauth\Provider\GitHub($this->GetConfig(), null, null, $oLogger);
			
			$oGitHub->disconnect(); // Does not redirect... and actually just clears the session variable, almost useless we can log again without any further user interaction
		}
	}
}