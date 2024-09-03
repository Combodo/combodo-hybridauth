<?php
/**
 * @copyright   Copyright (C) 2010-2019 Combodo SARL
 * @license     https://www.combodo.com/documentation/combodo-software-license.html
 *
 */

namespace Combodo\iTop\HybridAuth;

use AbstractLoginFSMExtension;
use Combodo\iTop\Application\Helper\Session;
use Combodo\iTop\HybridAuth\Service\HybridauthService;
use Dict;
use Exception;
use iLoginUIExtension;
use iLogoutExtension;
use IssueLog;
use LoginBlockExtension;
use LoginTwigContext;
use LoginWebPage;
use MetaModel;
use utils;

// iTop 2.7 compatibility
if (!class_exists('Combodo\iTop\Application\Helper\Session')) {
	require_once 'Legacy/Session.php';
}

class HybridAuthLoginExtension extends AbstractLoginFSMExtension implements iLogoutExtension, iLoginUIExtension
{
	const LOG_CHANNEL = "Hybridauth";

	/** @var ?HybridauthService $oHybridauthService */
	static $oHybridauthService;

	//used only for testing purpose
	public static function SetHybridauthService(?HybridauthService $oHybridauthService): void {
		self::$oHybridauthService = $oHybridauthService;
	}

	public static function GetHybridauthService(): HybridauthService {
		if (is_null(self::$oHybridauthService)){
			self::$oHybridauthService = new HybridauthService();
		}
		return self::$oHybridauthService;
	}

	/**
	 * Return the list of supported login modes for this plugin
	 *
	 * @return array of supported login modes
	 */
	public function ListSupportedLoginModes()
	{
		$aLoginModes = array();
		foreach (Config::ListProviders() as $sProvider => $bEnabled)
		{
			if ($bEnabled){
				$aLoginModes[] = "hybridauth-$sProvider";
			}
		}
		return $aLoginModes;
	}

	protected function OnStart(&$iErrorCode)
	{
		if (!Session::IsInitialized()) {
			Session::Start();
		}

		if (! Session::IsSet('login_mode')) {
			$sLoginModeFromHttp = self::GetLoginModeFromHttp();

			if (is_null($sLoginModeFromHttp)) {
				//no login_mode provided even by http
				return LoginWebPage::LOGIN_FSM_CONTINUE;
			}

			if (false === Config::IsLoginModeSupported($sLoginModeFromHttp)) {
				//login_mode provided by http not supported by current login extension
				return LoginWebPage::LOGIN_FSM_CONTINUE;
			}

			Session::Set('login_mode', $sLoginModeFromHttp);
			Session::WriteClose();
		} else if (false === Config::IsLoginModeSupported(Session::Get('login_mode'))){
			return LoginWebPage::LOGIN_FSM_CONTINUE;
		}

		Session::Unset('HYBRIDAUTH::STORAGE');
		$sOriginURL = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
		if (!utils::StartsWith($sOriginURL, utils::GetAbsoluteUrlAppRoot())) {
			// If the found URL does not start with the configured AppRoot URL
			$sOriginURL = utils::GetAbsoluteUrlAppRoot().'pages/UI.php';
		}
		Session::Set('login_original_page', $sOriginURL);

		return LoginWebPage::LOGIN_FSM_CONTINUE;
	}

