<?php

namespace App\Mail;

use App\Models\Parcel;
use App\Models\Setting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TrackingNumberMail extends Mailable
{
    public function __construct(public Parcel $parcel, public string $recipientName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your parcel is booked — Tracking No. {$this->parcel->tracking_number}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.tracking-number', with: [
            'company' => Setting::get('name'),
            'trackUrl' => route('track', ['tn' => $this->parcel->tracking_number]) . '#track',
        ]);
    }
}
