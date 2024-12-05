<?php

namespace Crumbls\Importer\Events;

class ImportStarted
{
	public string $importId;

	public function __construct(string $importId)
	{
		$this->importId = $importId;
	}
}