<?php

namespace Crumbls\Importer\States\XmlDriver;

use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\States\ProcessingState as BaseState;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Services\XmlParsingService;
use Crumbls\Importer\Parsers\WordPressXmlParser;
use Crumbls\Importer\Exceptions\ImportException;

class ProcessingState extends AbstractState
{
    public function onEnter(): void
    {
        $import = $this->getRecord();
        if (!$import instanceof ImportContract) {
            throw new \RuntimeException('Import contract not found in context');
        }

        try {
            $metadata = $import->metadata ?? [];
            $connectionName = $metadata['sqlite_connection'] ?? null;
            
            if (!$connectionName) {
                throw new \RuntimeException('SQLite connection not found in metadata');
            }

            // Create the appropriate parser based on driver
            $parser = $this->createParser($import);
            
            // Parse the file into database tables
            $parsingService = new XmlParsingService($import);
            $stats = $parsingService
                ->setParser($parser)
                ->setConnectionName($connectionName)
                ->parseFile($import->source_detail);

            // Update import with parsing results
            $import->update([
                'state' => static::class,
                'metadata' => array_merge($metadata, [
                    'parsing_completed' => true,
                    'parsing_stats' => $stats,
                    'processed_at' => now()->toISOString(),
                ])
            ]);

        } catch (\Exception $e) {
            $import->update([
                'state' => FailedState::class,
                'error_message' => $e->getMessage(),
                'failed_at' => now(),
            ]);
            throw $e;
        }
    }

    protected function createParser(ImportContract $import): \Crumbls\Importer\Contracts\XmlParserContract
    {
		// TODO: Implement parser creation logic based on import type
		throw ImportException::parserCreationFailed('Parser creation not implemented');
    }
}