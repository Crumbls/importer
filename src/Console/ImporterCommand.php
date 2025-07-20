<?php

declare(strict_types = 1);

namespace Crumbls\Importer\Console;

use Crumbls\Importer\Console\Prompts\CreateImportPrompt;
use Crumbls\Importer\Console\Prompts\ListImportsPrompt;
use Crumbls\Importer\Jobs\ExtractWordPressXmlJob;
use Crumbls\Importer\States\AutoDriver\PendingState;
use Crumbls\Importer\States\Shared\FailedState;
use Crumbls\Importer\Traits\IsDiskAware;
use Illuminate\Support\Str;
use function Laravel\Prompts\text;

use Crumbls\Importer\Console\Prompts\Sources\FileBrowserPrompt;
use Crumbls\Importer\Drivers\AutoDriver;
use Crumbls\Importer\Exceptions\CompatibleDriverNotFoundException;
use Crumbls\Importer\Exceptions\InputNotProvided;
use Crumbls\Importer\Exceptions\ImportException;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\Import;
use Crumbls\Importer\Resolvers\FileSourceResolver;
use Crumbls\Importer\Facades\Storage;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage as LaravelStorage;

class ImporterCommand extends Command
{

//	use IsDiskAware;

    protected $signature = 'importer {input? : Import ID or file path}';
    protected $description = 'Import command - starting fresh';

    public function handle(): int
    {
	    /**
	     * Clean up.
	     */
	    Import::all()->each(function (Import $import) {
//            $import->delete();
	    });

		$record = null;

	    $record = Import::find(427);
	    $record->driver = AutoDriver::class;
		$record->state = PendingState::class;
		$record->metadata = null;
	    $record->error_message = null;
		$record->save();
	    $record->clearStateMachine();

		if (!$record) {
			$prompt = new ListImportsPrompt($this);
			$record = $prompt->render();
		}

		if (!$record) {
			$prompt = new CreateImportPrompt($this);
			$record = $prompt->render();
			if (!$record) {
				$this->info('Cancelled.');
				return 0;
			}
		}

		return $this->handleRecord($record);
    }

	protected function handleRecord(ImportContract $record) : int {

		$this->clearScreen();

		$message = $record->wasRecentlyCreated ? 'importer::importer.import.created' : 'importer::importer.import.loaded';

		$this->info(__($message) . ': ' . $record->getKey());

		$driver = $record->getDriver();

		$driverClass = get_class($driver);

		if ($driverClass == AutoDriver::class) {
			$this->processDriver($record);

			$record->refresh();
			$driver = $record->getDriver();

			$driverClass = get_class($driver);

			if ($driverClass == AutoDriver::class) {
				throw new CompatibleDriverNotFoundException();
			}

			$record->clearStateMachine();
		}

		$exitCode = $this->processDriver($record);

//		$this->displayDatabaseStats($record);

		return $exitCode;
	}

	/**
	 * @param string $disk
	 * @param string $source
	 * @return ImportContract
	 * @throws CompatibleDriverNotFoundException
	 */
	protected function handleSourceStorage(string $disk, string $source) : ImportContract {
		$disks = $this->getAvailableDisks();

		if (!in_array($disk, $disks) || !LaravelStorage::disk($disk)->fileExists($source)) {
			throw new Exception('File not found....');
		}

		$this->info("Processing source: {$disk} -> {$source}");

		$record = Import::firstOrCreate([
			'source_type' => 'storage',
			'source_detail' => $disk.'::'.$source,
		]);

		return $record;

	}


