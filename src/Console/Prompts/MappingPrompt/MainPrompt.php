<?php

namespace Crumbls\Importer\Console\Prompts\MappingPrompt;

use Crumbls\Importer\Console\NavItem;
use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Console\Prompts\Contracts\MigrationPrompt;
use Crumbls\Importer\Console\Prompts\ListImportsPrompt;
use Crumbls\Importer\Models\ImportModelMap;
use Illuminate\Console\Command;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell as Tc;
use PhpTui\Tui\Extension\Core\Widget\TabsWidget;
use PhpTui\Tui\Extension\Core\Widget\ListWidget;
use PhpTui\Tui\Extension\Core\Widget\List\ListItem;
use PhpTui\Tui\Extension\Core\Widget\List\ListState;
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

class MainPrompt extends AbstractMappingPrompt implements MigrationPrompt
{
    private int $currentTab = 0;
    private int $selectedRow = 0;
    private int $scrollOffset = 0;
    private bool $focusOnTabs = true; // true = tabs focused, false = content focused
    
    private array $tabs = [
        'Overview',
        'Models', 
        'Tables',
        'Relationships',
        'Validation'
    ];

    private $cachedMaps = null;

    public function handleInput(Event $event, Command $command)
    {
        $this->baseHandleInput($event, $command);
    }
    
    protected function handleEnter(): void
    {
        $maps = $this->getImportModelMaps();
        
        if ($maps->isNotEmpty() && $maps->has($this->selectedRow)) {
            $selectedMap = $maps->get($this->selectedRow);
            
            match($this->currentTab) {
                1 => $this->editModel($selectedMap), // Models tab
                2 => $this->editTable($selectedMap), // Tables tab
                3 => $this->editRelationships($selectedMap), // Relationships tab
                4 => $this->editValidation($selectedMap), // Validation tab
                default => null
            };
        }
    }

    private function editModel(ImportModelMap $map): void
    {
        // TODO: Navigate to model editing prompt
        \Log::info("Edit model for entity: " . $map->entity_type);
    }

    private function editTable(ImportModelMap $map): void
    {
        // Store the selected map for the next prompt
        $this->command->selectedMap = $map;
        
        // Navigate to table editing prompt  
        $this->command->setPrompt(EditTablePrompt::class);
    }

    private function editRelationships(ImportModelMap $map): void
    {
        // TODO: Navigate to relationship editing prompt
        \Log::info("Edit relationships for entity: " . $map->entity_type);
    }

    private function editValidation(ImportModelMap $map): void
    {
        // TODO: Navigate to validation editing prompt
        \Log::info("Edit validation for entity: " . $map->entity_type);
    }

    private function continueToNextState(): void
    {
        $record = $this->command->getRecord();
        $stateMachine = $record->getStateMachine();
        $currentState = $stateMachine->getCurrentState();
        
        // Execute the mapping state to move forward
        if ($currentState->execute()) {
            $record->refresh();
            $this->command->setRecord($record);
            
            $newStateMachine = $record->getStateMachine();
            $newState = $newStateMachine->getCurrentState();
            $newPromptClass = $newState->getPromptClass();
            
            \Log::info("MappingState completed, transitioning", [
                'new_state' => class_basename($record->state),
                'new_prompt' => $newPromptClass
            ]);
            
            $this->command->setPrompt($newPromptClass);
        }
    }

    public static function breadcrumbs(): array
    {
        return [];
    }

    public function tui(): array
    {
        return [
			GridWidget::default()
	        ->direction(Direction::Horizontal)
	        ->constraints(
		        Constraint::percentage(20),
		        Constraint::percentage(80),
	        )
				->widgets(
					$this->getTabsWidget(),

					GridWidget::default()
						->direction(Direction::Vertical)
						->constraints(
							Constraint::min(0),    // Content
							Constraint::max(3)  // Status bar
						)
						->widgets(
							$this->getContentWidget(),
							$this->getStatusWidget()
						)

				)
        ];
    }

