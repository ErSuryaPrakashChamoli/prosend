<?php

namespace App\Services\Providers\OAuth;

use RuntimeException;

/**
 * The connected account no longer lets ProSend in; someone has to connect it again.
 */
class OAuthRevoked extends RuntimeException {}
