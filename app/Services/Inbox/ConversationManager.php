<?php

namespace App\Services\Inbox;

use App\Enums\ConversationStatus;
use App\Filament\Resources\Conversations\ConversationResource;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\User;
use App\Services\AuditLogger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Status, read, star, assignment and notes for inbox conversations. A positive status moves the prospect's stage along.
 */
class ConversationManager
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * @param  array{status?: ConversationStatus|string, is_read?: bool, is_starred?: bool}  $attributes
     */
    public function update(Conversation $conversation, array $attributes): void
    {
        $attributes = array_intersect_key($attributes, array_flip(['status', 'is_read', 'is_starred']));
        $conversation->forceFill($attributes)->save();

        if (! $conversation->wasChanged('status')) {
            return;
        }

        $stage = $conversation->status->contactStage();

        if ($stage !== null && $conversation->contact !== null && $conversation->contact->stage !== $stage) {
            $conversation->contact->forceFill(['stage' => $stage])->save();
        }

        $this->auditLogger->record('conversation.status_changed', $conversation, ['status' => $conversation->status->value]);
    }

    /**
     * Give the conversation to a member of its client, or to nobody, and tell the new assignee.
     *
     * @throws ValidationException
     */
    public function assign(Conversation $conversation, ?User $assignee, User $by): void
    {
        if ($assignee !== null && ! $assignee->isSuperAdmin() && $assignee->roleIn($conversation->client_id) === null) {
            throw ValidationException::withMessages(['assigned_to' => "{$assignee->name} does not work in this client."]);
        }

        $conversation->forceFill(['assigned_to' => $assignee?->getKey()])->save();
        $this->auditLogger->record('conversation.assigned', $conversation, ['to' => $assignee?->email], $by);

        if ($assignee === null || $assignee->is($by)) {
            return;
        }

        Notification::make()
            ->title("{$by->name} assigned you a conversation")
            ->body(e($conversation->subject))
            ->actions([
                Action::make('open')
                    ->label('Open conversation')
                    ->url(ConversationResource::getUrl('view', ['record' => $conversation], tenant: $conversation->client)),
            ])
            ->sendToDatabase($assignee);
    }

    public function addNote(Conversation $conversation, User $author, string $body): ConversationNote
    {
        return $conversation->notes()->create(['user_id' => $author->getKey(), 'body' => trim($body)]);
    }
}
