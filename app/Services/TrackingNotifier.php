<?php

namespace App\Services;

use App\Mail\ParcelBookedMail;
use App\Mail\TrackingNumberMail;
use App\Models\ActivityLog;
use App\Models\Parcel;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Emails the receiver the parcel's full details, and the sender its tracking number. */
final class TrackingNotifier
{
    /** @return list<array{channel:string, recipient_role:string, to:string, sent_at:string, delivered:bool, error:?string}> */
    public function send(Parcel $parcel, User $actor, bool $includeSender = true): array
    {
        $results = [];
        $contacts = array_filter([
            'Sender' => $includeSender ? ($parcel->sender['email'] ?? null) : null,
            'Receiver' => $parcel->receiver_email,
        ]);

        foreach ($contacts as $role => $email) {
            $error = null;
            try {
                Mail::to($email)->send($role === 'Receiver'
                    ? new ParcelBookedMail($parcel)
                    : new TrackingNumberMail($parcel, $parcel->sender['name'] ?? ''));
            } catch (\Throwable $e) {
                Log::warning('Parcel email failed', ['parcel' => $parcel->tracking_number, 'error' => $e->getMessage()]);
                $error = 'The mail server did not accept this email. Check the address is real; if every email fails, check the MAIL_ settings in .env.';
            }
            $results[] = ['channel' => 'email', 'recipient_role' => $role, 'to' => $email, 'sent_at' => now()->toIso8601String(), 'delivered' => $error === null, 'error' => $error];
        }

        if ($results) {
            $parcel->update(['tracking_emails' => array_merge($parcel->tracking_emails ?? [], $results)]);
            $ok = count(array_filter($results, fn ($r) => $r['delivered']));
            ActivityLog::record($actor, 'tracking_notified', "Sent tracking ID {$parcel->tracking_number} to {$ok} of " . count($results) . ' recipient(s) by email', 'parcel', $parcel->tracking_number);
        }
        return $results;
    }
}
