<?php

namespace Crumbls\Importer\Console\Renderers;

use Laravel\Prompts\Output\BufferedConsoleOutput;
use Laravel\Prompts\Table;
use Laravel\Prompts\Themes\Default\Renderer;
use Symfony\Component\Console\Helper\Table as SymfonyTable;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Terminal;

class TableRenderer extends Renderer
{
	/**
	 * Render the table.
	 */
	public function __invoke(Table $table): string
	{
		$terminal = new Terminal();
		$terminalWidth = $terminal->getWidth();
		$columnCount = count($table->headers ?? $table->rows[0] ?? []);

		// Calculate column widths
		$columnWidths = [];
		if ($columnCount > 1) {
			// First column stays compact (fixed width)
			$firstColumnWidth = 15;

			// Calculate remaining space for other columns
			$borderAndPaddingWidth = ($columnCount + 1) + ($columnCount * 2);
			$remainingWidth = $terminalWidth - $borderAndPaddingWidth - $firstColumnWidth;
			$otherColumnWidth = intval($remainingWidth / ($columnCount - 1));

			$columnWidths = [$firstColumnWidth];
			for ($i = 1; $i < $columnCount; $i++) {
				$columnWidths[] = $otherColumnWidth;
			}
		}

		$tableStyle = (new TableStyle())
			->setHorizontalBorderChars('─')
			->setVerticalBorderChars('│', '│')
			->setCellHeaderFormat('<info>%s</info>')
			->setCellRowFormat('%s')
			->setPadType(STR_PAD_BOTH)
			->setPaddingChar(' ');

		if (empty($table->headers)) {
			$tableStyle->setCrossingChars('┼', '', '', '', '┤', '┘</>', '┴', '└', '├', '<fg=gray>┌', '┬', '┐');
		} else {
			$tableStyle->setCrossingChars('┼', '<fg=gray>┌', '┬', '┐', '┤', '┘</>', '┴', '└', '├');
		}

		$buffered = new BufferedConsoleOutput;

		$symphonyTable = new SymfonyTable($buffered);
		$symphonyTable->setHeaders($table->headers)
			->setRows($table->rows)
			->setStyle($tableStyle);

		// Apply column widths to the Symfony Table (not the Laravel Prompts Table)
		if (!empty($columnWidths)) {
//			$symphonyTable->setColumnWidths($columnWidths);
		}

		$symphonyTable->render();

		foreach (explode(PHP_EOL, trim($buffered->content(), PHP_EOL)) as $line) {
			$this->line(' '.$line);
		}

		return $this;
	}


}