<?php

namespace Crumbls\Importer\Console\Prompts\MappingPrompt;

use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\Contracts\ImportModelMapContract;
use Crumbls\Importer\Resolvers\ModelResolver;
use Crumbls\LaravelCliTable\SelectableTable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Ramsey\Collection\Map\AbstractMap;
use Symfony\Component\Console\Helper\TableCell;

class ListEntityMappingsPrompt extends AbstractMappingPrompt
{
	public function render()
	{
		$this->clearScreen();
		$this->command->info("Browse Entity Mappings");
		$this->command->info("═══════════════════════════");
		$this->command->newLine();

		$records = $this->getImportModelMaps();

		if ($records->isEmpty()) {
			$this->command->error('No ImportModelMaps found for this import.');
			return 'back';
		}

		$headers = [
			'id',
			'status',
			'table',
			'model',
			'columns'
		];

		$rows = $records->map(function (ImportModelMapContract $map) {
			return [
				'id' => $map->getKey(),
				'status' => $this->isReady($map) ? '[READY]' : '[NOT READY]',
				'entityType' => $map->entity_type,
				'targetModel' => $map->target_model,
				'columnCount' => count($map->schema_mapping['columns'] ?? []),
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

		$table->setHeaders(array_map(function($header) { return '<fg=cyan;options=bold>'.$header.'</>'; }, $headers));

		$rows[] = [
			new TableCell('← Go Back', ['colspan' => count($headers)])
		];

		$table->setRows($rows);

		$selectedRow = $table->selectRow(function($row, $index) {
			if (array_key_exists(0, $row) && $row[0] instanceof TableCell) {
				return 'back';
			}
			return $index;
		});

		if ($selectedRow == 'back') {
			return $selectedRow;
		}

		return $records->get($selectedRow);
	}
}