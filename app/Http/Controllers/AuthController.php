<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->merge(['staff_id' => strtoupper(trim((string) $request->input('staff_id')))]);
        $cred = $request->validate([
            'staff_id' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        // `active` is part of the credential check: deactivated staff cannot sign in.
        if (!Auth::attempt(['staff_id' => $cred['staff_id'], 'password' => $cred['password'], 'active' => true], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['staff_id' => 'Staff ID or password is incorrect.']);
        }

        $request->session()->regenerate(); // prevents session fixation
        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->save();
        ActivityLog::record($user, 'login', "{$user->name} signed in");

        return redirect()->intended($user->homeRoute());
    }

    public function logout(Request $request)
    {
        if ($user = $request->user()) {
            ActivityLog::record($user, 'logout', "{$user->name} signed out");
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
