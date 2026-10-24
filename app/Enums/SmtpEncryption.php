<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SmtpEncryption: string implements HasLabel
{
    case StartTls = 'starttls';
    case Tls = 'tls';
    case None = 'none';

    public function getLabel(): string
    {
        return match ($this) {
            self::StartTls => 'STARTTLS (usually port 587)',
            self::Tls => 'Implicit TLS (usually port 465)',
            self::None => 'None (not recommended)',
        };
    }

    /**
     * The transport options Laravel's SMTP mailer understands for this mode.
     *
     * @return array{scheme: string, auto_tls: bool, require_tls: bool}
     */
    public function transportOptions(): array
    {
        return match ($this) {
            self::StartTls => ['scheme' => 'smtp', 'auto_tls' => true, 'require_tls' => true],
            self::Tls => ['scheme' => 'smtps', 'auto_tls' => true, 'require_tls' => false],
            self::None => ['scheme' => 'smtp', 'auto_tls' => false, 'require_tls' => false],
        };
    }
}
