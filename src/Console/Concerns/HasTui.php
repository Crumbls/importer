<?php

namespace Crumbls\Importer\Console\Concerns;


use Crumbls\Importer\Console\Renderers\ContinueButtonRenderer;
use Crumbls\Importer\Console\Renderers\SelectWidgetRenderer;
use Crumbls\Importer\Console\Renderers\StorageNameDropdownRenderer;
use PhpTui\Term\Actions;
use PhpTui\Term\ClearType;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;// as PhpTuiPhpTermBackend;
use PhpTui\Tui\Display\Backend;
use PhpTui\Tui\Display\Display;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Example\Demo\Page\BarChartPage;
use PhpTui\Tui\Example\Demo\Page\BlocksPage;
use PhpTui\Tui\Example\Demo\Page\CanvasPage;
use PhpTui\Tui\Example\Demo\Page\CanvasScalingPage;
use PhpTui\Tui\Example\Demo\Page\ChartPage;
use PhpTui\Tui\Example\Demo\Page\ColorsPage;
use PhpTui\Tui\Example\Demo\Page\EventsPage;
use PhpTui\Tui\Example\Demo\Page\GaugePage;
use PhpTui\Tui\Example\Demo\Page\ImagePage;
use PhpTui\Tui\Example\Demo\Page\ItemListPage;
use PhpTui\Tui\Example\Demo\Page\SparklinePage;
use PhpTui\Tui\Example\Demo\Page\SpritePage;
use PhpTui\Tui\Example\Demo\Page\TablePage;
use PhpTui\Tui\Example\Demo\Page\WindowPage;
use PhpTui\Tui\Extension\Bdf\BdfExtension;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\TabsWidget;
use PhpTui\Tui\Extension\ImageMagick\ImageMagickExtension;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;

trait HasTui
{
	private bool $tuiInitialized = false;

	protected Terminal $_tuiTerminal;
	protected Backend $_tuiBackend;
	protected Display $_tuiDisplay;

	protected function initializeTui() : void {
		if ($this->tuiInitialized) {
			return;
		}

		register_shutdown_function(function () {
			$this->terminateTui();
		});
		
		$this->tuiInitialized = true;
	}


	private function terminateTui() : void {
		if (isset($this->_tuiTerminal)) {
			$terminal = $this->_tuiTerminal;
			
			// Try to disable raw mode, but don't fail if it wasn't enabled
			try {
				$terminal->disableRawMode();
			} catch (\Throwable $e) {
				// Ignore - raw mode may not have been enabled
			}
			
			$terminal->execute(Actions::disableMouseCapture());
			$terminal->execute(Actions::alternateScreenDisable());
			$terminal->execute(Actions::cursorShow());
			$terminal->execute(Actions::clear(ClearType::All));
		}
	}

	public function getTerminal() : Terminal {
		if (!isset($this->_tuiTerminal)) {
			$this->initializeTui();
			
			// Temporarily disabled TTY check for debugging
			// if (!posix_isatty(STDIN) || !posix_isatty(STDOUT)) {
			//     throw new \RuntimeException('TUI requires a proper TTY terminal. Please run this command directly in your terminal, not through an IDE or other wrapper.');
			// }
			
			$this->_tuiTerminal = Terminal::new();

			$this->_tuiTerminal->execute(Actions::cursorHide());
			$this->_tuiTerminal->execute(Actions::alternateScreenEnable());
			$this->_tuiTerminal->execute(Actions::enableMouseCapture());
			
			// Try to enable raw mode, but continue if it fails
			try {
				$this->_tuiTerminal->enableRawMode();
			} catch (\Throwable $e) {
				// Raw mode failed - continue without it
				// This may affect input handling but allows the TUI to at least display
			}
		}
		return $this->_tuiTerminal;
	}

	public function setTerminal(Terminal $terminal) : self {
		$this->terminal = $terminal;
		return $this;
	}

	public function getBackend() : Backend {
		if (!isset($this->_tuiBackend)) {
			$this->initializeTui();
			$this->_tuiBackend = new PhpTermBackend($this->getTerminal());
		}
		return $this->_tuiBackend;
	}

	public function setBackend(Backend $backend) : self {
		$this->_tuiBackend = $backend;
		return $this;
	}

	public function getDisplay() : Display {
		if (!isset($this->_tuiDisplay)) {
			$this->initializeTui();
			$terminal = $this->getTerminal();

			$backend = $this->getBackend();

			$this->_tuiDisplay = DisplayBuilder::default($backend ?? new PhpTermBackend($terminal))
				->addExtension(new ImageMagickExtension())
				->addExtension(new BdfExtension())
				->addWidgetRenderer(new StorageNameDropdownRenderer())
				->addWidgetRenderer(new SelectWidgetRenderer())
				->addWidgetRenderer(new ContinueButtonRenderer())
				->build();
		}
		return $this->_tuiDisplay;
	}

	public function dd(mixed $in) : void {
		$this->terminateTui();
		dd($in);
	}
}