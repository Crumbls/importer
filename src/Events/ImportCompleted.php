<?php

namespace Crumbls\Importer\Events;

class ImportCompleted
{
	public string $importId;
	public array $stats;

	public function __construct(string $importId, array $stats)
	{
		$this->importId = $importId;
		$this->stats = $stats;
	}
}