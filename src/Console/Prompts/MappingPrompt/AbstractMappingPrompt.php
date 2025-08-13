<?php

namespace Crumbls\Importer\Console\Prompts\MappingPrompt;

use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Console\Prompts\MappingPrompt\MainMenuPrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\Contracts\ImportModelMapContract;
use Crumbls\Importer\Models\ImportModelMap;
use Crumbls\Importer\Resolvers\ModelResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

abstract class AbstractMappingPrompt extends AbstractPrompt
{

    // --- Navigation/Focus State ---
    protected int $currentTab = 0;
    protected int $selectedRow = 0;
    protected int $scrollOffset = 0;
    protected bool $focusOnTabs = true; // true = tabs focused, false = content focused
    protected array $tabs = [
        'Overview',
        'Models',
        'Tables',
        'Relationships',
        'Validation'
    ];

    // --- Navigation/Focus Logic ---
    protected function toggleFocus(): void
    {
        $this->focusOnTabs = !$this->focusOnTabs;
    }

    protected function handleFocusedInput($input): void
    {
        if ($this->focusOnTabs) {
            // Tab navigation mode
            if ($input === 'j' || $input === \PhpTui\Term\KeyCode::Down) {
                $this->nextTab();
            } elseif ($input === 'k' || $input === \PhpTui\Term\KeyCode::Up) {
                $this->previousTab();
            }
        } else {
            // Content navigation mode
            if ($input === 'j' || $input === \PhpTui\Term\KeyCode::Down) {
                $this->moveDown();
            } elseif ($input === 'k' || $input === \PhpTui\Term\KeyCode::Up) {
                $this->moveUp();
            } elseif ($input === 'h' || $input === \PhpTui\Term\KeyCode::Left) {
                $this->moveUp();
            } elseif ($input === 'l' || $input === \PhpTui\Term\KeyCode::Right) {
                $this->moveDown();
            }
        }
    }

    protected function previousTab(): void
    {
        $this->currentTab = max(0, $this->currentTab - 1);
        $this->resetSelection();
    }

    protected function nextTab(): void
    {
        $this->currentTab = min(count($this->tabs) - 1, $this->currentTab + 1);
        $this->resetSelection();
    }

    protected function moveUp(): void
    {
        if ($this->selectedRow > 0) {
            $this->selectedRow--;
            $this->adjustScroll();
        }
    }

    protected function moveDown(): void
    {
        $maps = $this->getImportModelMaps();
        $maxRow = count($maps) - 1;
        if ($this->selectedRow < $maxRow) {
            $this->selectedRow++;
            $this->adjustScroll();
        }
    }

    protected function resetSelection(): void
    {
        $this->selectedRow = 0;
        $this->scrollOffset = 0;
    }

    protected function adjustScroll(): void
    {
        $visibleRows = 10; // Approximate visible table rows
        if ($this->selectedRow < $this->scrollOffset) {
            $this->scrollOffset = $this->selectedRow;
        } elseif ($this->selectedRow >= $this->scrollOffset + $visibleRows) {
            $this->scrollOffset = $this->selectedRow - $visibleRows + 1;
        }
    }

    // Abstract: subclasses must implement this to handle Enter/selection
    abstract protected function handleEnter(): void;

    // Subclasses should call this in their handleInput
    protected function baseHandleInput(\PhpTui\Term\Event $event, \Illuminate\Console\Command $command)
    {
        if ($event instanceof \PhpTui\Term\Event\CharKeyEvent) {
            match($event->char) {
                'q' => parent::handleInput($event, $command),
                'c' => $this->continueToNextState(),
                default => $this->handleFocusedInput($event->char)
            };
        } elseif ($event instanceof \PhpTui\Term\Event\CodedKeyEvent) {
            match($event->code) {
                \PhpTui\Term\KeyCode::Esc => $this->command->setPrompt(\Crumbls\Importer\Console\Prompts\ListImportsPrompt::class),
                \PhpTui\Term\KeyCode::Tab => $this->toggleFocus(),
                \PhpTui\Term\KeyCode::Enter => $this->handleEnter(),
                default => $this->handleFocusedInput($event->code)
            };
        }
    }

    public function getImportModelMaps() : Collection {
        return once(function() {
            $modelClass = ModelResolver::importModelMap();

            $modelMaps = $this->record->relationLoaded('modelMaps') ? $this->record->modelMaps : $modelClass::where('import_id', $this->record->id)
                ->where('is_active', true)
                ->orderBy('priority', 'asc')
                ->get();

            return $modelMaps;
        });
    }

    protected function isReady(ImportModelMapContract $map): bool
    {
        return $map->isReady();
    }

    protected function getConflicts(): \Illuminate\Support\Collection
    {
        $modelClass = ModelResolver::importModelMap();
        $modelMaps = $this->record->relationLoaded('modelMaps')
            ? $this->record->modelMaps
            : $modelClass::where('import_id', $this->record->id)
                ->where('is_active', true)
                ->get();

        $conflicts = [];

        foreach ($modelMaps as $map) {
            $conflictResolution = $map->conflict_resolution ?? [];
            $hasConflict = $conflictResolution['conflict_detected'] ?? false;

            if ($hasConflict) {
                $conflicts[] = [
                    'id' => $map->getKey(),
                    'entity_type' => $map->entity_type,
                    'target_model' => $map->target_model,
                    'conflict_info' => $conflictResolution['existing_model_info'] ?? [],
                    'strategy' => $conflictResolution['strategy'] ?? 'smart_extension',
                    'safety_score' => $conflictResolution['existing_model_info']['safety_score'] ?? 0.5,
                ];
            }
        }

        return collect($conflicts);
    }
}