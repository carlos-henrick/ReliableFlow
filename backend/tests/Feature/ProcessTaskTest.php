<?php

namespace Tests\Feature;

use App\Jobs\ProcessTask;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProcessTaskTest extends TestCase
{
    use RefreshDatabase;
    public function test_it_processes_a_notification_task_successfully(): void
    {
        $task = Task::create(['type' => 'send_notification', 'payload' => ['message' => 'Test notification',], 'status' => 'pending', 'attempts' => 0,]);
        (new ProcessTask($task))->handle();
        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame(1, $task->attempts);
        $this->assertSame(['message' => 'Notification processed successfully.', 'simulated' => true,], $task->result);
        $this->assertNull($task->last_error);
        $this->assertNotNull($task->completed_at);
    }
    public function test_it_marks_unsupported_task_types_as_failed(): void
    {
        $task = Task::create(['type' => 'unsupported_type', 'payload' => [], 'status' => 'pending', 'attempts' => 0,]);
        try {
            (new ProcessTask($task))->handle();
            $this->fail('Expected the job to throw an exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unsupported task type: unsupported_type', $exception->getMessage());
        }
        $task->refresh();
        $this->assertSame('processing', $task->status);
        $this->assertSame(1, $task->attempts);
        (new ProcessTask($task))->failed(
            new RuntimeException('Unsupported task type: unsupported_type')
        );
        $task->refresh();
        $this->assertSame('failed', $task->status);
        $this->assertSame('Unsupported task type: unsupported_type', $task->last_error);
    }
    public function test_it_configures_three_attempts_and_progressive_backoff(): void
    {
        $task = new Task(['type' => 'send_notification','payload' => ['message' => 'Test notification'],]);
        $job = new ProcessTask($task);
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30], $job->backoff);
    }
}
