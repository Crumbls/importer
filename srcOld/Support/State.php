<?php

namespace Crumbls\Importer\Support;

use Crumbls\Importer\Constants\ImportState;
use Crumbls\Importer\Traits\HasId;
use Illuminate\Support\Facades\Cache;
use Crumbls\Importer\Events\CheckpointCreated;
use Crumbls\Importer\Events\ImportCompleted;
use Crumbls\Importer\Events\ImportFailed;
use Crumbls\Importer\Events\ImportStarted;
use Crumbls\Importer\Events\StepCompleted;
use Crumbls\Importer\Events\StepStarted;

trait State
{
	use HasId;
	protected ImportState $state;
	protected array $checkpoint = [];

	public function getState(): ImportState
	{
		return $this->state;
	}

	public function setState(ImportState $state): self
	{
		$this->state = $state;
		return $this;
	}

	public function hasState(ImportState $state): bool
	{
		return $this->state === $state;
	}

	public function saveCheckpoint(string $key, mixed $value): void
	{
		/*
		event(new CheckpointCreated($this->getId(), class_basename($this), [
			$key => $value
		]));
*/
		try {
			echo __METHOD__ . " import_checkpoint_{$this->getId()}_{$key}" . PHP_EOL;

			Cache::put(
				"import_checkpoint_{$this->getId()}_{$key}",
				$value
			);
		} catch (\Throwable $e) {
			dd($e);
	}
	}

	public function getCheckpoint(string $key): mixed
	{
		echo __METHOD__." import_checkpoint_{$this->getId()}_{$key}".PHP_EOL;

		return Cache::get("import_checkpoint_{$this->getId()}_{$key}");
	}
}