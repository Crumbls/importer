<?php

declare(strict_types = 1);

namespace Crumbls\Importer\Console;

use Crumbls\Importer\Drivers\AutoDriver;
use Crumbls\Importer\Exceptions\CompatibleDriverNotFoundException;
use Crumbls\Importer\Exceptions\InputNotProvided;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\Import;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class ImporterCommand extends Command
{
    protected $signature = 'importer {input? : Import ID or file path} {--auto : Run automatically without user interaction}';
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
            $files = glob(storage_path('app/private/imports/wp-*.xml'));
            $input = Arr::random($files);
        }

        if (!$input) {
            throw new InputNotProvided();
        }

        $record = null;

        if (is_file($input)) {
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

        return 0;
    }

    public function handleFile(string $input) : ImportContract {
        $this->info(__('importer::importer.import.processing_file', ['file' => $input]));

        $record = Import::firstOrCreate([
            'source_type' => 'file::absolute',
            'source_detail' => $input,
        ]);

        return $record;
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
		dd($record->toArray());
//        dump($currentStateClass);
  //      dump($preferredTransitions);

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
}
