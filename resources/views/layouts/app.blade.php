@php
  use App\Enums\Role;
  $me = auth()->user();
  $unread = $me ? $me->unreadNotifications()->count() : 0;
  $recent = $me ? $me->notifications()->latest()->limit(8)->get() : collect();
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#0d0d0f">
<title>@yield('title', 'Staff') · StallionKing Logistics</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="topbar"><div class="in">
  <a href="mailto:info@stallion-king.com">info@stallion-king.com</a>
  <a href="tel:+2348101233538">0810 123 3538</a>
  <span>19b Bush Street, Mende Maryland</span>
  @auth<span class="clock" data-clock>{{ now()->format('D j M, H:i') }} WAT</span>@endauth
</div></div>
<header class="top"><div class="in">
  <a class="brand" href="{{ $me ? $me->homeRoute() : route('track') }}">
    <img src="{{ asset('images/logo.png') }}" alt="StallionKing Logistics">
    <span class="tag">Staff portal</span>
  </a>
  <nav>
    <a href="{{ route('track') }}#track">Track shipment</a>
    @auth
      @if($me->hasRole(Role::warehouse()))
        <a href="{{ route('warehouse.create') }}" @class(['on' => request()->routeIs('warehouse.*')])>Warehouse</a>
        <a href="{{ route('clients.index') }}" @class(['on' => request()->routeIs('clients.*')])>Clients</a>
      @endif
      @if($me->hasRole(Role::dispatch()))
        <a href="{{ route('dispatch.index') }}" @class(['on' => request()->routeIs('dispatch.*')])>Dispatch</a>
      @endif
      <a href="{{ route('parcels.index') }}" @class(['on' => request()->routeIs('parcels.*')])>Parcels</a>
      @if($me->isAdmin())
        <a href="{{ route('staff.index') }}" @class(['on' => request()->routeIs('staff.*')])>Staff</a>
        <a href="{{ route('settings.edit') }}" @class(['on' => request()->routeIs('settings.*')])>Settings</a>
      @endif
      @if($me->role === Role::Superadmin)
        <a href="{{ route('activity.index') }}" @class(['on' => request()->routeIs('activity.*')])>Activity</a>
      @endif
    @else
      <a href="{{ route('track') }}">Website</a>
    @endauth
  </nav>
  @auth
  <div class="me">
    <details class="drop bell">
      <summary aria-label="Notifications">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        @if($unread)<span class="badge">{{ $unread > 9 ? '9+' : $unread }}</span>@endif
      </summary>
      <div class="panel">
        <div class="panel-head"><b>Notifications</b>
          @if($unread)<form method="post" action="{{ route('notifications.readAll') }}">@csrf<button class="link">Mark all read</button></form>@endif
        </div>
        @forelse($recent as $n)
          <a class="notif {{ $n->read_at ? '' : 'unread' }}" href="{{ route('notifications.open', $n->id) }}">
            <b>{{ $n->data['title'] ?? '' }}</b>
            <span>{{ $n->data['body'] ?? '' }}</span>
            <small>{{ $n->created_at->format('j M, H:i') }}</small>
          </a>
        @empty
          <p class="empty">No notifications yet.</p>
        @endforelse
      </div>
    </details>
    <span class="who">{{ $me->name }} <span class="role">{{ $me->role->value }}</span></span>
    <form method="post" action="{{ route('logout') }}">@csrf<button class="small">Sign out</button></form>
  </div>
  @endauth
</div></header>
<main @class(['wide' => $me])>
  @if(session('ok'))<div class="notice ok" role="status">{{ session('ok') }}</div>@endif
  @if(session('bad'))<div class="notice bad" role="alert">{{ session('bad') }}</div>@endif
  @if($errors->any() && !request()->routeIs('login'))<div class="notice bad" role="alert">{{ $errors->first() }}</div>@endif
  @yield('content')
</main>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<footer class="foot">&copy; {{ date('Y') }} StallionKing Logistics. Staff use only.</footer>
<script src="{{ asset('js/staff.js') }}" defer></script>
@stack('scripts')
</body>
</html>
