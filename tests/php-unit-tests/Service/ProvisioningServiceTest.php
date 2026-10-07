<?php

namespace Combodo\iTop\HybridAuth\Test;

/**
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @backupGlobals disabled
 *
 */

use Combodo\iTop\HybridAuth\HybridProvisioningAuthException;
use Combodo\iTop\HybridAuth\Service\ProvisioningService;
use Hybridauth\User\Profile;
use LoginWebPage;
use MetaModel;
use Person;
use UserExternal;

require_once __DIR__."/AbstractTestHybridauth.php";

class ProvisioningServiceTest extends AbstractTestHybridauth
{
	public const USE_TRANSACTION = false;

	//nominal usecase
	public function testDoProvisioningCreationOKUsingDefaultConfiguredOrgAndProfiles()
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', true);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_user', true);
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
		[$oReturnedCreatedPerson, $oReturnedCreatedUser] = ProvisioningService::GetInstance()->DoProvisioning($this->sLoginMode, $sEmail, $oProfileWithMostFields);

		/** @var ?\Person $oFoundPerson */
		$oFoundPerson = LoginWebPage::FindPerson($sEmail);
		self::assertNotNull($oFoundPerson);
		$this->assertEquals($oFoundPerson->GetKey(), $oReturnedCreatedPerson->GetKey(), "Person creation OK");

		self::assertEquals($oProfileWithMostFields->firstName, $oFoundPerson->Get('first_name'));
		self::assertEquals($oProfileWithMostFields->lastName, $oFoundPerson->Get('name'));
		self::assertEquals($sEmail, $oFoundPerson->Get('email'));
		self::assertEquals($oOrg->GetKey(), $oFoundPerson->Get('org_id'));
		self::assertEquals($oProfileWithMostFields->phone, $oFoundPerson->Get('phone'));

		/** @var ?\User $oFoundUser */
		$oFoundUser = LoginWebPage::FindUser($sEmail);
		self::assertNotNull($oFoundUser);
		$this->assertEquals($oFoundUser->GetKey(), $oReturnedCreatedUser->GetKey(), "User creation OK");

		self::assertEquals($sEmail, $oFoundUser->Get('login'));
		self::assertEquals($oFoundPerson->GetKey(), $oFoundUser->Get('contactid'));
		self::assertEquals('EN US', $oFoundUser->Get('language'));
		$this->assertUserProfiles($oFoundUser, ['Portal user']);
	}

	//nominal usecase
	public function testDoProvisioningCreationOKUsingLogin()
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', true);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_user', true);
		MetaModel::GetConfig()->SetDefaultLanguage('EN US');

		$sDefaultOrgName = $this->sUniqId;
		$oOrg = $this->CreateOrganization($sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', null);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', null);

		$sEmail = $this->sUniqId."@test.fr";
		self::assertNull(LoginWebPage::FindPerson($sEmail));
		self::assertNull(LoginWebPage::FindUser($sEmail));

		$sLogin = "LOGIN-".$this->sUniqId;
		$oProfileWithMostFields = new Profile();
		$oProfileWithMostFields->email = $this->sUniqId."@test.fr";
		$oProfileWithMostFields->firstName = 'firstNameA';
		$oProfileWithMostFields->lastName = 'lastNameA';
		$oProfileWithMostFields->phone = '456978';
		[$oReturnedCreatedPerson, $oReturnedCreatedUser] = ProvisioningService::GetInstance()->DoProvisioning($this->sLoginMode, $sLogin, $oProfileWithMostFields);

		/** @var ?\Person $oFoundPerson */
		$oFoundPerson = LoginWebPage::FindPerson($sEmail);
		self::assertNotNull($oFoundPerson);
		$this->assertEquals($oFoundPerson->GetKey(), $oReturnedCreatedPerson->GetKey(), "Person creation OK");

		$oFoundUser = LoginWebPage::FindUser($sLogin);
		self::assertNotNull($oFoundUser);
		$this->assertEquals($oFoundUser->GetKey(), $oReturnedCreatedUser->GetKey(), "User creation OK");
	}

	//nominal usecase
	public function testDoProvisioningCreateUserWithoutPerson()
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', false);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_user', true);
		MetaModel::GetConfig()->SetDefaultLanguage('EN US');

		$sDefaultOrgName = $this->sUniqId;
		$oOrg = $this->CreateOrganization($sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', null);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', null);

		$sEmail = $this->sUniqId.'@test.fr';
		self::assertNull(LoginWebPage::FindPerson($sEmail));
		self::assertNull(LoginWebPage::FindUser($sEmail));

		$sLogin = 'LOGIN-'.$this->sUniqId;
		$oProfileWithMostFields = new Profile();
		$oProfileWithMostFields->email = $this->sUniqId.'@test.fr';
		$oProfileWithMostFields->firstName = 'firstNameA';
		$oProfileWithMostFields->lastName = 'lastNameA';
		$oProfileWithMostFields->phone = '456978';
		[$oReturnedCreatedPerson, $oReturnedCreatedUser] = ProvisioningService::GetInstance()->DoProvisioning($this->sLoginMode, $sLogin, $oProfileWithMostFields);

		self::assertNull($oReturnedCreatedPerson, 'No Person should have been created if "synchronize_contact" is false');

		/** @var ?\Person $oFoundPerson */
		$oFoundPerson = LoginWebPage::FindPerson($sEmail);
		self::assertNull($oFoundPerson, 'No Person should exist if "synchronize_contact" is false');

		$oFoundUser = LoginWebPage::FindUser($sLogin);
		self::assertNotNull($oFoundUser);
		$this->assertEquals($oFoundUser->GetKey(), $oReturnedCreatedUser->GetKey(), 'User should have been created if "synchronize_user" is true');
	}

	public function testDoProvisioningCreationOK_SynchronizingOrgProfilesAndAllowedORgsViaIdpMatching()
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', true);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_user', true);
		MetaModel::GetConfig()->SetDefaultLanguage('EN US');
		$this->InitializeGroupsToProfile($this->sLoginMode, ["profile_id1" => "Change Approver", "profile_id2" => ["Administrator", "Configuration Manager"]]);

		$sOrgName1 = "anotherorg_".$this->sUniqId;
		$oOrg1 = $this->CreateOrganization($sOrgName1);
		$sOrgName2 = $this->CreateOrgAndGetName();
		$sOrgName3 = $this->CreateOrgAndGetName();
		$this->InitializeGroupsToOrgs($this->sLoginMode, ["org_id1" => $sOrgName1, "org_id2" => [$sOrgName2, $sOrgName3]]);

		$sDefaultOrgName = $this->sUniqId;
		$oOrg = $this->CreateOrganization($sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', null);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', null);

		$sEmail = $this->sUniqId."@test.fr";
		self::assertNull(LoginWebPage::FindPerson($sEmail));
		self::assertNull(LoginWebPage::FindUser($sEmail));

		$oProfileWithMostFields = new Profile();
		$oProfileWithMostFields->data['groups'] = ['profile_id1', 'profile_id2'];
		$oProfileWithMostFields->data['allowed_orgs'] = ['org_id2'];
		$oProfileWithMostFields->data['organization'] = 'org_id1';
		$oProfileWithMostFields->email = $this->sUniqId."@test.fr";
		$oProfileWithMostFields->firstName = 'firstNameA';
		$oProfileWithMostFields->lastName = 'lastNameA';
		$oProfileWithMostFields->phone = '456978';
		[$oReturnedCreatedPerson, $oReturnedCreatedUser] = ProvisioningService::GetInstance()->DoProvisioning($this->sLoginMode, $sEmail, $oProfileWithMostFields);

		/** @var ?\Person $oFoundPerson */
		$oFoundPerson = LoginWebPage::FindPerson($sEmail);
		self::assertNotNull($oFoundPerson);
		$this->assertEquals($oFoundPerson->GetKey(), $oReturnedCreatedPerson->GetKey(), "Person creation OK");

		self::assertEquals($oProfileWithMostFields->firstName, $oFoundPerson->Get('first_name'));
		self::assertEquals($oProfileWithMostFields->lastName, $oFoundPerson->Get('name'));
		self::assertEquals($sEmail, $oFoundPerson->Get('email'));
		self::assertEquals($oOrg1->GetKey(), $oFoundPerson->Get('org_id'), "org should come from org_id1/$sOrgName1/{$oOrg1->GetKey()} and not from default org $sDefaultOrgName/{$oOrg->GetKey()}");
		self::assertEquals($oProfileWithMostFields->phone, $oFoundPerson->Get('phone'));

		/** @var ?\User $oFoundUser */
		$oFoundUser = LoginWebPage::FindUser($sEmail);
		self::assertNotNull($oFoundUser);
		$this->assertEquals($oFoundUser->GetKey(), $oReturnedCreatedUser->GetKey(), "User creation OK");

		self::assertEquals($sEmail, $oFoundUser->Get('login'));
		self::assertEquals($oFoundPerson->GetKey(), $oFoundUser->Get('contactid'));
		self::assertEquals('EN US', $oFoundUser->Get('language'));
		$this->assertUserProfiles($oFoundUser, ['Change Approver', 'Administrator', 'Configuration Manager']);
		$this->assertAllowedOrg($oFoundUser, [$sOrgName1, $sOrgName2, $sOrgName3]);
	}

	public function testDoProvisioning_RefreshOKFromConfiguredDefaultOrgProfiles()
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', true);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_user', true);
		MetaModel::GetConfig()->SetDefaultLanguage('EN US');
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'refresh_existing_user', true);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'refresh_existing_contact', true);

		$sEmail = $this->sUniqId."@test.fr";
		$sDefaultOrgName = $this->sUniqId;
		$oOrg = $this->CreateOrganization($sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Portal user']);

		$oUserProfile = new Profile();
		$oUserProfile->email = $sEmail;
		[$oReturnedCreatedPerson, $oReturnedCreatedUser] = ProvisioningService::GetInstance()->DoProvisioning($this->sLoginMode, $sEmail, $oUserProfile);
		self::assertNotNull(LoginWebPage::FindPerson($sEmail));
		self::assertNotNull(LoginWebPage::FindUser($sEmail));

		$sDefaultOrgName2 = "anotherorg_".$this->sUniqId;
		$oOrg2 = $this->CreateOrganization($sDefaultOrgName2);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName2);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Configuration Manager']);

		$oProfileWithMostFields = new Profile();
		$oProfileWithMostFields->email = $sEmail;
		$oProfileWithMostFields->firstName = 'firstNameA';
		$oProfileWithMostFields->lastName = 'lastNameA';
		$oProfileWithMostFields->phone = '456978';
		[$oReturnedCreatedPerson, $oReturnedCreatedUser] = ProvisioningService::GetInstance()->DoProvisioning($this->sLoginMode, $sEmail, $oProfileWithMostFields);

		/** @var ?\Person $oFoundPerson */
		$oFoundPerson = LoginWebPage::FindPerson($sEmail);
		self::assertNotNull($oFoundPerson);
		$this->assertEquals($oFoundPerson->GetKey(), $oReturnedCreatedPerson->GetKey(), "Person refresh OK");

		self::assertEquals($oProfileWithMostFields->firstName, $oFoundPerson->Get('first_name'));
		self::assertEquals($oProfileWithMostFields->lastName, $oFoundPerson->Get('name'));
		self::assertEquals($sEmail, $oFoundPerson->Get('email'));
		self::assertEquals($oOrg2->GetKey(), $oFoundPerson->Get('org_id'));
		self::assertEquals($oProfileWithMostFields->phone, $oFoundPerson->Get('phone'));

		/** @var ?\User $oFoundUser */
		$oFoundUser = LoginWebPage::FindUser($sEmail);
		self::assertNotNull($oFoundUser);
		$this->assertEquals($oFoundUser->GetKey(), $oReturnedCreatedUser->GetKey(), "User refresh OK");

		self::assertEquals($sEmail, $oFoundUser->Get('login'));
		self::assertEquals($oFoundPerson->GetKey(), $oFoundUser->Get('contactid'));
		self::assertEquals('EN US', $oFoundUser->Get('language'));
		$this->assertUserProfiles($oFoundUser, ['Configuration Manager']);
	}

	public function testDoProvisioningRefreshOK_SynchronizingOrgProfilesAndAllowedORgsViaIdpMatching()
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', true);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_user', true);
		MetaModel::GetConfig()->SetDefaultLanguage('EN US');
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'refresh_existing_user', true);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'refresh_existing_contact', true);

		$sEmail = $this->sUniqId."@test.fr";
		$sDefaultOrgName = $this->sUniqId;
		$oOrg = $this->CreateOrganization($sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Portal user']);

		$oUserProfile = new Profile();
		$oUserProfile->email = $sEmail;
		[$oReturnedCreatedPerson, $oReturnedCreatedUser] = ProvisioningService::GetInstance()->DoProvisioning($this->sLoginMode, $sEmail, $oUserProfile);

		self::assertNotNull(LoginWebPage::FindPerson($sEmail));
		self::assertNotNull(LoginWebPage::FindUser($sEmail));

		$sDefaultOrgName2 = "anotherdefaultorg_".$this->sUniqId;
		$oOrg2 = $this->CreateOrganization($sDefaultOrgName2);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName2);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Configuration Manager']);
		$this->InitializeGroupsToProfile($this->sLoginMode, ["profile_id1" => "Change Approver", "profile_id2" => ["Administrator", "Configuration Manager"]]);

		$sOrgName1 = "anotherorg_".$this->sUniqId;
		$oOrg1 = $this->CreateOrganization($sOrgName1);
		$sOrgName2 = $this->CreateOrgAndGetName();
		$sOrgName3 = $this->CreateOrgAndGetName();
		$this->InitializeGroupsToOrgs($this->sLoginMode, ["org_id1" => $sOrgName1, "org_id2" => [$sOrgName2, $sOrgName3]]);

		$oProfileWithMostFields = new Profile();
		$oProfileWithMostFields->data['groups'] = ['profile_id1', 'profile_id2'];
		$oProfileWithMostFields->data['allowed_orgs'] = ['org_id1', 'org_id2'];
		$oProfileWithMostFields->data['organization'] = ['org_id1'];
		$oProfileWithMostFields->email = $sEmail;
		$oProfileWithMostFields->firstName = 'firstNameA';
		$oProfileWithMostFields->lastName = 'lastNameA';
		$oProfileWithMostFields->phone = '456978';
		[$oReturnedCreatedPerson, $oReturnedCreatedUser] = ProvisioningService::GetInstance()->DoProvisioning($this->sLoginMode, $sEmail, $oProfileWithMostFields);

		/** @var ?\Person $oFoundPerson */
		$oFoundPerson = LoginWebPage::FindPerson($sEmail);
		self::assertNotNull($oFoundPerson);
		$this->assertEquals($oFoundPerson->GetKey(), $oReturnedCreatedPerson->GetKey(), "Person refresh OK");

		self::assertEquals($oProfileWithMostFields->firstName, $oFoundPerson->Get('first_name'));
		self::assertEquals($oProfileWithMostFields->lastName, $oFoundPerson->Get('name'));
		self::assertEquals($sEmail, $oFoundPerson->Get('email'));
		self::assertEquals($oOrg1->GetKey(), $oFoundPerson->Get('org_id'), "org should come from org_id1/$sOrgName1/{$oOrg1->GetKey()} and not from default org $sDefaultOrgName/{$oOrg->GetKey()}");

		self::assertEquals($oProfileWithMostFields->phone, $oFoundPerson->Get('phone'));

		/** @var ?\User $oFoundUser */
		$oFoundUser = LoginWebPage::FindUser($sEmail);
		self::assertNotNull($oFoundUser);
		$this->assertEquals($oFoundUser->GetKey(), $oReturnedCreatedUser->GetKey(), "User refresh OK");

		self::assertEquals($sEmail, $oFoundUser->Get('login'));
		self::assertEquals($oFoundPerson->GetKey(), $oFoundUser->Get('contactid'));
		self::assertEquals('EN US', $oFoundUser->Get('language'));
		$this->assertUserProfiles($oFoundUser, ['Change Approver', 'Administrator', 'Configuration Manager']);
		$this->assertAllowedOrg($oFoundUser, [$sOrgName1, $sOrgName2, $sOrgName3]);
	}

	public function testDoProvisioningRefreshFailsSSoConnectionForbiddenAndUserEndsUpWithDefaultProfilesAfterwhile()
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', true);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_user', true);
		MetaModel::GetConfig()->SetDefaultLanguage('EN US');
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'refresh_existing_user', true);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'refresh_existing_contact', true);

		$sEmail = $this->sUniqId."@test.fr";
		$sDefaultOrgName = $this->sUniqId;
		$this->CreateOrganization($sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Portal user']);

		$oUserProfile = new Profile();
		$oUserProfile->email = $sEmail;

		ProvisioningService::GetInstance()->DoProvisioning($this->sLoginMode, $sEmail, $oUserProfile);
		self::assertNotNull(LoginWebPage::FindPerson($sEmail));
		self::assertNotNull(LoginWebPage::FindUser($sEmail));

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Change Approver', 'Configuration Manager']);
		$this->InitializeGroupsToProfile($this->sLoginMode, ["profile_id1" => ["Administrator"]]);

		$oProfileWithEmptyProfileGroups = new Profile();
		$oProfileWithEmptyProfileGroups->email = $sEmail;
		//no profile associated to user
		$oProfileWithEmptyProfileGroups->data['groups'] = [];

		try {
			ProvisioningService::GetInstance()->DoProvisioning($this->sLoginMode, $sEmail, $oProfileWithEmptyProfileGroups);
			$this->fail("SSO should have failed with HybridProvisioningAuthException");
		} catch (HybridProvisioningAuthException $e) {
			$this->assertEquals("No sp group/profile matching found and no valid URP_Profile to attach to user", $e->getMessage());

			/** @var ?\User $oFoundUser */
			$oFoundUser = LoginWebPage::FindUser($sEmail);
			self::assertNotNull($oFoundUser);
			$this->assertUserProfiles($oFoundUser, ['Change Approver', 'Configuration Manager'], "When no profile found SSO should raise an exception and user end up with default profiles afterwhile");
		}
	}
}
