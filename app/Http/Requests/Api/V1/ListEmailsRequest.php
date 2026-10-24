<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\MessageStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListEmailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(MessageStatus::class)],
            'tag' => ['nullable', 'string', 'max:50'],
            'to' => ['nullable', 'email:rfc'],
            'created_after' => ['nullable', 'date'],
            'created_before' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ];
    }
}
