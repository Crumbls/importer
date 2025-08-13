<?php

namespace Crumbls\Importer\Examples;

use Crumbls\Importer\States\Concerns\DetectsQueueWorkers;

/**
 * Example class showing how to use the DetectsQueueWorkers trait
 */
class QueueWorkerDetectionExample
{
    use DetectsQueueWorkers;

    public function demonstrateUsage()
    {
        // Basic queue worker check
        $status = $this->checkQueueWorkers('default');
        
        echo "Queue Status:\n";
        echo "Has Workers: " . ($status['has_workers'] ? 'Yes' : 'No') . "\n";
        echo "Worker Count: " . $status['worker_count'] . "\n";
        echo "Check Method: " . $status['check_method'] . "\n";
        echo "Checked At: " . $status['checked_at'] . "\n";
        
        // Show detailed database check results
        if ($status['check_method'] === 'database_comprehensive') {
            echo "\nDetailed Database Check:\n";
            echo "Processing Jobs: " . $status['processing_jobs'] . "\n";
            echo "Pending Jobs: " . $status['pending_jobs'] . "\n";
            echo "Recent Activity: " . $status['recent_activity'] . "\n";
            
            if (isset($status['worker_test'])) {
                $test = $status['worker_test'];
                echo "Worker Test: " . ($test['responsive'] ? 'Responsive' : 'Not Responsive') . "\n";
                if (isset($test['response_time'])) {
                    echo "Response Time: " . $test['response_time'] . "s\n";
                }
                if (isset($test['method'])) {
                    echo "Test Method: " . $test['method'] . "\n";
                }
            }
            
            if (isset($status['process_check']) && $status['process_check']['has_workers'] !== null) {
                $proc = $status['process_check'];
                echo "Process Check: " . ($proc['has_workers'] ? 'Found Workers' : 'No Workers') . "\n";
                echo "Process Count: " . $proc['worker_count'] . "\n";
            }
        }
        echo "\n";

        // Check multiple queues
        $queues = ['default', 'emails', 'heavy-processing'];
        $report = $this->getQueueStatusReport($queues);
        
        echo "Multi-Queue Report:\n";
        foreach ($report as $queueName => $queueStatus) {
            echo "Queue '{$queueName}': " . ($queueStatus['has_workers'] ? 'Active' : 'Inactive') . "\n";
        }
        echo "\n";

        // Clear cache example
        $this->clearQueueWorkerCache('default');
        echo "Cache cleared for 'default' queue\n";
    }

    /**
     * Example of how to handle queue worker detection in a real application
     */
    public function handleJobDispatch($jobClass, $queueName = 'default')
    {
        $workerStatus = $this->checkQueueWorkers($queueName);
        
        if (!$workerStatus['has_workers']) {
            // Handle no workers scenario
            $this->handleNoWorkers($queueName, $workerStatus);
            return false;
        }
        
        // Workers are available, dispatch the job
        dispatch(new $jobClass())->onQueue($queueName);
        
        echo "Job dispatched successfully to queue '{$queueName}'\n";
        echo "Workers available: {$workerStatus['worker_count']}\n";
        
        return true;
    }
    
    private function handleNoWorkers($queueName, $workerStatus)
    {
        echo "No workers detected for queue '{$queueName}'\n";
        echo "Check method: {$workerStatus['check_method']}\n";
        
        if (isset($workerStatus['error'])) {
            echo "Error: {$workerStatus['error']}\n";
        }
        
        echo "Please start queue workers:\n";
        echo "php artisan queue:work --queue={$queueName}\n";
    }
}