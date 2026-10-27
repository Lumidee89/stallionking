<?php

namespace App\Services;

use App\Enums\ParcelStatus;
use App\Models\ActivityLog;
use App\Models\Parcel;
use App\Models\ParcelEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ParcelService
{
    public function __construct(private TrackingNumberGenerator $numbers) {}

    /**
     * Book a parcel. Safe to retry: the same idempotency key returns the
     * parcel created by the first attempt instead of creating a duplicate.
     */
    public function book(array $data, User $actor, ?string $idempotencyKey): Parcel
    {
        if ($idempotencyKey && ($existing = Parcel::where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }

        // A unique-constraint collision on the tracking number is retried;
        // a collision on the idempotency key means a parallel request won.
        for ($try = 0; $try < 3; $try++) {
            try {
                return DB::transaction(function () use ($data, $actor, $idempotencyKey) {
                    $parcel = Parcel::create($data + [
                        'tracking_number' => $this->numbers->generate(),
                        'idempotency_key' => $idempotencyKey,
                        'status' => ParcelStatus::Booked,
                        'booked_by' => $actor->id,
                    ]);
                    $this->log($parcel, ParcelStatus::Booked, $actor, 'Parcel booked');
                    ActivityLog::record($actor, 'parcel_booked', "Booked parcel {$parcel->tracking_number} for {$parcel->receiver_name}", 'parcel', $parcel->tracking_number);
                    return $parcel;
                });
            } catch (QueryException $e) {
                if ($idempotencyKey && ($existing = Parcel::where('idempotency_key', $idempotencyKey)->first())) {
                    return $existing;
                }
                if ($try === 2) {
                    throw $e;
                }
            }
        }
        throw new \LogicException('Unreachable');
    }

    /**
     * Move a parcel one legal step. Row-locked so two staff can't double-advance it.
     * $changes are extra columns saved in the same write (e.g. the assigned rider).
     */
    public function advance(int $parcelId, ParcelStatus $next, User $actor, ?string $note = null, array $changes = []): Parcel
    {
        return DB::transaction(function () use ($parcelId, $next, $actor, $note, $changes) {
            $parcel = Parcel::whereKey($parcelId)->lockForUpdate()->firstOrFail();
            $this->step($parcel, $next, $actor, $note, $changes);
            return $parcel->refresh();
        });
    }

    /**
     * "Received in-store": walks a parcel through every remaining warehouse step
     * (received, packaged, scanned) in one go, recording each step in its history.
     */
    public function receiveInStore(int $parcelId, User $actor): Parcel
    {
        return DB::transaction(function () use ($parcelId, $actor) {
            $parcel = Parcel::whereKey($parcelId)->lockForUpdate()->firstOrFail();
            if (!in_array($parcel->status, [ParcelStatus::Booked, ParcelStatus::Received, ParcelStatus::Packaged], true)) {
                throw ValidationException::withMessages(['status' => "{$parcel->tracking_number} is already past the warehouse."]);
            }
            while ($parcel->status !== ParcelStatus::Scanned) {
                $this->step($parcel, $parcel->status->allowedNext()[0], $actor, null, [], false);
            }
            ActivityLog::record($actor, 'parcel_received_in_store', "Marked {$parcel->tracking_number} received in-store — ready for dispatch", 'parcel', $parcel->tracking_number);
            return $parcel->refresh();
        });
    }

    private function step(Parcel $parcel, ParcelStatus $next, User $actor, ?string $note, array $changes, bool $logActivity = true): void
    {
        if (!$parcel->status->canMoveTo($next)) {
            throw ValidationException::withMessages([
                'status' => "A parcel that is \"{$parcel->status->label()}\" cannot move to \"{$next->label()}\".",
            ]);
        }
        if ($next === ParcelStatus::Booked) {
            $changes['rider_id'] = null; // a re-booked parcel goes back to the warehouse without a rider
            $note ??= 'Re-booked after a delivery exception — ready for warehouse processing';
        }

        $parcel->update(['status' => $next] + $changes);
        $this->log($parcel, $next, $actor, $note);

        if ($logActivity) {
            $action = $next === ParcelStatus::Booked ? 'parcel_rebooked' : 'parcel_' . $next->value;
            $summary = $next === ParcelStatus::Booked
                ? "Re-booked {$parcel->tracking_number} after a delivery exception"
                : "Marked {$parcel->tracking_number} as \"{$next->label()}\"" . ($note ? " — {$note}" : '');
            ActivityLog::record($actor, $action, $summary, 'parcel', $parcel->tracking_number);
        }
    }

    private function log(Parcel $parcel, ParcelStatus $status, User $actor, ?string $note): void
    {
        ParcelEvent::create([
            'parcel_id' => $parcel->id,
            'status' => $status,
            'actor_id' => $actor->id,
            'actor_role' => $actor->role->value,
            'note' => $note === null ? null : mb_substr($note, 0, 500),
        ]);
    }
}
