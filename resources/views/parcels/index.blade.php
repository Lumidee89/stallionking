@extends('layouts.app')
@section('title', 'Parcels')
@section('content')
<div class="page-head">
  <div><p class="eyebrow">Overview</p><h1>All parcels</h1>
  <p class="hint">Every parcel, its current status and full history.</p></div>
</div>

<div class="stats">
  @foreach($stats as $label => $value)
    <div class="stat"><b @if($label === 'Exceptions' && $value) style="color:var(--bad)" @endif>{{ $value }}</b><span>{{ $label }}</span></div>
  @endforeach
</div>

@if($deletions->isNotEmpty())
  <div class="card">
    <h2 style="color:var(--bad)">Deletion requests <span class="count">{{ $deletions->count() }}</span></h2>
    @foreach($deletions as $d)
      <div class="request">
        <div class="grow">
          <div class="inline">@include('partials.tn', ['tn' => $d->tracking_number]) <span class="pill amber">Pending</span></div>
          <div class="hint small">Requested by {{ $d->requester?->name ?? 'a former staff member' }}@if($d->requester) ({{ $d->requester->staff_id }})@endif · {{ $d->created_at->format('j M, H:i') }}</div>
          <p style="margin:6px 0 0">{{ $d->reason }}</p>
        </div>
        @if(auth()->user()->isAdmin())
          <div class="stack" style="min-width:240px">
            <form method="post" action="{{ route('deletions.approve', $d) }}" data-confirm="Permanently delete {{ $d->tracking_number }} and its history? This cannot be undone.">@csrf<button class="small danger" style="width:100%">Approve &amp; delete</button></form>
            <form method="post" action="{{ route('deletions.reject', $d) }}" class="inline">@csrf<input name="note" placeholder="Reason for declining (optional)" maxlength="500"><button class="small">Decline</button></form>
          </div>
        @else
          <span class="hint small">Waiting for an admin</span>
        @endif
      </div>
    @endforeach
  </div>
@endif

<div class="card">
  <form method="get" class="filters">
    <input name="q" value="{{ $q }}" placeholder="Search tracking #, sender, receiver or phone" aria-label="Search">
    <select name="status" aria-label="Status"><option value="">All statuses</option>
      @foreach(\App\Enums\ParcelStatus::cases() as $s)<option value="{{ $s->value }}" @selected($status === $s)>{{ $s->label() }}</option>@endforeach
    </select>
    <button>Filter</button>
    @if($q !== '' || $status)<a class="btn" href="{{ route('parcels.index') }}">Clear</a>@endif
  </form>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Tracking #</th><th>Route</th><th>Receiver</th><th>Service</th><th>Status</th><th>Updated</th></tr></thead>
      <tbody>
      @forelse($parcels as $p)
        <tr class="clickable" data-href="{{ route('parcels.show', $p) }}">
          <td>@include('partials.tn', ['tn' => $p->tracking_number])</td>
          <td>{{ $p->route() }}</td>
          <td><a href="{{ route('parcels.show', $p) }}">{{ $p->receiver_name }}</a></td>
          <td>{{ $p->service_type }}</td>
          <td>@include('partials.status', ['status' => $p->status])</td>
          <td class="hint small">{{ $p->updated_at->format('j M, H:i') }}</td>
        </tr>
      @empty
        <tr><td colspan="6" class="empty">No parcels match this filter.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @if($parcels->hasPages())
    <div class="pager">
      @if($parcels->previousPageUrl())<a class="btn small" href="{{ $parcels->previousPageUrl() }}">← Newer</a>@endif
      <span class="hint small">Page {{ $parcels->currentPage() }} of {{ $parcels->lastPage() }}</span>
      @if($parcels->nextPageUrl())<a class="btn small" href="{{ $parcels->nextPageUrl() }}">Older →</a>@endif
    </div>
  @endif
</div>
@endsection
