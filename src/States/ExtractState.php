<?php

namespace Crumbls\Importer\States;

use Crumbls\Importer\Facades\Storage;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Parsers\WordPressXmlStreamParser;
use Crumbls\Importer\States\Concerns\DetectsQueueWorkers;
use Crumbls\Importer\Support\SourceResolverManager;
use Crumbls\Importer\Resolvers\FileSourceResolver;
use Crumbls\Importer\States\Shared\FailedState;
use Crumbls\Importer\Exceptions\ImportException;
use Exception;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;

class ExtractState extends AbstractState
{

	use DetectsQueueWorkers;

    public function onEnter(): void
    {
		dd(__LINE__);
        // Base implementation - override in specific drivers
        // This allows the WpXmlDriver to extend without throwing exceptions
    }

	public function execute() : bool {
		dd(__LINE__);
	}

	public function onExit(): void {
		dd(__LINE__);
	}

	public function dispatchJob($job)
	{
		dd(__LINE__);
		$jobInstance = dispatch($job);
		$jobId = method_exists($jobInstance, 'getJobId') ? $jobInstance->getJobId() : null;

		$import->metadata['job_id'] = $jobId;
		$import->metadata['job_status'] = 'queued';
		$import->save();

		return $jobId;
	}

	public function updateJobStatus($status)
	{
		dd(__LINE__);

		$import->metadata['job_status'] = $status;
		$import->save();
	}

	public function getJobStatus() : ?string
	{
		$import = $this->getRecord();
		$metadata = $import->metadata ?? [];
		return Arr::get($metadata, 'job_status', null);
	}

	public function getJobId()
	{
		dd(__LINE__);

		return Arr::get($import->metadata, 'job_id', null);
	}

}