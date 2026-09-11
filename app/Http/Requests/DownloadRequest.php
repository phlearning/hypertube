<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DownloadRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:500'],
            'candidates' => ['required', 'array', 'min:1', 'max:5'],
            'candidates.*.source' => ['required', 'string', 'max:100'],
            'candidates.*.source_id' => ['required', 'string', 'max:255'],
            'candidates.*.torrent_url' => ['required', 'url', 'max:2048'],
        ];
    }
}
