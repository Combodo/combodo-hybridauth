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

class AllowedOrgProvisioningServiceTest extends AbstractHybridauthTest
{
	protected function setUp(): void {
		parent::setUp();
	}

	public function testSynchronizeAllowedOrgs_NoGroupReturnedBySP_UseDefaultProfile() {
		$this->InitializeGroupsToOrgs($this->sLoginMode, ['A' => 'B']);

		//field 'groups' not found in IdP response
		//use fallback
		$this->CallAllowedOrgSynchronizationAndValidateAfterwhile(new Profile());
	}

	public function testSynchronizeAllowedOrgs_NoGroupMatchingConfigured_UserCreationWithoutAllowedOrg() {
		$oUserProfile = new Profile();
		$oUserProfile->data['allowed_orgs']= ['A' => 'B'];
		$this->CallAllowedOrgSynchronizationAndValidateAfterwhile($oUserProfile);
	}

	public function testSynchronizeAllowedOrgs_GroupMatchingBadlyConfigured_UserCreationWithoutAllowedOrg() {
		$this->InitializeGroupsToOrgs($this->sLoginMode, "groups_to_profiles set as string instead of array");

		$oUserProfile = new Profile();
		$oUserProfile->data['allowed_orgs']= ['sp_id1'];
		$this->CallAllowedOrgSynchronizationAndValidateAfterwhile($oUserProfile);
	}

