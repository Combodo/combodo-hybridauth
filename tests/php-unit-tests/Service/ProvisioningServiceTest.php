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
use Combodo\iTop\HybridAuth\Test\Provider\ServiceProviderMock;
use Combodo\iTop\Test\UnitTest\ItopDataTestCase;
use Config;
use DBObjectSearch;
use DBObjectSet;
use Hybridauth\User\Profile;
use LoginWebPage;
use MetaModel;
use Person;
use URP_UserProfile;
use UserExternal;
use Combodo\iTop\HybridAuth\HybridProvisioningAuthException;

class ProvisioningServiceTest extends ItopDataTestCase
{
	protected $sConfigTmpBackupFile;
	protected $sLoginMode;
	protected $sUniqId;

	protected function setUp(): void
	{
		parent::setUp();

		$this->RequireOnceItopFile('env-production/combodo-hybridauth/vendor/autoload.php');
		$this->RequireOnceItopFile('env-production/combodo-oauth2-client/vendor/autoload.php');
		$this->RequireOnceUnitTestFile('../Provider/ServiceProviderMock.php');

		$sConfigPath = MetaModel::GetConfig()->GetLoadedFile();

		clearstatcache();
		echo sprintf("rights via ls on %s:\n %s \n", $sConfigPath, exec("ls -al $sConfigPath"));
		$sFilePermOutput = substr(sprintf('%o', fileperms('/etc/passwd')), -4);
		echo sprintf("rights via fileperms on %s:\n %s \n", $sConfigPath, $sFilePermOutput);

		$this->sConfigTmpBackupFile = tempnam(sys_get_temp_dir(), "config_");
		MetaModel::GetConfig()->WriteToFile($this->sConfigTmpBackupFile);

		$sPath = __DIR__.'/Provider/ServiceProviderMock.php';
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'oauth_test_class_path', $sPath);

		$_SESSION = [];
		$this->sUniqId = "OpenID".uniqid();

		$sSsoMode = 'ServiceProviderMock';

		$aCurrentModuleSettings = MetaModel::GetConfig()->GetModuleSetting('combodo-hybridauth', 'providers', []);
		$aServiceProviderConf = array_merge($aCurrentModuleSettings,
			[
				"$sSsoMode" => [
					'adapter' => 'Combodo\iTop\HybridAuth\Test\Provider\ServiceProviderMock',
					'keys' => [
						'id' => 'ID',
						'secret' => 'SECRET',
					],
					'enabled' => true,
				],
			]
		);

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', $aServiceProviderConf);
		$this->sLoginMode = "hybridauth-".$sSsoMode;
		$this->InitLoginMode($this->sLoginMode);

