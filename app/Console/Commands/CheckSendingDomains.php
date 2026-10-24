<?php

namespace App\Console\Commands;

use App\Models\SendingDomain;
use App\Services\Deliverability\DomainAuthenticator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('prosend:check-domains')]
#[Description('Re-check SPF, DKIM, and DMARC for every sending domain, and their status with the provider')]
class CheckSendingDomains extends Command
{
    public function handle(DomainAuthenticator $authenticator): int
    {
        SendingDomain::query()->with('smtpProvider')->each(function (SendingDomain $domain) use ($authenticator): void {
            $problem = $authenticator->check($domain);

            $this->components->twoColumnDetail($domain->domain, $domain->isReady() ? '<fg=green>ready</>' : '<fg=yellow>'.($problem ?? 'not ready').'</>');
        });

        return self::SUCCESS;
    }
}
