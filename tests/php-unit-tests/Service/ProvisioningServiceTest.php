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
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', null);
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

	public function testDoProvisioning_CreationOK_UsingIdpFields(){
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', true);
		MetaModel::GetConfig()->SetDefaultLanguage('EN US');
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id1" => "Change Approver", "sp_id2" => ["Administrator", "Configuration Manager"]]);

		$sOrgName1 = $this->CreateOrgAndGetName();
		$sOrgName2 = $this->CreateOrgAndGetName();
		$sOrgName3 = $this->CreateOrgAndGetName();
		$this->InitializeGroupsToOrgs($this->sLoginMode, ["sp_id1" => $sOrgName1, "sp_id2" => [$sOrgName2, $sOrgName3]]);

		$sDefaultOrgName = $this->sUniqId;
		$oOrg = $this->CreateOrganization($sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', null);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', null);

		$sEmail = $this->sUniqId."@test.fr";
		self::assertNull(LoginWebPage::FindPerson($sEmail));
		self::assertNull(LoginWebPage::FindUser($sEmail));

		$oProfileWithMostFields = new Profile();
		$oProfileWithMostFields->data['groups']= ['sp_id1', 'sp_id2'];
		$oProfileWithMostFields->data['allowed_orgs']= ['sp_id1', 'sp_id2'];
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
		$this->assertUserProfiles($oFoundUser, ['Change Approver', 'Administrator', 'Configuration Manager']);
		$this->assertAllowedOrg($oFoundUser, [$sDefaultOrgName, $sOrgName1, $sOrgName2, $sOrgName3]);
	}

	public function testDoProvisioning_RefreshOK(){
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', true);
		MetaModel::GetConfig()->SetDefaultLanguage('EN US');
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'refresh_existing_users', true);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'refresh_existing_contact', true);

		$sEmail = $this->sUniqId."@test.fr";
		$sDefaultOrgName = $this->sUniqId;
		$oOrg = $this->CreateOrganization($sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Portal user']);
		list($oReturnedCreatedPerson, $oReturnedCreatedUser) = ProvisioningService::GetInstance()->DoProvisioning($this->sLoginMode, $sEmail , new Profile());
		self::assertNotNull(LoginWebPage::FindPerson($sEmail));
		self::assertNotNull(LoginWebPage::FindUser($sEmail));

		$sDefaultOrgName2 = "anotherorg_" . $this->sUniqId;
		$oOrg2 = $this->CreateOrganization($sDefaultOrgName2);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName2);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Configuration Manager']);

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

	public function testDoProvisioning_RefreshOK_UsingIdpFields(){
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', true);
		MetaModel::GetConfig()->SetDefaultLanguage('EN US');
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'refresh_existing_users', true);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'refresh_existing_contact', true);

		$sEmail = $this->sUniqId."@test.fr";
		$sDefaultOrgName = $this->sUniqId;
		$oOrg = $this->CreateOrganization($sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Portal user']);
		list($oReturnedCreatedPerson, $oReturnedCreatedUser) = ProvisioningService::GetInstance()->DoProvisioning($this->sLoginMode, $sEmail , new Profile());
		self::assertNotNull(LoginWebPage::FindPerson($sEmail));
		self::assertNotNull(LoginWebPage::FindUser($sEmail));

		$sDefaultOrgName2 = "anotherorg_" . $this->sUniqId;
		$oOrg2 = $this->CreateOrganization($sDefaultOrgName2);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName2);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Configuration Manager']);
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id1" => "Change Approver", "sp_id2" => ["Administrator", "Configuration Manager"]]);

		$sOrgName1 = $this->CreateOrgAndGetName();
		$sOrgName2 = $this->CreateOrgAndGetName();
		$sOrgName3 = $this->CreateOrgAndGetName();
		$this->InitializeGroupsToOrgs($this->sLoginMode, ["sp_id1" => $sOrgName1, "sp_id2" => [$sOrgName2, $sOrgName3]]);

		$oProfileWithMostFields = new Profile();
		$oProfileWithMostFields->data['groups']= ['sp_id1', 'sp_id2'];
		$oProfileWithMostFields->data['allowed_orgs']= ['sp_id1', 'sp_id2'];
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
		$this->assertUserProfiles($oFoundUser, ['Change Approver', 'Administrator', 'Configuration Manager']);
		$this->assertAllowedOrg($oFoundUser, [$sDefaultOrgName2, $sOrgName1, $sOrgName2, $sOrgName3]);
	}
}
