@extends('layouts.app')
@section('title', 'Staff')
@section('content')
@php $me = auth()->user(); @endphp
<div class="page-head">
  <div><p class="eyebrow">Staff</p><h1>Staff logins</h1>
  <p class="hint">Create sign-ins for your team. Each person signs in with their staff ID and password.
  @if($me->role !== \App\Enums\Role::Superadmin) As an admin you can add staff and dispatch accounts; only a superadmin can add admins. @endif</p></div>
</div>
<div class="split">
  <div class="card">
    <h2>Add a staff member</h2>
    <form method="post" action="{{ route('staff.store') }}" class="stack" autocomplete="off">
      @csrf
      <div><label for="s_name">Full name</label><input id="s_name" name="name" value="{{ old('name') }}" required maxlength="120"><div class="err">@error('name'){{ $message }}@enderror</div></div>
      <div><label for="s_id">Staff ID</label><input id="s_id" name="staff_id" value="{{ old('staff_id') }}" required maxlength="32" placeholder="e.g. SK-0002" style="text-transform:uppercase"><div class="err">@error('staff_id'){{ $message }}@enderror</div></div>
      <div><label for="s_email">Email (optional)</label><input id="s_email" name="email" type="email" value="{{ old('email') }}" maxlength="190"><div class="err">@error('email'){{ $message }}@enderror</div></div>
      <div><label for="s_pw">Password</label><div class="pw"><input id="s_pw" name="password" type="password" required minlength="12" autocomplete="new-password"><button type="button" class="small" data-toggle-pw>Show</button></div>
        <p class="hint small" style="margin:4px 0 0">At least 12 characters. Share it with them privately.</p><div class="err">@error('password'){{ $message }}@enderror</div></div>
      <div><label for="s_role">Role</label>
        <select id="s_role" name="role">@foreach($roles as $r)<option value="{{ $r->value }}" @selected(old('role', 'staff') === $r->value)>{{ $r->label() }}</option>@endforeach</select>
        <p class="hint small" style="margin:4px 0 0">Staff: book and process parcels. Dispatch: assign riders and confirm deliveries. Admin: everything, plus staff and approvals.</p></div>
      <button class="primary">Create login</button>
    </form>
  </div>
  <div class="list">
    @foreach($users as $u)
      <div class="card row" @if(!$u->active) style="opacity:.6" @endif>
        <span class="avatar">{{ \Illuminate\Support\Str::of($u->name)->explode(' ')->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->join('') }}</span>
        <div class="grow">
          <b>{{ $u->name }}</b>@if($u->is($me)) <span class="hint small">(you)</span>@endif
          <div class="hint small"><span class="mono">{{ $u->staff_id }}</span> · {{ $u->role->label() }}@unless($u->active) · Deactivated @endunless</div>
          <div class="hint small">{{ $u->last_login_at ? 'Last signed in ' . $u->last_login_at->format('j M Y, H:i') : 'Never signed in' }}</div>
        </div>
        <div class="btns">
          @if($me->role === \App\Enums\Role::Superadmin)<a class="btn small" href="{{ route('activity.index', ['actor' => $u->id]) }}">Activity</a>@endif
          @if($me->canManage($u))
            <a class="btn small" href="{{ route('staff.edit', $u) }}">Edit</a>
            @unless($u->is($me))
              <form method="post" action="{{ route('staff.toggle', $u) }}">@csrf<button class="small {{ $u->active ? 'danger' : '' }}">{{ $u->active ? 'Deactivate' : 'Reactivate' }}</button></form>
              <form method="post" action="{{ route('staff.destroy', $u) }}" data-confirm="Delete the login for {{ $u->name }}? This cannot be undone.">@csrf @method('DELETE')<button class="small danger">Delete</button></form>
            @endunless
          @endif
        </div>
      </div>
    @endforeach
  </div>
</div>
@endsection
