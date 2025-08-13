<?php

namespace Crumbls\Importer\Console\Prompts\MappingPrompt;

use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Console\Prompts\Contracts\MigrationPrompt;
use Crumbls\Importer\Models\ImportModelMap;
use Illuminate\Console\Command;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\ListWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Term\Event;
use PhpTui\Term\KeyCode;

class EditFieldPrompt extends AbstractPrompt implements MigrationPrompt
{
    private ImportModelMap $map;
    private string $fieldName;
    private array $fieldConfig;
    private int $selectedProperty = 0;
    private bool $isEditing = false;
    private string $editBuffer = '';
    
    // All Laravel column types
    private array $laravelTypes = [
        'id', 'bigIncrements', 'increments', 'mediumIncrements', 'smallIncrements', 'tinyIncrements',
        'bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger',
        'unsignedBigInteger', 'unsignedInteger', 'unsignedMediumInteger', 'unsignedSmallInteger', 'unsignedTinyInteger',
        'boolean',
        'char', 'string', 'text', 'mediumText', 'longText', 'tinyText',
        'binary',
        'decimal', 'double', 'float', 'unsignedDecimal',
        'date', 'dateTime', 'dateTimeTz', 'time', 'timeTz', 'timestamp', 'timestampTz', 'year',
        'json', 'jsonb',
        'uuid', 'ulid',
        'ipAddress', 'macAddress',
        'enum', 'set',
        'geometry', 'geometryCollection', 'lineString', 'multiLineString', 'multiPoint', 'multiPolygon', 'point', 'polygon'
    ];
    
    private array $editableProperties = [
        'destination_column',
        'laravel_column_type', 
        'nullable',
        'parameters'
    ];

    public function __construct(Command $command, $record = null)
    {
        parent::__construct($command, $record);
        
        // Get map and fieldName from command properties
        $this->map = $command->selectedMap ?? null;
        $this->fieldName = $command->selectedFieldName ?? null;
        
        if (!$this->map || !$this->fieldName) {
            throw new \InvalidArgumentException('ImportModelMap and field name must be provided via command properties');
        }
        
        $this->loadFieldConfig();
    }

    private function loadFieldConfig(): void
    {
        $columns = $this->map->getColumns();
        $this->fieldConfig = $columns[$this->fieldName] ?? [];
    }

    public function handleInput(Event $event, Command $command)
    {
        if ($this->isEditing) {
            $this->handleEditingInput($event);
            return;
        }

        if ($event instanceof Event\CharKeyEvent) {
            match($event->char) {
                'q' => $this->goBack(),
                'j' => $this->moveDown(),
                'k' => $this->moveUp(),
                's' => $this->save(),
                default => null
            };
        } elseif ($event instanceof Event\CodedKeyEvent) {
            match($event->code) {
                KeyCode::Esc => $this->goBack(),
                KeyCode::Up => $this->moveUp(),
                KeyCode::Down => $this->moveDown(),
                KeyCode::Enter => $this->startEditing(),
                default => null
            };
        }
    }

    private function handleEditingInput(Event $event): void
    {
        if ($event instanceof Event\CharKeyEvent) {
            if ($event->char === '\n' || $event->char === '\r') {
                $this->finishEditing();
            } elseif (ord($event->char) === 8 || ord($event->char) === 127) { // Backspace
                $this->editBuffer = substr($this->editBuffer, 0, -1);
            } elseif (ord($event->char) >= 32) { // Printable character
                $this->editBuffer .= $event->char;
            }
        } elseif ($event instanceof Event\CodedKeyEvent) {
            match($event->code) {
                KeyCode::Esc => $this->cancelEditing(),
                KeyCode::Enter => $this->finishEditing(),
                KeyCode::Backspace => $this->editBuffer = substr($this->editBuffer, 0, -1),
                default => null
            };
        }
    }

    private function moveUp(): void
    {
        $this->selectedProperty = max(0, $this->selectedProperty - 1);
    }

    private function moveDown(): void
    {
        $this->selectedProperty = min(count($this->editableProperties) - 1, $this->selectedProperty + 1);
    }

    private function startEditing(): void
    {
        $propertyName = $this->editableProperties[$this->selectedProperty];
        
        if ($propertyName === 'laravel_column_type') {
            $this->cycleColumnType();
            return;
        }
        
        if ($propertyName === 'nullable') {
            $this->toggleNullable();
            return;
        }

        $this->isEditing = true;
        $this->editBuffer = $this->getPropertyValue($propertyName);
    }

    private function cycleColumnType(): void
    {
        $currentType = $this->fieldConfig['laravel_column_type'] ?? 'string';
        $currentIndex = array_search($currentType, $this->laravelTypes);
        $nextIndex = ($currentIndex + 1) % count($this->laravelTypes);
        
        $this->fieldConfig['laravel_column_type'] = $this->laravelTypes[$nextIndex];
        $this->saveFieldConfig();
    }

    private function toggleNullable(): void
    {
        $this->fieldConfig['nullable'] = !($this->fieldConfig['nullable'] ?? false);
        $this->saveFieldConfig();
    }

    private function finishEditing(): void
    {
        $propertyName = $this->editableProperties[$this->selectedProperty];
        
        if ($propertyName === 'destination_column') {
            if ($this->isValidColumnName($this->editBuffer)) {
                $this->fieldConfig[$propertyName] = $this->editBuffer;
                $this->saveFieldConfig();
            } else {
                // Invalid column name - show error but don't save
                \Log::warning("Invalid column name: " . $this->editBuffer);
            }
        } else {
            $this->fieldConfig[$propertyName] = $this->editBuffer;
            $this->saveFieldConfig();
        }
        
        $this->isEditing = false;
        $this->editBuffer = '';
    }

