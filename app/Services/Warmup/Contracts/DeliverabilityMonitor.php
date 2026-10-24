<?php

namespace App\Services\Warmup\Contracts;

use App\Models\SendingDomain;

/**
 * Extension point for a legitimate third-party deliverability or inbox-placement monitoring service.
 *
 * ProSend ships no implementation: it never simulates opens, clicks, replies, or mailbox activity.
 * An implementation must report measurements the third party actually made, such as seed-list
 * placement tests, and return null when it has none.
 */
interface DeliverabilityMonitor
{
    public function name(): string;

    /**
     * Percentages of test messages that reached the inbox, spam folder, or neither, if measured.
     *
     * @return array{inbox: float, spam: float, missing: float, measured_at: \DateTimeInterface}|null
     */
    public function inboxPlacement(SendingDomain $domain): ?array;
}
