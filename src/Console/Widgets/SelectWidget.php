<?php

namespace Crumbls\Importer\Console\Widgets;

use Crumbls\Importer\Console\Widgets\Concerns\IsFocusable;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;

use PhpTui\Tui\Extension\Core\Widget\List\ListState;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Color\AnsiColor;

class SelectWidget implements Widget
{
	use IsFocusable;

    private array $items;
    private bool $isOpen;
    private int $selectedIndex;
    private int $scrollOffset;
    private int $visibleItems;
    private string $closedText;
    private ?string $title;
    private ListState $listState;

    public function __construct(
        array $items = [],
        string $closedText = 'Click to open selection',
        ?string $title = null,
        int $visibleItems = 10
    ) {
        $this->items = $items;
        $this->isOpen = false;
        $this->selectedIndex = 0;
        $this->scrollOffset = 0;
        $this->visibleItems = $visibleItems;
        $this->closedText = $closedText;
        $this->title = $title;
        $this->listState = new ListState();
    }

    public function render(DisplayBuilder $display): void
    {
        $this->widget()->render($display);
    }

    public function widget(): Widget
    {
        $block = BlockWidget::default()
            ->borders(Borders::ALL);


        if ($this->title !== null) {
            $block = $block->titles(Title::fromString($this->title));
        }

	    if ($this->isFocused()) {
		    $block = $block->borderStyle(Style::default()->fg(AnsiColor::Blue))
			    ->titleStyle(Style::default()->fg(AnsiColor::White));
		    ;
	    }


	    return $block->widget($this->isOpen ? $this->getOpenWidget() : $this->getClosedWidget());
    }

    private function getClosedWidget(): Widget
    {
        return ParagraphWidget::fromString($this->closedText);
    }

    private function getOpenWidget(): Widget
    {
        // Calculate visible range based on scroll offset
        $startIndex = $this->scrollOffset;
        $endIndex = min($startIndex + $this->visibleItems, count($this->items));
        $visibleItems = array_slice($this->items, $startIndex, $this->visibleItems);

        $lines = [];
        
        // Add each visible item
        for ($i = 0; $i < count($visibleItems); $i++) {
            $actualIndex = $startIndex + $i;
            $item = $visibleItems[$i];
            $isSelected = $actualIndex === $this->selectedIndex;
            
            if ($isSelected) {
                $lines[] = Line::fromSpans(
                    Span::styled(
                        sprintf('> %-50s', $this->formatItem($item)), 
                        Style::default()->bg(AnsiColor::Blue)->fg(AnsiColor::White)
                    )
                );
            } else {
                $lines[] = Line::fromSpans(
                    Span::fromString(sprintf('  %-50s', $this->formatItem($item)))
                );
            }
        }

        // Add scroll indicators if needed
        if ($this->needsScrollIndicators()) {
            $lines = $this->addScrollIndicators($lines);
        }

        return ParagraphWidget::fromLines(...$lines);
    }

    private function formatItem($item): string
    {
        if (is_string($item)) {
            return $item;
        } elseif (is_array($item)) {
            // Check for display key first (used by file browser)
            if (isset($item['display'])) {
                return $item['display'];
            }
            // Fallback to label key for other uses
            if (isset($item['label'])) {
                return $item['label'];
            }
            // Fallback to name key
            if (isset($item['name'])) {
                return $item['name'];
            }
        }
        
        return (string) $item;
    }

    private function needsScrollIndicators(): bool
    {
        return count($this->items) > $this->visibleItems;
    }

    private function addScrollIndicators(array $lines): array
    {
        $totalItems = count($this->items);
        $canScrollUp = $this->scrollOffset > 0;
        $canScrollDown = $this->scrollOffset + $this->visibleItems < $totalItems;

        // Add indicators to the first and last lines if needed
        if ($canScrollUp && !empty($lines)) {
            $firstLine = array_shift($lines);
            array_unshift($lines, Line::fromSpans(
                Span::styled('↑ Scroll up for more items', Style::default()->fg(AnsiColor::Gray))
            ));
        }

        if ($canScrollDown && !empty($lines)) {
            $lines[] = Line::fromSpans(
                Span::styled('↓ Scroll down for more items', Style::default()->fg(AnsiColor::Gray))
            );
        }

        return $lines;
    }

