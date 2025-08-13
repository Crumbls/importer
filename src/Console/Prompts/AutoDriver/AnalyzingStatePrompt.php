<?php

namespace Crumbls\Importer\Console\Prompts\AutoDriver;

use Crumbls\Importer\Console\NavItem;
use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Console\Prompts\Contracts\MigrationPrompt;
use Crumbls\Importer\Console\Prompts\CreateImportPrompt\SourcePrompt;
use Crumbls\Importer\Console\Prompts\ListImportsPrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Resolvers\ModelResolver;
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

class AnalyzingStatePrompt extends AbstractPrompt implements MigrationPrompt
{
	private bool $hasExecuted = false;
	private bool $isExecuting = true; // Start as executing
	private bool $analysisComplete = false; // Track if analysis is complete
	private ?float $executionStartTime = null;
	private ?float $completionTime = null; // Track when analysis completed
	private float $minimumDisplayTime = 0.5; // Show loading for at least 0.5 seconds
	private float $resultDisplayTime = 0.5; // Show result for 0.5 seconds after analysis

	private static array $spinnerFrames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
	private static int $currentFrame = 0;

	/**
	 * Get the current loading spinner character
	 */
	private function getLoadingSpinner(): string
	{
		$spinner = self::$spinnerFrames[self::$currentFrame];
		self::$currentFrame = (self::$currentFrame + 1) % count(self::$spinnerFrames);
		return $spinner;
	}

	/**
	 * Check if we should start the analysis
	 */
	private function shouldStartAnalysis(): bool
	{
		if ($this->analysisComplete || !$this->isExecuting || !$this->executionStartTime) {
			return false;
		}

		$elapsedTime = microtime(true) - $this->executionStartTime;
		return $elapsedTime >= $this->minimumDisplayTime;
	}

	/**
	 * Check if we should continue after showing the result
	 */
	private function shouldContinueAfterResult(): bool
	{
		if ($this->hasExecuted || !$this->analysisComplete || !$this->completionTime) {
			return false;
		}

		$elapsedTime = microtime(true) - $this->completionTime;
		return $elapsedTime >= $this->resultDisplayTime;
	}

	public function handleInput(Event $event, Command $command)
	{
		if ($event instanceof Event\CharKeyEvent) {
			if ($event->char === 'q') {
				parent::handleInput($event, $command);
				return;
			}
		} else if ($event instanceof Event\CodedKeyEvent) {
			if ($event->code === KeyCode::Esc) {
				$this->command->setPrompt(ListImportsPrompt::class);
				return;
			}
		}
	}

	public static function breadcrumbs() : array{
		// Return empty breadcrumbs to avoid issues
		return [];
	}

	public function tui(): array
	{
		// Initialize execution timer on first call
		if ($this->executionStartTime === null) {
			$this->executionStartTime = microtime(true);
		}
		
		// Check if we should start analysis (after initial display time)
		if ($this->shouldStartAnalysis()) {
			$this->executeAnalysis();
		}
		
		// Check if we should continue after showing result
		if ($this->shouldContinueAfterResult()) {
			$this->continueToNextPrompt();
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
//							$this->getHeaderWidget(),
							$this->getLoadingWidget(),
//							$this->getStatusWidget(),
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
		
		if ($this->hasExecuted) {
			// Analysis complete and transitioning
			$loadingText = "✓ Transitioning to next step...";
			$color = AnsiColor::Green;
			$title = 'Complete';
		} elseif ($this->analysisComplete) {
			// Analysis complete, showing result
			$driverName = class_basename($record->driver);
			$loadingText = sprintf("✓ Compatible driver detected: %s\n\nContinuing in %.1fs...", 
				$driverName, 
				max(0, $this->resultDisplayTime - (microtime(true) - ($this->completionTime ?: microtime(true))))
			);
			$color = AnsiColor::Green;
			$title = 'Driver Detected';
		} else {
			// Still analyzing
			$spinner = $this->getLoadingSpinner();
			$loadingText = sprintf("%s Determining compatible driver...\n\n", $spinner);
			$color = AnsiColor::Yellow;
			$title = 'Analyzing';
		}

		return BlockWidget::default()
			->titles(Title::fromString($title))
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
		return 'Analyzing Import';
	}

	/**
	 * Execute the analysis (find compatible driver)
	 */
	private function executeAnalysis(): void
	{
		if ($this->analysisComplete) {
			return;
		}

		$record = $this->command->getRecord();
		
		\Log::info("AnalyzingStatePrompt executing analysis", [
			'import_id' => $record->getKey(),
			'current_driver' => class_basename($record->driver),
			'current_state' => class_basename($record->state)
		]);

		$stateMachine = $record->getStateMachine();
		$state = $stateMachine->getCurrentState();
		
		\Log::info("AnalyzingStatePrompt got current state", [
			'import_id' => $record->getKey(),
			'state_class' => get_class($state),
			'expected_state' => $record->state
		]);

		if (!$state->execute()) {
			\Log::error("AnalyzingState execution failed", [
				'import_id' => $record->getKey(),
				'state_class' => get_class($state)
			]);
			return;
		}

		// Check if state or driver changed during execution
		$record->refresh();
		$this->command->setRecord($record);

		\Log::info("AnalyzingState analysis completed", [
			'import_id' => $record->getKey(),
			'new_driver' => class_basename($record->driver),
			'new_state' => class_basename($record->state)
		]);

		// Mark analysis as complete and start result display timer
		$this->analysisComplete = true;
		$this->completionTime = microtime(true);
	}

	/**
	 * Continue to the next prompt after showing the result
	 */
	private function continueToNextPrompt(): void
	{
		if ($this->hasExecuted) {
			return;
		}

		$record = $this->command->getRecord();
		$stateMachine = $record->getStateMachine();
		$state = $stateMachine->getCurrentState();

		$this->hasExecuted = true;
		$this->isExecuting = false;

		// Set the new prompt
		$newPromptClass = $state->getPromptClass();
		\Log::info("AnalyzingStatePrompt transitioning to next prompt", [
			'import_id' => $record->getKey(),
			'state_class' => get_class($state),
			'prompt_class' => $newPromptClass,
			'state_record_driver' => class_basename($record->driver),
			'state_record_state' => class_basename($record->state)
		]);
		
		$this->command->setPrompt($newPromptClass);
	}
}