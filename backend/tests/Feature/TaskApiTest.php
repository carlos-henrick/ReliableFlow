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
        $response = $this->postJson('/api/tasks', ['type' => 'send_notification','payload' => ['message' => 'Test notification',],]);
        $response->assertCreated()->assertJsonPath('type', 'send_notification')->assertJsonPath('payload.message','Test notification');
        $this->assertDatabaseHas('tasks', ['id' => $response->json('id'),'type' => 'send_notification','status' => 'pending','attempts' => 0,]);
        Queue::assertPushed(ProcessTask::class);
    }
    public function test_it_rejects_invalid_task_data(): void
    {
        Queue::fake();
        $response = $this->postJson('/api/tasks', ['type' => '','payload' => 'invalid',]);
        $response->assertUnprocessable()->assertJsonValidationErrors(['type', 'payload']);
        $this->assertDatabaseCount('tasks', 0);
        Queue::assertNothingPushed();
    }
}
