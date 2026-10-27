@extends('layouts.app')
@section('title', 'Warehouse')
@section('content')
<div class="page-head">
  <div><p class="eyebrow">Warehouse</p><h1>Book &amp; process parcels</h1>
  <p class="hint">Register new parcels, then mark them received in-store so dispatch can assign a rider.</p></div>
</div>

<div id="bookingResult"></div>

<div class="card">
  <h2>Book a new parcel</h2>
  <div class="scanbox">
    <div>
      <b>Scan a document to fill this form</b>
      <p class="hint" style="margin:4px 0 0">Photograph a waybill or booking form, upload a photo of one, or scan a contact QR code. Sender, receiver and package details found on it go into their fields. Check them before saving.</p>
    </div>
    <div class="scanbar">
      <button type="button" id="btnOcr">Scan document</button>
      <label class="btn" for="docUpload">Upload document photo</label>
      <input type="file" id="docUpload" accept="image/*" hidden>
      <button type="button" id="btnQr">Scan QR code</button>
    </div>
  </div>

  <div id="formNotice" role="status" aria-live="polite"></div>

  <form id="bookForm" novalidate>
    <fieldset>
      <legend>Sender</legend>
      <div class="grid">
        <div class="full">
          <label for="client_id">Frequent client (optional)</label>
          <select id="client_id" name="client_id"><option value="">One-off sender — fill in below</option>
            @foreach($clients as $c)<option value="{{ $c->id }}" data-name="{{ $c->name }}" data-phone="{{ $c->phone }}" data-email="{{ $c->email }}" data-city="{{ $c->city }}" @selected($selectedClient === $c->id)>{{ $c->name }} — {{ $c->city }}</option>@endforeach
          </select>
          <p class="hint small">Pick a saved client to fill in their details. You only need to add the receiver.</p>
        </div>
        <div><label for="sender_name">Full name</label><input id="sender_name" name="sender[name]" required maxlength="120"><div class="err" data-err="sender.name"></div></div>
        <div><label for="sender_phone">Phone</label><input id="sender_phone" name="sender[phone]" inputmode="tel" required><div class="err" data-err="sender.phone"></div></div>
        <div><label for="sender_city">City</label><input id="sender_city" name="sender[city]" maxlength="80"></div>
        <div><label for="sender_email">Email (optional)</label><input id="sender_email" name="sender[email]" type="email" maxlength="190" placeholder="to receive the tracking ID"><div class="err" data-err="sender.email"></div></div>
      </div>
      <button type="button" class="small" id="btnSaveSender" style="margin-top:12px">+ Save as frequent sender</button>
    </fieldset>

    <fieldset>
      <legend>Receiver</legend>
      <input type="hidden" name="receiver_source" id="receiver_source" value="manual">
      <div class="grid">
        <div><label for="receiver_name">Full name</label><input id="receiver_name" name="receiver_name" required maxlength="120"><div class="err" data-err="receiver_name"></div></div>
        <div><label for="receiver_phone">Phone</label><input id="receiver_phone" name="receiver_phone" inputmode="tel" required><div class="err" data-err="receiver_phone"></div></div>
        <div class="full"><label for="receiver_email">Email (optional — they will be emailed the parcel details)</label><input id="receiver_email" name="receiver_email" type="email" maxlength="190"><div class="err" data-err="receiver_email"></div></div>
        <div class="full"><label for="receiver_address">Delivery address</label><input id="receiver_address" name="receiver_address" required maxlength="500" list="addressSuggestions" autocomplete="off" placeholder="Start typing, e.g. 43 Ku…"><div class="err" data-err="receiver_address"></div></div>
        <div><label for="receiver_city">City</label><input id="receiver_city" name="receiver_city" required maxlength="80"><div class="err" data-err="receiver_city"></div></div>
        <div><label for="receiver_state">State</label><input id="receiver_state" name="receiver_state" maxlength="40"></div>
        <div class="full"><label for="receiver_landmark">Nearest landmark</label><input id="receiver_landmark" name="receiver_landmark" maxlength="150" list="landmarkSuggestions" autocomplete="off" placeholder="Start typing, e.g. Lekki…"></div>
      </div>
      <datalist id="addressSuggestions">@foreach(config('stallionking.address_suggestions') as $a)<option value="{{ $a }}">@endforeach</datalist>
      <datalist id="landmarkSuggestions">@foreach(config('stallionking.landmark_suggestions') as $l)<option value="{{ $l }}">@endforeach</datalist>
    </fieldset>

    <fieldset>
      <legend>Package</legend>
      <div class="grid">
        <div class="full"><label for="description">Description</label><input id="description" name="description" required maxlength="200" placeholder="e.g. Documents, electronics"><div class="err" data-err="description"></div></div>
        <div><label for="weight_range">Weight</label>
          <select id="weight_range" name="weight_range" required>
            @foreach(\App\Models\Parcel::WEIGHTS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
          </select></div>
        <div><label for="service_type">Service</label>
          <select id="service_type" name="service_type">
            @foreach(\App\Models\Parcel::SERVICES as $s)<option>{{ $s }}</option>@endforeach
          </select></div>
      </div>
    </fieldset>

    <div class="actions">
      <button class="primary" type="submit" data-send="1">Save &amp; send tracking ID</button>
      <button type="submit" data-send="0">Save parcel</button>
    </div>
    <p class="hint small">The receiver is always emailed the parcel details if their email is entered. "Save &amp; send" also emails the tracking ID to the sender; "Save parcel" does not.</p>
  </form>
</div>

<div class="card">
  <h2>Waiting in the warehouse</h2>
  <p class="hint">Parcels that are booked but not yet ready for dispatch. "Received in-store" records them as received, packaged and scanned, and moves them to Dispatch.</p>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Tracking #</th><th>Route</th><th>Receiver</th><th>Status</th><th></th></tr></thead>
      <tbody>
      @forelse($queue as $p)
        <tr>
          <td>@include('partials.tn', ['tn' => $p->tracking_number])</td>
          <td>{{ $p->route() }}</td>
          <td><a href="{{ route('parcels.show', $p) }}">{{ $p->receiver_name }}</a></td>
          <td>@include('partials.status', ['status' => $p->status])</td>
          <td><form method="post" action="{{ route('warehouse.receive', $p) }}">@csrf<button class="primary small">Received in-store</button></form></td>
        </tr>
      @empty
        <tr><td colspan="5" class="empty">No parcels waiting for warehouse action.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>

<dialog id="scanDialog" aria-labelledby="scanTitle">
  <div class="dlg-head"><b id="scanTitle">Scan</b><button type="button" id="scanClose" class="small" aria-label="Close scanner">Close</button></div>
  <div class="dlg-body">
    <video id="scanVideo" playsinline muted hidden></video>
    <img id="ocrPreview" alt="Captured document" hidden>
    <canvas id="scanCanvas" hidden></canvas>
    <p class="status" id="scanStatus" role="status" aria-live="polite"></p>
    <progress id="scanProgress" max="1" value="0" hidden></progress>
    <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap">
      <button type="button" class="primary" id="btnCapture" hidden>Capture document</button>
      <label class="btn" id="btnUploadWrap" style="margin:0" hidden>Take or choose a photo
        <input type="file" id="fileInput" accept="image/*" capture="environment" hidden>
      </label>
    </div>
  </div>
</dialog>
@endsection

@push('scripts')
<script>window.SK = { parseUrl: @json(route('warehouse.scan.parse')), storeUrl: @json(route('warehouse.store')), clientUrl: @json(route('clients.store')), parcelUrl: @json(url('/parcels')) };</script>
<script src="{{ asset('js/scanner.js') }}" defer></script>
@endpush
