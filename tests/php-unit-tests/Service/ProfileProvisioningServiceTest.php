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
	public function testSynchronizeProfiles_NoGroupReturnedBySP_UseDefaultProfile() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Portal user');
		$this->InitializeGroupsToProfile($this->sLoginMode, ['A' => 'B']);

		//field 'groups' not found in IdP response
		//use fallback
		$this->ValidateSynchronizeProfiles_FallbackToDefaultProfileUse(new Profile());
	}

	public function testSynchronizeProfiles_NoGroupMatchingConfigured_UserCreationWithDefaultProfileAndWarnings() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Portal user');

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['A' => 'B'];
		$this->ValidateSynchronizeProfiles_FallbackToDefaultProfileUse($oUserProfile);
	}

	public function testSynchronizeProfiles_GroupMatchingBadlyConfigured_UserCreationWithDefaultProfileAndWarnings() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Portal user');
		$this->InitializeGroupsToProfile($this->sLoginMode, "groups_to_profiles set as string instead of array");

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1'];
		$this->ValidateSynchronizeProfiles_FallbackToDefaultProfileUse($oUserProfile);
	}

	public function testSynchronizeProfiles_NoProfilesReturnedByIdp_UserCreationError() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Portal user');
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id2" => "itop_profile2"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= [];

		$this->expectExceptionMessage("No sp group/profile matching found and no valid URP_Profile to attach to user");
		$this->expectException(HybridProvisioningAuthException::class);
		$this->ValidateSynchronizeProfiles_FallbackToDefaultProfileUse($oUserProfile);
	}

	public function testSynchronizeProfiles_NoProfilesFoundViaGroupMatchingConfiguration_UserCreationError() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Portal user');
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id2" => "itop_profile2"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1'];

		$this->expectExceptionMessage("No sp group/profile matching found and no valid URP_Profile to attach to user");
		$this->expectException(HybridProvisioningAuthException::class);
		$this->ValidateSynchronizeProfiles_FallbackToDefaultProfileUse($oUserProfile);
	}

	public function testSynchronizeProfiles_OnlyUnexistingiTopProfilesToProvision_UserCreationError() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Configuration Manager');
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id1" => "unexisting_itop_profile1"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1', 'sp_id2'];

		$this->expectExceptionMessage("no valid URP_Profile to attach to user");
		$this->expectException(HybridProvisioningAuthException::class);
		$this->ValidateSynchronizeProfiles_FallbackToDefaultProfileUse($oUserProfile, null, true);
	}

	public function testSynchronizeProfiles_SomeUnexistingProfileToProvision_UserCreationWithFallbackProfileAndWarning() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Configuration Manager');
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id1" => "unexisting_itop_profile1", "sp_id2" => "Portal user"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1', 'sp_id2'];

		$this->ValidateSynchronizeProfiles_FallbackToDefaultProfileUse($oUserProfile);
	}

	public function testSynchronizeProfiles_UnexistingDefaultProfile_UserCreationError() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Wrong iTop Profile');

		$oUserProfile = new Profile();
		$this->expectExceptionMessage("no valid URP_Profile to attach to user");
		$this->expectException(HybridProvisioningAuthException::class);
		$this->ValidateSynchronizeProfiles_FallbackToDefaultProfileUse($oUserProfile, null, true);
	}

	public function testSynchronizeProfiles_UserCreationOK() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Administrator');
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id1" => "Configuration Manager", "sp_id2" => ["Administrator", "Portal user"]]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1', 'sp_id2'];
		$this->ValidateSynchronizeProfiles_FallbackToDefaultProfileUse($oUserProfile, ["Administrator", "Configuration Manager", "Portal user"]);
	}

	public function testSynchronizeProfiles_NoExistingProfileToUpdate_NoProfileModificationAndNoExceptionToLetUserLogInWithPreviousProfiles() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Administrator');
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id1" => "unexisting itop profile"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1'];
		$sEmail = $this->sUniqId."@test.fr";

		$aInitialProfileNames=[
			"Configuration Manager", //to remove after provisioning update
			"Change Approver", //to keep
		];
		$oUser = $this->CreateExternalUserWithProfiles($sEmail, $aInitialProfileNames);

		$this->expectException(HybridProvisioningAuthException::class);
		$this->expectExceptionMessage("no valid URP_Profile to attach to user");
		ProvisioningService::GetInstance()->SynchronizeProfiles($this->sLoginMode, $sEmail , $oUser, $oUserProfile, "");
	}

	public function testSynchronizeProfiles_UserUpdateOK() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Administrator');
		$this->InitializeGroupsToProfile($this->sLoginMode, ["sp_id1" => "Change Approver", "sp_id2" => "Portal user"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1', 'sp_id2'];
		$sEmail = $this->sUniqId."@test.fr";

		$aInitialProfileNames=[
			"Configuration Manager", //to remove after provisioning update
			"Change Approver", //to keep
		];
		$oUser = $this->CreateExternalUserWithProfiles($sEmail, $aInitialProfileNames);

		ProvisioningService::GetInstance()->SynchronizeProfiles($this->sLoginMode, $sEmail , $oUser, $oUserProfile, "");
		$this->assertUserProfiles($oUser, ['Portal user', "Change Approver"]);
	}
}
