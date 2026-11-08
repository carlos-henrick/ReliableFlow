<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTask;
use App\Models\Task;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Signature('tasks:recover-stale')]
#[Description('Requeue stale tasks and mark exhausted tasks as failed.')]
class RecoverStaleTasks extends Command
{
    public function handle(): int
    {
        $now = now();
        $pendingCutoff = $now->copy()->subSeconds(
            (int) config('queue.task_recovery_pending_after_seconds', 300),
        );

        $taskIds = Task::query()
            ->where(function (Builder $query) use ($now, $pendingCutoff): void {
                $query->where(function (Builder $query) use ($pendingCutoff): void {
                    $query->where('status', 'pending')
                        ->where('updated_at', '<=', $pendingCutoff);
                })->orWhere(function (Builder $query) use ($now, $pendingCutoff): void {
                    $query->where('status', 'processing')
                        ->where(function (Builder $query) use ($now, $pendingCutoff): void {
                            $query->where('lease_expires_at', '<=', $now)
                                ->orWhere(function (Builder $query) use ($pendingCutoff): void {
                                    $query->whereNull('lease_expires_at')
                                        ->where('updated_at', '<=', $pendingCutoff);
                                });
                        });
                });
            })
            ->orderBy('updated_at')
            ->limit(100)
            ->pluck('id');

        $recoveredCount = 0;
        $failedCount = 0;

        foreach ($taskIds as $taskId) {
            $result = DB::transaction(function () use ($taskId, $now, $pendingCutoff): Task|string|null {
                $task = Task::query()->whereKey($taskId)->lockForUpdate()->first();

                if ($task === null || ! $this->isRecoverable($task, $now, $pendingCutoff)) {
                    return null;
                }

                if ($task->attempts >= Task::MAX_ATTEMPTS) {
                    $task->update([
                        'status' => 'failed',
                        'last_error' => $task->last_error ?? 'Task exhausted its maximum processing attempts before recovery.',
                        'lease_token' => null,
                        'lease_expires_at' => null,
                    ]);

                    return 'failed';
                }

                $task->update([
                    'status' => 'pending',
                    'last_error' => $task->status === 'processing'
                        ? 'The processing lease expired; the task was queued for recovery.'
                        : 'The task remained pending past the recovery threshold and was queued again.',
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'updated_at' => $now,
                ]);

                return $task->fresh();
            });

            if ($result instanceof Task) {
                ProcessTask::dispatch($result);
                $recoveredCount++;
            } elseif ($result === 'failed') {
                $failedCount++;
            }
        }

        $this->info("Recovered {$recoveredCount} task(s); marked {$failedCount} exhausted task(s) as failed.");

        return self::SUCCESS;
    }

    private function isRecoverable(Task $task, Carbon $now, Carbon $pendingCutoff): bool
    {
        if ($task->status === 'pending') {
            return $task->updated_at->lte($pendingCutoff);
        }

        if ($task->status !== 'processing') {
            return false;
        }

        if ($task->lease_expires_at !== null) {
            return $task->lease_expires_at->lte($now);
        }

        return $task->updated_at->lte($pendingCutoff);
    }
}
