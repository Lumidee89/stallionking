@extends('layouts.app')
@section('title', 'Clients')
@section('content')
<div class="page-head">
  <div><p class="eyebrow">Clients</p><h1>Frequent senders</h1>
  <p class="hint">Save a regular sender's details once. Pick them when booking and only the receiver is left to fill in.</p></div>
</div>
<div class="split">
  <div class="card">
    <h2>Add a client</h2>
    <form method="post" action="{{ route('clients.store') }}" class="stack">
      @csrf
      <div><label for="c_name">Full name / business name</label><input id="c_name" name="name" value="{{ old('name') }}" required maxlength="120"><div class="err">@error('name'){{ $message }}@enderror</div></div>
      <div><label for="c_phone">Phone</label><input id="c_phone" name="phone" value="{{ old('phone') }}" inputmode="tel" required><div class="err">@error('phone'){{ $message }}@enderror</div></div>
      <div><label for="c_email">Email (optional)</label><input id="c_email" name="email" type="email" value="{{ old('email') }}" maxlength="190"><div class="err">@error('email'){{ $message }}@enderror</div></div>
      <div><label for="c_city">City</label><input id="c_city" name="city" value="{{ old('city') }}" required maxlength="80"><div class="err">@error('city'){{ $message }}@enderror</div></div>
      <button class="primary">Save client</button>
    </form>
  </div>
  <div class="list">
    @forelse($clients as $c)
      <div class="card row">
        <span class="avatar">{{ \Illuminate\Support\Str::of($c->name)->explode(' ')->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->join('') }}</span>
        <div class="grow">
          <b>{{ $c->name }}</b>
          <div class="hint small">{{ $c->phone }}@if($c->city) · {{ $c->city }}@endif @if($c->email) · {{ $c->email }}@endif</div>
          <div class="hint small">{{ $c->parcels_count }} parcel{{ $c->parcels_count === 1 ? '' : 's' }} booked</div>
        </div>
        <div class="btns">
          <a class="btn small primary" href="{{ route('warehouse.create', ['client' => $c->id]) }}">Use for new parcel</a>
          <form method="post" action="{{ route('clients.destroy', $c) }}" data-confirm="Remove {{ $c->name }}? Parcels already booked for them are not affected.">@csrf @method('DELETE')<button class="small danger">Remove</button></form>
        </div>
      </div>
    @empty
      <div class="card empty">No clients saved yet. Add a frequent sender to reuse their details next time.</div>
    @endforelse
  </div>
</div>
@endsection
