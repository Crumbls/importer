<?php

namespace Crumbls\Importer\Tests;

use Orchestra\Testbench\TestCase;
use Crumbls\Importer\ImporterServiceProvider;
use Crumbls\Importer\Constants\ImportState;
use Crumbls\Importer\Support\ImportOrchestrator;
use Crumbls\Importer\Steps\ReadStep;
use Crumbls\Importer\Drivers\CsvDriver;
use Illuminate\Support\Facades\Event;

class ImportTest extends TestCase
{
	protected function getPackageProviders($app)
	{
		return [ImporterServiceProvider::class];
	}

	protected function setUp(): void
	{
		parent::setUp();
		Event::fake();
	}
}