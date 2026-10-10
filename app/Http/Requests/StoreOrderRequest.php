<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'asset_id' => [
                'required',
                'integer',
                'exists:assets,id',
            ],

            'side' => [
                'required',
                Rule::in(['BUY', 'SELL']),
            ],

            'type' => [
                'required',
                Rule::in(['LIMIT', 'MARKET']),
            ],

            'quantity' => [
                'required',
                'string',
                'regex:/^\d+(\.\d{1,8})?$/',
                'gt:0',
            ],

            'limit_price' => [
                'nullable',
                'string',
                'regex:/^\d+(\.\d{1,8})?$/',
                'gt:0',
            ],

            'idempotency_key' => [
                'required',
                'string',
                'max:255',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $type = $this->input('type');
            $limitPrice = $this->input('limit_price');

            if ($type === 'LIMIT' && $limitPrice === null) {
                $validator->errors()->add(
                    'limit_price',
                    'A limit price is required for LIMIT orders.'
                );
            }

            if ($type === 'MARKET' && $limitPrice !== null) {
                $validator->errors()->add(
                    'limit_price',
                    'Do not provide a limit price for MARKET orders.'
                );
            }
        });
    }
}
