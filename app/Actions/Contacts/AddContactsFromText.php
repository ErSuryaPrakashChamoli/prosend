<?php

namespace App\Actions\Contacts;

use App\Enums\ConsentStatus;
use App\Models\Contact;
use App\Models\ContactList;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Adds contacts from pasted text, such as a column copied from a spreadsheet or "Name <email>" lines.
 * Existing contacts keep their details and consent; they are only added to the chosen list.
 */
class AddContactsFromText
{
    public const MAX_ADDRESSES = 5000;

    /**
     * @return array{created: int, existing: int, invalid: list<string>, truncated: bool}
     */
    public function handle(string $text, ?ContactList $list = null, ConsentStatus $consent = ConsentStatus::Unknown, ?string $consentSource = null): array
    {
        preg_match_all('/[^\s<>,;"\'()\[\]]+@[^\s<>,;"\'()\[\]]+/u', $text, $matches);

        $candidates = collect($matches[0])
            ->map(fn (string $email): string => Str::lower(rtrim($email, '.')))
            ->unique()
            ->values();

        $truncated = $candidates->count() > self::MAX_ADDRESSES;
        $candidates = $candidates->take(self::MAX_ADDRESSES);

        [$valid, $invalid] = $candidates->partition(
            fn (string $email): bool => Validator::make(['email' => $email], ['email' => ['email:rfc,strict', 'max:255']])->passes(),
        );

        $existing = Contact::query()->whereIn('email', $valid->all())->pluck('email');
        $new = $valid->diff($existing)->values();

        $created = 0;

        foreach ($new->chunk(500) as $chunk) {
            $created += Contact::query()->fillAndInsertOrIgnore($chunk->map(fn (string $email): array => [
                'email' => $email,
                'source' => 'Pasted list',
                'consent_status' => $consent,
                'consent_source' => $consent === ConsentStatus::Granted ? $consentSource : null,
                'consented_at' => $consent === ConsentStatus::Granted ? now() : null,
            ])->values()->all());
        }

        if ($list !== null && $valid->isNotEmpty()) {
            $list->contacts()->syncWithoutDetaching(
                Contact::query()->whereIn('email', $valid->all())->pluck('id')->all(),
            );
        }

        return [
            'created' => $created,
            'existing' => $existing->count(),
            'invalid' => $invalid->values()->all(),
            'truncated' => $truncated,
        ];
    }
}
