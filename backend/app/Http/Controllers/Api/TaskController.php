<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTask;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:100'],
            'payload' => ['required', 'array'],
        ]);

        $task = Task::create([
            'type' => $validated['type'],
            'payload' => $validated['payload'],
        ]);
        ProcessTask::dispatch($task);
        return response()->json($task, 201);
    }
}

