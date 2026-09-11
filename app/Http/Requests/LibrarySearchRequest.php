<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LibrarySearchRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'string', Rule::in(['name', 'year', 'rating', 'popularity'])],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'genre' => ['nullable', 'string', 'max:100'],
            'min_rating' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'year' => ['nullable', 'integer', 'min:1888', 'max:'.(date('Y') + 1)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
