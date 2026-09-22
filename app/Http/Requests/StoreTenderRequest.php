<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTenderRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'region' => ['required', Rule::in(config('rfq.regions'))],
            'return_by' => ['required', 'date', 'after:today'],
            'document' => ['required', 'string', 'min:20', 'max:'.config('rfq.max_document_chars')],
        ];
    }
}
