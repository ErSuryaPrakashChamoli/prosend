<?php

namespace App\Notifications;

use App\Filament\Pages\Dashboard;
use App\Models\Client;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone they can now work in a client. A new account gets a link to choose its password; it expires like any
 * password reset link, after which "Forgot password" on the sign-in page sends a fresh one.
 */
class ClientInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Client $client,
        public string $inviterName,
        public string $roleName,
        public ?string $passwordToken = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $appName = config('app.name');
        $expiresInMinutes = (int) config('auth.passwords.users.expire', 60);

        $message = (new MailMessage)
            ->subject("You have been added to {$this->client->name} on {$appName}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->inviterName} added you to {$this->client->name} as {$this->roleName}.");

        if ($this->passwordToken !== null) {
            return $message
                ->line('Choose a password to sign in for the first time.')
                ->action('Choose your password', Filament::getPanel('admin')->getResetPasswordUrl($this->passwordToken, $notifiable))
                ->line("The link works for {$expiresInMinutes} minutes. After that, use \"Forgot password\" on the sign-in page to get a new one.")
                ->line('If you did not expect this, you can ignore this email.');
        }

        return $message
            ->action("Open {$this->client->name}", Dashboard::getUrl(panel: 'admin', tenant: $this->client))
            ->line('Sign in with your existing password.');
    }
}
