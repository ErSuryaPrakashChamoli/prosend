<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Enums\EmailPurpose;
use App\Enums\SuppressionReason;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use App\Models\Suppression;
use App\Services\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

class ViewContact extends ViewRecord
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->doNotContactAction(),
            EditAction::make(),
        ];
    }

    /**
     * Do Not Contact is a manual suppression, so every sending path already enforces it and deleting the
     * contact never lifts it. Only administrators can remove it again.
     */
    private function doNotContactAction(): Action
    {
        return Action::make('doNotContact')
            ->label('Do not contact')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Contact $record): bool => Gate::allows('create', Suppression::class)
                && Suppression::blockingFor($record->email, EmailPurpose::Transactional) === null)
            ->requiresConfirmation()
            ->modalDescription('Adds this address to the suppression list. ProSend will send it no email at all, including transactional email. Only an administrator can undo this.')
            ->schema([
                Textarea::make('note')
                    ->label('Reason')
                    ->rows(2)
                    ->maxLength(2000),
            ])
            ->action(function (Contact $record, array $data, AuditLogger $auditLogger): void {
                $suppression = Suppression::record($record->email, SuppressionReason::Manual, 'manual', $data['note'] ?? null, auth()->user());

                $auditLogger->record('suppression.added', $suppression, [
                    'email' => $suppression->email,
                    'reason' => $suppression->reason->value,
                ]);

                $record->unsetRelation('suppression');

                Notification::make()
                    ->success()
                    ->title("{$record->email} will not be contacted")
                    ->send();
            });
    }
}
