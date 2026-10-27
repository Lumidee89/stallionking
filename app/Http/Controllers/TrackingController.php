<?php

namespace App\Http\Controllers;

use App\Enums\ParcelStatus;
use App\Models\Parcel;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    /** Normal delivery journey shown on the public tracker, in order. */
    private const JOURNEY = [
        ParcelStatus::Booked,
        ParcelStatus::Received,
        ParcelStatus::Packaged,
        ParcelStatus::Scanned,
        ParcelStatus::OutForDelivery,
        ParcelStatus::Delivered,
    ];

    /**
     * The public homepage is the designer's static HTML (resources/views/home.html), served as-is.
     * It is too large for Blade to parse, so only these placeholders are filled in.
     */
    public function show()
    {
        $html = strtr(file_get_contents(resource_path('views/home.html')), [
            '__SK_LOGIN_URL__' => e(route('login')),
            '__SK_TRACK_URL__' => json_encode(route('track.lookup'), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES),
            '__SK_TRACKING_JS__' => e(asset('js/tracking.js')),
        ]);
        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Public, rate-limited, and returns ONLY non-sensitive progress data. */
    public function lookup(Request $request)
    {
        // Plain browser visits (e.g. a shared link) land on the homepage tracker, which looks the number up itself.
        if (!$request->expectsJson()) {
            return redirect()->to(route('track', ['tn' => $request->query('tn')]) . '#track');
        }

        $tn = strtoupper(trim((string) $request->validate(['tn' => ['required', 'string', 'max:20']])['tn']));
        $parcel = Parcel::with('events')->where('tracking_number', $tn)->first();

        if (!$parcel) {
            return response()->json(['found' => false, 'tracking_number' => $tn], 404);
        }

        // When each journey step was last reached (a re-booked parcel can pass a step twice).
        $reached = [];
        foreach ($parcel->events as $e) {
            $reached[$e->status->value] = $e->created_at;
        }
        $current = $parcel->status;
        $currentIndex = array_search($current, self::JOURNEY, true);

        $steps = [];
        foreach (self::JOURNEY as $i => $status) {
            $at = $reached[$status->value] ?? null;
            $steps[] = [
                'label' => $status->label(),
                'state' => $currentIndex === false
                    ? ($at ? 'done' : 'pending')
                    : ($i < $currentIndex ? 'done' : ($i === $currentIndex ? 'active' : 'pending')),
                'at' => $at?->format('j M Y, H:i') . ($at ? ' WAT' : ''),
            ];
        }

        return response()->json([
            'found' => true,
            'tracking_number' => $parcel->tracking_number,
            'status' => $current->value,
            'status_label' => $current->label(),
            'destination' => trim($parcel->receiver_city . ($parcel->receiver_state ? ', ' . $parcel->receiver_state : '')),
            'exception' => $current === ParcelStatus::Exception,
            'exception_reason' => $current === ParcelStatus::Exception ? $this->publicReason($parcel->events->last()?->note) : null,
            'service' => $parcel->service_type,
            'steps' => $steps,
            'updated_at' => $parcel->updated_at?->format('j M Y, H:i'),
        ]);
    }

    /** Only the standard reason is public; staff's free-text detail may hold personal information. */
    private function publicReason(?string $note): ?string
    {
        foreach (config('stallionking.exception_reasons') as $reason) {
            if ($note && str_starts_with($note, $reason) && $reason !== 'Other (specify below)') {
                return $reason;
            }
        }
        return null;
    }
}