		//no provisioning
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_user', false);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'synchronize_contact', false);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'refresh_existing_users', false);

	}

	protected function InitLoginMode($sLoginMode)
	{
		$aAllowedLoginTypes = MetaModel::GetConfig()->GetAllowedLoginTypes();
		if (!in_array($sLoginMode, $aAllowedLoginTypes)) {
			$aAllowedLoginTypes[] = $sLoginMode;
			MetaModel::GetConfig()->SetAllowedLoginTypes($aAllowedLoginTypes);
		}
	}

	protected function tearDown(): void
	{
		parent::tearDown();

		if (!is_null($this->sConfigTmpBackupFile) && is_file($this->sConfigTmpBackupFile)) {
			//put config back
			$sConfigPath = MetaModel::GetConfig()->GetLoadedFile();
			@chmod($sConfigPath, 0770);
			$oConfig = new Config($this->sConfigTmpBackupFile);
			$oConfig->WriteToFile($sConfigPath);
			@chmod($sConfigPath, 0440);
		}

		if (is_file(ServiceProviderMock::GetFileConfPath())) {
			@unlink(ServiceProviderMock::GetFileConfPath());
		}

		$_SESSION = [];
	}

	private function CreatePersonByEmail($sEmail) : Person {
		$oOrg = $this->CreateOrganization($this->sUniqId);

		/** @var Person $oPerson */
		$oPerson = $this->createObject('Person', [
			'name' => $sEmail,
			'first_name' => $sEmail,
			'email' => $sEmail,
			'org_id' => $oOrg->GetKey(),
		]);

		return $oPerson;
	}

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

		$oFoundUser = LoginWebPage::FindUser($sEmail);
		self::assertNotNull($oFoundUser);
		$this->assertEquals($oFoundUser->GetKey(), $oReturnedCreatedUser->GetKey(), "User creation OK");

		self::assertEquals($sEmail, $oFoundUser->Get('login'));
		self::assertEquals($oFoundPerson->GetKey(), $oFoundUser->Get('contactid'));
		self::assertEquals('EN US', $oFoundUser->Get('language'));
		$this->assertUserProfiles($oFoundUser, ['Portal user']);
	}


	public function testDoPersonProvisioning_CreationOK(){
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

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', "Configuration Manager");

		$this->CreateOrganization($sDefaultOrgName);
		$this->CreateOrganization($sOrgName2);


		$sOrgName = $this->InvokeNonPublicMethod(ProvisioningService::class, 'GetOrganizationForProvisioning', ProvisioningService::GetInstance(), [$this->sLoginMode, $sIdpOrgName]);
		$this->assertEquals($sExpectedOrgReturned, $sOrgName);
	}

	public function testDoUserProvisioning_UserCreationOK() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Portal user');
		MetaModel::GetConfig()->SetDefaultLanguage('FR FR');

		$sEmail = $this->sUniqId."@test.fr";
		$oPerson = $this->CreatePersonByEmail($sEmail);

		self::assertNull(LoginWebPage::FindUser($sEmail));

		$oReturnedCreatedUser = ProvisioningService::GetInstance()->DoUserProvisioning($this->sLoginMode, $sEmail , $oPerson, new Profile());
		$oFoundUser = LoginWebPage::FindUser($sEmail);
		self::assertNotNull($oFoundUser);
		$this->assertEquals($oFoundUser->GetKey(), $oReturnedCreatedUser->GetKey(), "User creation OK");

		self::assertEquals($sEmail, $oFoundUser->Get('login'));
		self::assertEquals($oPerson->GetKey(), $oFoundUser->Get('contactid'));
		self::assertEquals('FR FR', $oFoundUser->Get('language'));
		$this->assertUserProfiles($oFoundUser, ['Portal user']);
		return $oPerson;
	}

	public function testDoUserProvisioning_UserUpdateOK() {
		$oPerson = $this->testDoUserProvisioning_UserCreationOK();

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'refresh_existing_users', true);
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Configuration Manager');
		MetaModel::GetConfig()->SetDefaultLanguage('EN US');

		$sEmail = $this->sUniqId."@test.fr";
		ProvisioningService::GetInstance()->DoUserProvisioning($this->sLoginMode, $sEmail , $oPerson, new Profile());
		$oFoundUser = LoginWebPage::FindUser($sEmail);
		self::assertNotNull($oFoundUser);

		self::assertEquals($sEmail, $oFoundUser->Get('login'));
		self::assertEquals($oPerson->GetKey(), $oFoundUser->Get('contactid'));
		self::assertEquals('FR FR', $oFoundUser->Get('language'));
		$this->assertUserProfiles($oFoundUser, ['Configuration Manager']);
	}

	public function testDoUserProvisioning_UserAlreadyExistAndNoUpdateConfigured() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Configuration Manager');

		$sEmail = $this->sUniqId."@test.fr";

		$oSet = new \ormLinkSet(\UserExternal::class, 'profile_list', \DBObjectSet::FromScratch(\URP_UserProfile::class));
		$oSet->AddItem(MetaModel::NewObject('URP_UserProfile', array('profileid' => self::$aURP_Profiles['Portal user'], 'reason' => 'UNIT Tests')));

		$this->createObject('UserExternal', [
			'login' => $sEmail,
			'profile_list' => $oSet,
		]);

		$oReturnedUser = ProvisioningService::GetInstance()->DoUserProvisioning($this->sLoginMode, $sEmail , $this->CreatePersonByEmail($sEmail), new Profile());
		$this->assertUserProfiles($oReturnedUser, ['Portal user']);
		self::assertEquals(0, $oReturnedUser->Get('contactid'));
	}

	private function assertUserProfiles(UserExternal $oUser, $aExpectedProfiles)
	{
		$oProfilesSearch = new DBObjectSearch('URP_Profiles');
		$oProfilesSearch->AllowAllData();
		$oProfilesSet = new DBObjectSet($oProfilesSearch);
		$aAllProfilNamesById = [];
		while ($oProfile = $oProfilesSet->Fetch())
		{
			$aAllProfilNamesById[$oProfile->GetKey()] = $oProfile->GetName();
		}

		$oProfilesSet = $oUser->Get('profile_list');

		$aFoundProfileNames=[];
		while ($oProfile = $oProfilesSet->Fetch())
		{
			$aFoundProfileNames[]=$aAllProfilNamesById[$oProfile->Get('profileid')];
		}
		sort($aExpectedProfiles);
		sort($aFoundProfileNames);
		$this->assertEquals($aExpectedProfiles, $aFoundProfileNames);
	}

	public function SetGroupsToProfile(string $sLoginMode, $groupToProfilesValue) {
		$aProviderConf = \Combodo\iTop\HybridAuth\Config::GetProviderConf($sLoginMode);
		$aProviderConf['groups_to_profiles'] = $groupToProfilesValue;

		$aProviders = \Combodo\iTop\HybridAuth\Config::Get('providers');
		$aProviders[str_replace("hybridauth-", "", $sLoginMode)]=$aProviderConf;

		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'providers', $aProviders);
	}

	public function ValidateSynchronizeProfiles_FallbackToDefaultProfileUse(Profile $oUserProfile, $aExpectedProfile=['Portal user'], $bExpectException=false) {
		$sEmail = $this->sUniqId."@test.fr";
		$oUser = MetaModel::NewObject('UserExternal');
		$oUser->Set('login', $sEmail);

		ProvisioningService::GetInstance()->SynchronizeProfiles($this->sLoginMode, $sEmail , $oUser, $oUserProfile, "");
		if (! $bExpectException){
			$this->assertUserProfiles($oUser, $aExpectedProfile);
		}
	}

	public function testSynchronizeProfiles_NoGroupReturnedBySP_UseDefaultProfile() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Portal user');
		$this->SetGroupsToProfile($this->sLoginMode, ['A' => 'B']);

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
		$this->SetGroupsToProfile($this->sLoginMode, "groups_to_profiles set as string instead of array");

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1'];
		$this->ValidateSynchronizeProfiles_FallbackToDefaultProfileUse($oUserProfile);
	}

	public function testSynchronizeProfiles_NoProfilesFoundViaGroupMatchingConfiguration_UserCreationError() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Portal user');
		$this->SetGroupsToProfile($this->sLoginMode, ["sp_id2" => "itop_profile2"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1'];

		$this->ValidateSynchronizeProfiles_FallbackToDefaultProfileUse($oUserProfile);
	}

	public function testSynchronizeProfiles_OnlyUnexistingiTopProfilesToProvision_UserCreationError() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Configuration Manager');
		$this->SetGroupsToProfile($this->sLoginMode, ["sp_id1" => "unexisting_itop_profile1"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1', 'sp_id2'];

		$this->expectExceptionMessage("no valid URP_Profile to attach to user");
		$this->expectException(HybridProvisioningAuthException::class);
		$this->ValidateSynchronizeProfiles_FallbackToDefaultProfileUse($oUserProfile, null, true);
	}

	public function testSynchronizeProfiles_SomeUnexistingProfileToProvision_UserCreationWithFallbackProfileAndWarning() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Configuration Manager');
		$this->SetGroupsToProfile($this->sLoginMode, ["sp_id1" => "unexisting_itop_profile1", "sp_id2" => "Portal user"]);

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
		$this->SetGroupsToProfile($this->sLoginMode, ["sp_id1" => "Configuration Manager", "sp_id2" => ["Administrator", "Portal user"]]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1', 'sp_id2'];
		$this->ValidateSynchronizeProfiles_FallbackToDefaultProfileUse($oUserProfile, ["Administrator", "Configuration Manager", "Portal user"]);
	}

	public function testSynchronizeProfiles_NoExistingProfileToUpdate_NoProfileModificationAndNoExceptionToLetUserLogInWithPreviousProfiles() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Administrator');
		$this->SetGroupsToProfile($this->sLoginMode, ["sp_id1" => "unexisting itop profile"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1'];
		$sEmail = $this->sUniqId."@test.fr";

		$aInitialProfileNames=[
			"Configuration Manager", //to remove after provisioning update
			"Change Approver", //to keep
		];

		$oProfilesSet = new \ormLinkSet(\UserExternal::class, 'profile_list', \DBObjectSet::FromScratch(\URP_UserProfile::class));
		foreach ($aInitialProfileNames as $sProfileName){
			$this->AddProfileToLnk($oProfilesSet, $sProfileName);
		}

		$oUser = $this->createObject(UserExternal::class, [
			'login' => $sEmail,
			'profile_list' => $oProfilesSet
		]);
		$this->assertUserProfiles($oUser, $aInitialProfileNames);
		ProvisioningService::GetInstance()->SynchronizeProfiles($this->sLoginMode, $sEmail , $oUser, $oUserProfile, "");
		$this->assertUserProfiles($oUser, $aInitialProfileNames);
	}

	public function testSynchronizeProfiles_UserUpdateOK() {
		MetaModel::GetConfig()->SetModuleSetting('combodo-hybridauth', 'default_profile', 'Administrator');
		$this->SetGroupsToProfile($this->sLoginMode, ["sp_id1" => "Change Approver", "sp_id2" => "Portal user"]);

		$oUserProfile = new Profile();
		$oUserProfile->data['groups']= ['sp_id1', 'sp_id2'];
		$sEmail = $this->sUniqId."@test.fr";

		$oProfilesSet = new \ormLinkSet(\UserExternal::class, 'profile_list', \DBObjectSet::FromScratch(\URP_UserProfile::class));
		$aInitialProfileNames=[
			"Configuration Manager", //to remove after provisioning update
			"Change Approver", //to keep
		];
		foreach ($aInitialProfileNames as $sProfileName){
			$this->AddProfileToLnk($oProfilesSet, $sProfileName);
		}

		$oUser= $this->createObject(UserExternal::class, [
			'login' => $sEmail,
			'profile_list' => $oProfilesSet
		]);
		$this->assertUserProfiles($oUser, $aInitialProfileNames);

		ProvisioningService::GetInstance()->SynchronizeProfiles($this->sLoginMode, $sEmail , $oUser, $oUserProfile, "");
		$this->assertUserProfiles($oUser, ['Portal user', "Change Approver"]);
	}

	private function AddProfileToLnk($oProfilesSet, $sProfileName)
	{
		$oProfilesSet->AddItem(MetaModel::NewObject('URP_UserProfile', array('profileid' => self::$aURP_Profiles[$sProfileName], 'reason' => 'UNIT Tests')));
	}
}


