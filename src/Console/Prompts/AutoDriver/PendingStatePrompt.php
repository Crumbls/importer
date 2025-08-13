<?php

namespace Crumbls\Importer\Console\Prompts\AutoDriver;

use Crumbls\Importer\Console\NavItem;
use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Console\Prompts\Contracts\MigrationPrompt;
use Crumbls\Importer\Console\Prompts\CreateImportPrompt\SourcePrompt;
use Crumbls\Importer\Console\Prompts\ListImportsPrompt;
use Crumbls\Importer\Console\Prompts\ViewImportPrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Resolvers\ModelResolver;
use Illuminate\Support\Facades\Log;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use Illuminate\Console\Command;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Color\AnsiColor;

use PhpTui\Term\Event;
use PhpTui\Term\KeyCode;

class PendingStatePrompt extends AbstractPrompt implements MigrationPrompt
{
	private static array $spinnerFrames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
	private static int $currentFrame = 0;
	private bool $hasExecuted = false;
	private bool $isExecuting = false;
	private ?float $executionStartTime = null;
	private float $minimumDisplayTime = 0.25; // Show loading for at least 1 second

	public function __construct(protected Command $command)
	{
		parent::__construct($command);
		// Don't execute in constructor - let the UI show first
		$this->startExecution();
	}

	/**
	 * Start the execution process with a brief delay to show the loading UI
	 * Only auto-execute for AutoDriver states, not specific driver states
	 */
	private function startExecution(): void
	{
		$record = $this->command->getRecord();
		
		// Only auto-execute if we're still using AutoDriver
		if (class_basename($record->driver) === 'AutoDriver') {
			$this->executionStartTime = microtime(true);
			$this->isExecuting = true;
		} else {
			// For specific drivers, don't auto-execute - wait for user action
			$this->isExecuting = false;
			$this->hasExecuted = false;
		}
	}

	/**
	 * Execute the state transition
	 */
	private function executeStateTransition(): void
	{
		if ($this->hasExecuted) {
			return;
		}

		$record = $this->command->getRecord();
		
		\Log::info("PendingStatePrompt executing state transition", [
			'import_id' => $record->getKey(),
			'current_driver' => class_basename($record->driver),
			'current_state' => class_basename($record->state),
			'is_auto_driver' => class_basename($record->driver) === 'AutoDriver'
		]);
		
		$stateMachine = $record->getStateMachine();
		$state = $stateMachine->getCurrentState();

		if (!$state->execute()) {
			\Log::error("State execution failed in PendingStatePrompt", [
				'import_id' => $record->getKey(),
				'state_class' => get_class($state)
			]);
			$this->command->dd("State execution failed");
			return;
		}

		// Check if state or driver changed during execution
		$record->refresh();
		$this->command->setRecord($record);

		\Log::info("State transition completed in PendingStatePrompt", [
			'import_id' => $record->getKey(),
			'new_driver' => class_basename($record->driver),
			'new_state' => class_basename($record->state)
		]);

		$stateMachine = $record->getStateMachine();
		$state = $stateMachine->getCurrentState();

		$this->hasExecuted = true;
		$this->isExecuting = false;

		// Set the new prompt
		$newPromptClass = $state->getPromptClass();
		\Log::info("Setting new prompt class", [
			'import_id' => $record->getKey(),
			'prompt_class' => $newPromptClass
		]);
		
		$this->command->setPrompt($newPromptClass);
	}

	/**
	 * Check if enough time has passed and we should execute the transition
	 */
	private function shouldExecuteTransition(): bool
	{
		if ($this->hasExecuted || !$this->isExecuting || !$this->executionStartTime) {
			return false;
		}

		$elapsedTime = microtime(true) - $this->executionStartTime;
		return $elapsedTime >= $this->minimumDisplayTime;
	}

	/**
	 * Get the current loading spinner character
	 */
	private function getLoadingSpinner(): string
	{
		$spinner = self::$spinnerFrames[self::$currentFrame];
		self::$currentFrame = (self::$currentFrame + 1) % count(self::$spinnerFrames);
		return $spinner;
	}

