<?php


namespace Combodo\iTop\HybridAuth\Test\Service;

use Combodo\iTop\HybridAuth\Service\HybridauthService;
use Combodo\iTop\Test\UnitTest\ItopTestCase;

class HybridauthServiceTest extends ItopTestCase {
	protected function setUp(): void {
		parent::setUp();

		$this->RequireOnceItopFile('env-production/combodo-hybridauth/vendor/autoload.php');
		$this->RequireOnceItopFile('env-production/combodo-oauth2-client/vendor/autoload.php');

	}
}
