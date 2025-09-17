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
use Hybridauth\User\Profile;
use LoginWebPage;
use MetaModel;
use Person;
use UserExternal;

require_once __DIR__ . "/AbstractHybridauthTest.php";

class ProvisioningServiceTest extends AbstractHybridauthTest
{
	const USE_TRANSACTION = false;

	public function testDoProvisioning_CreationOK(){
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', true);
		MetaModel::GetConfig()->SetDefaultLanguage('EN US');

		$sDefaultOrgName = $this->sUniqId;
		$oOrg = $this->CreateOrganization($sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', null);

		$sEmail = $this->sUniqId."@test.fr";
		self::assertNull(LoginWebPage::FindPerson($sEmail));
		self::assertNull(LoginWebPage::FindUser($sEmail));

		$oProfileWithMostFields = new Profile();
		$oProfileWithMostFields->email = $this->sUniqId."@test.fr";
		$oProfileWithMostFields->firstName = 'firstNameA';
		$oProfileWithMostFields->lastName = 'lastNameA';
		$oProfileWithMostFields->phone = '456978';
		list($oReturnedCreatedPerson, $oReturnedCreatedUser) = ProvisioningService::GetInstance()->DoProvisioning($this->sLoginMode, $sEmail , $oProfileWithMostFields);
		$oFoundPerson = LoginWebPage::FindPerson($sEmail);
		self::assertNotNull($oFoundPerson);
		$this->assertEquals($oFoundPerson->GetKey(), $oReturnedCreatedPerson->GetKey(), "Person creation OK");

		self::assertEquals($oProfileWithMostFields->firstName, $oFoundPerson->Get('first_name'));
		self::assertEquals($oProfileWithMostFields->lastName, $oFoundPerson->Get('name'));
		self::assertEquals($sEmail, $oFoundPerson->Get('email'));
		self::assertEquals($oOrg->GetKey(), $oFoundPerson->Get('org_id'));
		self::assertEquals($oProfileWithMostFields->phone, $oFoundPerson->Get('phone'));

		/** @var UserExternal $oFoundUser */
		$oFoundUser = LoginWebPage::FindUser($sEmail);
		self::assertNotNull($oFoundUser);
		$this->assertEquals($oFoundUser->GetKey(), $oReturnedCreatedUser->GetKey(), "User creation OK");

		self::assertEquals($sEmail, $oFoundUser->Get('login'));
		self::assertEquals($oFoundPerson->GetKey(), $oFoundUser->Get('contactid'));
		self::assertEquals('EN US', $oFoundUser->Get('language'));
		$this->assertUserProfiles($oFoundUser, ['Portal user']);
	}

	public function testDoProvisioning_RefreshOK(){
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', true);
		MetaModel::GetConfig()->SetDefaultLanguage('EN US');
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'refresh_existing_users', true);

		$sEmail = $this->sUniqId."@test.fr";
		$sDefaultOrgName = $this->sUniqId;
		$oOrg = $this->CreateOrganization($sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', ['Portal user']);
		list($oReturnedCreatedPerson, $oReturnedCreatedUser) = ProvisioningService::GetInstance()->DoProvisioning($this->sLoginMode, $sEmail , new Profile());
		self::assertNotNull(LoginWebPage::FindPerson($sEmail));
		self::assertNotNull(LoginWebPage::FindUser($sEmail));

		$sDefaultOrgName2 = "anotherorg_" . $this->sUniqId;
		$oOrg2 = $this->CreateOrganization($sDefaultOrgName2);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName2);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', ['Configuration Manager']);

		$oProfileWithMostFields = new Profile();
		$oProfileWithMostFields->email = $sEmail;
		$oProfileWithMostFields->firstName = 'firstNameA';
		$oProfileWithMostFields->lastName = 'lastNameA';
		$oProfileWithMostFields->phone = '456978';
		list($oReturnedCreatedPerson, $oReturnedCreatedUser) = ProvisioningService::GetInstance()->DoProvisioning($this->sLoginMode, $sEmail , $oProfileWithMostFields);

		$oFoundPerson = LoginWebPage::FindPerson($sEmail);
		self::assertNotNull($oFoundPerson);
		$this->assertEquals($oFoundPerson->GetKey(), $oReturnedCreatedPerson->GetKey(), "Person refresh OK");

		self::assertEquals($oProfileWithMostFields->firstName, $oFoundPerson->Get('first_name'));
		self::assertEquals($oProfileWithMostFields->lastName, $oFoundPerson->Get('name'));
		self::assertEquals($sEmail, $oFoundPerson->Get('email'));
		self::assertEquals($oOrg2->GetKey(), $oFoundPerson->Get('org_id'));
		self::assertEquals($oProfileWithMostFields->phone, $oFoundPerson->Get('phone'));

		/** @var UserExternal $oFoundUser */
		$oFoundUser = LoginWebPage::FindUser($sEmail);
		self::assertNotNull($oFoundUser);
		$this->assertEquals($oFoundUser->GetKey(), $oReturnedCreatedUser->GetKey(), "User refresh OK");

		self::assertEquals($sEmail, $oFoundUser->Get('login'));
		self::assertEquals($oFoundPerson->GetKey(), $oFoundUser->Get('contactid'));
		self::assertEquals('EN US', $oFoundUser->Get('language'));
		$this->assertUserProfiles($oFoundUser, ['Configuration Manager']);
	}
}
