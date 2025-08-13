<?php

namespace Crumbls\Importer\Console\Prompts;

use Crumbls\Importer\Console\Prompts\AutoDriver\PendingStatePrompt;
use Crumbls\Importer\Console\Prompts\Contracts\MigrationPrompt;
use Illuminate\Support\Facades\Log;
use PhpTui\Term\Event;
use Crumbls\Importer\Console\NavItem;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Illuminate\Console\Command;
use PhpTui\Term\KeyCode;
use Symfony\Component\Console\Question\Question;

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\Event\TerminalResizedEvent;

abstract class AbstractPrompt
{
	public function __construct(protected Command $command, protected ?ImportContract $record = null)
	{
	}


	public static function build(Command $command, ?ImportContract $record = null) : MigrationPrompt {
		return new static($command, $record);
	}

	public static function breadcrumbs() : array{
		// Simple implementation to avoid recursion
		return [];
	}


	public function handleInput(Event $event, Command $command) {
		$activePrompt = $command->getPrompt();

		if ($event instanceof CharKeyEvent) {
			if ($event->char === 'q') {
				/**
				 * TODO: Handle any saving....
				 */
				$command->stopLoop();
				return;
			}
		} else if ($event instanceof CodedKeyEvent) {
			if ($event->code === KeyCode::Tab) {
				$tabs = $activePrompt->breadcrumbs();
				$keys = array_keys($tabs);
				$x = array_search($activePrompt, $keys);
				if (!$x) {
					return;
				}
				$key = $keys[$x-1];
				return;
			} else if ($event->code === KeyCode::BackTab) {
				Log::info(__LINE__);
				exit;
			} else {
				$command->error($event->code->name);
				Log::info(__LINE__);
				exit;
			}
		} else if ($event instanceof TerminalResizedEvent) {
		} else if ($event instanceof MouseEvent) {
		} else {
		}
	}

	/**
	 * Clear the terminal screen for a clean interface
	 */
	protected function clearScreen(): void
	{

//		$this->command->getOutput()->write("\033[2J\033[H");
	}

	public function info(string $message) : void {
		$this->command->info($message);
	}

	protected function transitionToNextState() : void {
		//$this->record->clearStateMachine();
		$stateMachine = $this->record->getStateMachine();
		$driverConfigClass = $this->record->driver;
		$preferredTransitions = $driverConfigClass::config()->getPreferredTransitions();

		$state = $this->record->state;
		if (array_key_exists($state, $preferredTransitions)) {
			$stateMachine->transitionTo($preferredTransitions[$state]);
		}
	}
}