<?php

namespace App\Http\Requests\Collection;

use App\Enums\CollectionFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class CollectionItemRequest extends FormRequest
{
    /**
     * @return array<string, array<mixed>|string>
     */
    public static function rules(): array
    {
        return [
            'format' => ['required', new Enum(CollectionFormat::class)],
            'season_id' => ['nullable', 'exists:seasons,id'],
            'edition' => ['nullable', 'string'],
            'retailer' => ['nullable', 'string'],
            'barcode' => ['nullable', 'string', 'max:32'],
            'acquired_at' => ['nullable', 'date'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'location' => ['nullable', 'string'],
            'loaned_to' => ['nullable', 'string'],
            'loaned_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
