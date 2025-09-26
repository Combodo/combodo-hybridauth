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

require_once __DIR__ . "/AbstractHybridauthTest.php";

class PersonProvisioningServiceTest extends AbstractHybridauthTest
{
	public function testDoPersonProvisioning_PersonAlreadyExists(){
		$sEmail = $this->sUniqId."@test.fr";
		$oPerson = $this->CreatePersonByEmail($sEmail);

		$oFoundPerson = ProvisioningService::GetInstance()->DoPersonProvisioning($this->sLoginMode, $sEmail , new Profile());
		$this->assertEquals($oPerson->GetKey(), $oFoundPerson->GetKey(), "Person already created; should return existing one in DB");
	}

	public function testDoPersonProvisioning_SynchroDisabled(){
		$sEmail = $this->sUniqId."@test.fr";

		$this->expectExceptionMessage("Cannot find Person and no automatic Contact provisioning (synchronize_contact)");
		ProvisioningService::GetInstance()->DoPersonProvisioning($this->sLoginMode, $sEmail , new Profile());
	}

	public function testDoPersonProvisioning_CreationOKWithEmailOnly(){
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', true);

		$sDefaultOrgName = $this->sUniqId;
		$oOrg = $this->CreateOrganization($sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName);

		$sEmail = $this->sUniqId."@test.fr";
		self::assertNull(LoginWebPage::FindPerson($sEmail));

		$oUserProfile = new Profile();
		$oUserProfile->email = $sEmail;
		$oReturnedCreatedPerson = ProvisioningService::GetInstance()->DoPersonProvisioning($this->sLoginMode, $sEmail , $oUserProfile);
		$oFoundPerson = LoginWebPage::FindPerson($sEmail);
		self::assertNotNull($oFoundPerson);
		$this->assertEquals($oFoundPerson->GetKey(), $oReturnedCreatedPerson->GetKey(), "Person creation OK");

		self::assertEquals($sEmail, $oFoundPerson->Get('first_name'));
		self::assertEquals($sEmail, $oFoundPerson->Get('name'));
		self::assertEquals($sEmail, $oFoundPerson->Get('email'));
		self::assertEquals($oOrg->GetKey(), $oFoundPerson->Get('org_id'));
		self::assertEquals('', $oFoundPerson->Get('phone'));
	}

	public function testDoPersonProvisioning_CreationOKWithAllFieldsProvidedByIdp(){
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', true);

		$sDefaultOrgName = $this->sUniqId;
		$oOrg = $this->CreateOrganization($sDefaultOrgName);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName);

		$sEmail = $this->sUniqId."@test.fr";
		self::assertNull(LoginWebPage::FindPerson($sEmail));

		$oProfileWithMostFields = new Profile();
		$oProfileWithMostFields->email = $this->sUniqId."@test.fr";
		$oProfileWithMostFields->firstName = 'firstNameA';
		$oProfileWithMostFields->lastName = 'lastNameA';
		$oProfileWithMostFields->phone = '456978';
		$oReturnedCreatedPerson = ProvisioningService::GetInstance()->DoPersonProvisioning($this->sLoginMode, $sEmail , $oProfileWithMostFields);
		$oFoundPerson = LoginWebPage::FindPerson($sEmail);
		self::assertNotNull($oFoundPerson);
		$this->assertEquals($oFoundPerson->GetKey(), $oReturnedCreatedPerson->GetKey(), "Person creation OK");

		self::assertEquals($oProfileWithMostFields->firstName, $oFoundPerson->Get('first_name'));
		self::assertEquals($oProfileWithMostFields->lastName, $oFoundPerson->Get('name'));
		self::assertEquals($sEmail, $oFoundPerson->Get('email'));
		self::assertEquals($oOrg->GetKey(), $oFoundPerson->Get('org_id'));
		self::assertEquals($oProfileWithMostFields->phone, $oFoundPerson->Get('phone'));
	}

	public function GetOrganizationForProvisioningProvider()
	{
		$sDefaultOrgName = 'IDP_ORG1'.uniqid();
		$sOrgName2 = 'IDP_ORG2'.uniqid();

		return [
			'no org returned by IdP' => [$sDefaultOrgName, $sOrgName2, null, $sDefaultOrgName],
			'unknown org returned by IdP' => [$sDefaultOrgName, $sOrgName2, "unknown_IDP_Org", $sDefaultOrgName],
			'use IdP org name' => [$sDefaultOrgName, $sOrgName2, $sOrgName2, $sOrgName2],
		];
	}

	/**
	 * @dataProvider GetOrganizationForProvisioningProvider
	 */
	public function testGetOrganizationForProvisioning(string $sDefaultOrgName, string $sOrgName2, ?string $sIdpOrgName, string $sExpectedOrgReturned)
	{
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_user', true);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', true);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_organization', $sDefaultOrgName);

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ["Configuration Manager"]);

		$this->CreateOrganization($sDefaultOrgName);
		$this->CreateOrganization($sOrgName2);


		$sOrgName = $this->InvokeNonPublicMethod(ProvisioningService::class, 'GetOrganizationForProvisioning', ProvisioningService::GetInstance(), [$this->sLoginMode, $sIdpOrgName]);
		$this->assertEquals($sExpectedOrgReturned, $sOrgName);
	}
}
