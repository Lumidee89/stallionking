<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Parcel;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Staff logins. Admins manage staff and dispatch accounts; superadmins manage everyone. */
class StaffController extends Controller
{
    public function index(Request $request)
    {
        return view('staff.index', [
            'users' => User::orderByDesc('active')->orderBy('name')->get(),
            'roles' => $request->user()->role->assignableRoles(),
        ]);
    }

    public function store(Request $request)
    {
        $request->merge(['staff_id' => strtoupper(trim((string) $request->input('staff_id')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'staff_id' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9\-]*$/', 'unique:users,staff_id'],
            'email' => ['nullable', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', Password::min(12)],
            'role' => ['required', Rule::in($this->roleValues($request))],
        ], [
            'staff_id.unique' => 'That staff ID is already in use.',
            'staff_id.regex' => 'Use letters, numbers and dashes only, e.g. SK-0002.',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'staff_id' => $data['staff_id'],
            'email' => ($data['email'] ?? null) ?: strtolower($data['staff_id']) . '@staff.stallionking.local',
            'password' => $data['password'],
            'role' => Role::from($data['role']),
            'active' => true,
        ]);
        ActivityLog::record($request->user(), 'admin_created', "Created a login for {$user->name} ({$user->staff_id}) — role: {$user->role->label()}", 'user', $user->staff_id);

        return back()->with('ok', "Login created for {$user->name}. They can sign in with staff ID {$user->staff_id}.");
    }

    public function edit(Request $request, User $user)
    {
        abort_unless($request->user()->canManage($user), 403);
        return view('staff.edit', ['user' => $user, 'roles' => $request->user()->role->assignableRoles()]);
    }

    public function update(Request $request, User $user)
    {
        abort_unless($request->user()->canManage($user), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', Password::min(12)],
            'role' => ['required', Rule::in($this->roleValues($request))],
        ]);

        $data += ['email' => null, 'password' => null];
        $role = Role::from($data['role']);
        $this->keepOneSuperadmin($user, $role !== Role::Superadmin);
        abort_if($user->is($request->user()) && $role !== $user->role, 422, 'You cannot change your own role.');

        $user->fill(['name' => $data['name'], 'role' => $role]);
        if ($data['email']) {
            $user->email = $data['email'];
        }
        if ($data['password']) {
            $user->password = $data['password'];
            $user->remember_token = null;
        }
        $user->save();
        if ($data['password']) {
            DB::table('sessions')->where('user_id', $user->id)->delete(); // sign them out everywhere
        }
        ActivityLog::record($request->user(), 'admin_updated', "Updated the login for {$user->name} ({$user->staff_id})" . ($data['password'] ? ' — password reset' : ''), 'user', $user->staff_id);

        return redirect()->route('staff.index')->with('ok', "Saved changes to {$user->name}.");
    }

    public function toggle(Request $request, User $user)
    {
        abort_unless($request->user()->canManage($user), 403);
        abort_if($user->is($request->user()), 422, 'You cannot deactivate your own account.');
        if ($user->active) {
            $this->keepOneSuperadmin($user, true);
        }

        $user->update(['active' => !$user->active]);
        if (!$user->active) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
        ActivityLog::record($request->user(), $user->active ? 'admin_reactivated' : 'admin_deactivated', ($user->active ? 'Reactivated' : 'Deactivated') . " the login for {$user->name} ({$user->staff_id})", 'user', $user->staff_id);

        return back()->with('ok', "{$user->name} " . ($user->active ? 'can sign in again.' : 'can no longer sign in.'));
    }

    public function destroy(Request $request, User $user)
    {
        abort_unless($request->user()->canManage($user), 403);
        abort_if($user->is($request->user()), 422, 'You cannot delete your own account.');
        $this->keepOneSuperadmin($user, true);

        if (Parcel::where('booked_by', $user->id)->exists()) {
            return back()->with('bad', "{$user->name} has booked parcels, so their login is kept for the records. Deactivate it instead.");
        }
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->delete();
        ActivityLog::record($request->user(), 'admin_deleted', "Deleted the login for {$user->name} ({$user->staff_id})", 'user', $user->staff_id);

        return back()->with('ok', "Deleted the login for {$user->name}.");
    }

    private function roleValues(Request $request): array
    {
        return array_map(fn (Role $r) => $r->value, $request->user()->role->assignableRoles());
    }

    private function keepOneSuperadmin(User $user, bool $removing): void
    {
        if ($removing && $user->role === Role::Superadmin && $user->active
            && User::where('role', Role::Superadmin->value)->where('active', true)->whereKeyNot($user->id)->doesntExist()) {
            abort(422, 'At least one active superadmin login is required.');
        }
    }
}
