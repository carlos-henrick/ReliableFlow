<?php

namespace App\Jobs;

use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ProcessTask implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public array $backoff = [10, 30];
    public function __construct(
        public Task $task,
    ) {}

    public function handle(): void
    {
        $this->task->update(['status' => 'processing','attempts' => $this->attempts(),'last_error' => null,]);
        $this->task->refresh();
        $result = match ($this->task->type) {'send_notification' => ['message' => 'Notification processed successfully.','simulated' => true,],
            default => throw new RuntimeException("Unsupported task type: {$this->task->type}"),
        };
        $this->task->update(['status' => 'completed','result' => $result,'completed_at' => now(),'last_error' => null,]);
        logger()->info('Task processed successfully', ['task_id' => $this->task->id,'type' => $this->task->type,]);
    }
    public function failed(?Throwable $exception): void
    {
        $this->task->refresh();
        $this->task->update(['status' => 'failed','last_error' => $exception?->getMessage()?? 'Task failed after exhausting its attempts.']);
    }
}
