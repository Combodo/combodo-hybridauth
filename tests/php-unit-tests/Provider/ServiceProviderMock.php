<?php

namespace Combodo\iTop\HybridAuth\Test\Provider;

use Combodo\iTop\Application\Helper\Session;
use Hybridauth\Adapter\OAuth2;
use Hybridauth\User\Profile;
use LoginWebPage;
use IssueLog;
use utils;

/**
 * used for hybridauth iTop extension tests
 */
class ServiceProviderMock extends OAuth2 {
	public static function GetFileConfPath() : string {
		return __DIR__ . '/ServiceProviderMock.json';
	}

	public function authenticate() {
		/*$sLoginMode = Session::Get('login_mode');

		$sUri = $_SERVER['REQUEST_URI'] ?? '';
		IssueLog::Info("URI: $sUri");
		if (strpos($sUri, 'login_hybridauth=connected') !== false) {
			return;
		}

		if (! strpos($sUri, 'landing.php') !== false) {
			$sUrl = utils::GetAbsoluteUrlAppRoot()."env-production/combodo-hybridauth/landing.php?login_mode=$sLoginMode";
			IssueLog::Info("SSO redirection callback: $sUrl", null, ['REQUEST_URI' => $_SERVER['REQUEST_URI']]);
			LoginWebPage::HTTPRedirect($sUrl);
		}*/

		if (! Session::IsSet('auth_user')){
			$sEmail = $this->GetValue('profile_email');
			if (! is_null($sEmail)) {
				Session::Set('auth_user', $sEmail);
				Session::Unset('login_will_redirect');
				Session::Set('login_hybridauth', 'connected');
			}
		}
	}

	public function getUserProfile() : Profile {
		$class = new \ReflectionClass(Profile::class);

		$oProfile = new Profile();

		$sProfileFields = ['firstName', 'lastName', 'email', 'phone'];
		foreach ($sProfileFields as $sField) {
			$sSessionFieldKey = 'profile_'.$sField;
			if (Session::IsSet($sSessionFieldKey)) {
				$property = $class->getProperty($sField);
				$property->setAccessible(true);
				$property->setValue(Session::Get($sSessionFieldKey));
			}
		}

		return $oProfile;
	}

	private function GetValue($sKey) : ?string {
		if (!is_file(ServiceProviderMock::GetFileConfPath())){
			return null;
		}

		$aData = json_decode(file_get_contents(ServiceProviderMock::GetFileConfPath()), true);
		if (! is_array($aData)){
			return null;
		}

		return $aData[$sKey] ?? null;
	}

	public function disconnect() {}


}