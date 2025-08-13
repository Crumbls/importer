<?php

namespace Crumbls\Importer\Console\Widgets;

use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Style\Modifier;

class SelectDropdownWidget implements Widget
{
	private array $items;
	private ?string $selected;
	private bool $isOpen;
	private int $highlightedIndex;
	private string $label;

	public function __construct(
		string $label = 'Select an option',
		array $items = [],
		?string $selected = null,
		bool $isOpen = false,
		int $highlightedIndex = 0
	) {
		$this->label = $label;
		$this->items = $items;
		$this->selected = $selected;
		$this->isOpen = $isOpen;
		$this->highlightedIndex = $highlightedIndex;
	}

	public function render(DisplayBuilder $display): void
	{
		$widget = $this->getWidget();
		$widget->render($display);
	}

	public function getWidget(): Widget
	{
		if (!$this->isOpen) {
			return $this->getClosedWidget();
		} else {
			return $this->getOpenWidget();
		}
	}

	private function getClosedWidget(): Widget
	{
		$selectedText = $this->selected ?? 'Select an option...';
		$arrow = '▼';
		
		// Create a clean input-like appearance
		$inputContent = sprintf('%-50s %s', $selectedText, $arrow);

		if (!is_string($inputContent)) {
//		throw new \Exception($inputContent);

		}
//		dd($inputContent);

		return BlockWidget::default()
			->borders(Borders::ALL)
			->style(Style::default()->fg(AnsiColor::White))
			->widget(
				ParagraphWidget::fromLines(
					\PhpTui\Tui\Text\Line::fromSpan(
						Span::fromString($inputContent)
					)
				)->alignment(HorizontalAlignment::Left)
			);
	}

	private function getOpenWidget(): Widget
	{
		$lines = [];

		// Add header with current selection and up arrow
		$selectedText = $this->selected ?? 'Select an option...';
		$inputLine = sprintf('%-50s %s', $selectedText, '▲');
		$lines[] = \PhpTui\Tui\Text\Line::fromSpans(
			Span::fromString($inputLine)
		);

		// Add separator
		$lines[] = \PhpTui\Tui\Text\Line::fromString(str_repeat('─', 52));

		// Add items with proper spacing
		foreach ($this->items as $index => $item) {
			$isHighlighted = $index === $this->highlightedIndex;
			$isSelected = $item === $this->selected;

			$prefix = $isSelected ? '● ' : '  ';
			$text = $prefix . $item;

			if ($isHighlighted) {
				$lines[] = Line::fromSpans(
					Span::styled(sprintf('%-52s', $text), Style::default()->bg(AnsiColor::Blue)->fg(AnsiColor::White))
				);
			} else {
				$lines[] = Line::fromSpans(
					Span::raw(sprintf('%-52s', $text))
				);
			}
		}

		dd(array_reduce($lines, function($line) {
			return Line::fromString($line);
		}));

		return BlockWidget::default()
			->borders(Borders::ALL)
			->style(Style::default()->fg(AnsiColor::White))
			->widget(
				ParagraphWidget::fromLines(array_reduce($lines, function($line) {
					return Line::fromString($line);
				}))
			);
	}

	// Public methods for interaction
	public function toggle(): void
	{
		$this->isOpen = !$this->isOpen;
	}

	public function open(): void
	{
		$this->isOpen = true;
	}

	public function close(): void
	{
		$this->isOpen = false;
	}

	public function moveUp(): void
	{
		if ($this->highlightedIndex > 0) {
			$this->highlightedIndex--;
		}
	}

	public function moveDown(): void
	{
		if ($this->highlightedIndex < count($this->items) - 1) {
			$this->highlightedIndex++;
		}
	}

	public function selectHighlighted(): void
	{
		if (isset($this->items[$this->highlightedIndex])) {
			$this->selected = $this->items[$this->highlightedIndex];
			$this->close();
		}
	}

	public function getSelected(): ?string
	{
		return $this->selected;
	}

	public function isOpen(): bool
	{
		return $this->isOpen;
	}

	public function setItems(array $items): self
	{
		$this->items = $items;
		$this->highlightedIndex = 0;
		return $this;
	}

	public function getItems(): array
	{
		return $this->items;
	}

	public function setSelected(?string $selected): self
	{
		$this->selected = $selected;
		
		// Update highlighted index to match selection
		if ($selected !== null) {
			$index = array_search($selected, $this->items);
			if ($index !== false) {
				$this->highlightedIndex = $index;
			}
		}
		
		return $this;
	}

	public function getHighlightedIndex(): int
	{
		return $this->highlightedIndex;
	}

	public function getHighlightedItem(): ?string
	{
		return $this->items[$this->highlightedIndex] ?? null;
	}

	public function setHighlightedIndex(int $index): self
	{
		if ($index >= 0 && $index < count($this->items)) {
			$this->highlightedIndex = $index;
		}
		return $this;
	}

	public function reset(): self
	{
		$this->selected = null;
		$this->highlightedIndex = 0;
		$this->isOpen = false;
		return $this;
	}

	public function isEmpty(): bool
	{
		return empty($this->items);
	}

	public function hasSelection(): bool
	{
		return $this->selected !== null;
	}
}