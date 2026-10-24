<?php

namespace App\Services\Sending;

use App\Models\Contact;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Knows which variables content uses and where each value comes from. Built-in variables map to
 * contact fields; any other variable is read from the contact's custom attributes.
 */
class TemplateVariables
{
    /**
     * Built-in variables that come from a contact column.
     *
     * @var array<string, string>
     */
    public const CONTACT_COLUMNS = [
        'first_name' => 'first_name',
        'last_name' => 'last_name',
        'company_name' => 'company',
    ];

    /**
     * Built-in variables that always have a value.
     *
     * @var list<string>
     */
    public const ALWAYS_AVAILABLE = ['email', 'unsubscribe_url'];

    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,49}$/';

    /**
     * Every variable used in the given content, in order of first use.
     *
     * @param  string|array<string, mixed>|null  ...$contents
     * @return list<string>
     */
    public function extract(string|array|null ...$contents): array
    {
        $variables = [];

        foreach ($contents as $content) {
            if (blank($content)) {
                continue;
            }

            $html = is_array($content) ? RichContentRenderer::make($content)->toUnsafeHtml() : $content;

            preg_match_all('/\{\{\s*([a-z][a-z0-9_]{0,49})\s*\}\}|data-id="([a-z][a-z0-9_]{0,49})"/', $html, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $variables[] = $match[2] ?? '' ?: $match[1];
            }
        }

        return array_values(array_unique(array_filter($variables)));
    }

    /**
     * Variables that are neither built in nor standard attribute keys, such as typos.
     *
     * @param  list<string>  $variables
     * @return list<string>
     */
    public function invalid(array $variables): array
    {
        return array_values(array_filter($variables, fn (string $variable): bool => preg_match(self::KEY_PATTERN, $variable) !== 1));
    }

    /**
     * @param  list<string>  $variables
     * @return list<string>
     */
    public function customAttributes(array $variables): array
    {
        return array_values(array_filter(
            $variables,
            fn (string $variable): bool => ! array_key_exists($variable, self::CONTACT_COLUMNS) && ! in_array($variable, self::ALWAYS_AVAILABLE, true),
        ));
    }

    /**
     * The required variables the contact has no value for.
     *
     * @param  list<string>  $required
     * @return list<string>
     */
    public function missingFor(?Contact $contact, array $required): array
    {
        return array_values(array_filter($required, function (string $variable) use ($contact): bool {
            if (in_array($variable, self::ALWAYS_AVAILABLE, true)) {
                return false;
            }

            $value = array_key_exists($variable, self::CONTACT_COLUMNS)
                ? $contact?->{self::CONTACT_COLUMNS[$variable]}
                : ($contact?->custom_attributes[$variable] ?? null);

            return blank($value);
        }));
    }

    /**
     * Limit contacts to those with a value for every required variable.
     *
     * @param  Builder<Contact>  $contacts
     * @param  list<string>  $required
     * @return Builder<Contact>
     */
    public function constrain(Builder $contacts, array $required): Builder
    {
        foreach ($required as $variable) {
            if (in_array($variable, self::ALWAYS_AVAILABLE, true)) {
                continue;
            }

            // A name that can never hold a value excludes everyone, matching missingFor().
            if (preg_match(self::KEY_PATTERN, $variable) !== 1) {
                return $contacts->whereRaw('1 = 0');
            }

            $column = array_key_exists($variable, self::CONTACT_COLUMNS)
                ? self::CONTACT_COLUMNS[$variable]
                : "custom_attributes->{$variable}";

            $contacts->whereNotNull($column)->where($column, '!=', '');
        }

        return $contacts;
    }
}
