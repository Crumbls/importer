<?php

namespace Crumbls\Importer\Console\Prompts;

use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Resolvers\ModelResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;

class ListImportsPrompt extends AbstractPrompt
{
	public function __construct(protected Command $command, public bool $allowNew = true)
	{
		parent::__construct($command);
	}

	public function render()
	{
		$this->clearScreen();
		$modelClass = ModelResolver::import();

		$headers = [
			'id' => 'Import #',
			'driver' => 'Driver',
			'source_type' => 'Source Type',
			'status' => 'Current Status'
		];

		$results = $modelClass::query()
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
				$state = Str::chopEnd(class_basename($import->state), 'State');
				$state = preg_split('/(?=[A-Z])/',$state);
				$import->state = implode(' ', array_filter($state));
				return $import;

			});

		if ($results->isEmpty()) {
			return null;
		}

		$results = $results->toArray();

		table(
			headers: $headers,
			rows: $results
		);

		if ($this->allowNew) {
			$labelNew = __('Start a new import');

			/**
			 * Make sure this label isn't an id for an import for cases where they are using strings.
			 * Yes it's overkill, but it helps protect against bad actors.
			 */

			$x = 1;

			while ($modelClass::find($labelNew)) {
				$labelNew = __('Start a new import').' - '.$x;
				$x++;
			}

			$results[] = [
				'id' => $labelNew,
			];
		}

		$options = [];

		foreach ($results as $result) {
			$options[$result['id']] = $result['id'];
		}


		$id = select(
			label: __('Which import would you like to work on?'),
			options: $options,
			required: true,
			default: 472
		);

		if ($this->allowNew && $id === $labelNew) {
			return null;
		}

		$record = $modelClass::find($id);

		return $record;
	}
}