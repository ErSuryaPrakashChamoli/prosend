<?php

namespace App\Console\Commands;

use App\Enums\ProviderType;
use App\Enums\SmtpEncryption;
use App\Enums\UserRole;
use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use App\Models\User;
use App\Services\Templates\SampleTemplates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('prosend:setup {--skip-admin : Do not create an administrator}')]
#[Description('Create the first administrator, import the SMTP settings from .env, and add sample templates')]
class SetupProSend extends Command
{
    public function handle(): int
    {
        $this->importMailProvider();
        $this->installSampleTemplates();

        if (! $this->option('skip-admin')) {
            $this->createAdministrator();
        }

        $this->newLine();
        $this->components->info('Next: mark your sender as verified under Deliverability > Sender identities once your provider shows it as verified, and keep a queue worker running (php artisan queue:work).');

        return self::SUCCESS;
    }

    /**
     * Copy the .env SMTP settings into an encrypted provider record, once.
     */
    private function importMailProvider(): void
    {
        if (SmtpProvider::query()->exists()) {
            $this->components->twoColumnDetail('SMTP provider', '<fg=gray>already configured, skipped</>');

            return;
        }

        $config = config('mail.mailers.smtp');

        if (blank($config['host'] ?? null) || blank($config['username'] ?? null) || blank($config['password'] ?? null)) {
            $this->components->warn('MAIL_HOST, MAIL_USERNAME, and MAIL_PASSWORD are not all set, so no provider was imported. Add one in the panel instead.');

            return;
        }

        $type = $this->providerTypeFor((string) $config['host']);

        if ($type === null) {
            $this->components->warn("MAIL_HOST ({$config['host']}) is a Mailtrap host ProSend does not recognise, so it was not imported. Add the provider in the panel and choose Mailtrap Email Sending or Mailtrap Email Sandbox.");

            return;
        }

        $isBrevo = $type === ProviderType::Brevo;
        $port = (int) ($config['port'] ?? 587);

        $provider = SmtpProvider::query()->create([
            'name' => match ($type) {
                ProviderType::Brevo => 'Brevo',
                ProviderType::Mailtrap => 'Mailtrap',
                ProviderType::MailtrapSandbox => 'Mailtrap Sandbox',
                ProviderType::Smtp => 'SMTP',
            },
            'type' => $type,
            'host' => $config['host'],
            'port' => $port,
            'encryption' => ($config['scheme'] ?? null) === 'smtps' || $port === 465 ? SmtpEncryption::Tls : SmtpEncryption::StartTls,
            'username' => $config['username'],
            'password' => $config['password'],
            'daily_limit' => $isBrevo ? 300 : null,
        ]);

        $this->components->twoColumnDetail('SMTP provider', "<fg=green>imported {$provider->host}</>");

        $fromAddress = config('mail.from.address');

        if (filled($fromAddress) && $fromAddress !== 'hello@example.com' && ! SenderIdentity::query()->where('email', $fromAddress)->exists()) {
            $provider->senderIdentities()->create([
                'name' => config('mail.from.name'),
                'email' => $fromAddress,
            ]);

            $this->components->twoColumnDetail('Sender identity', "<fg=green>{$fromAddress}</> <fg=yellow>(not verified yet)</>");
        }

        if ($isBrevo) {
            $this->components->twoColumnDetail('Daily limit', '300 (Brevo free plan). Change it under Infrastructure > SMTP providers.');
        }

        if ($type === ProviderType::MailtrapSandbox) {
            $this->components->warn('This is a Mailtrap sandbox: email sent through it is captured for inspection and never delivered. Sandbox providers are refused in production unless PROSEND_ALLOW_SANDBOX_PROVIDERS=true.');
        } elseif ($type === ProviderType::Mailtrap) {
            $this->components->twoColumnDetail('Limits', 'Not set. Match them to your Mailtrap plan under Infrastructure > SMTP providers.');
        }
    }

    /**
     * Mailtrap's hosts decide whether it is the sandbox or live sending, so they are never imported as generic
     * SMTP. Null for any other Mailtrap host, which ProSend cannot place on either side.
     */
    private function providerTypeFor(string $host): ?ProviderType
    {
        $host = strtolower(trim($host));

        foreach ([ProviderType::MailtrapSandbox, ProviderType::Mailtrap] as $type) {
            if (in_array($host, $type->allowedHosts(), true)) {
                return $type;
            }
        }

        if (ProviderType::Smtp->hostProblem($host) !== null) {
            return null;
        }

        return str_contains($host, 'brevo.com') ? ProviderType::Brevo : ProviderType::Smtp;
    }

    private function installSampleTemplates(): void
    {
        $installed = app(SampleTemplates::class)->install();

        $this->components->twoColumnDetail('Sample templates', $installed > 0 ? "<fg=green>{$installed} added</>" : '<fg=gray>already installed, skipped</>');
    }

    private function createAdministrator(): void
    {
        if (User::query()->where('role', UserRole::Admin)->exists()) {
            $this->components->twoColumnDetail('Administrator', '<fg=gray>already exists, skipped</>');

            return;
        }

        if (! $this->input->isInteractive()) {
            $this->components->warn('No administrator exists. Run "php artisan prosend:setup" interactively to create one.');

            return;
        }

        $name = text('Administrator name', required: true);
        $email = text('Administrator email', required: true, validate: fn (string $value): ?string => Validator::make(
            ['email' => $value],
            ['email' => ['email', 'unique:users,email']],
        )->errors()->first('email') ?: null);
        $password = password('Password', required: true, validate: fn (string $value): ?string => Validator::make(
            ['password' => $value],
            ['password' => [Password::defaults()]],
        )->errors()->first('password') ?: null);

        User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => UserRole::Admin,
        ]);

        $this->components->twoColumnDetail('Administrator', "<fg=green>{$email}</>");
    }
}
