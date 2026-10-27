<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Support\ReceiverScanParser;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index()
    {
        return view('clients.index', ['clients' => Client::withCount('parcels')->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $request->merge(['phone' => ReceiverScanParser::normalisePhone((string) $request->input('phone')) ?: $request->input('phone')]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^0[789]\d{9}$/'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'city' => ['required', 'string', 'max:80'],
        ], ['phone.regex' => 'Enter a valid Nigerian mobile number, e.g. 0813 447 2201.']);

        $exists = Client::where('phone', $data['phone'])->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])->first();
        if ($exists) {
            $msg = "{$data['name']} is already a saved client.";
            return $request->expectsJson() ? response()->json(['message' => $msg], 409) : back()->with('bad', $msg);
        }

        $client = Client::create($data + ['created_by' => $request->user()->id]);
        ActivityLog::record($request->user(), 'client_added', "Added client {$client->name}", 'client', (string) $client->id);

        return $request->expectsJson()
            ? response()->json(['id' => $client->id, 'name' => $client->name], 201)
            : back()->with('ok', "Client saved: {$client->name}");
    }

    public function destroy(Request $request, Client $client)
    {
        $client->delete(); // booked parcels keep their sender details (client_id is nulled)
        ActivityLog::record($request->user(), 'client_deleted', "Removed client {$client->name}", 'client', (string) $client->id);
        return back()->with('ok', "Removed {$client->name}.");
    }
}
