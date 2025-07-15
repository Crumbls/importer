<?php

declare(strict_types = 1);

namespace Crumbls\Importer\Console;

use Crumbls\Importer\States\PendingState;
use Crumbls\Importer\Traits\IsDiskAware;
use Illuminate\Support\Str;
use function Laravel\Prompts\text;

use Crumbls\Importer\Console\Prompts\FileBrowserPrompt;
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
	    $record = Import::find(427);
	    if ($record) {
		    $record->driver = AutoDriver::class;
		    $record->state = \Crumbls\Importer\States\AutoDriver\PendingState::class;
		    $record->save();
//return 1;
			$this->handleRecord($record);
	    }

		return 1;
//		exit;

        $this->info(__('importer::importer.command.ready'));

	    $sourceType = null;
	    $source = $this->argument('input');
		$root = null;

        if (!$source) {
            $browser = new FileBrowserPrompt($this);
            $selectedFile = $browser->browse();
			$sourceType = 'storage';

            if (!$selectedFile) {
                $this->info('No file selected. Using random file fallback.');
                [$root, $source] = $this->getRandomFile();
                $this->info("Randomly selected: {$sourceType} -> {$source}");
            } else {
	            [$root, $source] = explode('::', $selectedFile, 2);
            }
        } else {
			if (preg_match('#^(.*?):{1,2}(.*?)$#', $source, $matches)) {
				$disks = $this->getAvailableDisks();
				if (in_array($matches[1], $disks)) {
					$sourceType = 'storage';
					$root = $matches[1];
					$source = $matches[2];
				} else {
					throw new \InvalidArgumentException("Invalid source format: {$source}");
				}
			} else {
				throw new \InvalidArgumentException("Source not found: {$source}");
			}
        }

        if (!$source) {
            throw new InputNotProvided();
        }

        $record = null;

		$method = Str::camel('handle source '.$sourceType);

		if (!method_exists($this, $method)) {
			throw new \BadMethodCallException("Handler method '{$method}' not found for source type '{$sourceType}'");
		}

		$record = $this->$method($root, $source);

		if (!$record) {
			throw new Exception('File not found....');
		}

		$this->handleRecord($record);

		return 0;
    }

	protected function handleRecord(ImportContract $record) : void {

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

		$this->displayDatabaseStats($record);

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


    protected function processDriver(ImportContract $record) : void {
        $record->clearStateMachine();

        $stateMachine = $record->getStateMachine();

        $driverConfigClass = $record->driver;

        $preferredTransitions = $driverConfigClass::config()->getPreferredTransitions();

        if (empty($preferredTransitions)) {
            $this->error('No preferred transitions defined for this driver');
            return;
        }

        $currentStateClass = $record->state;
//dd($currentStateClass);
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
//				dd($originalDriver, $record->driver);
                $stateMachine->transitionTo($nextState);
                $record->refresh();

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


}
