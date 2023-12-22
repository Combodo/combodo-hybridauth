<?php

namespace Combodo\iTop\HybridAuth\Repository;

use DBSearch;
use DBObjectSet;

class SssConfigRepository {
	public function GetOrganizations(): array {
		$oObjFilter = DBSearch::FromOQL("SELECT Organization");
		$oSet = new DBObjectSet($oObjFilter);

		$aOrg = [];
		while($oOrg = $oSet->Fetch()){
			$aOrg []= $oOrg->Get('friendlyname');
		}
		return $aOrg;
	}
}
