Hi {{ $parcel->receiver_name }},

A parcel has been booked for delivery to you with {{ $company }}.

@foreach($details as $label => $value)
{{ $label }}: {{ $value }}
@endforeach

Track your parcel: {{ $trackUrl }}

If any of these details are wrong, call us on 0810 123 3538 or reply to this email before the parcel is out for delivery.

{{ $company }}
