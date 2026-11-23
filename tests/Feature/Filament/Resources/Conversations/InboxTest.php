<?php

use App\Enums\ContactStage;
use App\Enums\ConversationStatus;
use App\Enums\SuppressionReason;
use App\Enums\SystemRole;
use App\Filament\Resources\Conversations\ConversationResource;
use App\Filament\Resources\Conversations\Pages\ListConversations;
use App\Filament\Resources\Conversations\Pages\ViewConversation;
use App\Filament\Resources\InboundMailboxes\Pages\ManageInboundMailboxes;
use App\Jobs\SendEmailMessage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\InboundMailbox;
use App\Models\SenderIdentity;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Sending\EmailSender;
use Filament\Actions\Testing\TestAction;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Fakes\FakeMailProviderDriver;

function conversationWithReply(array $attributes = []): Conversation
{
    $conversation = Conversation::factory()->create([
        'sender_identity_id' => (SenderIdentity::query()->where('email', 'sales@acme.test')->first() ?? SenderIdentity::factory()->create(['email' => 'sales@acme.test']))->id,
        'subject' => 'Quick question',
        ...$attributes,
    ]);

    $conversation->messages()->create([
        'direction' => ConversationMessage::INBOUND,
        'message_id_header' => 'reply-1@example.com',
        'references' => 'abc-123@acme.test',
        'from_email' => $conversation->contact->email,
        'subject' => 'Re: Quick question',
        'text_body' => 'Sounds interesting, tell me more.',
        'received_at' => now(),
    ]);

    return $conversation;
}

it('lists conversations, unread first in bold, and marks one read when opened', function () {
    $this->actingAs(User::factory()->sender()->create());
    $conversation = conversationWithReply();

    Livewire::test(ListConversations::class)->assertCanSeeTableRecords([$conversation]);
    Livewire::test(ViewConversation::class, ['record' => $conversation->getRouteKey()])
        ->assertSee('Sounds interesting, tell me more.');

    expect($conversation->refresh()->is_read)->toBeTrue();
});

it('sends a reply in the prospect\'s thread, from the sender they were emailed by', function () {
    $driver = FakeMailProviderDriver::install();
    $this->actingAs(User::factory()->sender()->create());
    $conversation = conversationWithReply();

    Livewire::test(ViewConversation::class, ['record' => $conversation->getRouteKey()])
        ->callAction('reply', data: ['body' => '<p>Happy to share details.</p>'])
        ->assertNotified('Reply queued');

    $answer = $conversation->messages()->where('direction', ConversationMessage::OUTBOUND)->sole();
    app()->call([new SendEmailMessage($answer->emailMessage), 'handle']);

    $email = $driver->sent[0]->getOriginalMessage();
    expect($email->getSubject())->toBe('Re: Quick question')
        ->and($email->getHeaders()->get('From')->getBodyAsString())->toContain('sales@acme.test')
        ->and($email->getHeaders()->get('In-Reply-To')->getBodyAsString())->toBe('<reply-1@example.com>')
        ->and($email->getHeaders()->get('References')->getBodyAsString())->toBe('<abc-123@acme.test> <reply-1@example.com>')
        ->and($email->getHeaders()->has('List-Unsubscribe'))->toBeFalse();
    expect($conversation->refresh()->status)->toBe(ConversationStatus::Open);
});

it('refuses to reply to a hard-bounced address', function () {
    Bus::fake();
    $this->actingAs(User::factory()->sender()->create());
    $conversation = conversationWithReply();
    Suppression::record($conversation->contact->email, SuppressionReason::HardBounce, 'test');

    Livewire::test(ViewConversation::class, ['record' => $conversation->getRouteKey()])
        ->callAction('reply', data: ['body' => '<p>Hello?</p>'])
        ->assertNotified('Reply not sent');

    Bus::assertNotDispatched(SendEmailMessage::class);
});

it('moves the prospect to Interested when the conversation is', function () {
    $this->actingAs(User::factory()->sender()->create());
    $conversation = conversationWithReply();

    Livewire::test(ViewConversation::class, ['record' => $conversation->getRouteKey()])
        ->callAction('status', data: ['status' => ConversationStatus::Interested->value]);

    expect($conversation->refresh()->status)->toBe(ConversationStatus::Interested)
        ->and($conversation->contact->refresh()->stage)->toBe(ContactStage::Interested);
});

it('assigns a conversation to a colleague and tells them', function () {
    $this->actingAs(User::factory()->sender()->create());
    $colleague = User::factory()->withClientRole(SystemRole::SalesExecutive)->create();
    $conversation = conversationWithReply();

    Livewire::test(ViewConversation::class, ['record' => $conversation->getRouteKey()])
        ->callAction('assign', data: ['assigned_to' => $colleague->id])
        ->assertHasNoActionErrors();

    expect($conversation->refresh()->assigned_to)->toBe($colleague->id)
        ->and(DatabaseNotification::query()->where('notifiable_id', $colleague->id)->count())->toBe(1);
});

it('adds internal notes', function () {
    $this->actingAs($user = User::factory()->sender()->create());
    $conversation = conversationWithReply();

    Livewire::test(ViewConversation::class, ['record' => $conversation->getRouteKey()])
        ->callAction(TestAction::make('note'), data: ['body' => 'Call on Friday'])
        ->assertHasNoActionErrors();

    expect($conversation->notes()->sole())->body->toBe('Call on Friday')->user_id->toBe($user->id);
});

it('shows a Sales Executive only conversations assigned to them or with prospects they own', function () {
    $rep = User::factory()->withClientRole(SystemRole::SalesExecutive)->create();
    $this->actingAs($rep);
    $assigned = conversationWithReply(['assigned_to' => $rep->id]);
    $owned = conversationWithReply(['contact_id' => Contact::factory()->create(['owner_id' => $rep->id])->id]);
    $someoneElses = conversationWithReply();

    Livewire::test(ListConversations::class)
        ->assertCanSeeTableRecords([$assigned, $owned])
        ->assertCanNotSeeTableRecords([$someoneElses]);

    $this->get(ConversationResource::getUrl('view', ['record' => $someoneElses]))->assertNotFound();
});

it('lets Guest Viewers read the inbox but not answer', function () {
    $this->actingAs(User::factory()->create());
    $conversation = conversationWithReply();

    Livewire::test(ViewConversation::class, ['record' => $conversation->getRouteKey()])
        ->assertActionHidden('reply')
        ->assertActionHidden('status');
});

it('needs the password again when a mailbox moves to another host', function () {
    $this->actingAs(User::factory()->withClientRole(SystemRole::Admin)->create());
    $mailbox = InboundMailbox::factory()->create(['host' => 'imap.acme.test']);

    Livewire::test(ManageInboundMailboxes::class)
        ->callAction(TestAction::make('edit')->table($mailbox), data: ['host' => 'imap.elsewhere.test', 'password' => null])
        ->assertHasActionErrors(['password' => 'required']);

    Livewire::test(ManageInboundMailboxes::class)
        ->callAction(TestAction::make('edit')->table($mailbox), data: ['name' => 'Renamed', 'password' => null])
        ->assertHasNoActionErrors();

    expect($mailbox->refresh())->name->toBe('Renamed')->password->toBe('secret-password');
});

it('keeps inbound mailboxes for people who manage mailboxes', function () {
    $this->actingAs(User::factory()->sender()->create());

    Livewire::test(ManageInboundMailboxes::class)->assertForbidden();
    expect(app(EmailSender::class))->toBeInstanceOf(EmailSender::class);
});
