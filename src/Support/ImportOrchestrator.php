<?php


// src/Support/ImportOrchestrator.php
namespace Crumbls\Importer\Support;

use Crumbls\Importer\Constants\ImportState;
use Crumbls\Importer\Contracts\ImportStepInterface;

use Crumbls\Importer\Drivers\AbstractDriver;
use Crumbls\Importer\Events\ImportStarted;
use Crumbls\Importer\Events\ImportCompleted;
use Crumbls\Importer\Events\ImportFailed;
use Crumbls\Importer\Events\StepStarted;
use Crumbls\Importer\Events\StepCompleted;
use Crumbls\Importer\Exceptions\ImportException;
use Crumbls\Importer\Steps\ReadStep;
use Crumbls\Importer\Steps\ValidateStep;
use Crumbls\Importer\Traits\HasDriver;
use Crumbls\Importer\Traits\HasId;

class ImportOrchestrator
{
	use HasDriver,
		HasId;

	protected array $steps = [];
	protected array $data = [];

	public static function create(string $id) : self {
		$ret = new self;
		$ret->setId($id);
		return $ret;
	}

	public function addStep(string $step): self
	{
		$this->steps[] = $step;
		return $this;
	}

	public function execute()
	{
		event(new ImportStarted($this->id));

		try {
			$stats = ['started_at' => now()];

			foreach ($this->steps as $stepName) {
				event(new StepStarted($this->id, $stepName));

				$startTime = microtime(true);

				$step = new $stepName();

				$f = new ReadStep();
				$f->setId($this->id);

//				$this->state = $stepName;

				$step->setDriver($this->getDriver());

				$this->data = $step->execute();

				$endTime = microtime(true);

				$stepStats = [
					'duration' => round($endTime - $startTime, 2),
					'records_processed' => count($this->data),
				];

				event(new StepCompleted($this->id, $stepName, $stepStats));

				$stats['steps'][$stepName] = $stepStats;
			}

			$stats['completed_at'] = now();

			event(new ImportCompleted($this->id, $stats));

			return $this->data;
		} catch (\Exception $e) {
			event(new ImportFailed($this->id, $e, [
				'last_step' => $stepName ?? null,
				'last_checkpoint' => $step->getCheckpoint('position') ?? null,
			]));

			throw $e;
		}
	}

	public function resumeFrom(ImportState $state)
	{
		$startIndex = $this->findStepIndex($state);

		if ($startIndex === false) {
			throw new ImportException("Cannot resume from state {$state->value}");
		}

		$this->steps = array_slice($this->steps, $startIndex);
		return $this->execute();
	}

	protected function findStepIndex(ImportState $state)
	{
		foreach ($this->steps as $index => $step) {
			if ($step->hasState($state)) {
				return $index;
			}
		}

		return false;
	}
}