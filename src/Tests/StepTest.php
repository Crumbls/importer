<?php


namespace Crumbls\Importer\Tests;

use Orchestra\Testbench\TestCase;
use Crumbls\Importer\ImporterServiceProvider;
use Crumbls\Importer\Constants\ImportState;
use Crumbls\Importer\Support\ImportOrchestrator;
use Crumbls\Importer\Steps\ReadStep;
use Crumbls\Importer\Drivers\CsvDriver;
use Illuminate\Support\Facades\Event;
class StepTest extends TestCase
{
	protected function getPackageProviders($app)
	{
		return [\Crumbls\Importer\ImporterServiceProvider::class];
	}

	/** @test */
	public function it_tracks_state_correctly()
	{
		$step = new ReadStep('test-import', new CsvDriver());
		$this->assertEquals(ImportState::READING, $step->getState());

		$step->setState(ImportState::FAILED);
		$this->assertEquals(ImportState::FAILED, $step->getState());
	}

	/** @test */
	public function it_handles_checkpoints()
	{
		$step = new ReadStep('test-import', new CsvDriver());
		$step->saveCheckpoint('position', 100);

		$this->assertEquals(100, $step->getCheckpoint('position'));
	}
}