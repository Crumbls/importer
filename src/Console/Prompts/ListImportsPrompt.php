<?php

namespace Crumbls\Importer\Console\Prompts;

use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Console\Prompts\Contracts\MigrationPrompt;
use Crumbls\Importer\Console\Prompts\CreateImportPrompt\SourcePrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Resolvers\ModelResolver;
use Crumbls\Importer\States\AbstractState;
use Crumbls\LaravelCliTable\SelectableTable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Console\Helper\TableCell;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;


use PhpTui\Term\Event;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Example\Demo\Component;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell as Tc;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\Table\TableState;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Widget;


class ListImportsPrompt extends AbstractPrompt implements MigrationPrompt
{

	protected $selected = 0;
	protected TableWidget $tableWidget;
	protected TableState $tableState;

	public function __construct(Command $command) {

		$headers = [
			'id' => 'Import #',
			'driver' => 'Driver',
			'source_type' => 'Source Type',
			'status' => 'Current Status'
		];

		$records = static::getRecords();

		$rows = $records->map(function (ImportContract $record) {
			return TableRow::fromCells(
				Tc::fromLine(Line::fromSpan(
					Span::fromString($record->getKey())
				)),
				Tc::fromLine(Line::fromString($record->driver)),
				Tc::fromLine(Line::fromString($record->source_type)),
				Tc::fromLine(Line::fromString($record->state)),
			);
		})->toArray();

		$this->tableState = new TableState();
		$this->tableWidget =TableWidget::default()
			->state($this->tableState)
			->select($this->selected)
			->highlightSymbol('X')
			->highlightStyle(Style::default()->black()->onCyan())
			->widths(
				Constraint::percentage(15),
				Constraint::percentage(25),
				Constraint::percentage(30),
				Constraint::percentage(30),
			)
			->header(
				TableRow::fromCells(
					Tc::fromString('Import #'),
					Tc::fromString('Driver'),
					Tc::fromString('Source Type'),
					Tc::fromString('Current Status'),
				)
			)
			->rows(
				TableRow::fromCells(
					Tc::fromLine(Line::fromString('Add New')),
					Tc::fromLine(Line::fromString('-')),
					Tc::fromLine(Line::fromString('-')),
					Tc::fromLine(Line::fromString('-')),
				),
				...$rows
			);
		return parent::__construct($command);
	}

	protected static function getRecords() : Collection {
		return once(function() {
			$modelClass = ModelResolver::import();

			return $modelClass::query()
				->select([
					'id',
					'driver',
					'source_type',
					'state'
				])
				->orderBy('updated_at', 'desc')
				->take(50)
				->get()
				->map(function(ImportContract $import) {
					$import->driver = Str::chopEnd(class_basename($import->driver), 'Driver');
					$state = Str::chopEnd(class_basename($import->state), 'State');
					$state = preg_split('/(?=[A-Z])/',$state);
					$import->state = implode(' ', array_filter($state));
					return $import;

				});
		});

	}

	public function tui() : array {
		return [
			BlockWidget::default()->titles(Title::fromString('Table'))->borders(Borders::ALL)
				->widget($this->tableWidget->select($this->selected))
		];
	}

	public function handleInput(Event $event, Command $command)
	{
		if ($event instanceof CodedKeyEvent) {
			if ($event->code === KeyCode::Down) {
				// TODO: FIX THIS.
				$max = static::getRecords()->count();
				$this->selected < $max ? $this->selected++ : $max;
				return;
			} else if ($event->code === KeyCode::Up) {
				$this->selected > 0 ? $this->selected-- : 0;
				return;
			} else if ($event->code === KeyCode::Enter) {
				/**
				 * Import selected!
				 */
				if ($this->selected === 0) {
					$command->setPrompt(SourcePrompt::class);
				} else {
					$records = static::getRecords();

					$modelClass = ModelResolver::import();
					$record = $modelClass::find($records->get($this->selected - 1)->getKey());

					if (!$record) {
						throw new \Exception('Record not found');
					}

					$this->command->setRecord($record);

					$stateMachine = $record->getStateMachine();

					$currentState = $stateMachine->getCurrentState();

					if ($currentState instanceof AbstractState) {

						$promptClass = $currentState->getPromptClass();

						if (class_exists($promptClass)) {
							$command->setPrompt($promptClass);
							return;
						}
					}
					//				$command->setRecord
//					$command->setPrompt(ViewImportPrompt::class);

				};
				return;
			}
		}
		parent::handleInput($event, $command);
	}

	public static function getTabTitle() : string {
		return 'List Imports';
	}

}