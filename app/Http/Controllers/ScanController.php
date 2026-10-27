<?php

namespace App\Http\Controllers;

use App\Http\Requests\ParseScanRequest;
use App\Support\ReceiverScanParser;

class ScanController extends Controller
{
    /**
     * Receives only TEXT (decoded QR payload or OCR output). Images never leave
     * the browser, which keeps uploads small and avoids storing customer photos.
     */
    public function parse(ParseScanRequest $request)
    {
        $result = ReceiverScanParser::parse($request->validated('text'));

        if ($result['found'] === 0) {
            return response()->json([
                'message' => 'We could not find any receiver details in that scan. Try again with better lighting or type the details in.',
            ], 422);
        }
        return response()->json($result);
    }
}
