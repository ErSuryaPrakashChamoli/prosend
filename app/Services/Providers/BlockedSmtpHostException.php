<?php

namespace App\Services\Providers;

use Symfony\Component\Mailer\Exception\TransportException;

/**
 * The SMTP host resolves to an address ProSend may not connect to. Thrown before any connection is made,
 * so it is handled like any other transport failure and never sends a message.
 */
class BlockedSmtpHostException extends TransportException {}
