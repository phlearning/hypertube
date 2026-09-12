<?php

namespace App\Http\Requests\Admin;

use App\Enums\TorrentJobClearScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClearTorrentJobsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::enum(TorrentJobClearScope::class)],
            'delete_files' => ['required', 'boolean'],
        ];
    }
}
