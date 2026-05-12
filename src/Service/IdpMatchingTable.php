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
	/** @var string|array $serviceProviderKey */
	private $serviceProviderKey;
	private ?string $sSeparator;

	/**
	 * @param string $sLoginMode
	 * @param mixed $aMatchingTable : matching definition between idp response and itop object names. should be an array or no matching applied
	 * @param string $sMatchingTableConfigurationKey : used here only for supportability (logging/exception messages)
	 * @param string|array $serviceProviderKey : key to fetch in IdP response
	 * @param string|null $sSeparator : separator to explode IdP response in array if needed
	 */
	public function __construct(string $sLoginMode, mixed $aMatchingTable, string $sMatchingTableConfigurationKey, string $serviceProviderKey, ?string $sSeparator)
	{
		$this->sLoginMode = $sLoginMode;
		$this->aMatchingTable = $aMatchingTable;
		$this->sMatchingTableConfigurationKey = $sMatchingTableConfigurationKey;
		$this->serviceProviderKey = $serviceProviderKey;
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
	public function GetObjectNamesFromIdpMatchingTable(string $sEmail, Profile $oUserProfile): ?array
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
		$aSpIds = IdpMatchingTable::GetIdpFieldValue($oUserProfile, $this->serviceProviderKey);
		if (is_string($aSpIds) && !is_null($this->sSeparator)) {
			$aFields = [];
			foreach (explode($this->sSeparator, $aSpIds) as $sValue) {
				$aFields[] = trim($sValue);
			}
			$aSpIds = $aFields;
		} elseif (!is_array($aSpIds)) {
			IssueLog::Warning("Service provider not an array", null, ['serviceProviderKey' => $this->serviceProviderKey, 'aSpIds' => $aSpIds]);

			return null;
		}

		IssueLog::Debug("Service provider contains proper value", null, ['serviceProviderKey' => $this->serviceProviderKey, 'aSpIds' => $aSpIds]);

		foreach ($aSpIds as $sSpGroupId) {
			$profileName = $this->aMatchingTable[$sSpGroupId] ?? null;
			if (is_null($profileName)) {
				IssueLog::Debug(
					"Service provider ID does not match any configured iTop name",
					HybridAuthLoginExtension::LOG_CHANNEL,
					['sp_id' => $sSpGroupId, $this->sMatchingTableConfigurationKey => $this->aMatchingTable]
				);
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
				'idp_key'                             => $this->serviceProviderKey,
				'sp_ids'                              => $aSpIds,
				$this->sMatchingTableConfigurationKey => $this->aMatchingTable,
			];

			IssueLog::Error(
				"No matching between IdP response and configured table ($this->sMatchingTableConfigurationKey)",
				HybridAuthLoginExtension::LOG_CHANNEL,
				$aContext
			);
		}

		return $aCurrentProfilesName;
	}

	/**
	 * @param \Hybridauth\User\Profile $oUserProfile
	 * @param array|string $serviceProviderKey
	 *
	 *  @return array|string|null
	 */
	public static function GetIdpFieldValue(Profile $oUserProfile, $serviceProviderKey)
	{
		return self::RecursiveGetIdpFieldValue($oUserProfile->data, $serviceProviderKey);
	}

	/**
	 * @param array $aData
	 * @param array|string $serviceProviderKey
	 *
	 * @return array|string|null
	 */
	private static function RecursiveGetIdpFieldValue(array $aData, $serviceProviderKey)
	{
		if (is_string($serviceProviderKey) || is_int($serviceProviderKey)) {
			return $aData[$serviceProviderKey] ?? null;
		}

		$first = array_key_first($serviceProviderKey);
		if (is_null($first)) {
			return null;
		}

		$nextKey = $serviceProviderKey[$first];
		if (is_int($nextKey)) {
			return $aData[$nextKey] ?? null;
		}

		$aNextData = $aData[$first] ?? null;
		if (is_null($aNextData)) {
			return null;
		}

		return self::RecursiveGetIdpFieldValue($aNextData, $nextKey);
	}
}
