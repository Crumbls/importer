<?php

namespace Crumbls\Importer\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use function Laravel\Prompts\select;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

use function Laravel\Prompts\multiselect;

class ModelConfigurator
{
    protected Command $command;
    protected array $configuration = [];
    protected array $suggestions = [];
    protected ?string $currentContext = null;
    protected ?string $currentModel = null;

    public function __construct(Command $command)
    {
        $this->command = $command;
    }

    public function configure(array $suggestions): array
    {
        $this->suggestions = $suggestions;
        $this->configuration = $this->initializeConfiguration($suggestions);
        
        $this->showWelcome();
        $this->runMainLoop();
        
        return $this->configuration;
    }

    protected function showWelcome(): void
    {
        $this->command->info('');
        $this->command->info('🔧 <comment>Model Configuration</comment>');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('Configure how your imported data should be structured as Laravel models.');
        $this->command->info('You can rename models, configure fields, set up relationships, and more.');
        $this->command->info('');
        $this->command->info('<comment>Commands:</comment> list, edit <model>, rename <model>, remove <model>, preview, save, help');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('');
    }

    protected function runMainLoop(): void
    {
        $this->currentContext = 'main';
        $this->showModelList();

        while (true) {
	        $command = select(
		        label: 'Commands',
		        options: [
			        'List',
			        'Edit Model',
			        'Rename Model',
			        'Remove Model',
			        'Add Model',
			        'Preview Configrution',
			        'Save Configuration',
			        'Continue'
		        ]
	        );

            if (empty($command)) {
                continue;
            }

			$command = Str::camel('handle command '.$command);

            try {
				$result = null;
				if (method_exists($this, $command)) {
					$result = $this->$command();
				} else {
					echo $command;
					exit;
					continue;
				}

//                $result = $this->handleCommand($command, $args);
                // TODO: Implement command handling
                $result = 'c';
                if ($result === 'c') {
                    break;
                }
            } catch (\Exception $e) {
                $this->command->error('Error: ' . $e->getMessage());
            }
        }
    }

    protected function handleCommand(string $command, array $args): ?string
    {
        if ($this->currentContext === 'main') {
            return $this->handleMainCommand($command, $args);
        } elseif ($this->currentContext === 'model') {
            return $this->handleModelCommand($command, $args);
        }

        return null;
    }

    protected function handleMainCommand(string $command, array $args): ?string
    {
        switch ($command) {
            case 'list':
            case 'ls':
                $this->showModelList();
                break;

            case 'edit':
                if (empty($args[0])) {
                    $this->command->error('Usage: edit <model>');
                    break;
                }
                return $this->editModel($args[0]);

            case 'rename':
                if (count($args) < 2) {
                    $this->command->error('Usage: rename <model> <new_name>');
                    break;
                }
                $this->renameModel($args[0], $args[1]);
                break;

            case 'remove':
                if (empty($args[0])) {
                    $this->command->error('Usage: remove <model>');
                    break;
                }
                $this->removeModel($args[0]);
                break;

            case 'add':
                $this->addModel();
                break;

            case 'preview':
                $this->showPreview();
                break;

            case 'save':
                return 'exit';

            case 'help':
                $this->showHelp();
                break;

            default:
                $this->command->error("Unknown command: {$command}. Type 'help' for available commands.");
        }

        return null;
    }

	protected function handleCommandEditModel() : void {
		$models = array_keys($this->configuration['models']);

		sort($models);

		$model = select(
			label: 'Model',
			options: $models
		);

		if (empty($model)) {
			return;
		}
		print_r($this->configuration['models'][$model]);
		// TODO: Implement model editing functionality
	}

    protected function showModelList(): void
    {
        $this->command->info('📋 <comment>Suggested Models:</comment>');
        
        $tableData = [];
        foreach ($this->configuration['models'] as $modelName => $model) {
            $sourceInfo = $model['source_table'];
            if (!empty($model['source_conditions'])) {
                $conditions = [];
                foreach ($model['source_conditions'] as $key => $value) {
                    $conditions[] = "{$key}={$value}";
                }
                $sourceInfo .= ' (' . implode(', ', $conditions) . ')';
            }

            $tableData[] = [
                $modelName,
                $model['table_name'],
                $sourceInfo,
                $model['estimated_records'] ?? '?',
                $model['configured'] ? '✅' : '⚙️'
            ];
        }

		$path = 'butts';

	    info(<<<EOT
    Installation complete!

    To get started, run:

        cd {$path}
        php artisan serve
    EOT);

	    multiselect(
		    label: 'What permissions should the user have?',
		    options: [
			    'view' => 'View',
			    'create' => 'Create',
			    'update' => 'Update',
			    'delete' => 'Delete',
			    'restore' => 'Restore',
			    'force-delete' => 'Force delete',
		    ],
		    validate: fn ($values) => match (true) {
			    empty($values) => 'Please select at least one permission.',
			    default => null,
		    },
		    hint: 'The permissions will determine what the user can do.',
	    );
//		dd($tableData);
//        $this->command->table(['Model', 'Table', 'Source', 'Records', 'Status'], $tableData);
	    table(['Model', 'Table', 'Source', 'Records', 'Status'], $tableData);
        $this->command->info('');
    }

