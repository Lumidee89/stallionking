@extends('layouts.app')
@section('title', 'Staff sign in')
@section('content')
<div class="auth">
  <div class="card">
    <p class="eyebrow">Staff portal</p>
    <h1>Sign in</h1>
    <p class="hint">Use the staff ID your admin gave you.</p>
    <form method="post" action="{{ url('/login') }}">
      @csrf
      <label for="staff_id">Staff ID</label>
      <input id="staff_id" name="staff_id" value="{{ old('staff_id') }}" autocomplete="username" placeholder="e.g. SK-1024" required autofocus style="text-transform:uppercase">
      <div class="err">@error('staff_id'){{ $message }}@enderror</div>
      <label for="password" style="margin-top:6px">Password</label>
      <div class="pw"><input id="password" name="password" type="password" autocomplete="current-password" required><button type="button" class="small" data-toggle-pw>Show</button></div>
      <p><label style="display:flex;align-items:center;gap:8px;font-weight:500"><input type="checkbox" name="remember" value="1"> Keep me signed in on this device</label></p>
      <button class="primary" style="width:100%;padding:15px">Sign in</button>
    </form>
  </div>
</div>
@endsection
