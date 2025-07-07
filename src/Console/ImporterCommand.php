<?php

declare(strict_types = 1);

namespace Crumbls\Importer\Console;

use Crumbls\Importer\Drivers\AutoDriver;
use Crumbls\Importer\Exceptions\CompatibleDriverNotFoundException;
use Crumbls\Importer\Exceptions\InputNotProvided;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\Import;
use Crumbls\Importer\Support\SourceResolverManager;
use Crumbls\Importer\Resolvers\FileSourceResolver;
use Crumbls\Importer\Facades\Storage;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage as LaravelStorage;

class ImporterCommand extends Command
{
    protected $signature = 'importer {input? : Import ID or file path} {--source-type= : Source type (file::absolute, disk::public, etc.)} {--auto : Run automatically without user interaction}';
    protected $description = 'Import command - starting fresh';

    public function handle(): int
    {
        /**
         * Clean up.
         */
        Import::all()->each(function (Import $import) {
            $import->delete();
        });

        $this->info(__('importer::importer.command.ready'));

        $input = $this->argument('input');

        if (!$input) {
            [$sourceType, $input] = $this->getRandomFile();
            $this->info("No input provided, randomly selected: {$sourceType} -> {$input}");
            
            // Set the source type for this random selection
            $this->input->setOption('source-type', $sourceType);
        }

        if (!$input) {
            throw new InputNotProvided();
        }

        $record = null;
        $sourceType = $this->option('source-type') ?: 'file::absolute';

        // If we have a source-type specified, always use handleFile
        // Otherwise, check if it's a file path
        if ($sourceType !== 'file::absolute' || is_file($input)) {
            $record = $this->handleFile($input);
        } else {
            dd($input);
        }

        if (!$record) {
            return 0;
        }

        $message = $record->wasRecentlyCreated ? 'importer::importer.import.created' : 'importer::importer.import.loaded';

        $this->info(__($message) . ': ' . $record->getKey());

        $driver = $record->getDriver();
        $driverClass = get_class($driver);

        if ($driverClass == AutoDriver::class) {
            $this->info(__('importer::importer.import.using_driver', ['driver' => $driverClass]));
            $this->processDriver($record);

            $record->refresh();

            $driver = $record->getDriver();
            $driverClass = get_class($driver);

            if ($driverClass == AutoDriver::class) {
                throw new CompatibleDriverNotFoundException();
            }
        }

        $this->info(__('importer::importer.import.using_driver', ['driver' => $driverClass]));
        $this->processDriver($record);

        // Debug SQLite database information after processing
        $this->displayDatabaseStats($record);

        return 0;
    }

    public function handleFile(string $input) : ImportContract {
        // Determine source type
        $sourceType = $this->option('source-type') ?: 'file::absolute';
        
        $this->info("Processing source: {$sourceType} -> {$input}");
        
        // Test the source resolver
        $this->testSourceResolver($sourceType, $input);

        $record = Import::firstOrCreate([
            'source_type' => $sourceType,
            'source_detail' => $input,
        ]);

        return $record;
    }

    protected function testSourceResolver(string $sourceType, string $sourceDetail): void 
    {
        $this->info("Testing SourceResolver...");
        
        // Create resolver manager
        $manager = new SourceResolverManager();
        $manager->addResolver(new FileSourceResolver($sourceType, $sourceDetail));
        
        try {
            // Test resolve
            $resolvedPath = $manager->resolve($sourceType, $sourceDetail);
            $this->info("✅ Resolved to: {$resolvedPath}");
            
            // Test metadata
            $metadata = $manager->getMetadata($sourceType, $sourceDetail);
            $this->info("✅ Metadata:");
            $this->table(['Key', 'Value'], collect($metadata)->map(fn($v, $k) => [$k, $v])->toArray());
            
        } catch (\Exception $e) {
            $this->error("❌ SourceResolver failed: " . $e->getMessage());
            throw $e;
        }
    }

    protected function processDriver(ImportContract $record) : void {
        // Clear any cached state machine to ensure we get the current driver's config
        $record->clearStateMachine();
        $stateMachine = $record->getStateMachine();
        $driverConfigClass = $record->driver;
        $preferredTransitions = $driverConfigClass::config()->getPreferredTransitions();

        if (empty($preferredTransitions)) {
            $this->error('No preferred transitions defined for this driver');
            return;
        }

        $currentStateClass = $record->state;

        // If no state is set, use the default state from driver config
        if (!$currentStateClass) {
            $currentStateClass = $driverConfigClass::config()->getDefaultState();
            if ($currentStateClass) {
                $record->update(['state' => $currentStateClass]);
                $record->refresh();
            }
        }

        $iterations = 0;
        $maxIterations = 10;

        while (array_key_exists($currentStateClass, $preferredTransitions) && $iterations < $maxIterations) {
            $nextState = $preferredTransitions[$currentStateClass];

            $this->info("Current state: " . class_basename($currentStateClass));
            $this->info("Next state: " . class_basename($nextState));

            if ($stateMachine->canTransitionTo($nextState)) {
                $originalDriver = $record->driver;
                $stateMachine->transitionTo($nextState);
                $record->refresh();

                // Check if the driver changed (happens in AnalyzingState)
                if ($record->driver !== $originalDriver) {
                    $this->info("✓ Driver changed to: " . class_basename($record->driver));
                    break; // Stop processing, let the command handle the new driver
                }

                $record->update(['state' => $nextState]);
                $record->refresh();
                $currentStateClass = $record->state;
                $this->info("✓ Transitioned to: " . class_basename($currentStateClass));
            } else {
                $this->error("❌ Cannot transition to: " . class_basename($nextState));
                break;
            }

            $iterations++;
        }
    }

