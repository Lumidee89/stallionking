<?php

namespace App\Mail;

use App\Models\Parcel;
use App\Models\Setting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent to the receiver when a parcel is booked for them: everything they need to know about it. */
class ParcelBookedMail extends Mailable
{
    public function __construct(public Parcel $parcel) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "A parcel is on its way to you — Tracking No. {$this->parcel->tracking_number}");
    }

    public function content(): Content
    {
        $p = $this->parcel;
        return new Content(
            view: 'mail.parcel-booked',
            text: 'mail.parcel-booked-text',
            with: [
                'company' => Setting::get('name'),
                'trackUrl' => route('track', ['tn' => $p->tracking_number]) . '#track',
                'details' => array_filter([
                    'Tracking number' => $p->tracking_number,
                    'Status' => $p->status->label(),
                    'From' => trim(($p->sender['name'] ?? '') . (!empty($p->sender['city']) ? ', ' . $p->sender['city'] : '')),
                    'Deliver to' => $p->receiver_name,
                    'Delivery address' => implode(', ', array_filter([$p->receiver_address, $p->receiver_city, $p->receiver_state])),
                    'Nearest landmark' => $p->receiver_landmark,
                    'Your phone on file' => $p->receiver_phone,
                    'Item' => $p->description,
                    'Weight' => $p->weightLabel(),
                    'Service' => $p->service_type,
                    'Booked on' => $p->created_at->format('j M Y, H:i') . ' WAT',
                ], fn ($v) => $v !== null && $v !== ''),
            ],
        );
    }
}
