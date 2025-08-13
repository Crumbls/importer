<?php

namespace Crumbls\Importer\Console\Widgets\Concerns;

use Illuminate\Support\Facades\Log;

trait IsFocusable
{
	protected bool $isFocused = false;

	public function focused(bool $isFocused = true): self
	{
		if ($this->isFocused === $isFocused) {
			return $this;
		}

		// Log::info('focus: ' . ($isFocused ? 'true' : 'false'));
		$this->isFocused = $isFocused;

		if (!$isFocused) {
			if (method_exists($this, 'close')) {
				$this->close();
			}
		}

		return $this;
	}

	public function isFocusable(): bool
	{
		return true;
	}

	public function isFocused() : bool {
		return $this->isFocused;
	}
}