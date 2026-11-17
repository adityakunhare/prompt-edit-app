<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('idempotency_key') && $this->header('Idempotency-Key') !== null) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:255'],
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['sometimes', 'string', Rule::in(['1024x1024', '1536x1024', '1024x1536'])],
            'quality' => ['sometimes', 'string', Rule::in(['low', 'medium', 'high'])],
            'reference_images' => ['sometimes', 'array', 'list', 'max:4'],
            'reference_images.*' => ['required', 'string', 'url:http,https'],
            'metadata' => [
                'sometimes',
                'array',
                'max:20',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== [] && array_is_list($value)) {
                        $fail('The metadata field must be an object of key-value pairs.');
                    }
                },
            ],
            'metadata.*' => ['string', 'max:500'],
        ];
    }
}
