<?php

declare(strict_types=1);

namespace App\Http\Requests;

use FitOut\Ingestion\DocumentText;
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
            'document' => ['required_without:file', 'prohibits:file', 'string', 'min:20', 'max:'.config('rfq.max_document_chars')],
            // Converted to text in the controller; the same length limit applies to the result.
            'file' => ['required_without:document', 'file', 'extensions:'.implode(',', DocumentText::FORMATS), 'max:'.config('rfq.max_upload_kb')],
        ];
    }
}
