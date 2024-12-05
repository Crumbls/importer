<?php

namespace Crumbls\Importer\States\Csv;

use Crumbls\Importer\States\AbstractState;
use InvalidArgumentException;
use SplFileObject;
class ValidateState extends AbstractState
{
	private const ALLOWED_EXTENSIONS = ['csv', 'txt'];
	private const ALLOWED_MIME_TYPES = [
		'text/csv',
		'text/plain',
		'application/csv',
		'application/vnd.ms-excel'
	];

	public function execute(): void
	{
		$driver = $this->getDriver();
		$filePath = $driver->getParameter('file_path');

		if (!file_exists($filePath)) {
			throw new InvalidArgumentException("File does not exist: {$filePath}");
		}

		// Check file extension
		$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
		if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
			throw new InvalidArgumentException("Invalid file extension: {$extension}");
		}

		// Check MIME type
		$mimeType = mime_content_type($filePath);
		if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
			throw new InvalidArgumentException("Invalid mime type: {$mimeType}");
		}

		// Check if file is readable and not empty
		$fileSize = filesize($filePath);
		if ($fileSize === 0) {
			throw new InvalidArgumentException("File is empty");
		}

		$driver->setParameter('file_size', $fileSize);
//		$this->setData('file_size', $fileSize);
	}

	public function canTransition(): bool
	{
		return true;
	}

	public function getNextState(): ?string
	{
		return DetectDelimiterState::class;
	}
}
