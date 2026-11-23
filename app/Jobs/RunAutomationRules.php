<?php

namespace App\Jobs;

use App\Enums\OutreachEvent;
use App\Models\Client;
use App\Models\Contact;
use App\Services\Automation\AutomationEngine;
use App\Services\Tenancy\CurrentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs a client's automation rules for one event, as that client. Rules of a deactivated client wait for nothing and
 * are skipped.
 */
class RunAutomationRules implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public OutreachEvent $event,
        public int $clientId,
        public ?int $contactId,
        public array $data,
        public string $occurrence,
        public int $depth,
    ) {
        $this->onQueue(config('prosend.automation.queue'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(AutomationEngine $engine, CurrentClient $currentClient): void
    {
        $client = Client::query()->find($this->clientId);

        if ($client === null || ! $client->is_active) {
            return;
        }

        $currentClient->runAs($client, function () use ($engine): void {
            $contact = $this->contactId === null ? null : Contact::query()->find($this->contactId);

            if ($this->contactId !== null && $contact === null) {
                return;
            }

            $engine->handle($this->event, $this->clientId, $contact, $this->data, $this->occurrence, $this->depth);
        });
    }
}
