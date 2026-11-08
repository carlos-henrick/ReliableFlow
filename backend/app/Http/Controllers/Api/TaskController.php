<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTask;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);

        $validated = $request->validate([
            'type' => ['required', 'string', 'max:100', Rule::in(Task::SUPPORTED_TYPES)],
            'payload' => ['required', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        $attributes = [
            'type' => $validated['type'],
            'payload' => $validated['payload'],
        ];

        if ($validated['idempotency_key'] !== null) {
            $task = Task::query()->firstOrCreate(
                ['idempotency_key' => $validated['idempotency_key']],
                $attributes,
            );

            if (
                $task->type !== $validated['type']
                || $this->normalizePayload($task->payload) !== $this->normalizePayload($validated['payload'])
            ) {
                return response()->json([
                    'message' => 'The Idempotency-Key has already been used with a different task request.',
                ], 409);
            }
        } else {
            $task = Task::create($attributes);
        }

        if ($task->wasRecentlyCreated) {
            ProcessTask::dispatch($task);
        }

        return response()->json($task, $task->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Task $task): JsonResponse
    {
        return response()->json([
            'id' => $task->id,
            'type' => $task->type,
            'status' => $task->status,
            'attempts' => $task->attempts,
            'result' => $task->result,
            'last_error' => $task->last_error,
            'completed_at' => $task->completed_at,
            'created_at' => $task->created_at,
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normalizePayload($value);
            }
        }

        if (! array_is_list($payload)) {
            ksort($payload);
        }

        return $payload;
    }
}
