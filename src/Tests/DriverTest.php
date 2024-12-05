<?php

namespace Crumbls\Importer\Tests;

use Orchestra\Testbench\TestCase;
use Crumbls\Importer\ImporterServiceProvider;
use Crumbls\Importer\Constants\ImportState;
use Crumbls\Importer\Support\ImportOrchestrator;
use Crumbls\Importer\Steps\ReadStep;
use Crumbls\Importer\Drivers\CsvDriver;
use Illuminate\Support\Facades\Event;
class DriverTest extends TestCase
{
	/** @test */
	public function csv_driver_can_handle_csv_files()
	{
		$driver = new CsvDriver();
		$this->assertTrue($driver->canHandle('test.csv'));
		$this->assertFalse($driver->canHandle('test.txt'));
	}

	/** @test */
	public function csv_driver_reads_batches_correctly()
	{
		$testFile = __DIR__ . '/fixtures/test.csv';
		file_put_contents($testFile, "name,age\nJohn,30\nJane,25");

		$driver = new CsvDriver();
		$driver->setSource($testFile);

		$batch = $driver->read();
		$this->assertCount(2, $batch);
		$this->assertEquals(['name' => 'John', 'age' => '30'], $batch[0]);

		unlink($testFile);
	}
}

class EloquentDriverTest extends TestCase
{
	/** @test */
	public function it_handles_eloquent_builder()
	{
		$builder = $this->mock(\Illuminate\Database\Eloquent\Builder::class);
		$driver = new EloquentDriver();

		$this->assertTrue($driver->canHandle($builder));
	}

	/** @test */
	public function it_handles_connection_config()
	{
		$config = [
			'connection' => 'testing',
			'table' => 'users',
			'where' => [
				['active', '=', 1]
			]
		];

		$driver = new EloquentDriver();
		$this->assertTrue($driver->canHandle($config));
	}
}