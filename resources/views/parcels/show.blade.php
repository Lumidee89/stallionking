@php use App\Enums\ParcelStatus; use App\Enums\Role; $me = auth()->user(); @endphp
@extends('layouts.app')
@section('title', $parcel->tracking_number)
@section('content')
<p><a class="hint" href="{{ route('parcels.index') }}">← All parcels</a></p>
<div class="card">
  <div class="page-head">
    <div>
      <p class="eyebrow">{{ $parcel->route() }}</p>
      <h1>@include('partials.tn', ['tn' => $parcel->tracking_number])</h1>
    </div>
    @include('partials.status', ['status' => $parcel->status])
  </div>

  <dl class="facts">
    <div><dt>Sender</dt><dd>{{ $parcel->sender['name'] ?? '' }} · {{ $parcel->sender['phone'] ?? '' }}@if(!empty($parcel->sender['email'])) · {{ $parcel->sender['email'] }}@endif</dd></div>
    <div><dt>Receiver</dt><dd>{{ $parcel->receiver_name }} · {{ $parcel->receiver_phone }}@if($parcel->receiver_email) · {{ $parcel->receiver_email }}@endif</dd></div>
    <div class="full"><dt>Delivery address</dt><dd>{{ $parcel->receiver_address }}, {{ $parcel->receiver_city }}@if($parcel->receiver_state), {{ $parcel->receiver_state }}@endif @if($parcel->receiver_landmark) · near {{ $parcel->receiver_landmark }}@endif</dd></div>
    <div><dt>Item</dt><dd>{{ $parcel->description ?: '—' }}</dd></div>
    <div><dt>Weight</dt><dd>{{ $parcel->weightLabel() }}</dd></div>
    <div><dt>Service</dt><dd>{{ $parcel->service_type }}</dd></div>
    <div><dt>Booked by</dt><dd>{{ $parcel->bookedBy?->name ?? '—' }} · {{ $parcel->created_at->format('j M Y, H:i') }}</dd></div>
  </dl>

  @if($parcel->rider)
    <div class="callout amber"><span class="avatar">{{ mb_substr($parcel->rider->name, 0, 1) }}</span><div><b>{{ $parcel->rider->name }}</b><div class="hint small">{{ $parcel->rider->phone }} · {{ $parcel->rider->vehicle }}</div></div></div>
  @endif

  @if($parcel->status === ParcelStatus::Exception)
    <div class="callout red">
      <div class="grow"><b>Delivery exception</b>
        <div class="hint small">{{ $parcel->events->last()?->note }}</div>
        <div class="hint small">Re-booking sends this parcel back to the warehouse queue under the same tracking number.</div>
      </div>
      @if($me->isAdmin())
        <form method="post" action="{{ route('parcels.rebook', $parcel) }}">@csrf<button class="primary small">Re-book parcel</button></form>
      @endif
    </div>
  @endif

  <div class="actions" style="margin-top:18px">
    @if($me->hasRole(Role::warehouse()))
      <form method="post" action="{{ route('parcels.sendTracking', $parcel) }}">@csrf<button class="small">Resend emails</button></form>
    @endif
    @if($me->hasRole(Role::warehouse()) && in_array($parcel->status, [ParcelStatus::Booked, ParcelStatus::Received, ParcelStatus::Packaged], true))
      <form method="post" action="{{ route('warehouse.receive', $parcel) }}">@csrf<button class="small primary">Received in-store</button></form>
    @endif
  </div>
</div>

<div class="split">
  <div class="card">
    <h2>Full history</h2>
    <ol class="history">
      @foreach($parcel->events->reverse() as $e)
        @php $kind = $e->status === ParcelStatus::Exception ? 'failed' : (str_starts_with((string) $e->note, 'Re-booked') ? 'rebooked' : 'done'); @endphp
        <li class="{{ $kind }}">
          <b>{{ $kind === 'rebooked' ? 'Re-booked' : $e->status->label() }}</b>
          <small>{{ $e->created_at->format('j M Y, H:i') }} WAT</small>
          @if($e->note && $kind !== 'rebooked')<span class="note">{{ $e->note }}</span>@endif
          @if($e->actor)<span class="actor">{{ $e->actor->name }} ({{ $e->actor->staff_id }})</span>@endif
        </li>
      @endforeach
    </ol>
  </div>

  <div>
    <div class="card">
      <h2>Emails sent</h2>
      @forelse(array_reverse($parcel->tracking_emails ?? []) as $n)
        <p class="small" style="margin:0 0 8px">
          <span class="pill {{ $n['delivered'] ? 'green' : 'red' }}">{{ $n['delivered'] ? 'Sent' : 'Failed' }}</span>
          {{ $n['recipient_role'] }} · {{ $n['to'] }} · <span class="hint">{{ \Illuminate\Support\Carbon::parse($n['sent_at'])->timezone(config('app.timezone'))->format('j M, H:i') }}</span>
          @if($n['error'])<br><span class="err">{{ $n['error'] }}</span>@endif
        </p>
      @empty
        <p class="hint small" style="margin:0">No email has been sent for this parcel.</p>
      @endforelse
    </div>

    <div class="card">
      <h2>Delete this parcel</h2>
      @if($pendingDeletion)
        <p class="hint small" style="margin:0">Deletion requested by {{ $pendingDeletion->requester?->name ?? 'a staff member' }}: "{{ $pendingDeletion->reason }}". Waiting for an admin to approve it on the <a href="{{ route('parcels.index') }}">Parcels</a> page.</p>
      @else
        <form method="post" action="{{ route('deletions.store', $parcel) }}" class="stack">
          @csrf
          <div><label for="reason">Reason</label><textarea id="reason" name="reason" rows="2" maxlength="500" required placeholder="e.g. Duplicate booking entered by mistake">{{ old('reason') }}</textarea></div>
          <button class="small danger">Request deletion</button>
          <p class="hint small" style="margin:0">An admin must approve it before the parcel is removed.</p>
        </form>
      @endif
    </div>
  </div>
</div>
@endsection
