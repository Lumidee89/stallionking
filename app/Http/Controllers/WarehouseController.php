<?php

namespace App\Http\Controllers;

use App\Enums\ParcelStatus;
use App\Http\Requests\StoreParcelRequest;
use App\Models\Client;
use App\Models\Parcel;
use App\Services\ParcelService;
use App\Services\TrackingNotifier;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function __construct(private ParcelService $parcels, private TrackingNotifier $notifier) {}

    public function create(Request $request)
    {
        $this->authorize('create', Parcel::class);
        return view('warehouse.book', [
            'clients' => Client::orderBy('name')->limit(200)->get(['id', 'name', 'phone', 'email', 'city']),
            'selectedClient' => (int) $request->query('client'),
            'queue' => Parcel::whereIn('status', [ParcelStatus::Booked, ParcelStatus::Received, ParcelStatus::Packaged])
                ->latest()->limit(100)->get(),
        ]);
    }

    public function store(StoreParcelRequest $request)
    {
        $data = $request->validated();
        $parcel = $this->parcels->book(
            $data,
            $request->user(),
            substr((string) $request->header('Idempotency-Key'), 0, 64) ?: null,
        );

        // The receiver always gets the parcel details; the sender's tracking-ID email is optional.
        // Only on the first, real booking: a retried request must not email again.
        $wantsSend = $request->boolean('send_notification', true);
        $emails = $parcel->wasRecentlyCreated ? $this->notifier->send($parcel, $request->user(), includeSender: $wantsSend) : [];

        if ($request->expectsJson()) {
            return response()->json([
                'tracking_number' => $parcel->tracking_number,
                'notifications' => $emails,
                'sender_skipped' => !$wantsSend,
            ], 201);
        }
        return redirect()->route('warehouse.create')->with('booked', $parcel->tracking_number);
    }

    public function receive(Request $request, Parcel $parcel)
    {
        $this->authorize('advanceTo', [$parcel, ParcelStatus::Received]);
        $this->parcels->receiveInStore($parcel->id, $request->user());
        return back()->with('ok', "{$parcel->tracking_number} received in-store — ready for dispatch.");
    }

    public function sendTracking(Request $request, Parcel $parcel)
    {
        $this->authorize('create', Parcel::class);
        $results = $this->notifier->send($parcel, $request->user());
        if (!$results) {
            return back()->with('bad', 'There is no sender or receiver email address on this parcel.');
        }
        $failed = count(array_filter($results, fn ($r) => !$r['delivered']));
        return back()->with($failed ? 'bad' : 'ok', $failed
            ? "{$failed} of " . count($results) . ' emails failed. See the list below.'
            : 'Emailed ' . implode(' and ', array_column($results, 'to')) . '.');
    }

    public function advance(Request $request, Parcel $parcel)
    {
        $next = ParcelStatus::tryFrom((string) $request->input('status'));
        abort_if($next === null, 422, 'Unknown status.');
        $this->authorize('advanceTo', [$parcel, $next]);

        $updated = $this->parcels->advance($parcel->id, $next, $request->user(), $request->input('note'));
        return response()->json(['status' => $updated->status->value]);
    }
}
