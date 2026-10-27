@extends('layouts.app')
@section('title', 'Dispatch')
@section('content')
<div class="page-head">
  <div><p class="eyebrow">Dispatch</p><h1>Riders &amp; deliveries</h1>
  <p class="hint">Assign scanned parcels to a rider, then confirm each delivery or report a problem.</p></div>
</div>

<div class="card">
  <h2>Ready for dispatch <span class="count">{{ $readyCount }}</span></h2>
  @if($activeRiders->isEmpty() && $readyCount)
    <div class="notice warn">Add a rider at the bottom of this page before assigning parcels.</div>
  @endif
  <form method="post" action="{{ route('dispatch.assign') }}" id="assignForm">
    @csrf
    <div class="table-wrap">
      <table>
        <thead><tr><th><input type="checkbox" data-check-all aria-label="Select all"></th><th>Tracking #</th><th>Route</th><th>Receiver</th><th>Assign &amp; dispatch</th></tr></thead>
        <tbody>
        @forelse($routes as $route => $group)
          @if($group->count() > 1)
            <tr class="group"><td colspan="5"><label><input type="checkbox" data-check-group="{{ $loop->index }}"> Same route — {{ $route }}: {{ $group->count() }} parcels can go to one rider</label></td></tr>
          @endif
          @foreach($group as $p)
            <tr>
              <td><input type="checkbox" name="tns[]" value="{{ $p->tracking_number }}" data-group="{{ $loop->parent->index }}" aria-label="Select {{ $p->tracking_number }}"></td>
              <td>@include('partials.tn', ['tn' => $p->tracking_number])</td>
              <td>{{ $route }}</td>
              <td><a href="{{ route('parcels.show', $p) }}">{{ $p->receiver_name }}</a></td>
              <td><div class="inline">
                <select name="row_rider[{{ $p->tracking_number }}]" aria-label="Rider for {{ $p->tracking_number }}"><option value="">Choose rider…</option>
                  @foreach($activeRiders as $r)<option value="{{ $r->id }}">{{ $r->name }} — {{ $r->vehicle }}</option>@endforeach
                </select>
                <button class="primary small" name="only" value="{{ $p->tracking_number }}">Assign</button>
              </div></td>
            </tr>
          @endforeach
        @empty
          <tr><td colspan="5" class="empty">No scanned parcels waiting for a rider.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
    @if($readyCount)
      <div class="bulkbar">
        <span class="hint" data-selected-count>0 parcels selected</span>
        <select name="rider_id" aria-label="Rider for selected parcels"><option value="">Assign selected to rider…</option>
          @foreach($activeRiders as $r)<option value="{{ $r->id }}">{{ $r->name }} — {{ $r->vehicle }}</option>@endforeach
        </select>
        <button class="primary small">Assign selected</button>
      </div>
    @endif
  </form>
</div>

<div class="card">
  <h2>Out for delivery <span class="count">{{ $out->count() }}</span></h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Tracking #</th><th>Receiver</th><th>Rider</th><th></th></tr></thead>
      <tbody>
      @forelse($out as $p)
        <tr>
          <td>@include('partials.tn', ['tn' => $p->tracking_number])</td>
          <td><a href="{{ route('parcels.show', $p) }}">{{ $p->receiver_name }}</a>, {{ $p->receiver_city }}</td>
          <td>{{ $p->rider?->name ?? '—' }}</td>
          <td><div class="inline">
            <form method="post" action="{{ route('dispatch.deliver', $p) }}" data-confirm="Mark {{ $p->tracking_number }} as delivered?">@csrf<button class="primary small">Mark delivered</button></form>
            <button type="button" class="small danger" data-report="{{ route('dispatch.exception', $p) }}" data-tn="{{ $p->tracking_number }}">Report issue</button>
          </div></td>
        </tr>
      @empty
        <tr><td colspan="4" class="empty">No parcels are out for delivery.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>

<h2 style="margin-top:28px">Riders and their deliveries</h2>
@forelse($riders as $r)
  @php $list = $riderParcels->get($r->id, collect()); $outNow = $list->where('status', \App\Enums\ParcelStatus::OutForDelivery)->count(); @endphp
  <div class="card" @if(!$r->active) style="opacity:.6" @endif>
    <div class="row">
      <span class="avatar">{{ \Illuminate\Support\Str::of($r->name)->explode(' ')->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->join('') }}</span>
      <div class="grow"><b>{{ $r->name }}</b><div class="hint small">{{ $r->vehicle }} · {{ $r->phone }}@unless($r->active) · Unavailable @endunless</div></div>
      <span class="pill {{ $outNow ? 'amber' : 'neutral' }}">{{ $outNow }} out now</span>
      <span class="hint small">{{ $list->count() }} total</span>
      <form method="post" action="{{ route('riders.toggle', $r) }}">@csrf<button class="small">{{ $r->active ? 'Mark unavailable' : 'Mark available' }}</button></form>
    </div>
    @if($list->isNotEmpty())
      <div class="table-wrap" style="margin-top:14px">
        <table>
          <thead><tr><th>Tracking #</th><th>Route</th><th>Receiver</th><th>Status</th></tr></thead>
          <tbody>
          @foreach($list->take(10) as $p)
            <tr><td>@include('partials.tn', ['tn' => $p->tracking_number])</td><td>{{ $p->route() }}</td><td><a href="{{ route('parcels.show', $p) }}">{{ $p->receiver_name }}</a></td><td>@include('partials.status', ['status' => $p->status])</td></tr>
          @endforeach
          </tbody>
        </table>
      </div>
      @if($list->count() > 10)<p class="hint small">+{{ $list->count() - 10 }} more not shown</p>@endif
    @else
      <p class="hint small" style="margin:10px 0 0">No packages carried yet.</p>
    @endif
  </div>
@empty
  <div class="card empty">No riders added yet.</div>
@endforelse

<div class="card" style="max-width:520px">
  <h2>Add a rider</h2>
  <form method="post" action="{{ route('riders.store') }}" class="stack">
    @csrf
    <div class="grid">
      <div><label for="r_name">Name</label><input id="r_name" name="name" value="{{ old('name') }}" required maxlength="120"></div>
      <div><label for="r_phone">Phone</label><input id="r_phone" name="phone" value="{{ old('phone') }}" inputmode="tel" required></div>
    </div>
    <div><label for="r_vehicle">Vehicle</label><select id="r_vehicle" name="vehicle">@foreach(config('stallionking.vehicles') as $v)<option>{{ $v }}</option>@endforeach</select></div>
    <button class="primary">Add rider</button>
  </form>
</div>

<dialog id="reportDialog" aria-labelledby="reportTitle">
  <form method="post" id="reportForm">
    @csrf
    <div class="dlg-head"><b id="reportTitle">Report a delivery issue</b><button type="button" class="small" data-close>Close</button></div>
    <div class="dlg-body stack">
      <p class="hint" style="margin:0" data-report-tn></p>
      <div><label for="reason">Reason</label><select id="reason" name="reason">@foreach(config('stallionking.exception_reasons') as $reason)<option>{{ $reason }}</option>@endforeach</select></div>
      <div><label for="detail">Additional detail (optional)</label><textarea id="detail" name="detail" rows="3" maxlength="300" placeholder="e.g. Tried calling twice, no answer since 10am"></textarea></div>
      <div class="actions"><button class="danger">Flag as exception</button><button type="button" data-close>Cancel</button></div>
    </div>
  </form>
</dialog>
@endsection
