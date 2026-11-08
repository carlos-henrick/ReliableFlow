<?php

namespace App\Jobs;

use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

class ProcessTask implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public array $backoff = [10, 30];

    public function __construct(
        public Task $task,
    ) {
        $this->tries = max(1, Task::MAX_ATTEMPTS - (int) $task->attempts);
        $this->timeout = (int) config('queue.worker_timeout', 60);
    }

    public function handle(): void
    {
        $task = $this->claimTask();

        if ($task === null) {
            return;
        }

        $this->task = $task;
        $leaseToken = (string) $task->lease_token;

        try {
            $result = match ($task->type) {
                Task::TYPE_SEND_NOTIFICATION => ['message' => 'Notification processed successfully.', 'simulated' => true],
                default => throw new RuntimeException("Unsupported task type: {$task->type}"),
            };
        } catch (Throwable $exception) {
            $released = Task::query()
                ->whereKey($task->getKey())
                ->where('lease_token', $leaseToken)
                ->update([
                    'status' => 'pending',
                    'last_error' => $exception->getMessage(),
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);

            if ($released === 0) {
                return;
            }

            throw $exception;
        }

        $completed = DB::transaction(function () use ($task, $leaseToken, $result): bool {
            $currentTask = Task::query()->whereKey($task->getKey())->lockForUpdate()->first();

            if ($currentTask === null || $currentTask->lease_token !== $leaseToken) {
                return false;
            }

            $currentTask->update([
                'status' => 'completed',
                'result' => $result,
                'completed_at' => now(),
                'last_error' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
            ]);

            return true;
        });

        if ($completed) {
            logger()->info('Task processed successfully', ['task_id' => $task->id, 'type' => $task->type]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $task = Task::query()->whereKey($this->task->getKey())->lockForUpdate()->first();

            if ($task === null || in_array($task->status, ['completed', 'failed'], true)) {
                return;
            }

            if ($task->status === 'processing' && $task->lease_expires_at?->isFuture()) {
                return;
            }

            $task->update([
                'status' => 'failed',
                'last_error' => $exception?->getMessage() ?? 'Task failed after exhausting its attempts.',
                'lease_token' => null,
                'lease_expires_at' => null,
            ]);
        });
    }

    private function claimTask(): ?Task
    {
        return DB::transaction(function (): ?Task {
            $task = Task::query()->whereKey($this->task->getKey())->lockForUpdate()->first();

            if ($task === null || in_array($task->status, ['completed', 'failed'], true)) {
                return null;
            }

            if ($task->status === 'processing' && $task->lease_expires_at?->isFuture()) {
                return null;
            }

            if ($task->attempts >= Task::MAX_ATTEMPTS) {
                $task->update([
                    'status' => 'failed',
                    'last_error' => 'Task exhausted its maximum processing attempts before recovery.',
                    'lease_token' => null,
                    'lease_expires_at' => null,
                ]);

                return null;
            }

            $workerTimeout = (int) config('queue.worker_timeout', 60);
            $retryAfter = (int) config('queue.connections.redis.retry_after', 90);

            if ($retryAfter <= $workerTimeout + 1) {
                throw new LogicException('Redis retry_after must exceed the worker timeout by at least two seconds.');
            }

            $leaseSeconds = max(
                $workerTimeout + 1,
                min((int) config('queue.task_processing_lease_seconds', 75), $retryAfter - 1),
            );

            $task->update([
                'status' => 'processing',
                'attempts' => $task->attempts + 1,
                'last_error' => null,
                'lease_token' => (string) Str::uuid(),
                'lease_expires_at' => now()->addSeconds($leaseSeconds),
            ]);

            return $task->fresh();
        });
    }
}
