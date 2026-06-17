<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index()
    {
        return Task::orderBy('position')->orderBy('id')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string',
            'client' => 'nullable|string',
            'priority' => 'nullable|in:baixa,media,alta',
            'due' => 'nullable|string|max:32',
            'type' => 'nullable|string|max:32',
            'column' => 'nullable|string|max:16',
        ]);

        $data['column'] = $data['column'] ?? 'todo';
        $data['priority'] = $data['priority'] ?? 'media';
        // Novas solicitações entram no topo de "A fazer".
        $data['position'] = (Task::where('column', $data['column'])->min('position') ?? 0) - 1;

        return response()->json(Task::create($data), 201);
    }

    public function update(Request $request, Task $task)
    {
        $data = $request->validate([
            'column' => 'nullable|string|max:16',
            'title' => 'nullable|string',
            'client' => 'nullable|string',
            'priority' => 'nullable|in:baixa,media,alta',
            'due' => 'nullable|string|max:32',
            'type' => 'nullable|string|max:32',
        ]);

        if (! empty($data['column']) && $data['column'] !== $task->column) {
            $data['position'] = (Task::where('column', $data['column'])->max('position') ?? -1) + 1;
        }

        $task->update($data);

        return $task;
    }

    public function destroy(Task $task)
    {
        $task->delete();

        return response()->json(['message' => 'ok']);
    }
}
