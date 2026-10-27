<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function edit()
    {
        return view('settings.edit', ['settings' => Setting::values()]);
    }

    public function update(Request $request)
    {
        $request->merge(['prefix' => strtoupper(trim((string) $request->input('prefix')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:200'],
            'prefix' => ['required', 'regex:/^[A-Z]{2,6}$/'],
        ], ['prefix.regex' => 'Use 2 to 6 letters, e.g. STK.']);

        Setting::put($data);
        ActivityLog::record($request->user(), 'settings_updated', 'Updated company settings', 'settings');
        return back()->with('ok', 'Settings saved. New parcels will use the ' . $data['prefix'] . '- prefix.');
    }
}
