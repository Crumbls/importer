<?php


namespace Crumbls\Importer\Tests;

use Orchestra\Testbench\TestCase;
use Crumbls\Importer\ImporterServiceProvider;
use Crumbls\Importer\Constants\ImportState;
use Crumbls\Importer\Support\ImportOrchestrator;
use Crumbls\Importer\Steps\ReadStep;
use Crumbls\Importer\Drivers\CsvDriver;
use Illuminate\Support\Facades\Event;
class OrchestratorTest extends TestCase
{
	/** @test */
	public function it_executes_steps_in_order()
	{
		$orchestrator = new ImportOrchestrator()
			->setId('test-import');

		$step1 = $this->createMock(ReadStep::class);
		$step1->expects($this->once())
			->method('execute')
			->willReturn(['data']);

		$step2 = $this->createMock(TransformStep::class);
		$step2->expects($this->once())
			->method('execute')
			->willReturn(['transformed']);

		$orchestrator->addStep($step1)
			->addStep($step2);

		$result = $orchestrator->execute();
		$this->assertEquals(['transformed'], $result);
	}

	/** @test */
	public function it_can_resume_from_state()
	{
		$orchestrator = ImportOrchestrator::create('test-import');


		$step1 = $this->createMock(ReadStep::class);
		$step2 = $this->createMock(TransformStep::class);

		$step2->method('getState')
			->willReturn(ImportState::TRANSFORMING);

		$step2->expects($this->once())
			->method('execute');

		$orchestrator->addStep($step1)
			->addStep($step2);

		$orchestrator->resumeFrom(ImportState::TRANSFORMING);
	}
}
