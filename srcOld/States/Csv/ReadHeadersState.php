<?php

namespace Crumbls\Importer\States\Csv;

use Crumbls\Importer\States\AbstractState;
use InvalidArgumentException;
use SplFileObject;
class ReadHeadersState extends AbstractState
{
	public function execute(): void
	{
		$driver = $this->getDriver();

		$headers = $driver->getParameter('headers');

		if ($headers !== null) {
			return;
		}

		$delimiter = $driver->getParameter('delimiter');
		$filePath = $driver->getParameter('file_path');

		$file = new SplFileObject($filePath, 'r');
		$file->setFlags(SplFileObject::READ_CSV);
		$file->setCsvControl($delimiter);

		// Read headers
		$headers = $file->fgetcsv();

		if ($headers === false || empty($headers)) {
			throw new InvalidArgumentException("No headers found in CSV file");
		}

		// Clean and validate headers
		$headers = array_map(function($header) {
			return trim($header);
		}, $headers);

		// Check for empty or duplicate headers
		$nonEmptyHeaders = array_filter($headers);
		if (count($nonEmptyHeaders) !== count($headers)) {
			throw new InvalidArgumentException("Empty header columns found");
		}

		if (count(array_unique($headers)) !== count($headers)) {
			throw new InvalidArgumentException("Duplicate headers found");
		}

		$driver->setParameter('headers', $headers);
		$driver->setParameter('header_count', count($headers));
	}

	public function canTransition(): bool
	{
		return $this->getDriver()->getParameter('headers') !== null;
	}

	public function getNextState(): ?string
	{
		return DefineModelState::class; // Or your next state
	}
}