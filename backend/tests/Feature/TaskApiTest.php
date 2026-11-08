<?php

namespace Tests\Feature;

use App\Jobs\ProcessTask;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TaskApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_task_and_dispatches_the_job(): void
    {
        Queue::fake();
        $response = $this->postJson('/api/tasks', ['type' => 'send_notification', 'payload' => ['message' => 'Test notification']]);
        $response->assertCreated()
            ->assertJsonPath('type', 'send_notification')
            ->assertJsonPath('payload.message', 'Test notification')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('attempts', 0);
        $this->assertDatabaseHas('tasks', ['id' => $response->json('id'), 'type' => 'send_notification', 'status' => 'pending', 'attempts' => 0]);
        Queue::assertPushed(ProcessTask::class);
    }

    public function test_it_rejects_invalid_task_data(): void
    {
        Queue::fake();
        $response = $this->postJson('/api/tasks', ['type' => '', 'payload' => 'invalid']);
        $response->assertUnprocessable()->assertJsonValidationErrors(['type', 'payload']);
        $this->assertDatabaseCount('tasks', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_rejects_unsupported_task_types_without_creating_or_queueing_a_task(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/tasks', [
            'type' => 'unsupported_type',
            'payload' => ['message' => 'Unsupported notification'],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['type']);
        $this->assertDatabaseCount('tasks', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_reuses_a_task_for_a_replayed_idempotency_key(): void
    {
        Queue::fake();
        $payload = ['type' => 'send_notification', 'payload' => ['message' => 'Test notification']];

        $firstResponse = $this->withHeader('Idempotency-Key', 'notification-123')->postJson('/api/tasks', $payload);
        $replayResponse = $this->withHeader('Idempotency-Key', 'notification-123')->postJson('/api/tasks', $payload);

        $firstResponse->assertCreated();
        $replayResponse->assertOk()->assertJsonPath('id', $firstResponse->json('id'));
        $this->assertDatabaseCount('tasks', 1);
        Queue::assertPushedTimes(ProcessTask::class, 1);
    }

    public function test_it_returns_conflict_when_an_idempotency_key_is_reused_with_different_data(): void
    {
        Queue::fake();
        $this->withHeader('Idempotency-Key', 'notification-123')->postJson('/api/tasks', [
            'type' => 'send_notification',
            'payload' => ['message' => 'Original notification'],
        ])->assertCreated();

        $response = $this->withHeader('Idempotency-Key', 'notification-123')->postJson('/api/tasks', [
            'type' => 'send_notification',
            'payload' => ['message' => 'Different notification'],
        ]);

        $response->assertConflict();
        $this->assertDatabaseCount('tasks', 1);
        Queue::assertPushedTimes(ProcessTask::class, 1);
    }

    public function test_it_returns_task_status_and_result_without_the_payload(): void
    {
        $task = Task::create([
            'type' => 'send_notification',
            'payload' => ['message' => 'Private payload'],
            'status' => 'completed',
            'attempts' => 1,
            'result' => ['simulated' => true],
        ]);

        $response = $this->getJson('/api/tasks/'.$task->id);

        $response->assertOk()
            ->assertJsonPath('id', $task->id)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.simulated', true)
            ->assertJsonMissingPath('payload');
    }

    public function test_it_returns_not_found_for_an_unknown_task(): void
    {
        $this->getJson('/api/tasks/00000000-0000-7000-8000-000000000000')->assertNotFound();
    }
}
