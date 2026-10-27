<?php

namespace App\Http\Controllers;

use App\Enums\ParcelStatus;
use App\Models\ActivityLog;
use App\Models\Parcel;
use App\Models\Rider;
use App\Notifications\StaffAlert;
use App\Services\ParcelService;
use App\Support\ReceiverScanParser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DispatchController extends Controller
{
    public function __construct(private ParcelService $parcels) {}

    public function index()
    {
        $ready = Parcel::where('status', ParcelStatus::Scanned)->oldest('updated_at')->get();
        $riders = Rider::orderBy('name')->get();

        return view('dispatch.index', [
            'routes' => $ready->groupBy(fn (Parcel $p) => $p->route()),
            'readyCount' => $ready->count(),
            'out' => Parcel::with('rider')->where('status', ParcelStatus::OutForDelivery)->oldest('updated_at')->get(),
            'riders' => $riders,
            'activeRiders' => $riders->where('active', true),
            'riderParcels' => Parcel::whereNotNull('rider_id')->latest('updated_at')->get()->groupBy('rider_id'),
        ]);
    }

    /** Assign one parcel (row button) or every ticked parcel (bulk bar) to a rider. */
    public function assign(Request $request)
    {
        $only = $request->input('only');
        $data = $only
            ? ['tns' => [$only], 'rider_id' => $request->input("row_rider.$only")]
            : $request->only('tns', 'rider_id');

        $v = validator($data, [
            'tns' => ['required', 'array', 'max:200'],
            'tns.*' => ['string', 'max:20'],
            'rider_id' => ['required', Rule::exists('riders', 'id')->where('active', true)],
        ], ['tns.required' => 'Tick at least one parcel to assign.', 'rider_id.required' => 'Choose a rider first.']);
        if ($v->fails()) {
            return back()->with('bad', $v->errors()->first());
        }

        $rider = Rider::find($data['rider_id']);
        $done = 0;
        foreach (Parcel::whereIn('tracking_number', $data['tns'])->get() as $parcel) {
            $this->authorize('advanceTo', [$parcel, ParcelStatus::OutForDelivery]);
            $this->parcels->advance($parcel->id, ParcelStatus::OutForDelivery, $request->user(), "Rider: {$rider->name}", ['rider_id' => $rider->id]);
            $done++;
        }
        return back()->with('ok', "{$done} parcel" . ($done === 1 ? '' : 's') . " dispatched with {$rider->name}.");
    }

    public function deliver(Request $request, Parcel $parcel)
    {
        $this->authorize('advanceTo', [$parcel, ParcelStatus::Delivered]);
        $this->parcels->advance($parcel->id, ParcelStatus::Delivered, $request->user(), 'Confirmed by recipient');
        return back()->with('ok', "{$parcel->tracking_number} marked delivered.");
    }

    public function exception(Request $request, Parcel $parcel)
    {
        $this->authorize('advanceTo', [$parcel, ParcelStatus::Exception]);
        $data = $request->validate([
            'reason' => ['required', Rule::in(config('stallionking.exception_reasons'))],
            'detail' => ['nullable', 'string', 'max:300'],
        ]);
        if ($data['reason'] === 'Other (specify below)' && !trim((string) ($data['detail'] ?? ''))) {
            throw ValidationException::withMessages(['detail' => 'Describe the problem.']);
        }

        $note = $data['reason'] . (trim((string) ($data['detail'] ?? '')) !== '' ? ' — ' . trim($data['detail']) : '');
        $this->parcels->advance($parcel->id, ParcelStatus::Exception, $request->user(), $note);
        StaffAlert::admins('Delivery exception reported', "{$parcel->tracking_number} ({$parcel->receiver_name}) was flagged: {$data['reason']}", route('parcels.show', $parcel), $request->user());

        return back()->with('ok', "{$parcel->tracking_number} flagged as a delivery exception.");
    }

    public function storeRider(Request $request)
    {
        $request->merge(['phone' => ReceiverScanParser::normalisePhone((string) $request->input('phone')) ?: $request->input('phone')]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^0[789]\d{9}$/'],
            'vehicle' => ['required', Rule::in(config('stallionking.vehicles'))],
        ], ['phone.regex' => 'Enter a valid Nigerian mobile number, e.g. 0813 447 2201.']);

        $rider = Rider::create($data);
        ActivityLog::record($request->user(), 'rider_added', "Added rider {$rider->name}", 'rider', (string) $rider->id);
        return back()->with('ok', "Rider added: {$rider->name}");
    }

    public function toggleRider(Request $request, Rider $rider)
    {
        $rider->update(['active' => !$rider->active]);
        ActivityLog::record($request->user(), $rider->active ? 'rider_reactivated' : 'rider_deactivated', ($rider->active ? 'Reactivated' : 'Deactivated') . " rider {$rider->name}", 'rider', (string) $rider->id);
        return back()->with('ok', "{$rider->name} is now " . ($rider->active ? 'available' : 'unavailable') . ' for new deliveries.');
    }
}
