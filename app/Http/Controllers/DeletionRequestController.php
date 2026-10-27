<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\DeletionRequest;
use App\Models\Parcel;
use App\Notifications\StaffAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Any staff member can ask for a parcel to be deleted; only an admin or superadmin can approve it. */
class DeletionRequestController extends Controller
{
    public function store(Request $request, Parcel $parcel)
    {
        $this->authorize('view', $parcel);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Describe why this parcel should be deleted.']);

        if (DeletionRequest::where('parcel_id', $parcel->id)->where('status', 'pending')->exists()) {
            return back()->with('bad', 'A deletion request for this parcel is already waiting for approval.');
        }

        DeletionRequest::create([
            'parcel_id' => $parcel->id,
            'tracking_number' => $parcel->tracking_number,
            'reason' => $data['reason'],
            'requested_by' => $request->user()->id,
        ]);
        ActivityLog::record($request->user(), 'deletion_requested', "Requested deletion of {$parcel->tracking_number}: {$data['reason']}", 'parcel', $parcel->tracking_number);
        StaffAlert::admins('Deletion request submitted', "{$request->user()->name} requested deletion of {$parcel->tracking_number} ({$parcel->receiver_name}): {$data['reason']}", route('parcels.index'), $request->user());

        return back()->with('ok', 'Deletion request sent to an admin for approval.');
    }

    public function approve(Request $request, DeletionRequest $deletion)
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_if($deletion->status !== 'pending', 422, 'This request has already been handled.');

        DB::transaction(function () use ($request, $deletion) {
            $deletion->update(['status' => 'approved', 'resolved_by' => $request->user()->id, 'resolved_at' => now()]);
            $deletion->parcel?->delete(); // its history goes with it (database cascade)
        });
        ActivityLog::record($request->user(), 'deletion_approved', "Approved deletion of {$deletion->tracking_number} — record permanently removed", 'parcel', $deletion->tracking_number);
        $deletion->requester?->notify(new StaffAlert('Deletion request approved', "{$deletion->tracking_number} was permanently deleted by {$request->user()->name}."));

        return back()->with('ok', "{$deletion->tracking_number} deleted.");
    }

    public function reject(Request $request, DeletionRequest $deletion)
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_if($deletion->status !== 'pending', 422, 'This request has already been handled.');
        $note = trim((string) ($request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? ''));

        $deletion->update(['status' => 'rejected', 'resolved_by' => $request->user()->id, 'resolved_at' => now(), 'resolution_note' => $note ?: null]);
        ActivityLog::record($request->user(), 'deletion_declined', "Declined the deletion request for {$deletion->tracking_number}" . ($note ? ": {$note}" : ''), 'parcel', $deletion->tracking_number);
        $deletion->requester?->notify(new StaffAlert('Deletion request declined', "{$request->user()->name} declined your request to delete {$deletion->tracking_number}" . ($note ? ": {$note}" : '.'), $deletion->parcel ? route('parcels.show', $deletion->parcel) : null));

        return back()->with('ok', 'Deletion request declined.');
    }
}
