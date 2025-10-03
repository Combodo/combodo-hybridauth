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

class ProfileProvisioningServiceTest extends AbstractHybridauthTest
{
	public function testSynchronizeProfilesShouldUseDefaultProfilesIfIdpResponseDoesNotIncludeProfile() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Portal user']);
		$this->InitializeGroupsToProfile($this->sLoginMode, ['A' => 'B']);

		//field 'groups' not found in IdP response
		//use fallback
		$this->CallProfileSynchronizationAndValidateProfilesAttachedAfterwhile(new Profile());
	}

	public function testSynchronizeProfilesShouldUseDefaultProfilesIfIdpResponseDoesNotMatchAnyExistingProfile() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Portal user']);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['A' => 'B'];
		$this->CallProfileSynchronizationAndValidateProfilesAttachedAfterwhile($oUserProfile);
	}

	public function testSynchronizeProfilesShouldUseDefaultProfilesIfProfileMatchingBadlyConfigured() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Portal user']);
		$this->InitializeGroupsToProfile($this->sLoginMode, "groups_to_profiles set as string instead of array");

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1'];
		$this->CallProfileSynchronizationAndValidateProfilesAttachedAfterwhile($oUserProfile);
	}

	public function testSynchronizeProfilesAndSSOShouldFailIfIdpProfileResponseIsEmpty() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Portal user']);
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id2" => "itop_profile2"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= [];

		$this->expectExceptionMessage("No sp group/profile matching found and no valid URP_Profile to attach to user");
		$this->expectException(HybridProvisioningAuthException::class);
		$this->CallProfileSynchronizationAndValidateProfilesAttachedAfterwhile($oUserProfile);
	}

	public function testSynchronizeProfilesAndSSOShouldFailIfIdpProfileResponseDoesNotMatchAnyConfiguredItopProfiles() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Portal user']);
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id2" => "itop_profile2"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1'];

		$this->expectExceptionMessage("No sp group/profile matching found and no valid URP_Profile to attach to user");
		$this->expectException(HybridProvisioningAuthException::class);
		$this->CallProfileSynchronizationAndValidateProfilesAttachedAfterwhile($oUserProfile);
	}

	public function testSynchronizeProfilesAndSSOShouldFailIfIdpProfileResponseMatchUnexistingProfilesOnly() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Configuration Manager']);
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id1" => "unexisting_itop_profile1"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1', 'sp_id2'];

		$this->expectExceptionMessage("no valid URP_Profile to attach to user");
		$this->expectException(HybridProvisioningAuthException::class);
		$this->CallProfileSynchronizationAndValidateProfilesAttachedAfterwhile($oUserProfile, null);
	}

	public function testSynchronizeProfilesWithPartialMatchingWithExistingItopProfiles() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Portal user']);
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id1" => "unexisting_itop_profile1", "sp_id2" => "Configuration Manager"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1', 'sp_id2'];

		$this->CallProfileSynchronizationAndValidateProfilesAttachedAfterwhile($oUserProfile, ['Configuration Manager']);
	}

	public function testSynchronizeProfilesAndSSOShouldFailTryingToAttachConfiguredDefaultProfilesNotExistingInItop() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Wrong iTop Profile']);

		$oUserProfile = new Profile();
		$this->expectExceptionMessage("no valid URP_Profile to attach to user");
		$this->expectException(HybridProvisioningAuthException::class);
		$this->CallProfileSynchronizationAndValidateProfilesAttachedAfterwhile($oUserProfile, null, true);
	}

	public function testSynchronizeProfilesMatchingAndProvisioningOkAtUserCreation() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Portal user']);
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id1" => "Configuration Manager", "sp_id2" => ["Administrator", "Portal power user"]]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1', 'sp_id2'];
		$this->CallProfileSynchronizationAndValidateProfilesAttachedAfterwhile($oUserProfile, ["Administrator", "Configuration Manager", "Portal power user"]);
	}

	public function testSynchronizeProfilesMatchingAndProvisioningOkAtUserCreationWithIdpResponseExplode() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Portal user']);
		$this->Configure($this->sLoginMode, 'profiles_idp_separator', ',');
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id1" => "Configuration Manager", "sp_id2" => ["Administrator", "Portal power user"]]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= 'sp_id1, sp_id2';
		$this->CallProfileSynchronizationAndValidateProfilesAttachedAfterwhile($oUserProfile, ["Administrator", "Configuration Manager", "Portal power user"]);
	}

	public function testSynchronizeProfilesCaseInsensitiveMatchingAndProvisioningOkAtUserCreation() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Portal user']);
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id1" => "Configuration Manager", "sp_id2" => ["administrator", "portal power user"]]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1', 'sp_id2'];
		$this->CallProfileSynchronizationAndValidateProfilesAttachedAfterwhile($oUserProfile, ["Administrator", "Configuration Manager", "Portal power user"]);
	}

	public function testSynchronizeProfilesMatchingAndProvisioningOkAtUserCreationFromAnotherConfiguredIdpKey() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Portal user']);
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id1" => "Configuration Manager", "sp_id2" => ["Administrator", "Portal power user"]]);
		$this->Configure($this->sLoginMode, 'profiles_idp_key', 'groups2');
		$oUserProfile = new Profile();
		$oUserProfile->data['groups2']= ['sp_id1', 'sp_id2'];
		$this->CallProfileSynchronizationAndValidateProfilesAttachedAfterwhile($oUserProfile, ["Administrator", "Configuration Manager", "Portal power user"]);
	}

	public function testSynchronizeProfilesAndSSOShouldFailIfUserHasNoMoreProfileAtRefresh() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Administrator']);
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id1" => "unexisting itop profile"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1'];
		$sEmail = $this->sUniqId."@test.fr";

		$aInitialProfileNames=[
			"Configuration Manager", //to remove after provisioning update
			"Change Approver", //to keep
		];

		try{
			$oUser = $this->CreateExternalUserWithProfilesAndAllowedOrgs($sEmail, $aInitialProfileNames);
			$aProviderConf = \Combodo\iTop\HybridAuth\Config::GetProviderConf($this->sLoginMode);
			ProvisioningService::GetInstance()->SynchronizeProfiles($this->sLoginMode, $sEmail , $oUser, $oUserProfile, $aProviderConf, "");
			$this->fail("SSO should have failed with HybridProvisioningAuthException");
		} catch(HybridProvisioningAuthException $e){
			$this->assertEquals("no valid URP_Profile to attach to user", $e->getMessage());
			$this->assertUserProfiles($oUser, ['Administrator'], "When no profile found SSO should raise an exception and user end up with default profiles afterwhile");
		}
	}

	public function testSynchronizeProfilesOk_UserUpdate() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Administrator']);
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id1" => "Change Approver", "sp_id2" => "Portal user"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1', 'sp_id2'];
		$sEmail = $this->sUniqId."@test.fr";

		$aInitialProfileNames=[
			"Configuration Manager", //to remove after provisioning update
			"Change Approver", //to keep
		];
		$oUser = $this->CreateExternalUserWithProfilesAndAllowedOrgs($sEmail, $aInitialProfileNames);

		$aProviderConf = \Combodo\iTop\HybridAuth\Config::GetProviderConf($this->sLoginMode);
		ProvisioningService::GetInstance()->SynchronizeProfiles($this->sLoginMode, $sEmail , $oUser, $oUserProfile, $aProviderConf, "");
		$this->assertUserProfiles($oUser, ['Portal user', "Change Approver"]);
	}

	public function testSynchronizeProfilesOk_UserUpdateFromAnotherIdpKey() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profiles', ['Administrator']);
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id1" => "Change Approver", "sp_id2" => "Portal user"]);
		$this->Configure($this->sLoginMode, 'profiles_idp_key', 'groups2');

		$oUserProfile = new Profile();
		$oUserProfile->data['groups2']= ['sp_id1', 'sp_id2'];
		$sEmail = $this->sUniqId."@test.fr";

		$aInitialProfileNames=[
			"Configuration Manager", //to remove after provisioning update
			"Change Approver", //to keep
		];
		$oUser = $this->CreateExternalUserWithProfilesAndAllowedOrgs($sEmail, $aInitialProfileNames);

		$aProviderConf = \Combodo\iTop\HybridAuth\Config::GetProviderConf($this->sLoginMode);
		ProvisioningService::GetInstance()->SynchronizeProfiles($this->sLoginMode, $sEmail , $oUser, $oUserProfile, $aProviderConf, "");
		$this->assertUserProfiles($oUser, ['Portal user', "Change Approver"]);
	}
}