    // State management methods
    public function open(): self
    {
        $this->isOpen = true;
        return $this;
    }

    public function close(): self
    {
        $this->isOpen = false;
        return $this;
    }

    public function toggle(): self
    {
        $this->isOpen = !$this->isOpen;
        return $this;
    }

    public function isOpen(): bool
    {
        return $this->isOpen;
    }

    // Navigation methods
    public function moveUp(): self
    {
        if ($this->selectedIndex > 0) {
            $this->selectedIndex--;
            $this->adjustScrollOffset();
        }
        return $this;
    }

    public function moveDown(): self
    {
        if ($this->selectedIndex < count($this->items) - 1) {
            $this->selectedIndex++;
            $this->adjustScrollOffset();
        }
        return $this;
    }

    public function pageUp(): self
    {
        $this->selectedIndex = max(0, $this->selectedIndex - $this->visibleItems);
        $this->adjustScrollOffset();
        return $this;
    }

    public function pageDown(): self
    {
        $this->selectedIndex = min(
            count($this->items) - 1, 
            $this->selectedIndex + $this->visibleItems
        );
        $this->adjustScrollOffset();
        return $this;
    }

    public function goToTop(): self
    {
        $this->selectedIndex = 0;
        $this->scrollOffset = 0;
        return $this;
    }

    public function goToBottom(): self
    {
        $this->selectedIndex = count($this->items) - 1;
        $this->adjustScrollOffset();
        return $this;
    }

    private function adjustScrollOffset(): void
    {
        // Ensure selected item is visible
        if ($this->selectedIndex < $this->scrollOffset) {
            $this->scrollOffset = $this->selectedIndex;
        } elseif ($this->selectedIndex >= $this->scrollOffset + $this->visibleItems) {
            $this->scrollOffset = $this->selectedIndex - $this->visibleItems + 1;
        }

        // Keep scroll offset within bounds
        $this->scrollOffset = max(0, min($this->scrollOffset, count($this->items) - $this->visibleItems));
    }

    // Selection methods
    public function selectCurrent(): mixed
    {
        return $this->getSelectedValue();
    }

    public function getSelectedValue(): mixed
    {
        if (!isset($this->items[$this->selectedIndex])) {
            return null;
        }

        $item = $this->items[$this->selectedIndex];
        
        if (is_array($item) && isset($item['value'])) {
            return $item['value'];
        }
        
        return $item;
    }

    public function getSelectedIndex(): int
    {
        return $this->selectedIndex;
    }

    public function setSelectedIndex(int $index): self
    {
        if ($index >= 0 && $index < count($this->items)) {
            $this->selectedIndex = $index;
            $this->adjustScrollOffset();
        }
        return $this;
    }

    // Item management methods
    public function setItems(array $items): self
    {
        $this->items = $items;
        $this->selectedIndex = 0;
        $this->scrollOffset = 0;
        return $this;
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function addItem($item): self
    {
        $this->items[] = $item;
        return $this;
    }

    public function removeItem(int $index): self
    {
        if (isset($this->items[$index])) {
            array_splice($this->items, $index, 1);
            
            // Adjust selected index if necessary
            if ($this->selectedIndex >= count($this->items)) {
                $this->selectedIndex = max(0, count($this->items) - 1);
            }
            
            $this->adjustScrollOffset();
        }
        return $this;
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    // Configuration methods
    public function setClosedText(string $text): self
    {
        $this->closedText = $text;
        return $this;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function setVisibleItems(int $count): self
    {
        $this->visibleItems = max(1, $count);
        $this->adjustScrollOffset();
        return $this;
    }

    public function getVisibleItems(): int
    {
        return $this->visibleItems;
    }

    // Utility methods
    public function canScrollUp(): bool
    {
        return $this->scrollOffset > 0;
    }

    public function canScrollDown(): bool
    {
        return $this->scrollOffset + $this->visibleItems < count($this->items);
    }

    public function getScrollOffset(): int
    {
        return $this->scrollOffset;
    }

    public function getTotalItems(): int
    {
        return count($this->items);
    }

    public function reset(): self
    {
        $this->selectedIndex = 0;
        $this->scrollOffset = 0;
        $this->isOpen = false;
        return $this;
    }
}