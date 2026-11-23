<?php

namespace App\Filament\Resources\SmtpProviders;

use App\Actions\Messages\SendTestEmail;
use App\Enums\MessageStatus;
use App\Enums\ProviderType;
use App\Filament\Resources\EmailMessages\EmailMessageResource;
use App\Models\SmtpProvider;
use App\Services\AuditLogger;
use App\Services\Providers\MailProviderManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Provider actions shared by the table and the provider pages.
 */
class SmtpProviderActions
{
    /**
     * Connects and signs in without sending anything. Success proves the settings work, not that email
     * will be delivered.
     */
    public static function testConnection(): Action
    {
        return Action::make('testConnection')
            ->label('Test connection')
            ->icon(Heroicon::OutlinedSignal)
            ->action(function (SmtpProvider $record, MailProviderManager $providers, AuditLogger $auditLogger): void {
                $driver = $providers->driver($record);

                try {
                    $driver->testConnection($record);
                } catch (TransportExceptionInterface $exception) {
                    $summary = $driver->classifyFailure($exception, $record)->summary;
                    $record->recordConnectionFailure($summary);
                    $auditLogger->record('provider.connection_tested', $record, ['result' => 'failed', 'error' => $record->last_error]);

                    Notification::make()->danger()->title('Connection failed')->body($summary)->persistent()->send();

                    return;
                }

                $record->recordConnectionSuccess();
                $auditLogger->record('provider.connection_tested', $record, ['result' => 'connected']);

                Notification::make()
                    ->success()
                    ->title(filled($record->username) ? "Connected and signed in to {$record->host}" : "Connected to {$record->host} (no login configured)")
                    ->body('No email was sent. Send a test email to check the whole sending path.')
                    ->send();
            });
    }

    public static function sendTestEmail(): Action
    {
        return Action::make('sendTestEmail')
            ->label('Send test email')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->modalDescription(fn (SmtpProvider $record): string => $record->isSandbox()
                ? 'Sends a short test email through this sandbox. Mailtrap captures it for inspection; it is not delivered to '.auth()->user()->email.'.'
                : 'Sends a short test email to '.auth()->user()->email.' through this provider.')
            ->schema(fn (SmtpProvider $record): array => [
                Select::make('sender_identity_id')
                    ->label('From')
                    ->options($record->senderIdentities()->pluck('email', 'id')->all())
                    ->required()
                    ->helperText('Add a sender identity for this provider if the list is empty.'),
            ])
            ->action(function (array $data, SendTestEmail $sendTest): void {
                try {
                    $message = $sendTest->handle([
                        'sender_identity_id' => $data['sender_identity_id'],
                        'subject' => 'ProSend connection test',
                        'body' => '<p>This test email confirms that ProSend can send through this provider.</p>',
                    ], auth()->user());
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Test email not sent')->body(implode(' ', $exception->validator->errors()->all()))->send();

                    return;
                }

                $notification = Notification::make()
                    ->title('Test email: '.$message->status->getLabel())
                    ->body($message->error_message ?? match ($message->status) {
                        MessageStatus::Captured => 'Open the sandbox in Mailtrap to inspect it. It was not delivered to '.$message->to_email.'.',
                        default => 'The provider accepted it, which does not prove delivery. Check the inbox (and spam folder) of '.$message->to_email.'.',
                    });

                (in_array($message->status, [MessageStatus::Accepted, MessageStatus::Captured], true) ? $notification->success() : $notification->danger())->send();
            });
    }

    public static function toggleEnabled(): Action
    {
        return Action::make('toggleEnabled')
            ->label(fn (SmtpProvider $record): string => $record->is_enabled ? 'Disable' : 'Enable')
            ->icon(fn (SmtpProvider $record): Heroicon => $record->is_enabled ? Heroicon::OutlinedPauseCircle : Heroicon::OutlinedPlayCircle)
            ->color(fn (SmtpProvider $record): string => $record->is_enabled ? 'warning' : 'success')
            ->requiresConfirmation()
            ->modalDescription(fn (SmtpProvider $record): string => $record->is_enabled
                ? 'Queued emails for this provider will wait until it is enabled again.'
                : 'Queued emails for this provider will start sending again.')
            ->action(function (SmtpProvider $record): void {
                $record->update(['is_enabled' => ! $record->is_enabled]);

                Notification::make()->success()->title($record->is_enabled ? 'Provider enabled' : 'Provider disabled')->send();
            });
    }

    /**
     * Emails this provider accepted or captured. Failed hand-overs are listed under Send attempts.
     */
    public static function viewSendingHistory(): Action
    {
        return Action::make('viewSendingHistory')
            ->label('Sending history')
            ->icon(Heroicon::OutlinedInboxStack)
            ->color('gray')
            ->url(fn (SmtpProvider $record): string => EmailMessageResource::getUrl('index', ['filters' => ['smtpProvider' => ['value' => $record->getKey()]]]));
    }

    public static function generateWebhookUrl(): Action
    {
        return Action::make('generateWebhookUrl')
            ->label(fn (SmtpProvider $record): string => filled($record->webhook_token_hash) ? 'Replace webhook URL' : 'Set up webhook')
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->visible(fn (SmtpProvider $record): bool => $record->type === ProviderType::Brevo)
            ->requiresConfirmation()
            ->modalHeading('Generate a Brevo webhook URL')
            ->modalDescription(fn (SmtpProvider $record): string => (filled($record->webhook_token_hash) ? 'The current URL will stop working. ' : '')
                .'The new URL is shown once. In Brevo, open Transactional > Settings > Webhook, add it, and select delivered, soft bounce, hard bounce, invalid email, blocked, error, spam, unsubscribed, opened, and clicked.')
            ->action(function (SmtpProvider $record): void {
                $token = $record->regenerateWebhookToken();

                Notification::make()
                    ->success()
                    ->title('Webhook URL (copy it now; it is not shown again)')
                    ->body(route('webhooks.brevo', ['provider' => $record, 'token' => $token]))
                    ->persistent()
                    ->send();
            });
    }
}
