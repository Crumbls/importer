<?php

namespace Crumbls\Importer\Console\Widgets;

use Crumbls\Importer\Console\Widgets\Concerns\IsFocusable;
use Illuminate\Support\Facades\Log;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\Block\Padding;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Widget\WidgetRenderer;

class StorageBrowserWidget implements Widget
{
	use IsFocusable;

	private static bool $isOpen = false;
	private static $inner;


	private function __construct(
		/**
		 * The direction of the grid
		 */
		public Direction $direction,
		/**
		 * The widgets. There should be at least as many constraints as widgets.
		 * @var list<Widget>
		 */
		public array $widgets,
		/**
		 * The constraints define the widget (Direction::Horizontal) or height
		 * (Direction::Vertical) of the cells.
		 * @var list<Constraint>
		 */
		public array $constraints,
	) {
	}

	public static function default(): self
	{
		return new self(
			Direction::Vertical,
			[],
			[],
		)
			->constraints(
				Constraint::min(1.5),
				Constraint::min(3),
			)
			->widgets(
				ParagraphWidget::fromString('Disk'),
				static::getInner(),
		);
	}

	public function direction(Direction $direction): self
	{
		$this->direction = $direction;

		return $this;
	}

	public function constraints(Constraint ...$constraints): self
	{
		$this->constraints = array_values($constraints);

		return $this;
	}

	public function widgets(Widget ...$widgets): self
	{
		$this->widgets = array_values($widgets);

		return $this;
	}

	public function open(bool $open = true) : self {
		if (!$open) {
			return $this->close();
		}

		if (!static::$isOpen) {

			Log::info('Disk browser opened');

			static::$isOpen = true;

			static::getInner()
				->widget(ParagraphWidget::fromString(static::$isOpen ? 'we are open!' : 'we are closed'));

		}

		return $this;
	}

	public function close(bool $close = true) : self {
		if (!$close) {
			return $this->open();
		}

		if (static::$isOpen) {
			static::$isOpen = false;
			Log::info('Disk browser closed');

			static::getInner()
				->widget(ParagraphWidget::fromString(static::$isOpen ? 'we are open!' : 'we are closed'));
		}

		return $this;
	}

	public static function getInner() {

		$str = static::$isFocused ? 'focused' : 'not focused';

		if (static::$isOpen) {
			$str = 'open: '.$str;
		} else {
			$str = 'closed: '.$str;
		}


			return BlockWidget::default()
				->borders(Borders::ALL)
				->style(
					Style::default()
						->fg(static::$isFocused ? AnsiColor::Red : AnsiColor::Black)
						->bg(static::$isFocused ? AnsiColor::Blue : AnsiColor::Black)
				)
				->padding(Padding::fromScalars(1,1,1,0))
//				->style(Style::default()->white()->onRed())
//				->widget(ParagraphWidget::fromString(static::$isOpen ? 'A' : 'B'))
//				->style(Style::default()->white()->onRed())
				->widget(ParagraphWidget::fromString($str))
;
	}

}