    protected function processDriver(ImportContract $record): int 
    {
        $this->clearScreen();

        $record->clearStateMachine();
        
        $stateMachine = $record->getStateMachine();
        $driverConfigClass = $record->driver;
        $preferredTransitions = $driverConfigClass::config()->getPreferredTransitions();
        
        if (empty($preferredTransitions)) {
            $this->error('No preferred transitions defined for this driver');
            return 1; // Error exit code
        }

        // Set initial state if needed
        $currentStateClass = $record->state;

        if (!$currentStateClass) {
            $currentStateClass = $driverConfigClass::config()->getDefaultState();
            if ($currentStateClass) {
                $stateMachine->transitionTo($currentStateClass);
                $record->update(['state' => $currentStateClass]);
                $record->refresh();
            }
        }

        $iterations = 0;
        $maxIterations = 50;

        // Main state processing loop
        while ($currentStateClass && $iterations < $maxIterations) {
			// Check if we've hit a failure state and should exit
			if ($this->isFailureState($currentStateClass)) {
				$this->error("❌ Import failed - stopping execution");
				$this->info("State: " . class_basename($currentStateClass));
				
				// Show the failure prompt one time to display details
				$promptClass = $currentStateClass::getCommandPrompt();
				$prompt = new $promptClass($this, $record);
				$prompt->render();
				
				return 1; // Exit with failure code
			}

            // 1. Show state prompt
            $promptClass = $currentStateClass::getCommandPrompt();
            $prompt = new $promptClass($this, $record);
            $result = $prompt->render();
            
            // 2. Execute state logic  
            $state = $stateMachine->getCurrentState();
            $originalDriver = $record->driver;
            $originalStateClass = $currentStateClass;
            
            if (!$state->execute()) {
                $this->error("State execution failed");
                return 1; // Exit with failure code
            }
            
            // 3. Check if state or driver changed during execution
            $record->refresh();
            
            // Check if driver changed during execution
            if ($record->driver !== $originalDriver) {

				$this->info("Driver changed to: " . $record->driver);
                return 0; // Success - driver change is normal
            }
            
            // Check if state changed during execution
            $newStateClass = $record->state;
            if ($newStateClass !== $originalStateClass) {
                $currentStateClass = $newStateClass;
                
                // Check if the new state is a failure state
                if ($this->isFailureState($currentStateClass)) {
                	$this->error("❌ Import transitioned to failed state during execution");
                	$this->info("Failed State: " . class_basename($currentStateClass));
                	
                	// Show the failure prompt to display details
                	$promptClass = $currentStateClass::getCommandPrompt();
                	$prompt = new $promptClass($this, $record);
                	$prompt->render();
                	
                	return 1; // Exit with failure code
                }
            } else {
                // State didn't change - check if it's a waiting/polling state
                $currentState = $stateMachine->getCurrentState();
                if (method_exists($currentState, 'shouldContinuePolling') && $currentState->shouldContinuePolling()) {
                    // State wants to continue polling, add a small delay and continue
                    sleep(1);
                    continue;
                } else {
                    // State execution complete, success
                    return 0; // Success exit code
                }
            }
            
            $iterations++;
        }
        
        if ($iterations >= $maxIterations) {
            $this->error("Maximum iterations reached - possible infinite loop detected");
            return 1; // Failure exit code
        } else {
            $this->info("State machine processing complete.");
            return 0; // Success exit code
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
                $this->info('1No storage driver found in metadata');
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

            // Display data structure analysis
            if (isset($metadata['data_map']) && !empty($metadata['data_map'])) {
                $this->displayDataStructureAnalysis($metadata['data_map']);
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

    /**
     * Display data structure analysis in an agnostic way
     */
    protected function displayDataStructureAnalysis(array $dataMap): void
    {
        if (empty($dataMap)) {
            return;
        }

        $this->info('');
        $this->info('<comment>Data Structure Analysis:</comment>');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // Group fields by type and entity
        $entityGroups = [];
        $fieldTypeStats = [];
        
        foreach ($dataMap as $field) {
            $fieldType = $field['field_type'] ?? 'unknown';
            $fieldName = $field['field_name'] ?? 'unnamed';
            $dataType = $field['type'] ?? 'unknown';
            $confidence = $field['confidence'] ?? 0;
            
            // Group by entity type (post_column, meta_field, etc.)
            if (!isset($entityGroups[$fieldType])) {
                $entityGroups[$fieldType] = [];
            }
            
            $entityGroups[$fieldType][] = [
                'name' => $fieldName,
                'type' => $dataType,
                'confidence' => $confidence,
                'breakdown' => $field['breakdown'] ?? []
            ];
            
            // Track field type statistics
            if (!isset($fieldTypeStats[$dataType])) {
                $fieldTypeStats[$dataType] = 0;
            }
            $fieldTypeStats[$dataType]++;
        }

        // Display entity groups
        foreach ($entityGroups as $entityType => $fields) {
            $this->info('');
            $this->info("<info>" . ucfirst(str_replace('_', ' ', $entityType)) . "s (" . count($fields) . " fields):</info>");
            
            // Prepare table data
            $tableData = [];
            foreach ($fields as $field) {
                $typeInfo = $field['type'];
                if ($field['confidence'] < 90) {
                    $typeInfo .= " ({$field['confidence']}% confidence)";
                }
                
                // Add additional info based on type
                $additionalInfo = $this->getFieldAdditionalInfo($field);
                
                $tableData[] = [
                    $field['name'],
                    $typeInfo,
                    $additionalInfo
                ];
            }
            
            $this->table(['Field Name', 'Detected Type', 'Additional Info'], $tableData);
        }

        // Display field type summary
        $this->info('');
        $this->info('<info>Field Type Summary:</info>');
        $summaryData = [];
        arsort($fieldTypeStats);
        foreach ($fieldTypeStats as $type => $count) {
            $percentage = round(($count / count($dataMap)) * 100, 1);
            $summaryData[] = [
                ucfirst($type),
                $count,
                "{$percentage}%"
            ];
        }
        $this->table(['Data Type', 'Count', 'Percentage'], $summaryData);
    }

    /**
     * Get additional information about a field based on its analysis
     */
    protected function getFieldAdditionalInfo(array $field): string
    {
        $info = [];
        $breakdown = $field['breakdown'] ?? [];
        
        // Add uniqueness info
        if (isset($breakdown['uniqueness_ratio'])) {
            $ratio = $breakdown['uniqueness_ratio'];
            if ($ratio > 90) {
                $info[] = 'Highly unique';
            } elseif ($ratio < 10) {
                $info[] = 'Low uniqueness';
            }
        }
        
        // Add sampling info if available
        if (isset($breakdown['sampling_info']['is_sampled']) && $breakdown['sampling_info']['is_sampled']) {
            $sampleSize = $breakdown['sampling_info']['sample_size'] ?? 0;
            $totalRecords = $breakdown['sampling_info']['total_records'] ?? 0;
            $info[] = "Sampled ({$sampleSize}/{$totalRecords})";
        }
        
        // Add type-specific info
        switch ($field['type']) {
            case 'datetime':
                if (isset($breakdown['datetime_analysis']['formats'])) {
                    $formats = array_keys($breakdown['datetime_analysis']['formats']);
                    $info[] = 'Format: ' . implode(', ', array_slice($formats, 0, 2));
                }
                break;
                
            case 'integer':
            case 'float':
                if (isset($breakdown['numeric_analysis']['min'], $breakdown['numeric_analysis']['max'])) {
                    $min = $breakdown['numeric_analysis']['min'];
                    $max = $breakdown['numeric_analysis']['max'];
                    $info[] = "Range: {$min} to {$max}";
                }
                break;
                
            case 'boolean':
                if (isset($breakdown['boolean_analysis']['true_count'], $breakdown['boolean_analysis']['false_count'])) {
                    $trueCount = $breakdown['boolean_analysis']['true_count'];
                    $falseCount = $breakdown['boolean_analysis']['false_count'];
                    $info[] = "True: {$trueCount}, False: {$falseCount}";
                }
                break;
                
            case 'string':
                if (isset($breakdown['total_count'])) {
                    $totalCount = $breakdown['total_count'];
                    $uniqueCount = $breakdown['unique_count'] ?? 0;
                    if ($uniqueCount < $totalCount / 10) {
                        $info[] = 'Likely categorical';
                    }
                }
                break;
        }
        
        return implode(', ', $info) ?: '-';
    }

	protected function clearScreen() : void {
		$this->getOutput()->write("\033[2J\033[H");
	}

	protected function isFailureState(string $stateClass) : bool {
		// Check for exact FailedState class match
		if ($stateClass === FailedState::class) {
			return true;
		}
		
		// Check for FailedState subclasses  
		if (is_subclass_of($stateClass, FailedState::class)) {
			return true;
		}
		
		// Check for any class name containing "Failed" 
		if (str_contains(class_basename($stateClass), 'Failed')) {
			return true;
		}
		
		return false;
	}
}
