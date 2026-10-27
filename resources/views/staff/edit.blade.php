@extends('layouts.app')
@section('title', 'Edit ' . $user->name)
@section('content')
<p><a class="hint" href="{{ route('staff.index') }}">← Staff</a></p>
<div class="card" style="max-width:560px">
  <p class="eyebrow">Edit login</p>
  <h1>{{ $user->name }}</h1>
  <p class="hint">Staff ID <span class="mono">{{ $user->staff_id }}</span> cannot be changed. If it was entered wrong, deactivate this login and create a new one.</p>
  <form method="post" action="{{ route('staff.update', $user) }}" class="stack" autocomplete="off">
    @csrf @method('PUT')
    <div><label for="name">Full name</label><input id="name" name="name" value="{{ old('name', $user->name) }}" required maxlength="120"><div class="err">@error('name'){{ $message }}@enderror</div></div>
    <div><label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" maxlength="190"><div class="err">@error('email'){{ $message }}@enderror</div></div>
    <div><label for="password">New password</label><div class="pw"><input id="password" name="password" type="password" minlength="12" autocomplete="new-password" placeholder="Leave blank to keep the current password"><button type="button" class="small" data-toggle-pw>Show</button></div>
      <p class="hint small" style="margin:4px 0 0">Setting a new password signs them out of every device.</p><div class="err">@error('password'){{ $message }}@enderror</div></div>
    <div><label for="role">Role</label>
      <select id="role" name="role" @disabled($user->is(auth()->user()))>@foreach($roles as $r)<option value="{{ $r->value }}" @selected(old('role', $user->role->value) === $r->value)>{{ $r->label() }}</option>@endforeach</select>
      @if($user->is(auth()->user()))<input type="hidden" name="role" value="{{ $user->role->value }}"><p class="hint small" style="margin:4px 0 0">You cannot change your own role.</p>@endif
    </div>
    <div class="actions"><button class="primary">Save changes</button><a class="btn" href="{{ route('staff.index') }}">Cancel</a></div>
  </form>
</div>
@endsection
