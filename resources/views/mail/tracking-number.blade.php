Hi {{ $recipientName ?: 'there' }},

Your parcel with {{ $company }} has been booked.

Tracking number: {{ $parcel->tracking_number }}
Route: {{ $parcel->route() }}
Service: {{ $parcel->service_type }}

Track it any time: {{ $trackUrl }}

Thank you for shipping with us.
{{ $company }}
