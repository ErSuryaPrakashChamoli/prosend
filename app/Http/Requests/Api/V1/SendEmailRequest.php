<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\EmailPurpose;
use App\Services\Sending\TemplateVariables;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The shape of a send request. Business rules (suppression, consent, sender, template) are checked by
 * the same code the panel's Compose page uses.
 */
class SendEmailRequest extends FormRequest
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
            'from' => ['required', 'email:rfc', 'max:255'],
            'to' => ['required', 'array', 'min:1', 'max:'.config('prosend.compose.max_recipients')],
            'to.*' => ['array:email,name'],
            'to.*.email' => ['required', 'email:rfc', 'max:255'],
            'to.*.name' => ['nullable', 'string', 'max:255'],
            'cc' => ['array', 'max:10'],
            'cc.*' => ['email:rfc', 'max:255'],
            'bcc' => ['array', 'max:10'],
            'bcc.*' => ['email:rfc', 'max:255'],
            'reply_to' => ['nullable', 'email:rfc', 'max:255'],
            'purpose' => ['required', Rule::enum(EmailPurpose::class)],
            'template_id' => ['nullable', 'integer'],
            'subject' => ['required_without:template_id', 'nullable', 'string', 'max:255'],
            'html' => ['required_without:template_id', 'nullable', 'string', 'max:500000'],
            'text' => ['nullable', 'string', 'max:500000'],
            'variables' => ['array', 'max:50'],
            'variables.*' => ['nullable', static::scalarUpTo(2000)],
            'tags' => ['array', 'max:10'],
            'tags.*' => ['string', 'regex:/^[a-z0-9_-]{1,50}$/'],
            'metadata' => ['array', 'max:20'],
            'metadata.*' => ['nullable', static::scalarUpTo(500)],
            'scheduled_at' => ['nullable', 'date', 'after:now', 'before:+30 days'],
            'attachments' => ['array', 'max:'.config('prosend.attachments.max_files')],
            'attachments.*' => ['array:filename,content,content_type'],
            'attachments.*.filename' => ['required', 'string', 'max:255', 'regex:/^[^\/\\\\]+$/'],
            'attachments.*.content' => ['required', 'string'],
            'attachments.*.content_type' => ['required', 'string', Rule::in(config('prosend.attachments.accepted_mime_types'))],
        ];
    }

    /**
     * A plain value (text, number or boolean) of at most the given number of characters.
     */
    private static function scalarUpTo(int $characters): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($characters): void {
            if (! is_scalar($value)) {
                $fail('Must be text, a number or true/false.');
            } elseif (mb_strlen((string) $value) > $characters) {
                $fail("Must be at most {$characters} characters.");
            }
        };
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_keys((array) $this->input('variables', [])) as $name) {
                    if (preg_match(TemplateVariables::KEY_PATTERN, (string) $name) !== 1) {
                        $validator->errors()->add('variables', "Variable names may only use lowercase letters, numbers and underscores: {$name}.");
                    }
                }

                foreach (array_keys((array) $this->input('metadata', [])) as $name) {
                    if (preg_match('/^[A-Za-z0-9_.-]{1,40}$/', (string) $name) !== 1) {
                        $validator->errors()->add('metadata', "Metadata keys may only use letters, numbers, dots, dashes and underscores: {$name}.");
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.max' => 'Send to at most :max recipients per request.',
            'attachments.*.content_type.in' => 'This attachment type is not allowed.',
            'scheduled_at.before' => 'Emails can be scheduled at most 30 days ahead.',
        ];
    }
}
