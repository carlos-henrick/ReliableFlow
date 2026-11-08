<?php

namespace Tests\Feature;

use App\Jobs\ProcessTask;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecoverStaleTasksTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requeues_a_task_with_an_expired_processing_lease(): void
    {
        Queue::fake();
        $task = Task::create([
            'type' => Task::TYPE_SEND_NOTIFICATION,
            'payload' => ['message' => 'Recover after worker interruption'],
            'status' => 'processing',
            'attempts' => 1,
            'lease_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->subSecond(),
        ]);

        $this->artisan('tasks:recover-stale')->assertExitCode(0);

        $task->refresh();
        $this->assertSame('pending', $task->status);
        $this->assertNull($task->lease_token);
        $this->assertNull($task->lease_expires_at);
        Queue::assertPushedTimes(ProcessTask::class, 1);
    }

    public function test_it_does_not_requeue_a_task_with_a_live_processing_lease(): void
    {
        Queue::fake();
        $task = Task::create([
            'type' => Task::TYPE_SEND_NOTIFICATION,
            'payload' => ['message' => 'Still processing'],
            'status' => 'processing',
            'attempts' => 1,
            'lease_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->addMinute(),
        ]);

        $this->artisan('tasks:recover-stale')->assertExitCode(0);

        $task->refresh();
        $this->assertSame('processing', $task->status);
        Queue::assertNothingPushed();
    }

    public function test_it_marks_a_stale_task_failed_after_the_attempt_limit(): void
    {
        Queue::fake();
        $task = Task::create([
            'type' => Task::TYPE_SEND_NOTIFICATION,
            'payload' => ['message' => 'Exhausted task'],
            'status' => 'processing',
            'attempts' => Task::MAX_ATTEMPTS,
            'lease_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->subSecond(),
        ]);

        $this->artisan('tasks:recover-stale')->assertExitCode(0);

        $task->refresh();
        $this->assertSame('failed', $task->status);
        $this->assertSame('Task exhausted its maximum processing attempts before recovery.', $task->last_error);
        $this->assertNull($task->lease_expires_at);
        Queue::assertNothingPushed();
    }

    public function test_it_requeues_a_task_that_stayed_pending_without_a_job(): void
    {
        Queue::fake();
        $task = Task::create([
            'type' => Task::TYPE_SEND_NOTIFICATION,
            'payload' => ['message' => 'Redis dispatch recovery'],
            'status' => 'pending',
            'attempts' => 0,
        ]);
        DB::table('tasks')->where('id', $task->id)->update(['updated_at' => now()->subSeconds(301)]);

        $this->artisan('tasks:recover-stale')->assertExitCode(0);

        $task->refresh();
        $this->assertSame('pending', $task->status);
        $this->assertSame('The task remained pending past the recovery threshold and was queued again.', $task->last_error);
        Queue::assertPushedTimes(ProcessTask::class, 1);
    }
}
