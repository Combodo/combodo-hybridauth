<?php

namespace Combodo\iTop\HybridAuth\Test\Provider;

use Combodo\iTop\Application\Helper\Session;
use Hybridauth\Adapter\OAuth2;
use Hybridauth\User\Profile;
use LoginWebPage;
use IssueLog;
use utils;
use Hybridauth\HttpClient;

/**
 * used for hybridauth iTop extension tests
 */
class ServiceProviderMock extends OAuth2 {
	public static function GetFileConfPath() : string {
		return __DIR__ . '/ServiceProviderMock.json';
	}

	public function authenticate() {
		if (! Session::IsSet('auth_user')){
			$aData = $this->GetData();
			\IssueLog::Info("ServiceProvider->authenticate data to pass to OpenID:", null, $aData);

			$sEmail = $aData['profile_email'] ?? null;
			if (! is_null($sEmail)) {
				Session::Set('auth_user', $sEmail);
				Session::Unset('login_will_redirect');
				Session::Set('login_hybridauth', 'connected');
			}
		}

		return true;
	}

	public function getUserProfile() : Profile {
		$aData = $this->GetData();
		\IssueLog::Info("ServiceProvider->getUserProfile data to pass to OpenID:", null, $aData);
		$class = new \ReflectionClass(Profile::class);

		$oProfile = new Profile();
		$sProfileFields = ['firstName', 'lastName', 'email', 'phone'];
		foreach ($sProfileFields as $sField) {
			$sSessionFieldKey = 'profile_'.$sField;
			$sValue = $aData[$sSessionFieldKey] ?? null;
			if (!is_null($sValue)) {
				$property = $class->getProperty($sField);
				$property->setAccessible(true);
				$property->setValue($oProfile, $sValue);
			}
		}

		return $oProfile;
	}

	private function GetData() : array {
		clearstatcache();
		if (! is_file(ServiceProviderMock::GetFileConfPath())){
			return [];
		}

		$aData = json_decode(file_get_contents(ServiceProviderMock::GetFileConfPath()), true);
		if (! is_array($aData)){
			return [];
		}

		return $aData;
	}


	public function disconnect() {}


}
