<?php

namespace Tests\Feature;

use App\Jobs\ProcessTask;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ProcessTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_processes_a_notification_task_successfully(): void
    {
        $task = Task::create(['type' => Task::TYPE_SEND_NOTIFICATION, 'payload' => ['message' => 'Test notification'], 'status' => 'pending', 'attempts' => 0]);
        (new ProcessTask($task))->handle();
        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame(1, $task->attempts);
        $this->assertSame(['message' => 'Notification processed successfully.', 'simulated' => true], $task->result);
        $this->assertNull($task->last_error);
        $this->assertNotNull($task->completed_at);
        $this->assertNull($task->lease_token);
        $this->assertNull($task->lease_expires_at);
    }

    public function test_it_marks_unsupported_task_types_as_failed(): void
    {
        $task = Task::create(['type' => 'unsupported_type', 'payload' => [], 'status' => 'pending', 'attempts' => 0]);
        try {
            (new ProcessTask($task))->handle();
            $this->fail('Expected the job to throw an exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unsupported task type: unsupported_type', $exception->getMessage());
        }
        $task->refresh();
        $this->assertSame('pending', $task->status);
        $this->assertSame(1, $task->attempts);
        $this->assertSame('Unsupported task type: unsupported_type', $task->last_error);
        $this->assertNull($task->lease_token);
        $this->assertNull($task->lease_expires_at);
        (new ProcessTask($task))->failed(
            new RuntimeException('Unsupported task type: unsupported_type')
        );
        $task->refresh();
        $this->assertSame('failed', $task->status);
        $this->assertSame('Unsupported task type: unsupported_type', $task->last_error);
    }

    public function test_it_configures_three_attempts_and_progressive_backoff(): void
    {
        $task = new Task(['type' => 'send_notification', 'payload' => ['message' => 'Test notification']]);
        $job = new ProcessTask($task);
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30], $job->backoff);
    }

    public function test_it_does_not_claim_a_task_while_another_worker_holds_a_live_lease(): void
    {
        $task = Task::create([
            'type' => Task::TYPE_SEND_NOTIFICATION,
            'payload' => ['message' => 'Already processing'],
            'status' => 'processing',
            'attempts' => 1,
            'lease_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->addMinute(),
        ]);

        (new ProcessTask($task))->handle();

        $task->refresh();
        $this->assertSame('processing', $task->status);
        $this->assertSame(1, $task->attempts);
    }
}
