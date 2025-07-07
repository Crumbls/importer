<?php

namespace Crumbls\Importer\States\WpXmlDriver;

use Crumbls\Importer\Facades\Storage;
use Crumbls\Importer\States\XmlDriver\ProcessingState as BaseState;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Parsers\WordPressXmlStreamParser;
use Crumbls\Importer\Support\SourceResolverManager;
use Crumbls\Importer\Resolvers\FileSourceResolver;
use Crumbls\Importer\States\Shared\FailedState;
use Illuminate\Database\Schema\Blueprint;

class ExtractState extends BaseState
{
    public function onEnter(): void
    {
        $import = $this->getImport();
        if (!$import instanceof ImportContract) {
            throw new \RuntimeException('Import contract not found in context');
        }

        try {
            $metadata = $import->metadata ?? [];
            
            // Get the storage driver from metadata
            $storage = Storage::driver($metadata['storage_driver'])
                ->configureFromMetadata($metadata);

            // Set up the source resolver
            $sourceResolver = new SourceResolverManager();
            $sourceResolver->addResolver(new FileSourceResolver($import->source_type, $import->source_detail));
            
            // Create and configure the WordPress XML parser
            $parser = new WordPressXmlStreamParser([
                'batch_size' => 100,
                'extract_meta' => true,
                'extract_comments' => true,
                'extract_terms' => true,
                'extract_users' => true,
                'memory_limit' => '256M',
            ]);
            
            // Parse the XML file
            $stats = $parser->parse($import, $storage, $sourceResolver);

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

}