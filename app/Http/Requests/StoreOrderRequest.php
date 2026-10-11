<?php

namespace App\Http\Requests;

use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOrderRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'side' => ['required', Rule::in(['BUY', 'SELL'])],
            'type' => ['required', Rule::in(['LIMIT', 'MARKET'])],
            'quantity' => ['required', 'string', 'regex:/^\d+(\.\d{1,8})?$/', 'gt:0'],
            'limit_price' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,8})?$/', 'gt:0'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->input('type');
            $limitPrice = $this->input('limit_price');

            if ($type === 'LIMIT' && ($limitPrice === null || $limitPrice === '')) {
                $validator->errors()->add('limit_price', 'A limit price is required for LIMIT orders.');
            }

            if ($type === 'MARKET' && $limitPrice !== null && $limitPrice !== '') {
                $validator->errors()->add('limit_price', 'Do not provide a limit price for MARKET orders.');
            }

            $assetId = $this->input('asset_id');
            if (is_numeric($assetId)) {
                $asset = Asset::query()->find((int) $assetId);
                if ($asset && ! $asset->is_tradable) {
                    $validator->errors()->add('asset_id', 'The selected asset is not tradable.');
                }
                if ($asset && is_string($this->input('quantity')) && $this->has('quantity')) {
                    $precision = (int) $asset->quantity_precision;
                    $fraction = explode('.', $this->input('quantity'))[1] ?? '';
                    if (strlen(rtrim($fraction, '0')) > $precision) {
                        $validator->errors()->add('quantity', "Quantity supports at most {$precision} decimal places for this asset.");
                    }
                }
                if ($asset && $type === 'LIMIT' && is_string($limitPrice)) {
                    $precision = (int) $asset->price_precision;
                    $fraction = explode('.', $limitPrice)[1] ?? '';
                    if (strlen(rtrim($fraction, '0')) > $precision) {
                        $validator->errors()->add('limit_price', "Price supports at most {$precision} decimal places for this asset.");
                    }
                }
            }
        });
    }
}
