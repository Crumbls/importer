<?php

namespace Crumbls\Importer\Console\Widgets;

use Crumbls\Importer\Console\Widgets\Concerns\IsFocusable;
use PhpTui\Tui\Extension\Core\Widget\Block\Padding;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Color\AnsiColor;

class StorageNameDropdownWidget implements Widget
{
	use IsFocusable;
	private array $options;
	private int $selectedIndex = 0;
	private bool $isOpen = false;

	private ?BlockWidget $innerWidget = null;

	public function __construct(array $options = [])
	{
		$this->options = array_values($options);
	}

	/**
	 * Returns a default dropdown widget with example options.
	 */
	public static function default(): self
	{
		return new self(['Local', 'S3', 'FTP']);
	}

	public function getOptions(): array
	{
		return $this->options;
	}

	public function getSelectedIndex(): int
	{
		return $this->selectedIndex;
	}

	public function setSelectedIndex(int $index): void
	{
		if ($index >= 0 && $index < count($this->options)) {
			$this->selectedIndex = $index;
		}
	}

	public function isOpen(): bool
	{
		return $this->isOpen;
	}

	public function open(): void
	{
		$this->isOpen = true;
	}

	public function close(): void
	{
		$this->isOpen = false;
	}

	public function toggleOpen(): void
	{
		$this->isOpen = !$this->isOpen;
	}



	public function getInner(): BlockWidget
	{
		if ($this->innerWidget === null) {
			$this->innerWidget = BlockWidget::default()
				->borders(Borders::ALL);
		}

		$status = $this->isOpen ? 'open' : 'closed';
		$focusStatus = $this->isFocused ? 'focused' : 'not focused';
		$text = sprintf('%s: %s', $status, $focusStatus);

		$style = Style::default()
			->fg($this->isFocused ? AnsiColor::Red : AnsiColor::Black)
			->bg($this->isFocused ? AnsiColor::Blue : AnsiColor::Black);

		$this->innerWidget
			->style($style)
			->padding(Padding::fromScalars(1, 1, 1, 0))
			->widget(ParagraphWidget::fromString($text));

		return $this->innerWidget;
	}
}
