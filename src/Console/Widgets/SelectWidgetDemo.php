<?php

namespace Crumbls\Importer\Console\Widgets;

use PhpTui\Tui\DisplayBuilder;

/**
 * Demo class showing how to use the SelectWidget
 * This is not part of the main widget - just for testing/demonstration
 */
class SelectWidgetDemo
{
    private SelectWidget $widget;
    private bool $running = true;

    public function __construct()
    {
        // Create sample data for testing
        $sampleItems = [
            'Apple',
            'Banana',
            'Cherry',
            'Date',
            'Elderberry',
            'Fig',
            'Grape',
            'Honeydew',
            'Kiwi',
            'Lemon',
            'Mango',
            'Orange',
            'Papaya',
            'Quince',
            'Raspberry',
            'Strawberry',
            'Tangerine',
            'Ugli fruit',
            'Vanilla bean',
            'Watermelon',
        ];

        $this->widget = new SelectWidget(
            items: $sampleItems,
            closedText: 'Press Enter to select a fruit',
            title: 'Fruit Selection',
            visibleItems: 8
        );
    }

    public function run(): void
    {
        $display = DisplayBuilder::default()->build();

        while ($this->running) {
            // Clear and render
            $display->draw($this->widget);

            // Handle input (simplified - in real usage you'd use proper key handling)
            $this->handleInput();
            
            usleep(100000); // 100ms delay
        }
    }

    private function handleInput(): void
    {
        // This is a simplified input handler for demonstration
        // In real usage, you'd integrate this with your TUI's event system
        
        // For now, just simulate some actions
        static $counter = 0;
        $counter++;

        // Demo sequence:
        switch ($counter) {
            case 1:
                $this->widget->open();
                echo "Widget opened\n";
                break;
            case 10:
                $this->widget->moveDown()->moveDown()->moveDown();
                echo "Moved down 3 positions\n";
                break;
            case 20:
                $this->widget->pageDown();
                echo "Page down\n";
                break;
            case 30:
                $this->widget->moveUp()->moveUp();
                echo "Moved up 2 positions\n";
                break;
            case 40:
                $selected = $this->widget->selectCurrent();
                echo "Selected: {$selected}\n";
                $this->widget->close();
                break;
            case 50:
                $this->running = false;
                echo "Demo complete\n";
                break;
        }
    }

    public static function keyEventExample(): array
    {
        return [
            // Key mappings you might use in your TUI
            'Enter' => 'toggle',           // Open/close widget
            'Escape' => 'close',           // Close widget
            'ArrowUp' => 'moveUp',         // Move selection up
            'ArrowDown' => 'moveDown',     // Move selection down
            'PageUp' => 'pageUp',          // Page up
            'PageDown' => 'pageDown',      // Page down
            'Home' => 'goToTop',           // Go to first item
            'End' => 'goToBottom',         // Go to last item
            'Space' => 'selectCurrent',    // Select current and close
        ];
    }

    public function handleKeyEvent(string $key): void
    {
        $mappings = self::keyEventExample();
        
        if (isset($mappings[$key])) {
            $method = $mappings[$key];
            
            // Handle toggle specially since it affects state
            if ($method === 'toggle') {
                if ($this->widget->isOpen()) {
                    // If open, treat Enter as select and close
                    $selected = $this->widget->selectCurrent();
                    $this->widget->close();
                    $this->onSelectionMade($selected);
                } else {
                    // If closed, open it
                    $this->widget->open();
                }
                return;
            }
            
            // Only handle other actions if widget is open
            if ($this->widget->isOpen()) {
                switch ($method) {
                    case 'close':
                        $this->widget->close();
                        break;
                    case 'selectCurrent':
                        $selected = $this->widget->selectCurrent();
                        $this->widget->close();
                        $this->onSelectionMade($selected);
                        break;
                    default:
                        // Call the method dynamically
                        if (method_exists($this->widget, $method)) {
                            $this->widget->$method();
                        }
                        break;
                }
            }
        }
    }

    private function onSelectionMade($selected): void
    {
        // Callback for when a selection is made
        echo "User selected: {$selected}\n";
    }
}