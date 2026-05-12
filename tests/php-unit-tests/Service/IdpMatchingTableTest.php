<?php

namespace Combodo\iTop\HybridAuth\Test;

use Combodo\iTop\HybridAuth\Service\IdpMatchingTable;
use Combodo\iTop\Test\UnitTest\ItopTestCase;
use Hybridauth\User\Profile;

class IdpMatchingTableTest extends ItopTestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		$this->RequireOnceItopFile('env-production/combodo-hybridauth/vendor/autoload.php');
		$this->RequireOnceItopFile('env-production/combodo-oauth2-client/vendor/autoload.php');
	}

	public static function GetIdpFieldValueProvider()
	{
		return [
			'field no found' => [
				'aData' => [],
				'serviceProviderKey' => 'org',
				'expected' => null,
			],
			'string found, search string' => [
				'aData' => ['org' => "org1"],
				'serviceProviderKey' => 'org',
				'expected' => "org1",
			],
			'array found, search string' => [
				'aData' => ['org' => ["org1"]],
				'serviceProviderKey' => 'org',
				'expected' => ["org1"],
			],
			'string found, empty search dict' => [
				'aData' => ['org' => []],
				'serviceProviderKey' => ['org' => "first"],
				'expected' => null,
			],
			'string found, search dict' => [
				'aData' => ['org' => ["first" => "org1"]],
				'serviceProviderKey' => ['org' => "first"],
				'expected' => "org1",
			],
			'string not found, search dict' => [
				'aData' => ['org' => ["2nd" => "org1"]],
				'serviceProviderKey' => ['org' => "first"],
				'expected' => null,
			],
			'string found, search array' => [
				'aData' => ['org' => ["org2", "org1"]],
				'serviceProviderKey' => ['org' => [1]],
				'expected' => "org1",
			],
			'string not found, search array' => [
				'aData' => ['org' => ["org2", "org1"]],
				'serviceProviderKey' => ['org' => [2]],
				'expected' => null,
			],
			'string found, search array2' => [
				'aData' => ['org' => ["org2", ["org1"]]],
				'serviceProviderKey' => ['org' => [1 => [0]]],
				'expected' => "org1",
			],
		];
	}

	/**
	 * @dataProvider GetIdpFieldValueProvider
	 */
	public function testGetIdpFieldValue(array $aData, $serviceProviderKey, $expected)
	{
		$oUserProfile = new Profile();
		$oUserProfile->data = $aData;
		$res = IdpMatchingTable::GetIdpFieldValue($oUserProfile, $serviceProviderKey);
		$this->assertEquals($expected, $res);
	}
}
