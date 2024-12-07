<?php

namespace Crumbls\Importer\States\Csv;

use Crumbls\Importer\States\AbstractState;
use InvalidArgumentException;
use SplFileObject;
class DetectDelimiterState extends AbstractState
{
	private const COMMON_DELIMITERS = [',', ';', "\t", '|'];
	private const SAMPLE_SIZE = 1024 * 4; // 4KB sample size

	public function execute(): void
	{
		$driver = $this->getDriver();
		$existing = $driver->getParameter('delimiter');
		if ($existing) {
			return;
		}
		$filePath = $driver->getParameter('file_path');
		$file = new SplFileObject($filePath, 'r');

		// Read sample from the beginning of the file
		$sample = '';
		$file->fseek(0);
		while (!$file->eof() && strlen($sample) < self::SAMPLE_SIZE) {
			$sample .= $file->fgets();
		}

		// Count occurrences of each delimiter
		$delimiterCounts = [];
		foreach (self::COMMON_DELIMITERS as $delimiter) {
			// Count consistent occurrences per line
			$lines = explode("\n", $sample);
			$counts = array_map(function($line) use ($delimiter) {
				return substr_count($line, $delimiter);
			}, array_filter($lines));

			// Get the most common count
			$countValues = array_count_values($counts);
			arsort($countValues);
			$mostCommonCount = key($countValues);

			$delimiterCounts[$delimiter] = [
				'count' => $mostCommonCount,
				'consistency' => (count(array_filter($counts, fn($count) => $count === $mostCommonCount)) / count($counts)) * 100
			];
		}

		// Find the most likely delimiter (highest consistent count)
		$bestDelimiter = null;
		$bestScore = 0;

		foreach ($delimiterCounts as $delimiter => $stats) {
			$score = $stats['count'] * ($stats['consistency'] / 100);
			if ($score > $bestScore) {
				$bestScore = $score;
				$bestDelimiter = $delimiter;
			}
		}

		if (!$bestDelimiter || $bestScore < 1) {
			throw new InvalidArgumentException("Could not detect a valid delimiter");
		}

		$driver->setParameter('delimiter', $bestDelimiter);
	}

	public function canTransition(): bool
	{
		$val = $this->getDriver()->getParameter('delimiter');
		return $val !== null;
	}

	public function getNextState(): ?string
	{
		return ReadHeadersState::class;
	}
}
