<?php

namespace Crumbls\Importer\States\CsvDriver;

use Crumbls\Importer\Console\Prompts\CsvDriver\ConfigureHeadersPrompt;
use Crumbls\Importer\Resolvers\FileSourceResolver;
use Crumbls\Importer\States\Concerns\HasStorageDriver;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Support\SourceResolverManager;
use Illuminate\Support\Facades\Log;

class AnalyzingState extends AbstractState
{
    use HasStorageDriver;


	public static function getCommandPrompt() : string {
		return ConfigureHeadersPrompt::class;
	}

	public function onEnter(): void
	{
		return;
	}

    /**
     * Check if this state needs configuration before it can execute
     */
    public function needsConfiguration(): bool
    {
        $record = $this->getRecord();
        $metadata = $record->metadata ?? [];

		if (!array_key_exists('headers', $metadata) || !is_array($metadata['headers']) || !$metadata['headers']) {
			return true;
		}

		return false;
        dump($metadata);
		// If headers are already set, no configuration needed
        $headers = $metadata['headers'] ?? null;
        $hasHeader = $metadata['headers_first_row'] ?? null;
		return !($headers && is_array($headers)) && ($hasHeader === null);
    }

    public function execute(): bool
    {
        $record = $this->getRecord();
		if ($this->needsConfiguration()) {
			throw new \Exception('Configuration is needed');
		}

	    $this->transitionToNextState($record);

        return true;
    }

    protected static function generateColumnNames(array $row): array
    {
        $count = count($row);
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $names[] = 'col_' . ($i + 1);
        }
        return $names;
    }
}
