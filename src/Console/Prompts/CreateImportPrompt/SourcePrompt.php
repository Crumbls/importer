<?php

namespace Crumbls\Importer\Console\Prompts\CreateImportPrompt;

use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Console\Prompts\ListImportsPrompt;
use Crumbls\Importer\Console\Prompts\ViewImportPrompt;
use Crumbls\Importer\Console\Widgets\ContinueButton;
use Crumbls\Importer\Console\Widgets\SelectWidget;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Resolvers\ModelResolver;
use Crumbls\Importer\Console\Prompts\Contracts\MigrationPrompt;
use Crumbls\Importer\Services\ImportService;
use PhpTui\Tui\Extension\Core\Widget\Block\Padding;
use \PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use PhpTui\Term\Event;
use PhpTui\Term\KeyCode;
use Crumbls\Importer\Console\NavItem;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\TabsWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Position\Position;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Widget\Widget;
use function Laravel\Prompts\select;

class SourcePrompt extends AbstractPrompt implements MigrationPrompt {

	protected ContinueButton $fileStorageButton;
	protected ContinueButton $databaseButton;
	protected string $selectedSourceType = 'storage'; // Default to file storage
	protected static int $maxTabIndex;
	protected static int $selected = 0;

	public function __construct(Command $command)
	{
		// Initialize two source type buttons
		$this->fileStorageButton = new ContinueButton('File Storage');
		$this->databaseButton = new ContinueButton('Database Connection');
		
		// Set initial focus on file storage button
		$this->fileStorageButton->focused(true);
		
		parent::__construct($command);
	}

	public function render() : ?ImportContract
	{
		$this->clearScreen();

		$sourceType = $this->selectSourceType();
		
		if (!$sourceType) {
			return null;
		}

		$method = 'select'.ucfirst($sourceType).'Source';

		if (!method_exists($this, $method)) {
			return null;
		}

		$ret = $this->$method();

		if (!$ret) {
			return null;
		}

		$modelClass = ModelResolver::import();

		$ret['completed_at'] = null;

		$record = $modelClass::firstOrCreate($ret);
		
		return $record;

	}
	
	protected function selectSourceType(): ?string
	{
		$sourceOptions = [
			'storage' => 'File Storage',
			'database' => 'Database Connection',
		];
		
		return select(
			label: __('Where is your data coming from?'),
			options: $sourceOptions,
			default: 'storage'
		);
	}

	protected function selectDatabaseConnection(): ?array
	{
		$prompt = new DatabaseConnectionPrompt($this->command);
		$selectedConnection = $prompt->select();

		if (!$selectedConnection) {
			return null;
		}

		return ['connection' => $selectedConnection];
	}

	protected function selectStorageSource(): ?array
	{
		$prompt = new FileBrowserPrompt($this->command);
		$path = $prompt->render();

		if (!$path) {
			return null;
		}

		return [
			'source_type' => 'storage',
			'source_detail' => $path
		];
	}

	public static function getTabTitle() : string {
		return 'Create an Import';
	}

	public static function breadcrumbs() : array{
		$base = ListImportsPrompt::breadcrumbs();
		$base[SourcePrompt::class] = new NavItem(SourcePrompt::class, 'Create Import');
		return $base;
	}

	public function tui(): array 
	{
		return [
			GridWidget::default()
				->direction(Direction::Horizontal)
				->constraints(
					Constraint::percentage(25),
					Constraint::percentage(50),
					Constraint::percentage(25),
				)
				->widgets(
					GridWidget::default()
						->direction(Direction::Vertical)
						->widgets(),
					GridWidget::default()
						->direction(Direction::Vertical)
						->constraints(
							Constraint::max(3), // Header
							Constraint::max(3), // Source buttons row
							Constraint::min(1)  // Spacer
						)
						->widgets(
							$this->getHeaderWidget(),
							$this->getSourceButtonsWidget(),
							$this->getSpacerWidget(),
						),
					GridWidget::default()
						->direction(Direction::Vertical)
						->widgets(),
				)
		];
	}

	private function getSourceButtonsWidget(): Widget
	{
		return GridWidget::default()
			->direction(Direction::Horizontal)
			->constraints(
				Constraint::percentage(50),
				Constraint::percentage(50),
			)
			->widgets(
				$this->fileStorageButton,
				$this->databaseButton,
			);
	}

	private function getHeaderWidget(): Widget
	{
		return BlockWidget::default()
			->borders(Borders::ALL)
			->widget(
				ParagraphWidget::fromString('Choose a data source to create your import.')
					->alignment(HorizontalAlignment::Center)
			);
	}

	private function getSpacerWidget(): Widget
	{
		return BlockWidget::default();
	}

	public function handleInput(Event $event, Command $command)
	{
		if ($event instanceof Event\CodedKeyEvent) {
			switch ($event->code) {
				case KeyCode::Left:
					$this->focusLeft();
					return;
					
				case KeyCode::Right:
					$this->focusRight();
					return;
					
				case KeyCode::Enter:
					$current = $this->getFocusedWidget();
					
					// Navigate directly based on which button is focused
					if ($current === $this->fileStorageButton) {
						$command->setPrompt(FilePrompt::class);
					} elseif ($current === $this->databaseButton) {
						// TODO: Implement DatabasePrompt
						$command->setPrompt(FilePrompt::class); // Fallback for now
					}
					return;
					
				case KeyCode::Esc:
					$command->setPrompt(ListImportsPrompt::class);
					return;
			}
		}

		parent::handleInput($event, $command);
	}

	protected static function switchTabRight(Command $command) : void {
		static::$selected = static::$selected < static::$maxTabIndex ? static::$selected + 1 : 0;
	}

	protected static function switchTabLeft(Command $command) : void {
		static::$selected = static::$selected ?static::$selected - 1 : static::$maxTabIndex;
	}

	public static function tab(string $title, bool $selected = false) {
		return BlockWidget::default()
			->borders(Borders::ALL)
			->style($selected ?Style::default()->red() : Style::default())
			->widget(
				ParagraphWidget::fromString($title)
					->alignment(HorizontalAlignment::Center)
			);
	}

	public static function getTabDescription() : mixed {
		$description = match(static::$selected) {
			0 => 'Browse storage to select the file you want to import.',
			1 => 'Select a connection to import your data from.'
		};
		return
			ParagraphWidget::fromString($description)->alignment(HorizontalAlignment::Center);
	}

	/**
	 * Get the currently focused widget
	 */
	public function getFocusedWidget(): ?object 
	{
		if ($this->fileStorageButton->isFocused()) {
			return $this->fileStorageButton;
		}
		if ($this->databaseButton->isFocused()) {
			return $this->databaseButton;
		}
		return null;
	}

	/**
	 * Get source buttons for left/right navigation
	 */
	private function getSourceButtons(): array
	{
		return [
			$this->fileStorageButton,
			$this->databaseButton,
		];
	}

	// No up/down navigation needed - only left/right between source buttons

	/**
	 * Move focus left between source buttons
	 */
	private function focusLeft(): void
	{
		$current = $this->getFocusedWidget();
		
		// Only works on source buttons
		if ($current === $this->databaseButton) {
			$current->focused(false);
			$this->fileStorageButton->focused(true);
			$this->selectedSourceType = 'storage';
		}
	}

	/**
	 * Move focus right between source buttons
	 */
	private function focusRight(): void
	{
		$current = $this->getFocusedWidget();
		
		// Only works on source buttons  
		if ($current === $this->fileStorageButton) {
			$current->focused(false);
			$this->databaseButton->focused(true);
			$this->selectedSourceType = 'database';
		}
	}
}