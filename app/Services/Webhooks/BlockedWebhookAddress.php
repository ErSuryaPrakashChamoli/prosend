<?php

namespace App\Services\Webhooks;

use RuntimeException;

/**
 * A webhook endpoint's address is one ProSend never connects to, such as a private or cloud metadata address.
 */
class BlockedWebhookAddress extends RuntimeException {}