    protected function editModel(string $modelName): ?string
    {
        if (!isset($this->configuration['models'][$modelName])) {
            $this->command->error("Model '{$modelName}' not found.");
            return null;
        }

        $this->currentContext = 'model';
        $this->currentModel = $modelName;
        
        $this->showModelDetails($modelName);
        
        while ($this->currentContext === 'model') {
            $input = $this->command->ask($modelName . '> ');
            
            if (empty($input)) {
                continue;
            }

            $parts = explode(' ', trim($input));
            $command = array_shift($parts);
            $args = $parts;

            $result = $this->handleModelCommand($command, $args);
            
            if ($result === 'back') {
                $this->currentContext = 'main';
                $this->currentModel = null;
                $this->showModelList();
                break;
            }
        }

        return null;
    }

    protected function handleModelCommand(string $command, array $args): ?string
    {
        switch ($command) {
            case 'fields':
            case 'show':
                $this->showModelDetails($this->currentModel);
                break;

            case 'rename-field':
                if (count($args) < 2) {
                    $this->command->error('Usage: rename-field <old_name> <new_name>');
                    break;
                }
                $this->renameField($args[0], $args[1]);
                break;

            case 'type':
                if (count($args) < 2) {
                    $this->command->error('Usage: type <field> <new_type>');
                    break;
                }
                $this->changeFieldType($args[0], $args[1]);
                break;

            case 'map':
                if (count($args) < 2) {
                    $this->command->error('Usage: map <field> <source_field>');
                    break;
                }
                $this->mapField($args[0], $args[1]);
                break;

            case 'add-field':
                $this->addField();
                break;

            case 'remove-field':
                if (empty($args[0])) {
                    $this->command->error('Usage: remove-field <field>');
                    break;
                }
                $this->removeField($args[0]);
                break;

            case 'table-name':
                if (empty($args[0])) {
                    $this->command->error('Usage: table-name <new_name>');
                    break;
                }
                $this->changeTableName($args[0]);
                break;

            case 'relationships':
                $this->showRelationships();
                break;

            case 'back':
                return 'back';

            case 'help':
                $this->showModelHelp();
                break;

            default:
                $this->command->error("Unknown command: {$command}. Type 'help' for available commands.");
        }

        return null;
    }

    protected function showModelDetails(string $modelName): void
    {
        $model = $this->configuration['models'][$modelName];
        
        $this->command->info('');
        $this->command->info("📝 <comment>Configuring {$modelName} Model</comment>");
        $this->command->info("Table: <info>{$model['table_name']}</info> | Source: <info>{$model['source_table']}</info>");
        $this->command->info('');
        
        $this->command->info('<comment>Fields:</comment>');
        $fieldData = [];
        foreach ($model['fields'] as $fieldName => $field) {
            $fieldData[] = [
                $fieldName,
                $field['type'] ?? 'string',
                $field['source'] ?? $fieldName,
                $field['nullable'] ?? false ? 'Yes' : 'No',
                $field['primary'] ?? false ? 'Yes' : 'No'
            ];
        }
        
        $this->command->table(['Field', 'Type', 'Source', 'Nullable', 'Primary'], $fieldData);
        
        $this->command->info('');
        $this->command->info('<comment>Commands:</comment> fields, rename-field, type, map, add-field, remove-field, table-name, relationships, back, help');
        $this->command->info('');

	    $role = select(
		    label: 'What role should the user have?',
		    options: ['Member', 'Contributor', 'Owner']
	    );

    }

    protected function renameField(string $oldName, string $newName): void
    {
        $model = &$this->configuration['models'][$this->currentModel];
        
        if (!isset($model['fields'][$oldName])) {
            $this->command->error("Field '{$oldName}' not found.");
            return;
        }

        $model['fields'][$newName] = $model['fields'][$oldName];
        unset($model['fields'][$oldName]);
        
        $this->command->info("✅ Renamed field '{$oldName}' to '{$newName}'");
        $this->markAsConfigured($this->currentModel);
    }

