<?php

namespace App\Http\Requests;

use App\Models\Parcel;
use App\Support\ReceiverScanParser;
use Illuminate\Foundation\Http\FormRequest;

class ParseScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Parcel::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:' . ReceiverScanParser::MAX_INPUT],
            'source' => ['required', 'in:qr,ocr'],
        ];
    }
}
