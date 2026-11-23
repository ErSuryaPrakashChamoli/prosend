<?php

namespace Database\Factories;

use App\Enums\SmtpEncryption;
use App\Models\InboundMailbox;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboundMailbox>
 */
class InboundMailboxFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = fake()->unique()->userName().'@acme.test';

        return [
            'name' => 'Sales inbox',
            'email' => $email,
            'host' => 'imap.acme.test',
            'port' => 993,
            'encryption' => SmtpEncryption::Tls,
            'username' => $email,
            'password' => 'secret-password',
            'folder' => 'INBOX',
        ];
    }
}
