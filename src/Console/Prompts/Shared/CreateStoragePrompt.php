<?php

namespace Crumbls\Importer\Console\Prompts\Shared;

use Crumbls\Importer\Console\NavItem;
use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Console\Prompts\Contracts\MigrationPrompt;
use Crumbls\Importer\Console\Prompts\ListImportsPrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
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

class CreateStoragePrompt extends AbstractPrompt implements MigrationPrompt
{
	private static array $spinnerFrames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
	private static int $currentFrame = 0;
	private bool $hasExecuted = false;
	private bool $isExecuting = true; // Start as executing
	private ?float $executionStartTime = null;
	private float $minimumDisplayTime = 1.0; // Show for 1 second

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
	 * Execute the state transition
	 */
	private function executeStateTransition(): void
	{
		if ($this->hasExecuted) {
			return;
		}

		$record = $this->command->getRecord();
		
		\Log::info("CreateStoragePrompt executing state transition", [
			'import_id' => $record->getKey(),
			'current_driver' => class_basename($record->driver),
			'current_state' => class_basename($record->state)
		]);
		
		$stateMachine = $record->getStateMachine();
		$state = $stateMachine->getCurrentState();

		if (!$state->execute()) {
			\Log::error("CreateStorage execution failed", [
				'import_id' => $record->getKey(),
				'state_class' => get_class($state)
			]);
			return;
		}

		// Check if state or driver changed during execution
		$record->refresh();
		$this->command->setRecord($record);

		\Log::info("CreateStorage transition completed", [
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
		\Log::info("CreateStoragePrompt setting new prompt", [
			'import_id' => $record->getKey(),
			'prompt_class' => $newPromptClass
		]);
		
		$this->command->setPrompt($newPromptClass);
	}

	public function handleInput(Event $event, Command $command)
	{
		// Handle user input - allow early skip with Enter
		if ($event instanceof Event\CharKeyEvent) {
			if ($event->char === 'q') {
				parent::handleInput($event, $command);
				return;
			}
		} else if ($event instanceof Event\CodedKeyEvent) {
			if ($event->code === KeyCode::Esc) {
				$this->command->setPrompt(ListImportsPrompt::class);
				return;
			} elseif ($event->code === KeyCode::Enter && !$this->hasExecuted) {
				// Skip waiting and execute immediately
				$this->executeStateTransition();
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
		
		// Check if we should execute the transition
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
		
		if ($this->hasExecuted) {
			$loadingText = "✓ Storage created successfully, continuing...";
			$color = AnsiColor::Green;
		} elseif ($this->isExecuting) {
			$elapsedTime = $this->executionStartTime ? microtime(true) - $this->executionStartTime : 0;
			$remaining = max(0, $this->minimumDisplayTime - $elapsedTime);
			
			$loadingText = sprintf(
				"%s Creating storage for import data...\n\nPress Enter to skip waiting (%.1fs remaining)",
				$spinner,
				$remaining
			);
			$color = AnsiColor::Yellow;
		} else {
			$loadingText = "Initializing storage...";
			$color = AnsiColor::Blue;
		}

		return BlockWidget::default()
			->titles(Title::fromString('Storage Setup'))
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
		return 'Creating Storage';
	}
}