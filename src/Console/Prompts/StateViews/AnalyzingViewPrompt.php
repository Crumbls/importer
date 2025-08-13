<?php

namespace Crumbls\Importer\Console\Prompts\StateViews;

use Crumbls\Importer\Console\NavItem;
use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Console\Prompts\Contracts\MigrationPrompt;
use Crumbls\Importer\Console\Prompts\ListImportsPrompt;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AbstractState;
use Illuminate\Console\Command;
use PhpTui\Term\Event;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Color\AnsiColor;

/**
 * AnalyzingViewPrompt - Shows the analyzing state with progress and analysis results
 */
class AnalyzingViewPrompt extends AbstractPrompt implements MigrationPrompt
{
    protected ?ImportContract $import = null;

    public function __construct(Command $command)
    {
        parent::__construct($command);
        $this->import = $command->getRecord();
    }

    public function render(): ?ImportContract
    {
        return $this->import;
    }

    public function tui(): array
    {
        if (!$this->import) {
            return [
                BlockWidget::default()
                    ->titles(Title::fromString('No Import Available'))
                    ->borders(Borders::ALL)
                    ->widget(
                        ParagraphWidget::fromString('No import record available.')
                            ->alignment(HorizontalAlignment::Center)
                    )
            ];
        }

        return [
            GridWidget::default()
                ->direction(Direction::Horizontal)
                ->constraints(
                    Constraint::percentage(20),
                    Constraint::percentage(60),
                    Constraint::percentage(20),
                )
                ->widgets(
                    GridWidget::default()->direction(Direction::Vertical)->widgets(),
                    GridWidget::default()
                        ->direction(Direction::Vertical)
                        ->constraints(
                            Constraint::max(5),  // Header
                            Constraint::min(10), // Analysis content
                            Constraint::max(5),  // Progress
                            Constraint::max(3),  // Actions
                        )
                        ->widgets(
                            $this->getHeaderWidget(),
                            $this->getAnalysisWidget(),
                            $this->getProgressWidget(),
                            $this->getActionsWidget(),
                        ),
                    GridWidget::default()->direction(Direction::Vertical)->widgets(),
                )
        ];
    }

    protected function getHeaderWidget(): Widget
    {
        return BlockWidget::default()
            ->titles(Title::fromString(sprintf('Analyzing Import #%d', $this->import->getKey())))
            ->borders(Borders::ALL)
            ->style(Style::default()->fg(AnsiColor::Blue))
            ->widget(
                ParagraphWidget::fromString(sprintf(
                    'Driver: %s | Source: %s',
                    $this->import->driver ?? 'Unknown',
                    $this->import->source_type ?? 'Unknown'
                ))
                ->alignment(HorizontalAlignment::Center)
            );
    }

    protected function getAnalysisWidget(): Widget
    {
        // Get analysis data from the state if available
        $content = $this->getAnalysisContent();

        return BlockWidget::default()
            ->titles(Title::fromString('Analysis Results'))
            ->borders(Borders::ALL)
            ->widget(
                ParagraphWidget::fromString($content)
            );
    }

    protected function getAnalysisContent(): string
    {
        // Check if this is AutoDriver analyzing state
        $stateMachine = $this->import->getStateMachine();
        $currentState = $stateMachine->getCurrentState();
        
        if ($currentState instanceof \Crumbls\Importer\States\AutoDriver\AnalyzingState) {
            return $this->getAutoDriverContent();
        }

        // Try to get analysis data from the import's state for other drivers
        try {
            if ($currentState && method_exists($currentState, 'getAnalysisData')) {
                $analysisData = $currentState->getAnalysisData();
                
                if (!empty($analysisData)) {
                    $content = "Data Structure Analysis:\n\n";
                    
                    foreach ($analysisData as $field) {
                        $content .= sprintf(
                            "• %s: %s (%d%% confidence)\n",
                            $field['field_name'] ?? 'Unknown Field',
                            $field['type'] ?? 'unknown',
                            $field['confidence'] ?? 0
                        );
                    }
                    
                    return $content;
                }
            }
        } catch (\Exception $e) {
            // Fall back to generic message
        }

        return "Analyzing data structure and field types...\n\n" .
               "This process examines your source data to:\n" .
               "• Detect field data types\n" .
               "• Analyze data patterns\n" .
               "• Suggest mapping strategies\n" .
               "• Identify potential issues\n\n" .
               "Please wait while analysis completes.";
    }

    protected function getAutoDriverContent(): string
    {
        return "🔍 Auto-detecting compatible driver...\n\n" .
               "Testing drivers in order:\n" .
               "• WordPress XML Driver... ⏳\n" .
               "• CSV Driver... 🔍\n" .
               "• JSON Driver... ⏳\n" .
               "• Generic XML Driver... ⏳\n\n" .
               "This will automatically select the best driver\n" .
               "for your data source and transition to the\n" .
               "appropriate processing state.\n\n" .
               "━━━━━━━━━━░░░░░░░░░░ 50%";
    }

    protected function getProgressWidget(): Widget
    {
        return BlockWidget::default()
            ->titles(Title::fromString('Progress'))
            ->borders(Borders::ALL)
            ->style(Style::default()->fg(AnsiColor::Yellow))
            ->widget(
                ParagraphWidget::fromString("🔍 Analysis in progress...\n\nPress Enter to continue when ready")
                    ->alignment(HorizontalAlignment::Center)
            );
    }

    protected function getActionsWidget(): Widget
    {
        return BlockWidget::default()
            ->titles(Title::fromString('Actions'))
            ->borders(Borders::ALL)
            ->style(Style::default()->fg(AnsiColor::Green))
            ->widget(
                ParagraphWidget::fromString('Enter: Continue | R: Refresh | Esc: Back to list')
                    ->alignment(HorizontalAlignment::Center)
            );
    }

    public function handleInput(Event $event, Command $command)
    {
        if ($event instanceof CodedKeyEvent) {
            switch ($event->code) {
                case KeyCode::Enter:
                    // Try to transition the state or continue processing
                    if ($this->import) {
                        $stateMachine = $this->import->getStateMachine();
                        $currentState = $stateMachine->getCurrentState();
                        
                        if ($currentState instanceof AbstractState) {
                            // Execute the state and potentially transition
                            if ($currentState->execute()) {
                                // Check if we should auto-transition
                                if ($currentState->shouldAutoTransition($this->import)) {
                                    // Let the state handle the transition
                                    $command->setPrompt(ViewImportPrompt::class);
                                    return;
                                }
                            }
                        }
                    }
                    break;
                    
                case KeyCode::Char:
                    if ($event->char === 'r' || $event->char === 'R') {
                        // Refresh the view
                        $command->setPrompt(static::class);
                        return;
                    }
                    break;
                    
                case KeyCode::Esc:
                    $command->setPrompt(ListImportsPrompt::class);
                    return;
            }
        }

        parent::handleInput($event, $command);
    }

    public static function getTabTitle(): string
    {
        return 'Analyzing Data';
    }

    public static function breadcrumbs(): array
    {
        $base = ListImportsPrompt::breadcrumbs();
        $base[static::class] = new NavItem(static::class, 'Analyzing Data');
        return $base;
    }
}