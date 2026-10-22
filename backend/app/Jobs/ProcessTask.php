<?php

namespace App\Jobs;

use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessTask implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Task $task,
    ) {}

    public function handle(): void
    {
        $this->task->update([
            'status' => 'processing',
            'attempts' => $this->task->attempts + 1,
            'last_error' => null,
        ]);
        try {
            logger()->info('Task processed successfully', ['task_id' => $this->task->id,'type' => $this->task->type,]);
            $this->task->update(['status' => 'completed', 'completed_at' => now(),]);
        } catch (Throwable $exception) {
            $this->task->update(['status' => 'failed', 'last_error' => $exception->getMessage(),]);
            throw $exception;
        }
    }
}

