@php
  $tone = match ($status) {
      \App\Enums\ParcelStatus::Booked => 'neutral',
      \App\Enums\ParcelStatus::Received, \App\Enums\ParcelStatus::Packaged => 'blue',
      \App\Enums\ParcelStatus::Scanned, \App\Enums\ParcelStatus::OutForDelivery => 'amber',
      \App\Enums\ParcelStatus::Delivered => 'green',
      \App\Enums\ParcelStatus::Exception => 'red',
  };
@endphp
<span class="pill {{ $tone }}"><span class="dot"></span>{{ $status->label() }}</span>
