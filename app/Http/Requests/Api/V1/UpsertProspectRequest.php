<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ConsentStatus;
use App\Enums\ContactStage;
use App\Models\ContactList;
use App\Services\Sending\TemplateVariables;
use App\Services\Tenancy\CurrentClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertProspectRequest extends FormRequest
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
            'email' => ['required', 'email:rfc', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'timezone' => ['nullable', 'timezone:all'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'stage' => ['sometimes', Rule::enum(ContactStage::class)],
            'tags' => ['nullable', 'array', 'max:50'],
            'tags.*' => ['string', 'max:50'],
            'custom_attributes' => ['nullable', 'array', 'max:50'],
            'custom_attributes.*' => ['nullable', 'scalar', 'max:1000'],
            'consent_status' => ['sometimes', Rule::enum(ConsentStatus::class)],
            'consent_source' => ['required_if:consent_status,'.ConsentStatus::Granted->value, 'nullable', 'string', 'max:255'],
            'list_ids' => ['nullable', 'array', 'max:50'],
            'list_ids.*' => ['integer', Rule::exists(ContactList::class, 'id')->where('client_id', app(CurrentClient::class)->id())],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            foreach (array_keys((array) $this->input('custom_attributes', [])) as $key) {
                if (! is_string($key) || preg_match(TemplateVariables::KEY_PATTERN, $key) !== 1) {
                    $validator->errors()->add('custom_attributes', "\"{$key}\" is not a valid attribute name: use lower-case letters, digits and underscores.");
                }
            }
        }];
    }
}
