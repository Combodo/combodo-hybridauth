<?php

namespace Combodo\iTop\HybridAuth\Service;

use Combodo\iTop\HybridAuth\HybridAuthLoginExtension;
use Hybridauth\User\Profile;
use IssueLog;

class IdpMatchingTable
{
	private string $sLoginMode;
	private mixed $aMatchingTable;
	private string $sMatchingTableConfigurationKey;
	private string $sServiceProviderProfileKey;
	private ?string $sSeparator;

	/**
	 * @param string $sLoginMode
	 * @param mixed $aMatchingTable : matching definition between idp response and itop object names. should be an array or no matching applied
	 * @param string $sMatchingTableConfigurationKey : used here only for supportability (logging/exception messages)
	 * @param string $sServiceProviderProfileKey : key to fetch in IdP response
	 * @param string|null $sSeparator : separator to explode IdP response in array if needed
	 */
	public function __construct(string $sLoginMode, mixed $aMatchingTable, string $sMatchingTableConfigurationKey, string $sServiceProviderProfileKey, ?string $sSeparator)
	{
		$this->sLoginMode = $sLoginMode;
		$this->aMatchingTable = $aMatchingTable;
		$this->sMatchingTableConfigurationKey = $sMatchingTableConfigurationKey;
		$this->sServiceProviderProfileKey = $sServiceProviderProfileKey;
		$this->sSeparator = $sSeparator;
	}

	/**
	 * Use IdP response to compute matching table and return a list of names
	 *
	 * @param string $sEmail
	 * @param \Combodo\iTop\HybridAuth\Service\Profile $oUserProfile
	 *
	 * @return array|null: return null when matching is not possible somehow. either it is not configured either IdP response does not fit
	 * * @throws \Combodo\iTop\HybridAuth\HybridProvisioningAuthException
	 */
	public function GetObjectNamesFromIdpMatchingTable(string $sEmail, Profile $oUserProfile) : ?array
	{
		if (is_null($this->aMatchingTable)) {
			return null;
		}

		IssueLog::Debug(__METHOD__.": use matching table", HybridAuthLoginExtension::LOG_CHANNEL, [$this->sMatchingTableConfigurationKey => $this->aMatchingTable]);

		if (!is_array($this->aMatchingTable)) {
			IssueLog::Warning("Configuration issue with $this->sMatchingTableConfigurationKey section", null, ['login_mode' => $this->sLoginMode, $this->sMatchingTableConfigurationKey => $this->aMatchingTable]);

			return null;
		}

		$aCurrentProfilesName = [];
		$aSpGroupsIds = $oUserProfile->data[$this->sServiceProviderProfileKey] ?? null;
		if (is_string($aSpGroupsIds) && !is_null($this->sSeparator)) {
			$aFields = [];
			foreach (explode($this->sSeparator, $aSpGroupsIds) as $sValue) {
				$aFields[] = trim($sValue);
			}
			$aSpGroupsIds = $aFields;
		} else if (!is_array($aSpGroupsIds)) {
			IssueLog::Warning("Service provider $this->sServiceProviderProfileKey not an array", null, [$this->sServiceProviderProfileKey => $aSpGroupsIds]);

			return null;
		}

		IssueLog::Debug("Service provider contains proper $this->sServiceProviderProfileKey value", null, [$this->sServiceProviderProfileKey => $aSpGroupsIds]);

		foreach ($aSpGroupsIds as $sSpGroupId) {
			$profileName = $this->aMatchingTable[$sSpGroupId] ?? null;
			if (is_null($profileName)) {
				IssueLog::Warning("Service provider ID does not match any configured iTop name",
					HybridAuthLoginExtension::LOG_CHANNEL, ['sp_id' => $sSpGroupId, $this->sMatchingTableConfigurationKey => $this->aMatchingTable]);
				continue;
			}

			if (is_array($profileName)) {
				foreach ($profileName as $sProfileName) {
					$aCurrentProfilesName[] = $sProfileName;
				}
			} else {
				$aCurrentProfilesName[] = $profileName;
			}
		}

		if (count($aCurrentProfilesName) == 0) {
			$aContext = [
				'login_mode'                          => $this->sLoginMode,
				'email'                               => $sEmail,
				'idp_key'                             => $this->sServiceProviderProfileKey,
				'sp_ids'                              => $aSpGroupsIds,
				$this->sMatchingTableConfigurationKey => $this->aMatchingTable,
			];

			IssueLog::Error("No matching between IdP $this->sServiceProviderProfileKey response and configured table ($this->sMatchingTableConfigurationKey)",
				HybridAuthLoginExtension::LOG_CHANNEL, $aContext);
		}

		return $aCurrentProfilesName;
	}

}
