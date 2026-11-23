<?php

namespace App\Services\Inbox;

/**
 * What became of one email read from an inbound mailbox.
 */
enum InboundOutcome: string
{
    case Reply = 'reply';
    case AutoReply = 'auto_reply';
    case Bounce = 'bounce';
    case Duplicate = 'duplicate';
    case Ignored = 'ignored';
    case Unreadable = 'unreadable';
}
