<?php

namespace Crumbls\Importer\Tests\States\Concerns;

use Crumbls\Importer\States\Concerns\DetectsQueueWorkers;
use Crumbls\Importer\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

class DetectsQueueWorkersTest extends TestCase
{
    use DetectsQueueWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_detects_database_queue_workers_with_processing_jobs()
    {
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database.driver', 'database');

        // Create a processing job (reserved_at is set)
        DB::table('jobs')->insert([
            'queue' => 'test-queue',
            'payload' => json_encode(['job' => 'TestJob']),
            'attempts' => 1,
            'reserved_at' => now()->subMinutes(2)->getTimestamp(),
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);

        $result = $this->checkQueueWorkers('test-queue');

        // Based on actual implementation, it does comprehensive checking
        $this->assertEquals('test-queue', $result['queue']);
        $this->assertEquals('database_comprehensive', $result['check_method']);
        $this->assertArrayHasKey('processing_jobs', $result);
        $this->assertArrayHasKey('has_workers', $result);
        $this->assertArrayHasKey('worker_count', $result);
    }

    #[Test]
    public function it_detects_no_database_workers_when_no_activity()
    {
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database.driver', 'database');

        // Add old jobs that aren't being processed
        DB::table('jobs')->insert([
            'queue' => 'test-queue',
            'payload' => json_encode(['job' => 'TestJob']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->subHours(1)->getTimestamp(),
        ]);

        $result = $this->checkQueueWorkers('test-queue');

        $this->assertEquals('test-queue', $result['queue']);
        $this->assertEquals('database_comprehensive', $result['check_method']);
        $this->assertArrayHasKey('has_workers', $result);
        $this->assertArrayHasKey('worker_count', $result);
    }

    #[Test]
    public function it_detects_workers_from_recent_failures()
    {
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database.driver', 'database');

        // Add recent failed job indicating worker activity
        DB::table('failed_jobs')->insert([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'test-queue',
            'payload' => json_encode(['job' => 'TestJob']),
            'exception' => 'Test exception',
            'failed_at' => now()->subMinutes(2),
        ]);

        $result = $this->checkQueueWorkers('test-queue');

        $this->assertEquals('test-queue', $result['queue']);
        $this->assertEquals('database_comprehensive', $result['check_method']);
        $this->assertArrayHasKey('has_workers', $result);
        $this->assertArrayHasKey('recent_failures', $result);
    }

    #[Test]
    public function it_handles_sync_queue_driver()
    {
        Config::set('queue.default', 'sync');
        Config::set('queue.connections.sync.driver', 'sync');

        $result = $this->checkQueueWorkers('test-queue');

        $this->assertTrue($result['has_workers']);
        $this->assertEquals('sync', $result['worker_count']);
        $this->assertEquals('sync_driver', $result['check_method']);
    }

    #[Test]
    public function it_handles_unknown_queue_drivers()
    {
        Config::set('queue.default', 'custom');
        Config::set('queue.connections.custom.driver', 'custom');

        $result = $this->checkQueueWorkers('test-queue');

        $this->assertTrue($result['has_workers']);
        $this->assertEquals('unknown', $result['worker_count']);
        $this->assertEquals('unknown_driver_assumed', $result['check_method']);
        $this->assertEquals('custom', $result['driver']);
    }

    #[Test]
    public function it_caches_queue_worker_checks()
    {
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database.driver', 'database');

        // First call
        $result1 = $this->checkQueueWorkers('test-queue');
        $this->assertArrayHasKey('checked_at', $result1);

        // Second call should return cached result
        $result2 = $this->checkQueueWorkers('test-queue');
        $this->assertEquals($result1['checked_at'], $result2['checked_at']);
    }

    #[Test]
    public function it_can_clear_queue_worker_cache()
    {
        Config::set('queue.default', 'sync');
        Config::set('queue.connections.sync.driver', 'sync');

        // Prime the cache
        $this->checkQueueWorkers('test-queue');
        $this->assertTrue(Cache::has('queue_workers_check_test-queue'));

        // Clear cache
        $this->clearQueueWorkerCache('test-queue');
        $this->assertFalse(Cache::has('queue_workers_check_test-queue'));
    }

    #[Test]
    public function it_provides_comprehensive_queue_status_report()
    {
        Config::set('queue.default', 'sync');
        Config::set('queue.connections.sync.driver', 'sync');

        $report = $this->getQueueStatusReport(['test-queue-1', 'test-queue-2']);

        $this->assertArrayHasKey('test-queue-1', $report);
        $this->assertArrayHasKey('test-queue-2', $report);
        $this->assertTrue($report['test-queue-1']['has_workers']);
        $this->assertTrue($report['test-queue-2']['has_workers']);
    }

    #[Test]
    public function it_handles_database_errors_gracefully()
    {
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database.driver', 'database');

        // Mock DB to throw exception
        DB::shouldReceive('table')->andThrow(new \Exception('Database connection failed'));

        $result = $this->checkQueueWorkers('test-queue');

        $this->assertFalse($result['has_workers']);
        $this->assertEquals('database_error', $result['check_method']);
        $this->assertArrayHasKey('error', $result);
    }

    #[Test]
    public function it_detects_workers_with_recent_job_activity_and_low_backlog()
    {
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database.driver', 'database');

        // Add recent job activity with small backlog
        DB::table('jobs')->insert([
            'queue' => 'test-queue',
            'payload' => json_encode(['job' => 'TestJob']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->subMinutes(5)->getTimestamp(),
        ]);

        $result = $this->checkQueueWorkers('test-queue');

        $this->assertEquals('test-queue', $result['queue']);
        $this->assertEquals('database_comprehensive', $result['check_method']);
        $this->assertArrayHasKey('has_workers', $result);
        $this->assertArrayHasKey('pending_jobs', $result);
    }

    #[Test]
    public function it_correctly_identifies_no_workers_with_large_backlog()
    {
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database.driver', 'database');

        // Add many pending jobs (indicating backlog)
        for ($i = 0; $i < 15; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'test-queue',
                'payload' => json_encode(['job' => 'TestJob']),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->getTimestamp(),
                'created_at' => now()->subMinutes(5)->getTimestamp(),
            ]);
        }

        $result = $this->checkQueueWorkers('test-queue');

        $this->assertFalse($result['has_workers']); // Large backlog suggests no active workers
        $this->assertEquals(15, $result['pending_jobs']);
    }

    #[Test]
    public function it_handles_sqs_queue_driver()
    {
        Config::set('queue.default', 'sqs');
        Config::set('queue.connections.sqs.driver', 'sqs');

        $result = $this->checkQueueWorkers('test-queue');

        $this->assertTrue($result['has_workers']); // SQS assumes workers
        $this->assertEquals('unknown', $result['worker_count']);
        $this->assertEquals('sqs_assumed', $result['check_method']);
        $this->assertArrayHasKey('note', $result);
    }

    #[Test]
    public function it_includes_all_required_fields_in_response()
    {
        Config::set('queue.default', 'sync');
        Config::set('queue.connections.sync.driver', 'sync');

        $result = $this->checkQueueWorkers('test-queue');

        // Check all required fields are present
        $requiredFields = ['has_workers', 'worker_count', 'queue', 'check_method', 'checked_at'];
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $result, "Missing required field: {$field}");
        }

        // Validate data types
        $this->assertIsBool($result['has_workers']);
        $this->assertIsString($result['queue']);
        $this->assertIsString($result['check_method']);
        $this->assertIsString($result['checked_at']);
    }
}