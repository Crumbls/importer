<?php

namespace Crumbls\Importer\Events;

class ImportFailed
{
	public string $importId;
	public \Throwable $exception;
	public array $context;

	public function __construct(string $importId, \Throwable $exception, array $context = [])
	{
		$this->importId = $importId;
		$this->exception = $exception;
		$this->context = $context;
	}
}
