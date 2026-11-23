<?php

namespace App\Actions\Contacts;

use App\Enums\ConsentStatus;
use App\Enums\ContactStage;
use App\Models\Contact;
use Illuminate\Support\Str;

/**
 * Creates or updates a prospect by email, for the API (CRMs, Zapier-style tools, Google Sheets add-ons). Only the
 * fields sent are changed; tags are added, never removed. It never clears an unsubscribe or a suppression, and
 * granting consent requires saying where it came from.
 */
class UpsertProspect
{
    /**
     * @param  array<string, mixed>  $data  Validated by UpsertProspectRequest.
     * @return array{0: Contact, 1: bool} The prospect, and whether it was created.
     */
    public function handle(array $data, string $source): array
    {
        $email = Str::lower(trim((string) $data['email']));
        $contact = Contact::query()->where('email', $email)->first() ?? new Contact(['email' => $email, 'source' => $source]);
        $created = ! $contact->exists;

        $contact->fill(collect($data)->only(['first_name', 'last_name', 'company', 'job_title', 'industry', 'website', 'phone', 'timezone', 'notes'])->all());

        if (array_key_exists('stage', $data)) {
            $contact->stage = ContactStage::from($data['stage']);
        }

        if (filled($data['tags'] ?? null)) {
            $contact->tags = array_values(array_unique([...($contact->tags ?? []), ...array_map(trim(...), $data['tags'])]));
        }

        if (filled($data['custom_attributes'] ?? null)) {
            $contact->custom_attributes = [...($contact->custom_attributes ?? []), ...$data['custom_attributes']];
        }

        if (array_key_exists('consent_status', $data)) {
            $status = ConsentStatus::from($data['consent_status']);

            if ($status !== $contact->consent_status) {
                $contact->consent_status = $status;
                $contact->consent_source = $status === ConsentStatus::Granted ? $data['consent_source'] : $contact->consent_source;
                $contact->consented_at = $status === ConsentStatus::Granted ? now() : $contact->consented_at;
            }
        }

        $contact->save();

        if (filled($data['list_ids'] ?? null)) {
            $contact->lists()->syncWithoutDetaching($data['list_ids']);
        }

        return [$contact, $created];
    }
}
