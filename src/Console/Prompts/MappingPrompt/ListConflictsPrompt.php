<?php

namespace Crumbls\Importer\Console\Prompts\MappingPrompt;

use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\Contracts\ImportModelMapContract;
use Crumbls\Importer\Resolvers\ModelResolver;
use Crumbls\LaravelCliTable\SelectableTable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\TableCell;
use Ramsey\Collection\Map\AbstractMap;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

class ListConflictsPrompt extends AbstractMappingPrompt
{

	public function render() : void
	{
		$this->clearScreen();

		$records = $this->getConflicts();
		$conflictCount = $records->count();

		if (!$conflictCount) {
			return;
		}

		$idx = 0;

		$rows = $records->map(function(array $conflict) use (&$idx) {
			return [
				'id' => $conflict['id'],
				'target_model' => $conflict['target_model'],
				'strategy' => $conflict['strategy'],
				'safety_score' => $conflict['safety_score'],
			];
		})->toArray();

		$table = new SelectableTable($this->command->getOutput());

		$style = $table->getTableStyle();

		$style = $style->setHorizontalBorderChars('─')
			->setVerticalBorderChars('│')
			->setCrossingChars(
				'┼',  // crossing
				'┌',  // top left corner
				'┬',  // top mid
				'┐',  // top right corner
				'┤',  // right mid
				'┘',  // bottom right corner
				'─',  // bottom mid
				'└',  // bottom left corner
				'├'   // left mid
			);

		$table->setTableStyle($style);

		$table->setSelectedColors('default', 'cyan');

		$headers = [
			'id',
			'model',
			'strategy',
			'score'
		];

		$table->setHeaders(array_map(function($header) { return '<fg=cyan;options=bold>'.$header.'</>'; }, $headers));

		$rows[] = [
			new TableCell('← Go Back', ['colspan' => count($headers)])
		];

		$table->setRows($rows);

		$selectedRow = $table->selectRow(function($row, $index) {
			if (array_key_exists(0, $row) && $row[0] instanceof TableCell) {
				return 'back';
			}
			return $row['id'];
		});

		if ($selectedRow === 'back') {
			return;
		}

		$record = $this->getImportModelMaps()->first(function(ImportModelMapContract $map) use ($selectedRow) {
			return $map->getKey() == $selectedRow;
		});

		$prompt = new ViewEntityPrompt($this->command, $this->record, $record);
		$prompt->render();
	}
}