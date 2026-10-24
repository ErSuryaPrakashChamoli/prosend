<?php

namespace App\Services\Sending;

final readonly class RenderedEmail
{
    public function __construct(
        public string $subject,
        public string $html,
        public string $text,
        public ?string $unsubscribeUrl = null,
    ) {}
}
