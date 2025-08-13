<?php

namespace Crumbls\Importer\Console;

use Crumbls\Importer\Console\Concerns\HasTui;
use Crumbls\Importer\Console\Prompts\Contracts\MigrationPrompt;
use Crumbls\Importer\Exceptions\TuiException;
use Crumbls\Importer\Console\Prompts\CreateImportPrompt;
use Crumbls\Importer\Console\Prompts\ListImportsPrompt;
use Crumbls\Importer\Drivers\AutoDriver;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\Import;
use Crumbls\Importer\Services\ImportService;
use Crumbls\Importer\Console\Renderers\StorageNameDropdownRenderer;
use Crumbls\Importer\States\AutoDriver\PendingState;
use Illuminate\Console\Command;
use Crumbls\Importer\Console\Widgets\StorageNameDropdownWidget;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use PhpTui\Term\Actions;
use PhpTui\Term\ClearType;
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
use PhpTui\Tui\Extension\Core\Widget\Paragraph\Wrap;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\TabsWidget;
use PhpTui\Tui\Extension\ImageMagick\ImageMagickExtension;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Widget\Widget;

class ImporterCommand extends Command
{
	use HasTui;

	protected $signature = 'importer';
	protected $description = 'Run the importer tool';

	private MigrationPrompt $activePrompt;

	protected ImportContract $record;

	protected Display $display;

	private int $selectedIndex = 0;

	private bool $loopRunning = false;

	public function getRecord(): ?ImportContract
	{
		return isset($this->record) ? $this->record : null;
	}

	public function setRecord(ImportContract $record): self {
		$this->record = $record;
		return $this;
	}



	public function handle()
	{
		$path = base_path().'/storage/logs/laravel.log';
		$f = trim(file_get_contents($path));
		if ($f) {
			dump($f);
			$cmd = 'echo "" > '.base_path().'/storage/logs/laravel.log';
			exec($cmd);
			exit;
		}

		/**
		 * DEBUG DO NOT TOUCH
		 */
		Import::whereNotIn('id',[427,439])
			->get()->each(function($record) {
			$record->delete();
		});

		foreach(Arr::shuffle([
//			427,
//			439
		]) as $id) {
			$record = Import::find($id);
			$record->driver = AutoDriver::class;
			$record->state = PendingState::class;
			$record->metadata = null;
			$record->error_message = null;
			$record->save();
			$record->clearStateMachine();
		}
		/**
		 * END DEBUG
		 */

		try {
			$this->setPrompt(ListImportsPrompt::class);
			$terminal = $this->getTerminal();
			$backend = $this->getBackend();
			$display = $this->getDisplay();
			
			$this->loopRunning = true;
			$this->runLoop();
		} catch (\Throwable $e) {
			$this->terminateTui();
			throw $e;
		}

		return Command::SUCCESS;
	}

	public function stopLoop() : void {
		$this->loopRunning = false;
	}

	private function runLoop(): void
	{
		$terminal = $this->getTerminal();

		// the main loop
		while ($this->loopRunning) {
			$activePrompt = $this->getPrompt();

			while (null !== $event = $terminal->events()->next()) {
				$activePrompt->handleInput($event, $this);
			}

			try {
				$this->getDisplay()->draw($this->layout());
			} catch (\Throwable $e) {
				$this->dd($e);
			}

			// sleep for Xms - note that it's encouraged to implement apps
			// using an async library such as Amp or React
			usleep(50_000);
		}
	}



	private function layout(): Widget
	{
		$activePrompt = $this->getPrompt();

		$hasErrors = count($this->getErrors());

		$constraints = array_filter([
			Constraint::min(3),
//			$hasErrors ? Constraint::min(5) : null,
			Constraint::min(1),
		]);

		return GridWidget::default()
			->direction(\PhpTui\Tui\Widget\Direction::Vertical)
			->constraints(
				...$constraints
			)
			->widgets(
				$this->header(),
//				$this->footer(),
				...$activePrompt->tui(),
			);
	}

	public function getPrompt(): MigrationPrompt
	{
		if (!isset($this->activePrompt)) {
			$this->setPrompt(ListImportsPrompt::class);
		}

		return $this->activePrompt;
	}

	public function getErrors() : array {
		if (isset($this->errors) && $this->errors) {
			return $this->errors;
		}
		return [];
	}

	public function addError(string $error) : void {
		$this->errors[] = $error;
	}

	public function setPrompt(MigrationPrompt|string $prompt): self {
		if (is_string($prompt)) {
			if (!class_exists($prompt)) {
				throw TuiException::promptClassNotFound($prompt);
			} else if (!is_subclass_of($prompt, MigrationPrompt::class)) {
				throw TuiException::promptClassInvalidInterface($prompt, MigrationPrompt::class);
			}
			$prompt = $prompt::build($this, $this->getRecord());
		} else if (!is_subclass_of($prompt, MigrationPrompt::class)) {
			throw TuiException::promptClassInvalidInterface($prompt::class, MigrationPrompt::class);
		}

		$this->activePrompt = $prompt;

		return $this;
	}

	private function footer() : Widget {
		$activePrompt = $this->getPrompt();

		return BlockWidget::default()
			->borders(Borders::ALL)->style(Style::default()->white())
			->widget();
	}

	private function header(): Widget
	{
		$activePrompt = $this->getPrompt();

		$tabs = $activePrompt::breadcrumbs();

		$lines = [];

		$grid = GridWidget::default()
			->direction(Direction::Horizontal)
			->constraints(
				Constraint::percentage(10),
				Constraint::percentage(90),
			)
			->widgets(
				GridWidget::default()
					->direction(Direction::Vertical)
					->constraints(...array_map(fn () => Constraint::max(4), array_fill(0, 9, true)))
					->widgets(
						BlockWidget::default()
							->borders(Borders::ALL)
							->widget(
								ParagraphWidget::fromText(
									\PhpTui\Tui\Text\Text::parse(sprintf('<fg=red>[q]</>%s', 'uit'))
								)
									->wrap(Wrap::Word)
									->alignment(HorizontalAlignment::Left)
							)
					)
				,
				GridWidget::default()
					->direction(Direction::Vertical)
					->constraints(...array_map(fn () => Constraint::max(4), array_fill(0, 9, true)))
					->widgets(
						BlockWidget::default()
							->borders(Borders::ALL)
							->widget(

							TabsWidget::fromTitles(
							...array_reduce($tabs, function (array $lines, $page) {
							$lines[] = Line::fromString(sprintf('%s', $page->getTabTitle()));
							return $lines;
						}, []),
						)
								->select(count($tabs))
								->highlightStyle(Style::default()->white()->onBlue())
								->divider(Span::fromString('>'))
							)

					),
			)
		;

		return $grid;

		return BlockWidget::default()
			->borders(Borders::ALL)->style(Style::default()->white())
			->widget(
				TabsWidget::fromTitles(
					Line::parse('<fg=red>[q]</>uit'),
					...array_reduce($tabs, function (array $lines, $page) {
					$lines[] = Line::fromString(sprintf('%s', $page->getTabTitle()));
					return $lines;
				}, []),
				)->select(count($tabs))->highlightStyle(Style::default()->white()->onBlue())

			);
	}
}