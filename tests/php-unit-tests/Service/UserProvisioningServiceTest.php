<?php

namespace Combodo\iTop\HybridAuth\Test;

/**
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @backupGlobals disabled
 *
 */

use Combodo\iTop\HybridAuth\Service\ProvisioningService;
use Combodo\iTop\Test\UnitTest\ItopDataTestCase;
use Config;
use DBObjectSearch;
use DBObjectSet;
use Hybridauth\User\Profile;
use LoginWebPage;
use MetaModel;
use Person;
use UserExternal;
use Combodo\iTop\HybridAuth\HybridProvisioningAuthException;

require_once __DIR__ . "/AbstractHybridauthTest.php";

class UserProvisioningServiceTest extends AbstractHybridauthTest
{
	public function testDoUserProvisioning_UserCreationOK() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Portal user']);
		MetaModel::GetConfig()->SetDefaultLanguage('EN US');

		$sEmail = $this->sUniqId."@test.fr";
		$oPerson = $this->CreatePersonByEmail($sEmail);

		self::assertNull(LoginWebPage::FindUser($sEmail));

		$oReturnedCreatedUser = ProvisioningService::GetInstance()->DoUserProvisioning($this->sLoginMode, $sEmail , $oPerson, new Profile());

		/** @var UserExternal $oFoundUser */
		$oFoundUser = LoginWebPage::FindUser($sEmail);
		self::assertNotNull($oFoundUser);
		$this->assertEquals($oFoundUser->GetKey(), $oReturnedCreatedUser->GetKey(), "User creation OK");

		self::assertEquals($sEmail, $oFoundUser->Get('login'));
		self::assertEquals($oPerson->GetKey(), $oFoundUser->Get('contactid'));
		self::assertEquals('EN US', $oFoundUser->Get('language'));
		$this->assertUserProfiles($oFoundUser, ['Portal user']);
	}

	public function testDoUserProvisioning_UserUpdateOKWithAnotherProfile() {
		$sEmail = $this->sUniqId."@test.fr";
		$oPerson = $this->CreatePersonByEmail($sEmail);
		$oFoundUser = $this->CreateExternalUserWithProfilesAndAllowedOrgs($sEmail, ['Portal user']);
		self::assertEquals('FR FR', $oFoundUser->Get('language'));

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'refresh_existing_user', true);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Configuration Manager']);
		MetaModel::GetConfig()->SetDefaultLanguage('EN US');

		self::assertNotNull(LoginWebPage::FindUser($sEmail));
		ProvisioningService::GetInstance()->DoUserProvisioning($this->sLoginMode, $sEmail , $oPerson, new Profile());

		/** @var UserExternal $oFoundUser */
		$oFoundUser = LoginWebPage::FindUser($sEmail);
		self::assertNotNull($oFoundUser);
		self::assertEquals($sEmail, $oFoundUser->Get('login'));
		self::assertEquals($oPerson->GetKey(), $oFoundUser->Get('contactid'));
		self::assertEquals('FR FR', $oFoundUser->Get('language'));
		$this->assertUserProfiles($oFoundUser, ['Configuration Manager']);
	}

	public function testDoUserProvisioning_UserAlreadyExistAndNoUpdateConfigured() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Configuration Manager']);

		$sEmail = $this->sUniqId."@test.fr";
		$oPerson = $this->CreatePersonByEmail($sEmail);
		$this->CreateExternalUserWithProfilesAndAllowedOrgs($sEmail, ['Portal user']);

		$oReturnedUser = ProvisioningService::GetInstance()->DoUserProvisioning($this->sLoginMode, $sEmail , $oPerson, new Profile());
		$this->assertUserProfiles($oReturnedUser, ['Portal user']);
		self::assertEquals(0, $oReturnedUser->Get('contactid'));
	}
}
