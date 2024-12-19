<?php


namespace Crumbls\Importer\Drivers\Common\States;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\Support\ColumnMapper;
use Crumbls\Importer\Traits\HasTransformerDefinition;
use Crumbls\Importer\Traits\IsTableSchemaAware;
use PDO;
use Illuminate\Support\Str;

/**
 * A state to create models from a database.
 */
class DatabaseToMigrationState extends AbstractState
{
	use IsTableSchemaAware,
		HasTransformerDefinition;
	
	public function getName(): string {
		return 'database-to-migration';
	}

	public function handle(): void {
		$record = $this->getRecord();

		$md = $record->metadata ?? [];

		$md['transformers'] = $md['transformers'] ?? [];

		/**
		 * Now create migrations.
		 */
	}
}