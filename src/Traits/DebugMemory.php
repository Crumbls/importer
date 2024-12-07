<?php

namespace Crumbls\Importer\Traits;

use Crumbls\Importer\Models\Import;

trait DebugMemory
{
	protected function logMemoryUsage(Import $import, string $point): void
	{
		$memory = memory_get_usage(true);
		$peak = memory_get_peak_usage(true);

		$import->logs()->create([
			'level' => 'debug',
			'stage' => $this->getName(),
			'message' => "Memory at {$point}",
			'context' => [
				'memory_usage' => $this->formatBytes($memory),
				'peak_memory' => $this->formatBytes($peak),
				'point' => $point
			]
		]);
	}

	protected function formatBytes($bytes): string
	{
		$units = ['B', 'KB', 'MB', 'GB'];
		$bytes = max($bytes, 0);
		$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
		$pow = min($pow, count($units) - 1);
		$bytes /= pow(1024, $pow);

		return round($bytes, 2) . ' ' . $units[$pow];
	}
}