    protected function changeFieldType(string $fieldName, string $newType): void
    {
        $model = &$this->configuration['models'][$this->currentModel];
        
        if (!isset($model['fields'][$fieldName])) {
            $this->command->error("Field '{$fieldName}' not found.");
            return;
        }

        $validTypes = ['string', 'text', 'integer', 'bigInteger', 'boolean', 'timestamp', 'date', 'json'];
        if (!in_array($newType, $validTypes)) {
            $this->command->error("Invalid type. Valid types: " . implode(', ', $validTypes));
            return;
        }

        $model['fields'][$fieldName]['type'] = $newType;
        $this->command->info("✅ Changed '{$fieldName}' type to '{$newType}'");
        $this->markAsConfigured($this->currentModel);
    }

    protected function showPreview(): void
    {
        $this->command->info('');
        $this->command->info('📋 <comment>Configuration Preview</comment>');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        
        foreach ($this->configuration['models'] as $modelName => $model) {
            $this->command->info("🏗️  <info>{$modelName}</info> → {$model['table_name']}");
            $this->command->info("   Source: {$model['source_table']}");
            
            if (!empty($model['source_conditions'])) {
                $conditions = [];
                foreach ($model['source_conditions'] as $key => $value) {
                    $conditions[] = "{$key}={$value}";
                }
                $this->command->info("   Conditions: " . implode(', ', $conditions));
            }
            
            $this->command->info("   Fields: " . count($model['fields']));
            $this->command->info('');
        }
        
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }

    protected function showHelp(): void
    {
        $this->command->info('');
        $this->command->info('<comment>Available Commands:</comment>');
        $this->command->info('  <info>list</info>              - Show all models');
        $this->command->info('  <info>edit <model></info>      - Configure a specific model');
        $this->command->info('  <info>rename <model> <name></info> - Rename a model');
        $this->command->info('  <info>remove <model></info>    - Remove a model');
        $this->command->info('  <info>add</info>               - Add a new model');
        $this->command->info('  <info>preview</info>           - Preview configuration');
        $this->command->info('  <info>save</info>              - Save configuration and continue');
        $this->command->info('  <info>help</info>              - Show this help');
        $this->command->info('');
    }

    protected function initializeConfiguration(array $suggestions): array
    {
        return [
            'models' => $suggestions,
            'configured_at' => null,
            'configuration_version' => '1.0'
        ];
    }

    protected function markAsConfigured(string $modelName): void
    {
        $this->configuration['models'][$modelName]['configured'] = true;
    }

    // Placeholder methods for future implementation
    protected function renameModel(string $oldName, string $newName): void 
    {
        $this->command->info("✅ Model '{$oldName}' renamed to '{$newName}'");
    }
    
    protected function removeModel(string $modelName): void 
    {
        unset($this->configuration['models'][$modelName]);
        $this->command->info("✅ Model '{$modelName}' removed");
        $this->showModelList();
    }
    
    protected function addModel(): void 
    {
        $this->command->info("🔧 Add model functionality coming soon...");
    }
    
    protected function mapField(string $field, string $source): void 
    {
        $this->command->info("✅ Mapped '{$field}' to source '{$source}'");
    }
    
    protected function addField(): void 
    {
        $this->command->info("🔧 Add field functionality coming soon...");
    }
    
    protected function removeField(string $field): void 
    {
        $this->command->info("✅ Removed field '{$field}'");
    }
    
    protected function changeTableName(string $newName): void 
    {
        $this->configuration['models'][$this->currentModel]['table_name'] = $newName;
        $this->command->info("✅ Table name changed to '{$newName}'");
    }
    
    protected function showRelationships(): void 
    {
        $this->command->info("🔧 Relationships configuration coming soon...");
    }
    
    protected function showModelHelp(): void 
    {
        $this->command->info('');
        $this->command->info('<comment>Model Commands:</comment>');
        $this->command->info('  <info>fields</info>                    - Show model fields');
        $this->command->info('  <info>rename-field <old> <new></info>  - Rename a field');
        $this->command->info('  <info>type <field> <type></info>       - Change field type');
        $this->command->info('  <info>map <field> <source></info>      - Map field to source');
        $this->command->info('  <info>table-name <name></info>         - Change table name');
        $this->command->info('  <info>back</info>                      - Return to model list');
        $this->command->info('');
    }
}