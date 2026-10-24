<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TemplateCategory: string implements HasLabel
{
    case Welcome = 'welcome';
    case EmailVerification = 'email_verification';
    case PasswordReset = 'password_reset';
    case AccountNotification = 'account_notification';
    case OrderUpdate = 'order_update';
    case Newsletter = 'newsletter';
    case Announcement = 'announcement';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Welcome => 'Welcome',
            self::EmailVerification => 'Email verification',
            self::PasswordReset => 'Password reset',
            self::AccountNotification => 'Account notification',
            self::OrderUpdate => 'Order or application update',
            self::Newsletter => 'Newsletter',
            self::Announcement => 'Announcement',
            self::Other => 'Other',
        };
    }

    public function purpose(): EmailPurpose
    {
        return match ($this) {
            self::Newsletter, self::Announcement => EmailPurpose::Marketing,
            default => EmailPurpose::Transactional,
        };
    }
}
