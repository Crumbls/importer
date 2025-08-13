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

class GenericAutoPrompt extends AbstractPrompt implements MigrationPrompt
{
	private bool $hasExecuted = false;
	private bool $isExecuting = true; // Start as executing
	private ?float $executionStartTime = null;
	private float $displayTime = 1.0; // Show for 1 second by default

	/**
	 * Check if enough time has passed and we should execute the transition
	 */
	private function shouldExecuteTransition(): bool
	{
		if ($this->hasExecuted || !$this->isExecuting || !$this->executionStartTime) {
			return false;
		}

		$elapsedTime = microtime(true) - $this->executionStartTime;
		return $elapsedTime >= $this->displayTime;
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
		
		\Log::info("GenericAutoPrompt executing state transition", [
			'import_id' => $record->getKey(),
			'current_driver' => class_basename($record->driver),
			'current_state' => class_basename($record->state)
		]);
		
		$stateMachine = $record->getStateMachine();
		$state = $stateMachine->getCurrentState();

		if (!$state->execute()) {
			\Log::error("GenericAutoPrompt execution failed", [
				'import_id' => $record->getKey(),
				'state_class' => get_class($state)
			]);
			return;
		}

		// Check if state or driver changed during execution
		$record->refresh();
		$this->command->setRecord($record);

		\Log::info("GenericAutoPrompt transition completed", [
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
		\Log::info("GenericAutoPrompt setting new prompt", [
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
		
		if ($this->hasExecuted) {
			$loadingText = "✓ Continuing...";
			$color = AnsiColor::Green;
		} else {
			$elapsedTime = $this->executionStartTime ? microtime(true) - $this->executionStartTime : 0;
			$remaining = max(0, $this->displayTime - $elapsedTime);
			
			$driverName = class_basename($record->driver);
			$stateName = $this->getReadableStateName(class_basename($record->state));
			
			$loadingText = sprintf(
				"Using %s driver\n\n%s\n\nPress Enter to skip waiting (%.1fs remaining)",
				$driverName,
				$stateName,
				$remaining
			);
			$color = AnsiColor::Blue;
		}

		return BlockWidget::default()
			->titles(Title::fromString($this->getWindowTitle()))
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

	private function getWindowTitle(): string
	{
		$record = $this->command->getRecord();
		$stateName = $this->getReadableStateName(class_basename($record->state));
		return $stateName;
	}

	private function getReadableStateName(string $stateClass): string
	{
		// Convert state class names to readable titles
		$map = [
			'PendingState' => 'Ready to Process',
			'AnalyzingState' => 'Analyzing Data',
			'ExtractState' => 'Extracting Data',
			'MappingState' => 'Mapping Fields', 
			'CreateStorageState' => 'Creating Storage',
			'TransformReviewState' => 'Reviewing Transform',
			'ModelCreationState' => 'Creating Models',
			'MigrationBuilderState' => 'Building Migration',
			'PostTypePartitioningState' => 'Partitioning Post Types',
		];

		return $map[$stateClass] ?? $stateClass;
	}

	public static function getTabTitle() : string
	{
		return 'Processing';
	}
}