	public function testSynchronizeAllowedOrgs_NoAllowedOrgReturnedByIdp_UserCreationOK() {
		$this->InitializeGroupsToOrgs($this->sLoginMode, ["sp_id2" => "org2"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['allowed_orgs']= [];

		$this->CallAllowedOrgSynchronizationAndValidateAfterwhile($oUserProfile);
	}

	public function testSynchronizeAllowedOrgs_NoAllowedOrgFoundViaGroupMatchingConfiguration_UserCreationOK() {
		$this->InitializeGroupsToOrgs($this->sLoginMode, ["sp_id2" => "org2"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['allowed_orgs']= ['sp_id1'];

		$this->CallAllowedOrgSynchronizationAndValidateAfterwhile($oUserProfile);
	}

	public function testSynchronizeAllowedOrgs_OnlyUnexistingItopAllowedOrgsToProvision_UserCreationOK() {
		$this->InitializeGroupsToOrgs($this->sLoginMode, ["sp_id1" => "unexisting_itop_org1"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['allowed_orgs']= ['sp_id1', 'sp_id2'];

		$this->CallAllowedOrgSynchronizationAndValidateAfterwhile($oUserProfile);
	}

	public function testSynchronizeAllowedOrgs_SomeUnexistingOrgToProvision_UserCreationOK() {
		$sOrgName1 = $this->CreateOrgAndGetName();
		$this->InitializeGroupsToOrgs($this->sLoginMode, ["sp_id1" => "unexisting_itop_org1", "sp_id2" => $sOrgName1]);

		$oUserProfile = new Profile();
		$oUserProfile->data['allowed_orgs']= ['sp_id1', 'sp_id2'];

		$this->CallAllowedOrgSynchronizationAndValidateAfterwhile($oUserProfile, [$sOrgName1]);
	}

	public function testSynchronizeAllowedOrgs_UserCreationOK_UseDefaultAllowedOrg() {
		$sOrgName1 = $this->CreateOrgAndGetName();
		$sOrgName2 = $this->CreateOrgAndGetName();
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_allowed_orgs', [$sOrgName1, $sOrgName2]);

		$oUserProfile = new Profile();
		$this->CallAllowedOrgSynchronizationAndValidateAfterwhile($oUserProfile, [$sOrgName1, $sOrgName2]);
	}

	public function testSynchronizeAllowedOrgs_UserCreationOK_ConfiguredExplodeOnAllowedOrgIdpResponse() {
		$sOrgName1 = $this->CreateOrgAndGetName();
		$sOrgName2 = $this->CreateOrgAndGetName();
		$sOrgName3 = $this->CreateOrgAndGetName();

		$this->Configure($this->sLoginMode, 'allowed_orgs_idp_separator', ',');
		$this->InitializeGroupsToOrgs($this->sLoginMode, ["sp_id1" => $sOrgName1, "sp_id2" => [$sOrgName2, $sOrgName3]]);

		$oUserProfile = new Profile();
		$oUserProfile->data['allowed_orgs']= 'sp_id1, sp_id2';
		$this->CallAllowedOrgSynchronizationAndValidateAfterwhile($oUserProfile, [$sOrgName1, $sOrgName2, $sOrgName3]);
	}

	public function testSynchronizeAllowedOrgs_UserCreationOK_UseOfAnotherIdpKey() {
		$sOrgName1 = $this->CreateOrgAndGetName();
		$sOrgName2 = $this->CreateOrgAndGetName();
		$sOrgName3 = $this->CreateOrgAndGetName();
		$this->InitializeGroupsToOrgs($this->sLoginMode, ["sp_id1" => $sOrgName1, "sp_id2" => [$sOrgName2, $sOrgName3]]);

		$this->Configure($this->sLoginMode, 'allowed_orgs_idp_key', 'groups2');
		$oUserProfile = new Profile();
		$oUserProfile->data['groups2']= ['sp_id1', 'sp_id2'];
		$this->CallAllowedOrgSynchronizationAndValidateAfterwhile($oUserProfile, [$sOrgName1, $sOrgName2, $sOrgName3]);
	}

	public function testSynchronizeAllowedOrgs_NoExistingOrgToUpdate_UserConnect_AllPreviousAllowedOrgsRemoved() {
		$this->InitializeGroupsToOrgs($this->sLoginMode, ["sp_id1" => "unexisting itop org"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['allowed_orgs']= ['sp_id1'];
		$sEmail = $this->sUniqId."@test.fr";

		$sOrgName = $this->sUniqId . '_' . microtime();
		$oOrg = $this->CreateOrganization($sOrgName);
		$oUser = $this->CreateExternalUserWithProfilesAndAllowedOrgs($sEmail, ["Configuration Manager"], [ $oOrg->GetKey()]);
		$this->assertAllowedOrg($oUser, [$sOrgName]);

		$aProviderConf = \Combodo\iTop\HybridAuth\Config::GetProviderConf($this->sLoginMode);
		ProvisioningService::GetInstance()->SynchronizeAllowedOrgs($this->sLoginMode, $sEmail , $oUser, $oUserProfile, $aProviderConf, "");
		$this->assertAllowedOrg($oUser, []);
	}

	public function testSynchronizeAllowedOrgs_UserUpdateOK() {
		$sOrgName1 = $this->CreateOrgAndGetName();
		$sOrgName2 = $this->CreateOrgAndGetName();
		$this->InitializeGroupsToOrgs($this->sLoginMode, ["sp_id1" => $sOrgName1, "sp_id2" => $sOrgName2]);

		$oUserProfile = new Profile();
		$oUserProfile->data['allowed_orgs']= ['sp_id1', 'sp_id2'];
		$sEmail = $this->sUniqId."@test.fr";

		$oUser = $this->CreateExternalUserWithProfilesAndAllowedOrgs($sEmail, ["Configuration Manager"]);
		$this->assertAllowedOrg($oUser, []);

		$aProviderConf = \Combodo\iTop\HybridAuth\Config::GetProviderConf($this->sLoginMode);
		ProvisioningService::GetInstance()->SynchronizeAllowedOrgs($this->sLoginMode, $sEmail , $oUser, $oUserProfile, $aProviderConf, "");
		$this->assertAllowedOrg($oUser, [$sOrgName1, $sOrgName2]);
	}

	public function testSynchronizeAllowedOrgs_UserUpdateOK_UseOfAnotherIdpKey() {
		$sOrgName1 = $this->CreateOrgAndGetName();
		$sOrgName2 = $this->CreateOrgAndGetName();
		$this->InitializeGroupsToOrgs($this->sLoginMode, ["sp_id1" => $sOrgName1, "sp_id2" => $sOrgName2]);
		$this->Configure($this->sLoginMode, 'allowed_orgs_idp_key', 'groups2');

		$oUserProfile = new Profile();
		$oUserProfile->data['groups2']= ['sp_id1', 'sp_id2'];
		$sEmail = $this->sUniqId."@test.fr";
		$oUser = $this->CreateExternalUserWithProfilesAndAllowedOrgs($sEmail, ["Configuration Manager"]);
		$this->assertAllowedOrg($oUser, []);


		$aProviderConf = \Combodo\iTop\HybridAuth\Config::GetProviderConf($this->sLoginMode);
		ProvisioningService::GetInstance()->SynchronizeAllowedOrgs($this->sLoginMode, $sEmail , $oUser, $oUserProfile, $aProviderConf, "");
		$this->assertAllowedOrg($oUser, [$sOrgName1, $sOrgName2]);
	}

	public function testSynchronizeAllowedOrgs_UserUpdateOK_SearchOrgByCodeInsteadOfName() {
		$sCode1 = "code1".uniqid();
		$sOrgName1 = $this->CreateOrgAndGetName($sCode1);
		$sCode2 = "code2".uniqid();
		$sOrgName2 = $this->CreateOrgAndGetName($sCode2);
		$this->InitializeGroupsToOrgs($this->sLoginMode, ["sp_id1" => $sCode1, "sp_id2" => $sCode2]);
		$this->Configure($this->sLoginMode, 'allowed_orgs_oql_search_field', 'code');

		$oUserProfile = new Profile();
		$oUserProfile->data['allowed_orgs']= ['sp_id1', 'sp_id2'];
		$sEmail = $this->sUniqId."@test.fr";

		$oUser = $this->CreateExternalUserWithProfilesAndAllowedOrgs($sEmail, ["Configuration Manager"]);
		$this->assertAllowedOrg($oUser, []);

		$aProviderConf = \Combodo\iTop\HybridAuth\Config::GetProviderConf($this->sLoginMode);
		ProvisioningService::GetInstance()->SynchronizeAllowedOrgs($this->sLoginMode, $sEmail , $oUser, $oUserProfile, $aProviderConf, "");
		$this->assertAllowedOrg($oUser, [$sOrgName1, $sOrgName2]);
	}
}
