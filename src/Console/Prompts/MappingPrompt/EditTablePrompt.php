<?php

namespace Crumbls\Importer\Console\Prompts\MappingPrompt;

use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Console\Prompts\Contracts\MigrationPrompt;
use Crumbls\Importer\Models\ImportModelMap;
use Illuminate\Console\Command;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell as Tc;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Term\Event;
use PhpTui\Term\KeyCode;

class EditTablePrompt extends AbstractPrompt implements MigrationPrompt
{
    private ImportModelMap $map;
    private int $selectedRow = 0;
    private int $scrollOffset = 0;
    private ?array $cachedFields = null;

    public function __construct(Command $command, $record = null)
    {
        parent::__construct($command, $record);
        
        // Get map from command selectedMap property
        $this->map = $command->selectedMap ?? null;
        
        if (!$this->map) {
            throw new \InvalidArgumentException('ImportModelMap must be provided via command selectedMap property');
        }
    }

    public function handleInput(Event $event, Command $command)
    {
        if ($event instanceof Event\CharKeyEvent) {
            match($event->char) {
                'q' => $this->goBack(),
                'j' => $this->moveDown(),
                'k' => $this->moveUp(),
                'r' => $this->refreshFields(),
                default => null
            };
        } elseif ($event instanceof Event\CodedKeyEvent) {
            match($event->code) {
                KeyCode::Esc => $this->goBack(),
                KeyCode::Up => $this->moveUp(),
                KeyCode::Down => $this->moveDown(),
                KeyCode::Enter => $this->editField(),
                default => null
            };
        }
    }

    private function moveUp(): void
    {
        if ($this->selectedRow > 0) {
            $this->selectedRow--;
            $this->adjustScroll();
        }
    }

    private function moveDown(): void
    {
        $fields = $this->getFields();
        $maxRow = count($fields) - 1;
        
        if ($this->selectedRow < $maxRow) {
            $this->selectedRow++;
            $this->adjustScroll();
        }
    }

    private function adjustScroll(): void
    {
        $visibleRows = 15; // Approximate visible table rows
        
        if ($this->selectedRow < $this->scrollOffset) {
            $this->scrollOffset = $this->selectedRow;
        } elseif ($this->selectedRow >= $this->scrollOffset + $visibleRows) {
            $this->scrollOffset = $this->selectedRow - $visibleRows + 1;
        }
    }

    private function editField(): void
    {
        $fields = $this->getFields();
        
        if (!empty($fields) && isset($fields[$this->selectedRow])) {
            $fieldName = $fields[$this->selectedRow]['name'];
            
            // Store the selected map and field for the next prompt
            $this->command->selectedMap = $this->map;
            $this->command->selectedFieldName = $fieldName;
            
            // Navigate to field editing prompt
            $this->command->setPrompt(EditFieldPrompt::class);
        }
    }

    private function refreshFields(): void
    {
        $this->cachedFields = null;
        $this->map->refresh();
    }

    private function goBack(): void
    {
        $this->command->setPrompt(MainPrompt::class);
    }

    private function getFields(): array
    {
        if ($this->cachedFields !== null) {
            return $this->cachedFields;
        }

        $columns = $this->map->getColumns();
        $fields = [];
        
        foreach ($columns as $fieldName => $config) {
            $fields[] = [
                'name' => $fieldName,
                'source' => $config['source_identifier'] ?? $fieldName,
                'destination' => $config['destination_column'] ?? 'not set',
                'type' => $config['laravel_column_type'] ?? 'string',
                'nullable' => ($config['nullable'] ?? false) ? '✓' : '✗',
                'source_type' => $config['source_type'] ?? 'column',
                'parameters' => $this->formatParameters($config['parameters'] ?? [])
            ];
        }
        
        $this->cachedFields = $fields;
        return $fields;
    }

    private function formatParameters(array $params): string
    {
        if (empty($params)) return '';
        
        if (count($params) === 1 && is_numeric($params[0])) {
            return "({$params[0]})";
        }
        
        if (count($params) === 2 && is_numeric($params[0]) && is_numeric($params[1])) {
            return "({$params[0]},{$params[1]})";
        }
        
        return '(' . implode(',', $params) . ')';
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
        $title = sprintf("Table: %s → %s (%d fields)", 
            $this->map->source_table ?? 'Unknown',
            $this->map->getTargetTableName() ?? 'Not set',
            count($this->getFields())
        );
        
        return BlockWidget::default()
            ->titles(Title::fromString($title))
            ->borders(Borders::ALL)
            ->style(Style::default()->fg(AnsiColor::Blue));
    }

    private function getContentWidget()
    {
        $fields = $this->getFields();
        
        if (empty($fields)) {
            return BlockWidget::default()
                ->titles(Title::fromString('No Fields'))
                ->borders(Borders::ALL)
                ->widget(
                    ParagraphWidget::fromString('No fields found for this table.')
                        ->alignment(HorizontalAlignment::Center)
                );
        }

        $headerRow = TableRow::fromCells(
            Tc::fromString('Source Field'),
            Tc::fromString('Target Column'),
            Tc::fromString('Type'),
            Tc::fromString('Null'),
            Tc::fromString('Context'),
            Tc::fromString('Params')
        );
        
        $rows = [];
        foreach ($fields as $index => $field) {
            $rows[] = TableRow::fromCells(
                Tc::fromString($field['source']),
                Tc::fromString($field['destination']),
                Tc::fromString($field['type']),
                Tc::fromString($field['nullable']), 
                Tc::fromString($field['source_type']),
                Tc::fromString($field['parameters'])
            );
        }

        return BlockWidget::default()
            ->titles(Title::fromString('Field Mappings'))
            ->borders(Borders::ALL)
            ->widget(
                TableWidget::default()
                    ->header($headerRow)
                    ->rows(...$rows)
                    ->widths(
                        Constraint::percentage(20), // Source Field
                        Constraint::percentage(20), // Target Column  
                        Constraint::percentage(15), // Type
                        Constraint::percentage(8),  // Null
                        Constraint::percentage(12), // Context
                        Constraint::percentage(25)  // Params
                    )
            );
    }

    private function getHelpWidget()
    {
        $selectedField = '';
        $fields = $this->getFields();
        if (!empty($fields) && isset($fields[$this->selectedRow])) {
            $selectedField = " • Selected: " . $fields[$this->selectedRow]['name'];
        }

        $help = "j/k: Navigate • Enter: Edit field • r: Refresh • Esc: Back" . $selectedField;

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->widget(
                ParagraphWidget::fromString($help)
                    ->alignment(HorizontalAlignment::Center)
            );
    }

    public static function getTabTitle(): string
    {
        return 'Edit Table';
    }
}