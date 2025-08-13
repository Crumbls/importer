<?php

namespace Crumbls\Importer\Console\Prompts\CreateImportPrompt;

use Crumbls\Importer\Console\NavItem;
use Crumbls\Importer\Console\Prompts\AbstractPrompt;
use Crumbls\Importer\Console\Prompts\Contracts\MigrationPrompt;
use Crumbls\Importer\Console\Prompts\ViewImportPrompt;
use Crumbls\Importer\Console\Widgets\ContinueButton;
use Crumbls\Importer\Console\Widgets\SelectDropdownWidget;
use Crumbls\Importer\Console\Widgets\SelectWidget;
use Crumbls\Importer\Console\Widgets\StorageNameDropdownWidget;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Resolvers\ModelResolver;
use Crumbls\Importer\Traits\IsDiskAware;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpTui\Term\Event;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Extension\Core\Widget\Block\Padding;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\Paragraph\Wrap;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\TabsWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PhpTui\Tui\Widget\VerticalAlignment;
use PhpTui\Tui\Widget\Widget;
use function Laravel\Prompts\select;


class FilePrompt extends AbstractPrompt implements MigrationPrompt
{
	use IsDiskAware;
	protected int $selectedIndex = 1;
	protected int $viewportHeight = 50;

	use IsDiskAware;

	protected SelectWidget $storageSelector;
	protected SelectWidget $fileSelector;
	protected ContinueButton $continueButton;
	protected string $currentDirectory = '/';
	protected array $supportedExtensions = ['csv', 'xml', 'wpxml', 'tsv'];

	protected string $state = 'initial';

	protected int $activeComponentIndex = 0;
	// ffs


	/**
	 * Generate sample data for the list
	 */
	private function generateSampleData(): array
	{
		$items = [];
		for ($i = 1; $i <= 50; $i++) {
			$items[] = sprintf("Item %03d - Sample content for list item number %d", $i, $i);
		}
		return $items;
	}

	public function __construct(Command $command)
	{


		// Initialize storage selector
		$this->storageSelector = new SelectWidget(
			items: $this->getAvailableDisks(),
			closedText: 'Select a storage system',
			title: __('Storage Browser'),
			visibleItems: 8
		);

		$this->storageSelector->setSelectedIndex(0);
		$this->storageSelector->setClosedText($this->storageSelector->getSelectedValue());
		$this->storageSelector->focused(true);

		// Initialize file selector with empty items (populated after storage selection)
		$this->fileSelector = new SelectWidget(
			items: [],
			closedText: 'Select storage first to browse files',
			title: __('File Browser'),
			visibleItems: 8
		);

		// Auto-load files from the first storage option on initial load
		$this->loadFilesFromStorage();

		$this->continueButton = new ContinueButton();

		parent::__construct($command);
	}

