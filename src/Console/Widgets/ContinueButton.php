<?php

namespace Crumbls\Importer\Console\Widgets;

use Crumbls\Importer\Console\Widgets\Concerns\IsFocusable;
use PhpTui\Tui\Extension\Core\Widget\Block\Padding;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Color\AnsiColor;


class ContinueButton implements Widget
{
	use IsFocusable;

	private string $text;

	public function __construct(string $text = 'Continue')
	{
		$this->text = $text;
	}

	public function widget(): Widget
	{
		// Create ultra-compact button styling
		$buttonText = $this->isFocused() 
			? "> [ {$this->text} ] <"  // Show focus indicators
			: "  [ {$this->text} ]  ";   // Normal state with spacing
		
		// Choose colors based on focus state
		$textColor = $this->isFocused() ? AnsiColor::Blue : AnsiColor::Green;
		
		return BlockWidget::default()
			->borders(Borders::ALL)
			->borderType(BorderType::Plain) // Plain borders for minimal height
			->borderStyle(Style::default()->fg($textColor)) // Colored border
			->widget(
				ParagraphWidget::fromString($buttonText)
					->style(Style::default()->fg($textColor)->bold()) // Colored, bold text
					->alignment(HorizontalAlignment::Center) // Center the text
			);
	}

	public function setText(string $text): self
	{
		$this->text = $text;
		return $this;
	}

	public function getText(): string
	{
		return $this->text;
	}
}