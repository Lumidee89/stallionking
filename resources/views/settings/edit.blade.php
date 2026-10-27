@extends('layouts.app')
@section('title', 'Settings')
@section('content')
<div class="card" style="max-width:560px">
  <p class="eyebrow">Settings</p>
  <h1>Company settings</h1>
  <p class="hint">Used in tracking emails and for new tracking numbers.</p>
  <form method="post" action="{{ route('settings.update') }}" class="stack">
    @csrf @method('PUT')
    <div><label for="name">Company name</label><input id="name" name="name" value="{{ old('name', $settings['name']) }}" required maxlength="120"></div>
    <div><label for="tagline">Tagline</label><input id="tagline" name="tagline" value="{{ old('tagline', $settings['tagline']) }}" maxlength="200"></div>
    <div><label for="prefix">Tracking number prefix</label><input id="prefix" name="prefix" value="{{ old('prefix', $settings['prefix']) }}" required maxlength="6" style="text-transform:uppercase">
      <p class="hint small" style="margin:4px 0 0">New parcels look like <span class="mono">{{ $settings['prefix'] }}-7F3K92XQ</span>. Existing tracking numbers do not change.</p><div class="err">@error('prefix'){{ $message }}@enderror</div></div>
    <button class="primary">Save settings</button>
  </form>
</div>
@endsection
