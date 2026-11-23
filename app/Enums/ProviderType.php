<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;

enum ProviderType: string implements HasColor, HasLabel
{
    case Brevo = 'brevo';
    case Smtp = 'smtp';
    case Mailtrap = 'mailtrap';
    case MailtrapSandbox = 'mailtrap_sandbox';

    public function getLabel(): string
    {
        return match ($this) {
            self::Brevo => 'Brevo',
            self::Smtp => 'Generic SMTP',
            self::Mailtrap => 'Mailtrap Email Sending',
            self::MailtrapSandbox => 'Mailtrap Email Sandbox (testing only)',
        };
    }

    public function getColor(): string
    {
        return $this->isSandbox() ? 'warning' : 'gray';
    }

    /**
     * A sandbox captures email for inspection and never delivers it to the recipient.
     */
    public function isSandbox(): bool
    {
        return $this === self::MailtrapSandbox;
    }

    public function defaultHost(): ?string
    {
        return match ($this) {
            self::Brevo => 'smtp-relay.brevo.com',
            self::Smtp => null,
            self::Mailtrap => 'live.smtp.mailtrap.io',
            self::MailtrapSandbox => 'sandbox.smtp.mailtrap.io',
        };
    }

    public function defaultPort(): int
    {
        return $this === self::MailtrapSandbox ? 2525 : 587;
    }

    /**
     * Mailtrap Email Sending always signs in as "api", with an API token as the password.
     */
    public function defaultUsername(): ?string
    {
        return $this === self::Mailtrap ? 'api' : null;
    }

    /**
     * The only hosts this type may connect to, or null when any host is allowed.
     *
     * @return list<string>|null
     */
    public function allowedHosts(): ?array
    {
        return match ($this) {
            self::Mailtrap => ['live.smtp.mailtrap.io', 'bulk.smtp.mailtrap.io'],
            self::MailtrapSandbox => ['sandbox.smtp.mailtrap.io'],
            default => null,
        };
    }

    /**
     * Why the host does not fit this type. Mailtrap hosts must use a Mailtrap type, so a sandbox can never
     * pass for a live service, and a live Mailtrap host can never be labelled as a sandbox.
     */
    public function hostProblem(string $host): ?string
    {
        $host = rtrim(Str::lower(trim($host)), '.');
        $allowedHosts = $this->allowedHosts();

        if ($allowedHosts !== null) {
            return in_array($host, $allowedHosts, true)
                ? null
                : "{$this->getLabel()} uses ".implode(' or ', $allowedHosts).'.';
        }

        return $host === 'mailtrap.io' || str_ends_with($host, '.mailtrap.io')
            ? 'Choose a Mailtrap provider type for Mailtrap hosts, so sandbox and live sending are kept apart.'
            : null;
    }

    /**
     * Types an existing provider may switch to. A provider can never move between sandbox and live sending.
     *
     * @return list<self>
     */
    public function compatibleTypes(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type->isSandbox() === $this->isSandbox()));
    }
}