	public function render(): ?ImportContract
	{
		$this->clearScreen();

		$sourceType = $this->selectSourceType();

		if (!$sourceType) {
			return null;
		}

		$method = 'select' . ucfirst($sourceType) . 'Source';

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

	/**
	 * @return string|null
	 * @deprecated
	 */
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

	public static function getTabTitle(): string
	{
		return 'File Browser';//. $this->activeComponentIndex;
	}

	public static function breadcrumbs(): array
	{
		$base = SourcePrompt::breadcrumbs();
		$base[FilePrompt::class] = new NavItem(FilePrompt::class, 'File Browser');
		return $base;
	}

	public function tui(): array
	{
		if ($this->state == 'initial') {
			return static::tuiStateInitial();
		} else if ($this->state == 'disk') {
			return static::tuiStateDisk();
		} else if ($this->state == 'storage') {
			return static::tuiStateStorage();
		}
		throw new \Exception($this->state);
	}

	public function handleInput(Event $event, Command $command)
	{
		if ($event instanceof CharKeyEvent) {
			if ($event->char === 'q') {

			}
		} else if ($event instanceof CodedKeyEvent) {
			// Handle main navigation when dropdown not focused
			switch ($event->code) {
				case KeyCode::Down:
					if ($this->storageSelector->isOpen()) {
						if ($this->storageSelector->getSelectedIndex() < $this->storageSelector->getTotalItems() - 1) {
							$this->storageSelector->moveDown();
						}
					} else if ($this->fileSelector->isOpen()) {
						if ($this->fileSelector->getSelectedIndex() < $this->fileSelector->getTotalItems() - 1) {
							$this->fileSelector->moveDown();
						}
					} else {
						// Navigate to next focusable widget
						$this->focusNext();
					}
					return;
				case KeyCode::Up:
					if ($this->storageSelector->isOpen()) {
						if ($this->storageSelector->getSelectedIndex() > 0) {
							$this->storageSelector->moveUp();
						}
					} else if ($this->fileSelector->isOpen()) {
						if ($this->fileSelector->getSelectedIndex() > 0) {
							$this->fileSelector->moveUp();
						}
					} else {
						// Navigate to previous focusable widget
						$this->focusPrevious();
					}
					return;
				case KeyCode::Esc:
					$command->setPrompt(SourcePrompt::class);
					return;
				case KeyCode::Enter:
					if ($this->storageSelector->isOpen()) {
						$this->storageSelector->close();
						$this->storageSelector->setClosedText($this->storageSelector->getSelectedValue());
						// Storage selection changed - load files and auto-focus file selector
						$this->loadFilesFromStorage();
						$this->focusNext(); // Move focus to file selector
						$this->state = 'initial';
						return;
					} else if ($this->fileSelector->isOpen()) {
						$selectedItem = $this->fileSelector->getSelectedValue();
						
						if (is_array($selectedItem) && $selectedItem['type'] === 'directory') {
							// Navigate to directory
							$this->currentDirectory = $selectedItem['path'];
							$this->loadFilesFromStorage();
							$this->fileSelector->setSelectedIndex(0); // Reset selection to first item
							return;
						} else {
							// File selected - close selector
							$this->fileSelector->close();
							$this->fileSelector->setClosedText($this->getDisplayName($selectedItem));
							$this->state = 'initial';
							return;
						}
					}
					
					// Handle Enter on focused widgets
					if ($this->storageSelector->isFocused()) {
						$this->state = 'disk';
						$this->storageSelector->open();
					} else if ($this->fileSelector->isFocused()) {
						// Only allow opening if storage is selected
						if ($this->storageSelector->getSelectedValue()) {
							$this->state = 'storage';
							$this->fileSelector->open();
						}
					} else {
						/**
						 * TODO: Dial this in to handle expanded content in the future.
						 */
						$widget = $this->getFocusedWidget();
						if ($widget) {
							/**
							 * TODO:Validate our data.
							 */
							if ($this->storageSelector->getSelectedValue() && $this->fileSelector->getSelectedValue()) {
								$selectedFile = $this->fileSelector->getSelectedValue();
								$filePath = is_array($selectedFile) ? $selectedFile['path'] : $selectedFile;
								
								$modelClass = ModelResolver::import();

								$record = $modelClass::firstOrCreate([
									'source_type' => 'storage',
									'source_detail' => $this->storageSelector->getSelectedValue().'::'.$filePath,
								]);

								$command->setRecord($record);
								$command->setPrompt(ViewImportPrompt::class);
							}
						}
					}
					return;
			}
		} elseif ($event instanceof KeyEvent) {
			// Handle character keys (like Space for dropdown toggle)
			if ($event->char === ' ' && static::$selected === 0 && !static::$dropdownFocused) {
				Log::info(__LINE__);
				static::$dropdownFocused = true;
				static::$storageDropdown->toggle();
				return;
			}
		}

		parent::handleInput($event, $command);
	}

	public static function tab(string $title, bool $selected = false)
	{
		return BlockWidget::default()
			->borders(Borders::ALL)
			->style($selected ? Style::default()->red() : Style::default())
			->widget(
				ParagraphWidget::fromString($title)
					->alignment(HorizontalAlignment::Center)
			);
	}

	public static function getTabDescription(): mixed
	{
		$description = match (static::$selected) {
			0 => 'Browse storage to select the file you want to import.',
			1 => 'Select a connection to import your data from.'
		};
		return
			ParagraphWidget::fromString($description)->alignment(HorizontalAlignment::Center);
	}

	protected static function getStorageNameDropdownWidget(): mixed
	{
		return StorageNameDropdownWidget::default();
	}

	protected function tuiStateDisk(): array
	{
		$height = max(15, count($this->getAvailableDisks()) * 1.5);

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
							Constraint::max($height),
							Constraint::max(3),
							Constraint::max(3),
							Constraint::min(1)
						)
						->widgets(
							$this->getWidgetStorage(),
							$this->getWidgetFile(),
							$this->getWidgetButton(),
							$this->getWidgetPlaceholder(),
						),

					GridWidget::default()
						->direction(Direction::Vertical)
						->widgets(),
				)
		];
	}

	protected function tuiStateStorage(): array
	{
		$height = max(15, count([]) * 1.5);

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
							Constraint::max(3),
							Constraint::max($height),
							Constraint::max(3),
							Constraint::min(1)
						)
						->widgets(
							$this->getWidgetStorage(),
							$this->getWidgetFile(),
							$this->getWidgetButton(),
							$this->getWidgetPlaceholder(),

						),

					GridWidget::default()
						->direction(Direction::Vertical)
						->widgets(),
				)
		];
	}


	protected function tuiStateInitial(): array
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
							Constraint::max(3),
							Constraint::max(3),
							Constraint::max(3),
							Constraint::min(1)
						)
						->widgets(
							$this->getWidgetStorage(),
							$this->getWidgetFile(),
							$this->getWidgetButton(),
							$this->getWidgetPlaceholder(),

						),

					GridWidget::default()
						->direction(Direction::Vertical)
						->widgets(),
				)
		];
	}

	public function getWidgetFile(): Widget
	{
		return $this->fileSelector;
	}

	public function getWidgetStorage(): Widget
	{
		return $this->storageSelector;
	}

	public function getWidgetButton(): Widget
	{
		return $this->continueButton;
	}
	public function getWidgetPlaceholder(): Widget
	{
return BlockWidget::default();
	}
	/**
	 * Get the currently focused widget
	 */
	public function getFocusedWidget(): ?object 
	{
		if ($this->storageSelector->isFocused()) {
			return $this->storageSelector;
		} else if ($this->fileSelector->isFocused()) {
			return $this->fileSelector;
		}
		return $this->continueButton;
	}

	/**
	 * Get widgets in current focus order based on state
	 */
	public function getCurrentFocusOrder(): array 
	{
		return [
			$this->storageSelector,
			$this->fileSelector,
			$this->continueButton
		];
	}

	/**
	 * Get the next focusable widget in the chain
	 */
	private function getNextFocusableWidget(?object $currentWidget): ?object 
	{
		$focusOrder = $this->getCurrentFocusOrder();
		
		if ($currentWidget === null) {
			return $focusOrder[0] ?? null;
		}
		
		$currentIndex = array_search($currentWidget, $focusOrder, true);
		
		if ($currentIndex !== false && isset($focusOrder[$currentIndex + 1])) {
			return $focusOrder[$currentIndex + 1];
		}
		
		return null; // At end of focus chain
	}

	/**
	 * Get the previous focusable widget in the chain
	 */
	private function getPreviousFocusableWidget(?object $currentWidget): ?object 
	{
		$focusOrder = $this->getCurrentFocusOrder();
		
		if ($currentWidget === null) {
			return end($focusOrder) ?: null;
		}
		
		$currentIndex = array_search($currentWidget, $focusOrder, true);
		
		if ($currentIndex !== false && $currentIndex > 0) {
			return $focusOrder[$currentIndex - 1];
		}
		
		return null; // At beginning of focus chain
	}

	/**
	 * Move focus to the next widget
	 */
	private function focusNext(): void 
	{
		$current = $this->getFocusedWidget();
		$next = $this->getNextFocusableWidget($current);
		
		if ($next) {
			// Unfocus current widget if it exists and supports focus
			if ($current) {
				if (method_exists($current, 'focused')) {
					$current->focused(false);
				}
			}

			// Focus next widget if it supports focus
			if (method_exists($next, 'focused')) {
				$next->focused(true);
			} else {
				Log::info($next);
			}
		}
	}

	/**
	 * Move focus to the previous widget
	 */
	private function focusPrevious(): void 
	{
		$current = $this->getFocusedWidget();
		$previous = $this->getPreviousFocusableWidget($current);
		
		if ($previous) {
			// Unfocus current widget if it exists and supports focus
			if ($current && method_exists($current, 'focused')) {
				$current->focused(false);
			}
			
			// Focus previous widget if it supports focus
			if (method_exists($previous, 'focused')) {
				$previous->focused(true);
			}
		}
	}

	/**
	 * Load files from selected storage system and directory
	 */
	private function loadFilesFromStorage(): void
	{
		$selectedStorage = $this->storageSelector->getSelectedValue();
		
		if (!$selectedStorage) {
			$this->fileSelector->setItems([]);
			$this->fileSelector->setClosedText('No storage selected');
			return;
		}

		$files = $this->getFilteredFiles($selectedStorage, $this->currentDirectory);
		
		$this->fileSelector->setItems($files);
		
		if (!empty($files)) {
			$this->fileSelector->setSelectedIndex(0);
			$this->fileSelector->setClosedText($this->getDisplayName($files[0]));
		} else {
			$this->fileSelector->setClosedText('No compatible files found');
		}
	}

	/**
	 * Get filtered files and directories from storage
	 */
	private function getFilteredFiles(string $storage, string $directory): array
	{
		$items = [];
		
		// Add ".." to go up directory (except at root)
		if ($directory !== '/') {
			$items[] = [
				'type' => 'directory',
				'name' => '..',
				'path' => dirname($directory),
				'display' => '📁 ..'
			];
		}
		
		// This would normally connect to your storage system
		// For now, I'll create a mock implementation
		$mockFiles = $this->getMockFilesForStorage($storage, $directory);
		
		// Add directories first
		foreach ($mockFiles as $file) {
			if ($file['type'] === 'directory') {
				$items[] = [
					'type' => 'directory',
					'name' => $file['name'],
					'path' => $directory . '/' . $file['name'],
					'display' => '📁 ' . $file['name']
				];
			}
		}
		
		// Add supported files
		foreach ($mockFiles as $file) {
			if ($file['type'] === 'file') {
				$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
				if (in_array($extension, $this->supportedExtensions)) {
					$items[] = [
						'type' => 'file',
						'name' => $file['name'],
						'path' => $directory . '/' . $file['name'],
						'display' => '📄 ' . $file['name']
					];
				}
			}
		}
		
		return $items;
	}

	/**
	 * Mock file system for demonstration
	 * In real implementation, this would connect to actual storage
	 */
	private function getMockFilesForStorage(string $storage, string $directory): array
	{
		// Mock data - in real implementation, you'd query the actual storage system
		$mockData = [
			'/' => [
				['type' => 'directory', 'name' => 'imports'],
				['type' => 'directory', 'name' => 'exports'], 
				['type' => 'file', 'name' => 'sample.csv'],
				['type' => 'file', 'name' => 'data.xml'],
				['type' => 'file', 'name' => 'ignored.txt'], // Will be filtered out
			],
			'/imports' => [
				['type' => 'file', 'name' => 'products.csv'],
				['type' => 'file', 'name' => 'customers.tsv'],
				['type' => 'file', 'name' => 'wordpress-export.wpxml'],
			],
			'/exports' => [
				['type' => 'file', 'name' => 'export.xml'],
				['type' => 'file', 'name' => 'report.csv'],
			],
		];
		
		return $mockData[$directory] ?? [];
	}

	/**
	 * Get display name from file item
	 */
	private function getDisplayName($item): string
	{
		if (is_array($item) && isset($item['display'])) {
			return $item['display'];
		}
		
		return is_string($item) ? $item : 'Unknown';
	}

	/**
	 * Handle file/directory selection
	 */
	private function handleFileSelection(): void
	{
		$selectedItem = $this->fileSelector->getSelectedValue();
		
		if (!is_array($selectedItem)) {
			return;
		}
		
		if ($selectedItem['type'] === 'directory') {
			// Navigate to directory
			$this->currentDirectory = $selectedItem['path'];
			$this->loadFilesFromStorage();
		}
		// Files are handled by Enter key in handleInput
	}

}