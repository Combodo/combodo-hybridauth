<?php

namespace Combodo\iTop\HybridAuth;

use Exception;
use Throwable;
use utils;

class HybridProvisioningAuthException extends Exception {
	public array $aContext = [];

	public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null, array $aContext = [])
	{
		if (!is_null($previous)) {
			$sStack = $previous->getTraceAsString();
		} else {
			$sStack = $this->getTraceAsString();
		}

		$this->aContext['error'] = $this->sError;
		$this->aContext['stack'] = $sStack;
		parent::__construct($message, $code, $previous);
	}
}
