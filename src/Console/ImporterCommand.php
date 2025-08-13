<?php

declare(strict_types = 1);

namespace Crumbls\Importer\Console;

use Crumbls\Importer\Console\Prompts\ListImportsPrompt;
use Crumbls\Importer\Drivers\AutoDriver;
use Crumbls\Importer\Exceptions\CompatibleDriverNotFoundException;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\Import;
use Crumbls\Importer\States\AutoDriver\PendingState;
use Crumbls\Importer\States\Shared\FailedState;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

use PhpTui\Term\Actions;
use PhpTui\Term\ClearType;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;// as PhpTuiPhpTermBackend;
use PhpTui\Tui\Display\Backend;
use PhpTui\Tui\Display\Display;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Example\Demo\Page\BarChartPage;
use PhpTui\Tui\Example\Demo\Page\BlocksPage;
use PhpTui\Tui\Example\Demo\Page\CanvasPage;
use PhpTui\Tui\Example\Demo\Page\CanvasScalingPage;
use PhpTui\Tui\Example\Demo\Page\ChartPage;
use PhpTui\Tui\Example\Demo\Page\ColorsPage;
use PhpTui\Tui\Example\Demo\Page\EventsPage;
use PhpTui\Tui\Example\Demo\Page\GaugePage;
use PhpTui\Tui\Example\Demo\Page\ImagePage;
use PhpTui\Tui\Example\Demo\Page\ItemListPage;
use PhpTui\Tui\Example\Demo\Page\SparklinePage;
use PhpTui\Tui\Example\Demo\Page\SpritePage;
use PhpTui\Tui\Example\Demo\Page\TablePage;
use PhpTui\Tui\Example\Demo\Page\WindowPage;
use PhpTui\Tui\Extension\Bdf\BdfExtension;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\TabsWidget;
use PhpTui\Tui\Extension\ImageMagick\ImageMagickExtension;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;

/**
 * Class ImporterCommand
 *
 * @package Crumbls\Importer\Console
 */
class ImporterCommand extends SimpleTuiDemo
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'importer';

    /**
     * The console command description.
     */
    protected $description = 'Interactive interface for Importer';

    private array $tableChecks = [];
    private bool $tablesReady = false;

	public function dis_handle(): int
	{
		/**
		 * Check if required tables exist
		 */
		if (!$this->hasRequiredTables()) {
			$this->handleInstaller();
			return self::SUCCESS;
		}

		/**
		 * DEBUG DO NOT TOUCH
		 */
		Import::where('id','<>',427)->get()->each(function($record) {
//			$record->delete();
		});

		foreach(Arr::shuffle([
			427,
//			439
		]) as $id) {
			$record = Import::find($id);
			$record->driver = AutoDriver::class;
			$record->state = PendingState::class;
			$record->metadata = null;
			$record->error_message = null;
			$record->save();
			$record->clearStateMachine();
		}
		/**
		 * END DEBUG
		 */

		// Run the main import management loop
		$this->runImportManagementLoop();

		return self::SUCCESS;
	}


	protected function clearScreen() : void {
		$this->getOutput()->write("\033[2J\033[H");
	}

	private function runImportManagementLoop(): void
	{
		do {
			$this->clearScreen();

			$record = null;
			
			// First try to show existing imports using ListImportsPrompt
			$listPrompt = new ListImportsPrompt($this);
			$record = $listPrompt->render();
			
			if (!$record) {
				// No import selected, show create import prompt
				$record = $this->createImportWithPrompt();
			}

			if (!$record) {
				/**
				 * Exit...
				 */
				break;
			}

			$this->handleRecord($record);

			// Ask if they want to continue
			$continue = \Laravel\Prompts\confirm('Do you want to select another import?', true);
			if (!$continue) {
				break;
			}
			
		} while (true);
	}

    private function getImportModel()
    {
        $importClass = config('importer.models.import');
        return app($importClass);
    }

	/**
	 * I'll expand this to work on different connections down the road,
	 * but for now, here we are.
	 * @return bool
	 */
	private function hasRequiredTables(): bool
	{
		return once(function() {
			// You will define which models/tables to check
			$tables = $this->getRequiredTables();
			foreach($tables as $table) {
				if (!Schema::hasTable($table)) {
					return false;
				}
			}
			return true;
		});
	}

	private function getRequiredModels(): array
	{
		return config('importer.models');
	}

	private function getRequiredTables(): array {
		$requiredModels = $this->getRequiredModels();
		return array_combine($requiredModels, array_map(function($model) {
			return with(new $model())->getTable();
		}, $requiredModels));
	}

	private function handleInstaller(): void
	{
		$this->info('Required tables are missing. Please run migrations first:');
		$this->info('php artisan migrate');
		$this->info('');
		$this->info('Or install the package tables with:');
		$this->info('php artisan importer:install');
	}

	private function createImportWithPrompt(): ?ImportContract
	{
		try {
			$prompt = new CreateImportPrompt($this);
			$record = $prompt->render();

			return $record;
		} catch (\Exception $e) {
			$this->error("Failed to create import: {$e->getMessage()}");
		}
	}

	private function handleRecord(ImportContract $record): mixed
	{
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

		return $exitCode;

		// b
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
				dump(__LINE__);
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

	public function state(?string $stateClass = null) : void
	{
		if (!$stateClass) {
			return;
		}
		$stateShortName = class_basename($stateClass);
		$stateShortName = $stateClass;
		$this->info('Current State: '.$stateShortName);
//		$this->newLine();
	}

}