	protected function OnReadCredentials(&$iErrorCode)
	{
		if (LoginWebPage::getIOnExit() === LoginWebPage::EXIT_RETURN) {
			// Not allowed if not already connected
			return LoginWebPage::LOGIN_FSM_CONTINUE;
		}

		if (!Session::IsInitialized()) {
			Session::Start();
		}

		if (!Session::IsSet('login_mode'))
		{
			$aAllowedModes = MetaModel::GetConfig()->GetAllowedLoginTypes();
			$aSupportedLoginModes = self::ListSupportedLoginModes();
			foreach ($aAllowedModes as $sLoginMode)
			{
				if (in_array($sLoginMode, $aSupportedLoginModes))
				{
					Session::Set('login_mode', $sLoginMode);
					break;
				}
			}
		}
		if (Config::IsLoginModeSupported(Session::Get('login_mode'))) {
			if (!Session::IsSet('auth_user'))
			{
				try
				{
					if (!Session::IsSet('login_will_redirect'))
					{
						// we are about to be redirected to the OpenID provider
						Session::Set('login_will_redirect', true);
					}
					else
					{
						if (empty(utils::ReadParam('login_hybridauth')))
						{
							Session::Unset('login_will_redirect');
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

	/**
	 * @return mixed|null
	 */
	public static function GetLoginModeFromHttp(){
		$sLoginMode = utils::ReadParam('login_mode', null, false, 'raw_data');
		if (!is_null($sLoginMode)){
			return $sLoginMode;
		}


		return $_REQUEST['login_mode'] ?? null;
	}

	/**
	 * @return string : iTop URL to redirect to from Service Provider callback page landing.php
	 * @throws \Hybridauth\Exception\InvalidArgumentException
	 * @throws \Hybridauth\Exception\RuntimeException
	 * @throws \Hybridauth\Exception\UnexpectedValueException
	 */
	public function HandleServiceProviderCallback() : string {
		Session::Start();

		$bLoginDebug = MetaModel::GetConfig()->Get('login_debug');
		if ($bLoginDebug) {
			IssueLog::Info('---------------------------------');
			IssueLog::Info($_SERVER['REQUEST_URI']);
			IssueLog::Info("--> Entering Hybrid Auth landing page");
			$sSessionLog = session_id().' '.utils::GetSessionLog();
			IssueLog::Info("SESSION: $sSessionLog");
		}

		//set login_mode if unset in session but provided in url
		if (! Session::IsSet('login_mode')) {
			$sLoginMode = self::GetLoginModeFromHttp();

			if (is_null($sLoginMode)) {
				IssueLog::Warning("No login_mode passed to service provider callback (landing.php)", HybridAuthLoginExtension::LOG_CHANNEL);
				throw new \Exception("No login_mode specified by service provider.");
			}
			if (Config::IsLoginModeSupported($sLoginMode)) {
				Session::Set('login_mode', $sLoginMode);
				Session::WriteClose();
			}
		}

		// Get the info from provider
		$oAuthAdapter = HybridAuthLoginExtension::ConnectHybridAuth();
		$oUserProfile = $oAuthAdapter->getUserProfile();
		IssueLog::Info("OpenID UserProfile returned by service provider", HybridAuthLoginExtension::LOG_CHANNEL,
			[
				'oUserProfile' => $oUserProfile,
			]);
		Session::Set('auth_user', $oUserProfile->email);

		// Already redirected to OpenID provider
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
		return $sURL;
	}

	protected function OnCheckCredentials(&$iErrorCode)
	{
		$sLoginMode = Session::Get('login_mode');
		if (Config::IsLoginModeSupported($sLoginMode)) {
			if (!Session::IsSet('auth_user'))
			{
				$iErrorCode = LoginWebPage::EXIT_CODE_WRONGCREDENTIALS;
				return LoginWebPage::LOGIN_FSM_ERROR;
			}
			self::DoUserProvisioning($sLoginMode);
		}
		return LoginWebPage::LOGIN_FSM_CONTINUE;
	}

	protected function OnCredentialsOK(&$iErrorCode)
	{
		if (Config::IsLoginModeSupported(Session::Get('login_mode'))) {
			$sAuthUser = Session::Get('auth_user');
			if (!LoginWebPage::CheckUser($sAuthUser))
			{
				$iErrorCode = LoginWebPage::EXIT_CODE_WRONGCREDENTIALS;
				return LoginWebPage::LOGIN_FSM_ERROR;
			}
			LoginWebPage::OnLoginSuccess($sAuthUser, 'external', Session::Get('login_mode'));
		}
		return LoginWebPage::LOGIN_FSM_CONTINUE;
	}

	protected function OnError(&$iErrorCode)
	{
		if (Config::IsLoginModeSupported(Session::Get('login_mode'))) {
			Session::Unset('HYBRIDAUTH::STORAGE');
			Session::Unset('hybridauth_count');
			if (LoginWebPage::getIOnExit() === LoginWebPage::EXIT_RETURN) {
				// Not allowed if not already connected
				return LoginWebPage::LOGIN_FSM_CONTINUE;
			}
			if ($iErrorCode != LoginWebPage::EXIT_CODE_MISSINGLOGIN)
			{
				$oLoginWebPage = new LoginWebPage();
				$oLoginWebPage->DisplayLogoutPage(false, Dict::S('HybridAuth:Error:UserNotAllowed'));
				exit(-1);
			}
		}
		return LoginWebPage::LOGIN_FSM_CONTINUE;
	}

	protected function OnConnected(&$iErrorCode)
	{
		if (Config::IsLoginModeSupported(Session::Get('login_mode'))) {
			Session::Set('can_logoff', true);
			return LoginWebPage::CheckLoggedUser($iErrorCode);
		}
		return LoginWebPage::LOGIN_FSM_CONTINUE;
	}

	private static function GetProviderName()
	{
		$sLoginMode = Session::Get('login_mode', '');
		$sProviderName = substr($sLoginMode, strlen('hybridauth-'));
		if (false === $sProviderName){
			\IssueLog::Error("login_mode provided not OpenID compliant", HybridAuthLoginExtension::LOG_CHANNEL, ['$sLoginMode' => $sLoginMode]);
			throw new \Exception("login_mode provided not OpenID compliant");
		}
		return $sProviderName;
	}

	/**
	 * Execute all actions to log out properly
	 */
	public function LogoutAction()
	{
		if (utils::StartsWith(Session::Get('login_mode'), 'hybridauth-'))
		{
			$oAuthAdapter = self::ConnectHybridAuth();
			// Does not redirect...
			// and actually just clears the session variable,
			// almost useless we can log again without any further user interaction
			// At least it disconnects from iTop
			$oAuthAdapter->disconnect();
		}
	}

	private function DoUserProvisioning(string $sLoginMode)
	{
		try
		{
			if (! Config::IsUserSynchroEnabled($sLoginMode))
			{
				return; // No automatic User provisioning
			}
			$sEmail = Session::Get('auth_user');
			if (LoginWebPage::FindUser($sEmail, false))
			{
				return; // User already present
			}
			$oAuthAdapter = HybridAuthLoginExtension::ConnectHybridAuth();
			$oUserProfile = $oAuthAdapter->getUserProfile();
			IssueLog::Info("OpenID UserProfile returned by service provider", HybridAuthLoginExtension::LOG_CHANNEL,
			[
				'oUserProfile' => $oUserProfile,
			]
		);
			if ($oUserProfile == null)
			{
				return; // No data available for this user
			}
			$oPerson = LoginWebPage::FindPerson($sEmail);
			if ($oPerson == null)
			{
				if (! Config::IsContactSynchroEnabled($sLoginMode))
				{
					return; // No automatic Contact provisioning
				}

				// Create the person
				$sFirstName = $oUserProfile->firstName;
				$sLastName = $oUserProfile->lastName;
				$sOrganization = Config::GetDefaultOrg($sLoginMode);
				$aAdditionalParams = array('phone' => $oUserProfile->phone);
				IssueLog::Info("OpenID Person provisioning", HybridAuthLoginExtension::LOG_CHANNEL,
					[
						'first_name' => $sFirstName,
						'last_name' => $sLastName,
						'email' => $sEmail,
						'org' => $sOrganization,
						'addition_params' => $aAdditionalParams,
					]
				);
				$oPerson = LoginWebPage::ProvisionPerson($sFirstName, $sLastName, $sEmail, $sOrganization, $aAdditionalParams);
			}
			$sProfile = Config::GetSynchroProfile($sLoginMode);
			$aProfiles = array($sProfile);
			IssueLog::Info("OpenID User provisioning", HybridAuthLoginExtension::LOG_CHANNEL, ['login' => $sEmail, 'profiles' => $aProfiles, 'contact_id' => $oPerson->GetKey()]);
			LoginWebPage::ProvisionUser($sEmail, $oPerson, $aProfiles);
		}
		catch (Exception $e)
		{
			IssueLog::Error($e->getMessage());
		}
	}

	public function GetTwigContext()
	{
		$oLoginContext = new LoginTwigContext();
		$oLoginContext->SetLoaderPath(utils::GetAbsoluteModulePath('combodo-hybridauth').'view');
		$oLoginContext->AddCSSFile(utils::GetAbsoluteUrlModulesRoot().'combodo-hybridauth/css/hybridauth.css');

		$aData = array();
		$aAllowedModes = MetaModel::GetConfig()->GetAllowedLoginTypes();
		foreach (Config::Get('providers') as $sProvider => $aProviderData) {
			// If provider not allowed -> next
			if (!in_array("hybridauth-$sProvider", $aAllowedModes)) {
				continue;
			}
			$sFaImage = null;
			$sIconUrl = null;
			if (isset($aProviderData['icon_url'])) {
				$sIconUrl = $aProviderData['icon_url'];
			}

			if (! is_null($sIconUrl) && ! empty(trim($sIconUrl))){
				if (! utils::StartsWith($sIconUrl, "http") && strpos($sIconUrl, '.')!=0){
					$sIconUrl = utils::GetAbsoluteUrlAppRoot() . $sIconUrl;
				} else {
					$sFaImage = $sIconUrl;
				}
			} else {
				$sAdapterClass = $sProvider;
				if (array_key_exists('adapter', $aProviderData) && ! empty($aProviderData['adapter'])) {
					try {
						$sAdapterClass = $aProviderData['adapter'];
						$oReflection = new \ReflectionClass($sAdapterClass);
						$sAdapterClass = $oReflection->getShortName();
					} catch (\ReflectionException $e) {
						\IssueLog::Warning("Unknown SSO adapter class $sProvider", null, [$sAdapterClass]);
					}
				}

				$sFaImage = utils::StartsWith($sAdapterClass, "Microsoft") ? "fa-microsoft" : "fa-$sAdapterClass";
			}

			\IssueLog::Debug("login button settings", null,
				[
					'sProvider' => $sProvider,
					'sFaImage' => $sFaImage,
					'sIconUrl' => $sIconUrl,
				]
			);

			$sLabel = isset($aProviderData['label']) ? Dict::Format($aProviderData['label'], $sProvider) : Dict::Format('HybridAuth:Login:SignIn', $sProvider);
			$sTooltip = isset($aProviderData['tooltip']) ? Dict::Format($aProviderData['tooltip'], $sProvider) : Dict::Format('HybridAuth:Login:SignInTooltip', $sProvider);
			$aData[] = array(
				'sLoginMode' => "hybridauth-$sProvider",
				'sLabel'     => $sLabel,
				'sTooltip'   => $sTooltip,
				'sFaImage'   => $sFaImage,
				'sIconUrl'   => $sIconUrl,
			);
		}

		$oBlockExtension = new LoginBlockExtension('hybridauth_sso_button.html.twig', $aData);

		$oLoginContext->AddBlockExtension('login_sso_buttons', $oBlockExtension);

		return $oLoginContext;
	}

	/**
	 * If not connected to the OpenID provider, redirect and exit.
	 * If already connected, just get the info from the OpenID provider and return.
	 *
	 * @return \Hybridauth\Adapter\AdapterInterface
	 *
	 * @throws \Hybridauth\Exception\InvalidArgumentException
	 * @throws \Hybridauth\Exception\RuntimeException
	 * @throws \Hybridauth\Exception\UnexpectedValueException
	 */
	public static function ConnectHybridAuth()
	{
		$sName = self::GetProviderName();
		try{
			return self::GetHybridauthService()->authenticate($sName);
		} catch(\Exception $e){
			\IssueLog::Error("Fail to authenticate with provider name '$sName'", HybridAuthLoginExtension::LOG_CHANNEL, ['exception' => $e->getMessage(), 'provider_name' => $sName]);
			throw $e;
		}
	}
}