    private function getTabsWidget()
    {
        $items = [];
        foreach ($this->tabs as $index => $tab) {
            $items[] = ListItem::fromString($tab);
        }
        
        $borderStyle = $this->focusOnTabs 
            ? Style::default()->fg(AnsiColor::Yellow) 
            : Style::default()->fg(AnsiColor::White);
        
        $title = $this->focusOnTabs ? 'Navigation [ACTIVE]' : 'Navigation';
        
        return BlockWidget::default()
            ->titles(Title::fromString($title))
            ->borders(Borders::ALL)
            ->style($borderStyle)
            ->widget(
                ListWidget::default()
                    ->items(...$items)
                    ->select($this->currentTab)
                    ->highlightStyle(Style::default()->fg(AnsiColor::Yellow)->bold())
            );
    }

    private function getContentWidget()
    {
        $borderStyle = !$this->focusOnTabs 
            ? Style::default()->fg(AnsiColor::Yellow) 
            : Style::default()->fg(AnsiColor::White);
        
        $title = !$this->focusOnTabs 
            ? $this->tabs[$this->currentTab] . ' [ACTIVE]'
            : $this->tabs[$this->currentTab];
        
        return BlockWidget::default()
            ->titles(Title::fromString($title))
            ->borders(Borders::ALL)
            ->style($borderStyle)
            ->widget($this->getTabContent());
    }

    private function getTabContent()
    {
        return match($this->currentTab) {
            0 => $this->getOverviewContent(),
            1 => $this->getModelsContent(),
            2 => $this->getTablesContent(),
            3 => $this->getRelationshipsContent(),
            4 => $this->getValidationContent(),
            default => ParagraphWidget::fromString('Tab not implemented')
        };
    }

    private function getOverviewContent()
    {
        $maps = $this->getImportModelMaps();
        
        if ($maps->isEmpty()) {
            return ParagraphWidget::fromString(
                "No import model maps found.\n\n" .
                "The mapping state may not have executed yet.\n" .
                "Press 'c' to continue and generate mappings."
            )->alignment(HorizontalAlignment::Left);
        }
        
        $totalMaps = $maps->count();
        $readyMaps = $maps->filter(fn($map) => method_exists($map, 'isReady') ? $map->isReady() : false);
        $conflictMaps = $maps->filter(fn($map) => method_exists($map, 'hasModelConflict') ? $map->hasModelConflict() : false);

        $text = sprintf(
            "Import Configuration Overview\n\n" .
            "Entities found: %d\n" .
            "Ready for generation: %d\n" .
            "Conflicts to resolve: %d\n\n" .
            "Status: %s\n\n" .
            "Navigation:\n" .
            "• Left/Right arrows or h/l: Switch tabs\n" .
            "• Up/Down arrows or j/k: Navigate items\n" .
            "• Enter: Edit selected item\n" .
            "• 'c': Continue to next state\n" .
            "• 'q': Quit\n",
            $totalMaps,
            $readyMaps->count(),
            $conflictMaps->count(),
            $conflictMaps->count() > 0 ? 'Conflicts need resolution' : 'Ready to generate'
        );

        return ParagraphWidget::fromString($text)
            ->alignment(HorizontalAlignment::Left);
    }

    private function getModelsContent()
    {
        $maps = $this->getImportModelMaps();
        
        if ($maps->isEmpty()) {
            return ParagraphWidget::fromString('No entities found');
        }

        $headerRow = TableRow::fromCells(
            Tc::fromString('Entity'),
            Tc::fromString('Model'),
            Tc::fromString('Namespace'),
            Tc::fromString('Status')
        );
        
        $rows = [];
        foreach ($maps as $index => $map) {
            $status = $map->hasModelConflict() ? '⚠ Conflict' : 
                     ($map->isReady() ? '✓ Ready' : '○ Pending');

            $rows[] = TableRow::fromCells(
                Tc::fromString($map->entity_type ?? 'Unknown'),
                Tc::fromString($map->getTargetModelName() ?? 'Not set'),
                Tc::fromString($map->destination_info['namespace'] ?? 'App\\Models'),
                Tc::fromString($status)
            );
        }

        return TableWidget::default()
            ->header($headerRow)
            ->rows(...$rows)
            ->widths(
                Constraint::percentage(25),
                Constraint::percentage(25),
                Constraint::percentage(25),
                Constraint::percentage(25)
            );
    }

