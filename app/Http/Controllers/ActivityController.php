<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $actor = (int) $request->query('actor');

        $logs = ActivityLog::query()
            ->when($actor, fn ($query) => $query->where('actor_id', $actor))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('summary', 'like', "%{$q}%")
                ->orWhere('actor_name', 'like', "%{$q}%")
                ->orWhere('actor_staff_id', 'like', "%{$q}%")
                ->orWhere('target_id', 'like', "%{$q}%")))
            ->latest('id')
            ->paginate(100)->withQueryString();

        return view('activity.index', [
            'logs' => $logs,
            'q' => $q,
            'actor' => $actor,
            'users' => User::orderBy('name')->get(['id', 'name', 'staff_id']),
        ]);
    }
}
