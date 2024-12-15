<?php

namespace Crumbls\Importer\Support;

class SqlFileIterator implements \Iterator {
	private $handle;
	private $position = 0;
	private $buffer = '';
	private const BUFFER_SIZE = 8192; // 8KB chunks
	private $currentStatement = '';
	private $inString = false;
	private $stringChar = '';

	public function __construct(string $filePath) {
		$this->handle = fopen($filePath, 'r');
		if ($this->handle === false) {
			throw new \RuntimeException("Could not open file: $filePath");
		}
	}

	public function current(): string {
		return $this->currentStatement;
	}

	public function next(): void {
		$this->position++;
		$this->currentStatement = '';

		while (!feof($this->handle)) {
			// If buffer is empty, read more
			if (empty($this->buffer)) {
				$this->buffer = fread($this->handle, self::BUFFER_SIZE);
				if ($this->buffer === false) {
					break;
				}
			}

			// Process buffer character by character
			while (strlen($this->buffer) > 0) {
				$char = $this->buffer[0];
				$this->buffer = substr($this->buffer, 1);

				// Handle string literals
				if (($char === "'" || $char === '"') &&
					(!isset($prevChar) || $prevChar !== '\\')) {
					if (!$this->inString) {
						$this->inString = true;
						$this->stringChar = $char;
					} elseif ($char === $this->stringChar) {
						$this->inString = false;
					}
				}

				$this->currentStatement .= $char;

				// Check for statement end
				if ($char === ';' && !$this->inString) {
					// Skip certain MySQL commands
					if ($this->shouldSkipStatement($this->currentStatement)) {
						$this->currentStatement = '';
						continue;
					}

					$this->currentStatement = trim($this->currentStatement);
					if (!empty($this->currentStatement)) {
						return;
					}
				}

				$prevChar = $char;
			}
		}

		// Handle any remaining statement
		if (!empty($this->currentStatement)) {
			$statement = trim($this->currentStatement);
			$this->currentStatement = '';
			if (!empty($statement)) {
				return;
			}
		}
	}

	public function key(): int {
		return $this->position;
	}

	public function valid(): bool {
		return !empty($this->currentStatement) || !feof($this->handle);
	}

	public function rewind(): void {
		rewind($this->handle);
		$this->position = 0;
		$this->buffer = '';
		$this->currentStatement = '';
		$this->next();
	}

	private function shouldSkipStatement(string $statement): bool {
		$skipPatterns = [
			'/^LOCK TABLES/i',
			'/^UNLOCK TABLES/i',
			'/^\/\*.*?\*\//s',
			'/^--.*$/m',
			'/^SET/i',
			'/^DROP TABLE/i',
			'/^ALTER TABLE/i'
		];

		foreach ($skipPatterns as $pattern) {
			if (preg_match($pattern, trim($statement))) {
				return true;
			}
		}

		return false;
	}

	public function __destruct() {
		if (is_resource($this->handle)) {
			fclose($this->handle);
		}
	}
}