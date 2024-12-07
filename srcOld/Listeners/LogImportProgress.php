<?php

namespace Crumbls\Importer\Listeners;

class LogImportProgress
{
	public function handle($event): void
	{
		if (config('importer.logging.enabled')) {
			$message = match (get_class($event)) {
				ImportStarted::class => "Import {$event->importId} started",
				ImportCompleted::class => "Import {$event->importId} completed. Stats: " . json_encode($event->stats),
				ImportFailed::class => "Import {$event->importId} failed: {$event->exception->getMessage()}",
				StepStarted::class => "Step {$event->step} started for import {$event->importId}",
				StepCompleted::class => "Step {$event->step} completed for import {$event->importId}",
				default => null
			};

			if ($message) {
				logger()->channel(config('importer.logging.channel'))->info($message);
			}
		}
	}
}