    protected function getRandomFile(): array
    {
        $candidates = [];
        
        // Check file::absolute sources (legacy imports directory)
        $absoluteFiles = glob(storage_path('app/private/imports/wp-*.xml'));
        foreach ($absoluteFiles as $file) {
            $candidates[] = ['file::absolute', $file];
        }
        
        // Check disk::local sources
        $localFiles = LaravelStorage::disk('local')->files();
        foreach ($localFiles as $file) {
            if (str_ends_with($file, '.xml')) {
                $candidates[] = ['disk::local', $file];
            }
        }
        
        // Check disk::public sources  
        $publicFiles = LaravelStorage::disk('public')->files();
        foreach ($publicFiles as $file) {
            if (str_ends_with($file, '.xml')) {
                $candidates[] = ['disk::public', $file];
            }
        }

		// TEMP: DEBUGGING
	    /*
	    $candidates = array_values(array_filter($candidates, function ($candidate) {
			return $candidate[0] == 'disk::public';
	    }));
*/
        if (empty($candidates)) {
            throw new InputNotProvided('No XML files found in any supported storage location');
        }
        
        return Arr::random($candidates);
    }

    protected function displayDatabaseStats(ImportContract $import): void
    {
        try {
            $metadata = $import->metadata ?? [];
            
            if (!isset($metadata['storage_driver'])) {
                $this->info('No storage driver found in metadata');
                return;
            }

            // Get the storage driver
            $storage = Storage::driver($metadata['storage_driver'])
                ->configureFromMetadata($metadata);

            $this->info('');
            $this->info('📊 <comment>SQLite Database Statistics</comment>');
            $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            
            // Database file info
            $dbPath = $storage->getStorePath();
            $dbSize = $storage->getSize();
            $this->info("Database Path: <info>{$dbPath}</info>");
            $this->info("Database Size: <info>" . $this->formatBytes($dbSize) . "</info>");
            
            // Get tables and their row counts
            $tables = $storage->getTables();
            $totalRows = 0;
            
            $this->info('');
            $this->info('<comment>Tables and Row Counts:</comment>');
            
            $tableData = [];
            foreach ($tables as $table) {
                $rowCount = $storage->count($table);
                $totalRows += $rowCount;
                $tableData[] = [$table, number_format($rowCount)];
            }
            
            // Display table data in a nice format
            if (!empty($tableData)) {
                $this->table(['Table', 'Rows'], $tableData);
            } else {
                $this->info('No tables found');
            }
            
            $this->info('');
            $this->info("Total Tables: <info>" . count($tables) . "</info>");
            $this->info("Total Rows: <info>" . number_format($totalRows) . "</info>");
            $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            
            // Show analysis results if available
            if (isset($metadata['analysis_results'])) {
                $this->displayAnalysisResults($metadata['analysis_results']);
            }
            
        } catch (\Exception $e) {
            $this->error('Failed to retrieve database stats: ' . $e->getMessage());
        }
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    protected function displayAnalysisResults(array $analysis): void
    {
        $this->info('');
        $this->info('🔍 <comment>WordPress Data Analysis</comment>');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        
        // Summary
        if (isset($analysis['summary'])) {
            $summary = $analysis['summary'];
            $this->info('<comment>Summary:</comment>');
            $this->info("Total Posts: <info>{$summary['total_posts']}</info>");
            $this->info("Total Meta Fields: <info>{$summary['total_meta_fields']}</info>");
            $this->info("Total Terms: <info>{$summary['total_terms']}</info>");
            $this->info("Total Users: <info>{$summary['total_users']}</info>");
            $this->info('');
        }

        // Post Types
        if (isset($analysis['post_types']) && !empty($analysis['post_types'])) {
            $this->info('<comment>Post Types:</comment>');
            $postTypesData = [];
            foreach ($analysis['post_types'] as $postType => $data) {
                $statusInfo = [];
                foreach ($data['statuses'] as $status => $count) {
                    $statusInfo[] = "{$status}: {$count}";
                }
                $postTypesData[] = [$postType, $data['total_count'], implode(', ', $statusInfo)];
            }
            $this->table(['Post Type', 'Count', 'Statuses'], $postTypesData);
        }

        // Taxonomies
        if (isset($analysis['taxonomies']) && !empty($analysis['taxonomies'])) {
            $this->info('<comment>Taxonomies:</comment>');
            $taxonomiesData = [];
            foreach ($analysis['taxonomies'] as $taxonomy => $data) {
                $sampleTerms = array_column($data['sample_terms'], 'name');
                $taxonomiesData[] = [
                    $taxonomy, 
                    $data['term_count'], 
                    implode(', ', array_slice($sampleTerms, 0, 3))
                ];
            }
            $this->table(['Taxonomy', 'Terms', 'Sample Terms'], $taxonomiesData);
        }

        // Most Common Meta Fields
        if (isset($analysis['summary']['most_common_meta_fields']) && !empty($analysis['summary']['most_common_meta_fields'])) {
            $this->info('<comment>Most Common Meta Fields:</comment>');
            $metaData = [];
            foreach (array_slice($analysis['summary']['most_common_meta_fields'], 0, 10) as $field => $count) {
                $metaData[] = [$field, number_format($count)];
            }
            $this->table(['Meta Field', 'Usage Count'], $metaData);
        }

        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }
}