    private function cancelEditing(): void
    {
        $this->isEditing = false;
        $this->editBuffer = '';
    }

    private function isValidColumnName(string $name): bool
    {
        // SQL compliance validation
        if (empty($name)) return false;
        
        // Cannot start with digit
        if (is_numeric(substr($name, 0, 1))) return false;
        
        // Only alphanumeric and underscores
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) return false;
        
        // Not a reserved keyword (basic check)
        $reserved = [
            'select', 'from', 'where', 'insert', 'update', 'delete', 'create', 'drop', 'alter',
            'table', 'database', 'index', 'view', 'trigger', 'procedure', 'function', 'user',
            'group', 'order', 'by', 'having', 'limit', 'offset', 'union', 'join', 'inner',
            'left', 'right', 'full', 'outer', 'on', 'as', 'and', 'or', 'not', 'null',
            'primary', 'key', 'foreign', 'unique', 'check', 'default', 'auto_increment'
        ];
        
        if (in_array(strtolower($name), $reserved)) return false;
        
        // Length check
        if (strlen($name) > 64) return false;
        
        return true;
    }

    private function getPropertyValue(string $propertyName): string
    {
        $value = $this->fieldConfig[$propertyName] ?? '';
        
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        
        if (is_array($value)) {
            return json_encode($value);
        }
        
        return (string) $value;
    }

    private function saveFieldConfig(): void
    {
        $schemaMapping = $this->map->schema_mapping ?? [];
        $schemaMapping['columns'][$this->fieldName] = $this->fieldConfig;
        
        $this->map->setSchemaMapping($schemaMapping);
        $this->map->save();
        
        \Log::info("Field configuration saved", [
            'field' => $this->fieldName,
            'config' => $this->fieldConfig
        ]);
    }

    private function save(): void
    {
        $this->saveFieldConfig();
    }

    private function goBack(): void
    {
        // Store the map for the table prompt
        $this->command->selectedMap = $this->map;
        $this->command->setPrompt(EditTablePrompt::class);
    }

    public static function breadcrumbs(): array
    {
        return [];
    }

    public function tui(): array
    {
        return [
            GridWidget::default()
                ->direction(Direction::Vertical)
                ->constraints(
                    Constraint::length(3), // Header
                    Constraint::min(0),    // Content
                    Constraint::length(3)  // Help
                )
                ->widgets(
                    $this->getHeaderWidget(),
                    $this->getContentWidget(),
                    $this->getHelpWidget()
                )
        ];
    }

    private function getHeaderWidget()
    {
        $title = sprintf("Edit Field: %s → %s", 
            $this->fieldName,
            $this->fieldConfig['destination_column'] ?? 'not set'
        );
        
        return BlockWidget::default()
            ->titles(Title::fromString($title))
            ->borders(Borders::ALL)
            ->style(Style::default()->fg(AnsiColor::Yellow));
    }

    private function getContentWidget()
    {
        $content = '';
        
        foreach ($this->editableProperties as $index => $property) {
            $prefix = $index === $this->selectedProperty ? '► ' : '  ';
            $value = $this->getDisplayValue($property);
            
            if ($this->isEditing && $index === $this->selectedProperty) {
                $value = $this->editBuffer . '█'; // Show cursor
            }
            
            $content .= sprintf("%s%s: %s\n", $prefix, $this->getPropertyLabel($property), $value);
        }
        
        // Add source info
        $content .= "\n" . str_repeat('─', 40) . "\n";
        $content .= sprintf("Source: %s\n", $this->fieldConfig['source_identifier'] ?? $this->fieldName);
        $content .= sprintf("Context: %s\n", $this->fieldConfig['source_context'] ?? 'unknown');
        $content .= sprintf("Type: %s\n", $this->fieldConfig['source_type'] ?? 'column');

        return BlockWidget::default()
            ->titles(Title::fromString('Field Configuration'))
            ->borders(Borders::ALL)
            ->widget(
                ParagraphWidget::fromString($content)
                    ->alignment(HorizontalAlignment::Left)
            );
    }

    private function getPropertyLabel(string $property): string
    {
        return match($property) {
            'destination_column' => 'Column Name',
            'laravel_column_type' => 'Laravel Type',
            'nullable' => 'Nullable',
            'parameters' => 'Parameters',
            default => ucfirst($property)
        };
    }

    private function getDisplayValue(string $property): string
    {
        $value = $this->fieldConfig[$property] ?? null;
        
        return match($property) {
            'nullable' => $value ? '✓ Yes' : '✗ No',
            'laravel_column_type' => $value ?? 'string',
            'parameters' => is_array($value) ? json_encode($value) : ($value ?? '[]'),
            default => $value ?? 'not set'
        };
    }

    private function getHelpWidget()
    {
        if ($this->isEditing) {
            $help = "Editing mode: Type to edit • Enter: Save • Esc: Cancel";
        } else {
            $help = "j/k: Navigate • Enter: Edit • s: Save • Esc: Back";
            
            $selectedProp = $this->editableProperties[$this->selectedProperty] ?? '';
            if ($selectedProp === 'laravel_column_type') {
                $help .= " • (Enter cycles through types)";
            } elseif ($selectedProp === 'nullable') {
                $help .= " • (Enter toggles)";
            }
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->widget(
                ParagraphWidget::fromString($help)
                    ->alignment(HorizontalAlignment::Center)
            );
    }

    public static function getTabTitle(): string
    {
        return 'Edit Field';
    }
}