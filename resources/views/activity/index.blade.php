@extends('layouts.app')
@section('title', 'Activity log')
@section('content')
<div class="page-head">
  <div><p class="eyebrow">Activity log</p><h1>Who did what</h1>
  <p class="hint">Every action on the platform, with the staff member who did it.</p></div>
</div>
<div class="card">
  <form method="get" class="filters">
    <input name="q" value="{{ $q }}" placeholder="Search action, name or tracking #" aria-label="Search">
    <select name="actor" aria-label="Staff member"><option value="">All staff</option>
      @foreach($users as $u)<option value="{{ $u->id }}" @selected($actor === $u->id)>{{ $u->name }} ({{ $u->staff_id }})</option>@endforeach
    </select>
    <button>Filter</button>
    @if($q !== '' || $actor)<a class="btn" href="{{ route('activity.index') }}">Clear</a>@endif
  </form>
  <div class="table-wrap">
    <table>
      <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Reference</th></tr></thead>
      <tbody>
      @forelse($logs as $l)
        <tr>
          <td class="hint small nowrap">{{ $l->created_at->format('j M Y, H:i') }}</td>
          <td><b>{{ $l->actor_name ?? 'System' }}</b><div class="hint small"><span class="mono">{{ $l->actor_staff_id }}</span> · {{ ucfirst((string) $l->actor_role) }}</div></td>
          <td>{{ $l->summary }}</td>
          <td>@if($l->target_type === 'parcel' && $l->target_id)<a class="tn" href="{{ route('parcels.show', $l->target_id) }}">{{ $l->target_id }}</a>@else{{ $l->target_id ?? '—' }}@endif</td>
        </tr>
      @empty
        <tr><td colspan="4" class="empty">No activity recorded yet.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @if($logs->hasPages())
    <div class="pager">
      @if($logs->previousPageUrl())<a class="btn small" href="{{ $logs->previousPageUrl() }}">← Newer</a>@endif
      <span class="hint small">Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}</span>
      @if($logs->nextPageUrl())<a class="btn small" href="{{ $logs->nextPageUrl() }}">Older →</a>@endif
    </div>
  @endif
</div>
@endsection