	public function handleInput(Event $event, Command $command)
	{
		$record = $this->command->getRecord();
		$isAutoDriver = class_basename($record->driver) === 'AutoDriver';
		
		// Handle user input
		if ($event instanceof Event\CharKeyEvent) {
			if ($event->char === 'q') {
				parent::handleInput($event, $command);
				return;
			}
		} else if ($event instanceof Event\CodedKeyEvent) {
			if ($event->code === KeyCode::Esc) {
				$this->command->setPrompt(ListImportsPrompt::class);
				return;
			} elseif ($event->code === KeyCode::Enter) {
				if ($isAutoDriver && $this->isExecuting) {
					// Skip waiting for AutoDriver analysis
					$this->executeStateTransition();
				} elseif (!$isAutoDriver && !$this->hasExecuted) {
					// Manual execution for specific driver
					$this->executeStateTransition();
				}
				return;
			}
		}
	}

	public static function breadcrumbs() : array{
		$base = ViewImportPrompt::breadcrumbs();
		$base[PendingStatePrompt::class] = new NavItem(PendingStatePrompt::class, static::getTabTitle());
		return $base;
	}

	public function tui(): array
	{
		if ($this->shouldExecuteTransition()) {
			$this->executeStateTransition();
		}

		return [
			GridWidget::default()
				->direction(Direction::Horizontal)
				->constraints(
					Constraint::percentage(25),
					Constraint::percentage(50),
					Constraint::percentage(25),
				)
				->widgets(
					GridWidget::default()
						->direction(Direction::Vertical)
						->widgets(),
					GridWidget::default()
						->direction(Direction::Vertical)
						->constraints(
							Constraint::min(3),  // Status
							Constraint::min(1)   // Spacer
						)
						->widgets(
							$this->getLoadingWidget(),
							$this->getSpacerWidget(),
						),
					GridWidget::default()
						->direction(Direction::Vertical)
						->widgets(),
				)
		];
	}


	private function getLoadingWidget()
	{
		$record = $this->command->getRecord();
		$spinner = $this->getLoadingSpinner();
		
		// Check if we're dealing with AutoDriver or specific driver
		$isAutoDriver = class_basename($record->driver) === 'AutoDriver';
		
		if ($this->hasExecuted) {
			$loadingText = "✓ Driver detection complete, transitioning...";
			$color = AnsiColor::Green;
		} elseif ($this->isExecuting && $isAutoDriver) {
			$elapsedTime = $this->executionStartTime ? microtime(true) - $this->executionStartTime : 0;
			$remaining = max(0, $this->minimumDisplayTime - $elapsedTime);
			
			if ($remaining > 0) {
				$loadingText = sprintf(
					"%s Analyzing data source...\n\nPress Enter to skip waiting (%.1fs remaining)",
					$spinner,
					$remaining
				);
			} else {
				$loadingText = sprintf(
					"%s Determining compatible driver...",
					$spinner
				);
			}
			$color = AnsiColor::Yellow;
		} elseif (!$isAutoDriver) {
			// Show status for specific driver
			$driverName = class_basename($record->driver);
			$loadingText = sprintf(
				"✓ Driver detected: %s\n\nImport is ready to proceed.\nPress Enter to continue or Esc to go back.",
				$driverName
			);
			$color = AnsiColor::Green;
		} else {
			$loadingText = "Initializing...";
			$color = AnsiColor::Blue;
		}

		return BlockWidget::default()
			->titles(Title::fromString('Processing'))
			->borders(Borders::ALL)
			->style(Style::default()->fg($color))
			->widget(
				ParagraphWidget::fromString($loadingText)
					->alignment(HorizontalAlignment::Center)
			);
	}


	private function getSpacerWidget()
	{
		return BlockWidget::default();
	}

	public static function getTabTitle() : string
	{
		return 'Pending';
	}
}