    private function getTablesContent()
    {
        $maps = $this->getImportModelMaps();
        
        if ($maps->isEmpty()) {
            return ParagraphWidget::fromString('No entities found');
        }

        $headerRow = TableRow::fromCells(
            Tc::fromString('Entity'),
            Tc::fromString('Source Table'),
            Tc::fromString('Target Table'),
            Tc::fromString('Fields')
        );
        
        $rows = [];
        foreach ($maps as $index => $map) {
            $fieldCount = count($map->getColumns());
            
            $rows[] = TableRow::fromCells(
                Tc::fromString($map->entity_type ?? 'Unknown'),
                Tc::fromString($map->source_table ?? 'Unknown'),
                Tc::fromString($map->getTargetTableName() ?? 'Not set'),
                Tc::fromString((string) $fieldCount)
            );
        }

        return TableWidget::default()
            ->header($headerRow)
            ->rows(...$rows)
            ->widths(
                Constraint::percentage(25),
                Constraint::percentage(25),
                Constraint::percentage(25),
                Constraint::percentage(25)
            );
    }

    private function getRelationshipsContent()
    {
        $maps = $this->getImportModelMaps();
        
        if ($maps->isEmpty()) {
            return ParagraphWidget::fromString('No entities found');
        }

        $headerRow = TableRow::fromCells(
            Tc::fromString('Entity'),
            Tc::fromString('Belongs To'),
            Tc::fromString('Has Many'),
            Tc::fromString('Status')
        );
        
        $rows = [];
        foreach ($maps as $map) {
            $belongsTo = count($map->getRelationshipsByType('belongs_to'));
            $hasMany = count($map->getRelationshipsByType('has_many'));
            
            $rows[] = TableRow::fromCells(
                Tc::fromString($map->entity_type ?? 'Unknown'),
                Tc::fromString((string) $belongsTo),
                Tc::fromString((string) $hasMany),
                Tc::fromString($belongsTo + $hasMany > 0 ? '✓ Set' : '○ None')
            );
        }

        return TableWidget::default()
            ->header($headerRow)
            ->rows(...$rows)
            ->widths(
                Constraint::percentage(25),
                Constraint::percentage(25),
                Constraint::percentage(25),
                Constraint::percentage(25)
            );
    }

    private function getValidationContent()
    {
        $maps = $this->getImportModelMaps();
        
        if ($maps->isEmpty()) {
            return ParagraphWidget::fromString('No entities found');
        }

        $headerRow = TableRow::fromCells(
            Tc::fromString('Entity'),
            Tc::fromString('Required Fields'),
            Tc::fromString('Validation Rules'),
            Tc::fromString('Status')
        );
        
        $rows = [];
        foreach ($maps as $map) {
            $validation = $map->data_validation ?? [];
            $requiredCount = count($validation['required_fields'] ?? []);
            $rulesCount = count($validation['validation_rules'] ?? []);
            
            $rows[] = TableRow::fromCells(
                Tc::fromString($map->entity_type ?? 'Unknown'),
                Tc::fromString((string) $requiredCount),
                Tc::fromString((string) $rulesCount),
                Tc::fromString($rulesCount > 0 ? '✓ Set' : '○ Default')
            );
        }

        return TableWidget::default()
            ->header($headerRow)
            ->rows(...$rows)
            ->widths(
                Constraint::percentage(25),
                Constraint::percentage(25),
                Constraint::percentage(25),
                Constraint::percentage(25)
            );
    }

    private function getStatusWidget()
    {
        $focusMode = $this->focusOnTabs ? 'Navigation' : 'Content';
        $controls = $this->focusOnTabs ? 'j/k: Switch tabs' : 'j/k: Navigate rows';
        
        $statusText = sprintf(
            "Focus: %s | %s | Tab: %s | Row: %d | [Tab] to toggle focus | 'c' continue, 'q' quit",
            $focusMode,
            $controls,
            $this->tabs[$this->currentTab],
            $this->selectedRow + 1
        );

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->widget(
                ParagraphWidget::fromString($statusText)
                    ->alignment(HorizontalAlignment::Center)
            );
    }

    private function getImportModelMaps()
    {
        // Always refresh for now to avoid caching issues
        $record = $this->command->getRecord();
        
        $this->cachedMaps = ImportModelMap::where('import_id', $record->id)
            ->orderBy('entity_type')
            ->get();

        return $this->cachedMaps;
    }

    public static function getTabTitle(): string
    {
        return 'Mapping Editor';
    }
}
