<?php

namespace App\Http\Controllers;

use App\Enums\ParcelStatus;
use App\Models\DeletionRequest;
use App\Models\Parcel;
use App\Services\ParcelService;
use Illuminate\Http\Request;

class ParcelController extends Controller
{
    public function __construct(private ParcelService $parcels) {}

    /** Admin overview: every parcel, its status, and pending deletion requests. */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Parcel::class);
        $q = trim((string) $request->query('q'));
        $status = ParcelStatus::tryFrom((string) $request->query('status'));

        $list = Parcel::query()
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('tracking_number', 'like', "%{$q}%")
                ->orWhere('receiver_name', 'like', "%{$q}%")
                ->orWhere('receiver_phone', 'like', "%{$q}%")
                ->orWhere('sender->name', 'like', "%{$q}%")))
            ->latest('updated_at')
            ->paginate(50)->withQueryString();

        return view('parcels.index', [
            'parcels' => $list,
            'q' => $q,
            'status' => $status,
            'stats' => [
                'Total parcels' => Parcel::count(),
                'In transit' => Parcel::whereIn('status', [ParcelStatus::Scanned, ParcelStatus::OutForDelivery])->count(),
                'Delivered today' => Parcel::where('status', ParcelStatus::Delivered)->where('updated_at', '>=', today())->count(),
                'Exceptions' => Parcel::where('status', ParcelStatus::Exception)->count(),
            ],
            'deletions' => DeletionRequest::with('requester')->where('status', 'pending')->latest()->get(),
        ]);
    }

    public function show(Parcel $parcel)
    {
        $this->authorize('view', $parcel);
        $parcel->load(['events.actor', 'rider', 'bookedBy']);
        return view('parcels.show', [
            'parcel' => $parcel,
            'pendingDeletion' => DeletionRequest::with('requester')->where('parcel_id', $parcel->id)->where('status', 'pending')->first(),
        ]);
    }

    /** Sends a delivery-exception parcel back to the warehouse queue under the same tracking number. */
    public function rebook(Request $request, Parcel $parcel)
    {
        $this->authorize('advanceTo', [$parcel, ParcelStatus::Booked]);
        $this->parcels->advance($parcel->id, ParcelStatus::Booked, $request->user());
        return redirect()->route('warehouse.create')->with('ok', "{$parcel->tracking_number} re-booked — ready for warehouse processing.");
    }
}
