<?php

namespace App\Http\Requests;

use App\Models\Parcel;
use App\Support\ReceiverScanParser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreParcelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Parcel::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Normalise phone numbers server-side whatever the client sent.
        $phone = ReceiverScanParser::normalisePhone((string) $this->input('receiver_phone'));
        $sender = (array) $this->input('sender', []);
        $senderPhone = ReceiverScanParser::normalisePhone((string) ($sender['phone'] ?? ''));
        $this->merge([
            'receiver_phone' => $phone ?: $this->input('receiver_phone'),
            'sender' => array_merge($sender, ['phone' => $senderPhone ?: ($sender['phone'] ?? '')]),
            'service_type' => $this->input('service_type') ?: 'Standard',
        ]);
    }

    public function rules(): array
    {
        return [
            'sender.name' => ['required', 'string', 'max:120'],
            'sender.phone' => ['required', 'regex:/^0[789]\d{9}$/'],
            'sender.email' => ['nullable', 'email:rfc', 'max:190'],
            'sender.city' => ['nullable', 'string', 'max:80'],
            'receiver_name' => ['required', 'string', 'max:120'],
            'receiver_phone' => ['required', 'regex:/^0[789]\d{9}$/'],
            'receiver_email' => ['nullable', 'email:rfc', 'max:190'],
            'receiver_address' => ['required', 'string', 'max:500'],
            'receiver_city' => ['required', 'string', 'max:80'],
            'receiver_state' => ['nullable', 'string', 'max:40'],
            'receiver_landmark' => ['nullable', 'string', 'max:150'],
            'weight_range' => ['required', Rule::in(array_keys(Parcel::WEIGHTS))],
            'description' => ['required', 'string', 'max:200'],
            'service_type' => ['required', Rule::in(Parcel::SERVICES)],
            'receiver_source' => ['required', 'in:manual,qr,ocr'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ];
    }

    public function messages(): array
    {
        return [
            '*.phone.regex' => 'Enter a valid Nigerian mobile number, e.g. 0813 447 2201.',
            'receiver_phone.regex' => 'Enter a valid Nigerian mobile number, e.g. 0813 447 2201.',
            'description.required' => 'Describe what is in the parcel, e.g. Documents.',
        ];
    }
}
