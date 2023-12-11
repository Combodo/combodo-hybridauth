<?php

namespace Combodo\iTop\HybridAuth\Controller;

class SSOConfigUtils {
	public function __construct(){
	}

	public function GetConfigAsArray() {
		return [
			'ssoEnabled' => false,
			'ssoSP' => [
				'Google' => true,
				'MicrosoftGraph' => false,
			],
			'ssoSpId' => '',
			'ssoSpSecret' => '',
			'ssoUserSync' => false,
			'ssoUserOrg' => null,
		];
	}
}
