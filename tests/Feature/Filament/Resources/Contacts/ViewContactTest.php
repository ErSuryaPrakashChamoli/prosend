<?php

use App\Enums\SuppressionReason;
use App\Enums\UserRole;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Suppression;
use App\Models\User;
use Livewire\Livewire;

it('suppresses the address when a contact is marked do not contact', function () {
    $user = User::factory()->sender()->create();
    $this->actingAs($user);
    $contact = Contact::factory()->create(['email' => 'jane@example.com']);

    Livewire::test(ViewContact::class, ['record' => $contact->getRouteKey()])
        ->callAction('doNotContact', data: ['note' => 'Asked by phone not to be contacted'])
        ->assertHasNoActionErrors()
        ->assertNotified('jane@example.com will not be contacted');

    expect(Suppression::query()->sole())
        ->email->toBe('jane@example.com')
        ->reason->toBe(SuppressionReason::Manual)
        ->note->toBe('Asked by phone not to be contacted')
        ->created_by->toBe($user->id);
    expect(AuditLog::query()->where('action', 'suppression.added')->count())->toBe(1);
});

it('turns an unsubscribe into a do not contact suppression', function () {
    $this->actingAs(User::factory()->sender()->create());
    $contact = Contact::factory()->create(['email' => 'jane@example.com']);
    Suppression::factory()->create(['email' => 'jane@example.com', 'reason' => SuppressionReason::Unsubscribed]);

    Livewire::test(ViewContact::class, ['record' => $contact->getRouteKey()])
        ->callAction('doNotContact')
        ->assertHasNoActionErrors();

    expect(Suppression::query()->sole()->reason)->toBe(SuppressionReason::Manual);
});

it('hides do not contact from viewers and for addresses that already get no email', function (?SuppressionReason $existingReason, UserRole $role) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    $contact = Contact::factory()->create(['email' => 'jane@example.com']);

    if ($existingReason !== null) {
        Suppression::factory()->create(['email' => 'jane@example.com', 'reason' => $existingReason]);
    }

    Livewire::test(ViewContact::class, ['record' => $contact->getRouteKey()])
        ->assertActionHidden('doNotContact');
})->with([
    'viewer' => [null, UserRole::Viewer],
    'already bounced' => [SuppressionReason::HardBounce, UserRole::Sender],
    'already suppressed manually' => [SuppressionReason::Manual, UserRole::Sender],
]);

it('only links a website with an http or https address', function () {
    $this->actingAs(User::factory()->create());
    $safe = Contact::factory()->create(['website' => 'https://acme.test']);
    $unsafe = Contact::factory()->create();
    Contact::query()->whereKey($unsafe->id)->update(['website' => 'javascript:alert(1)']);

    Livewire::test(ViewContact::class, ['record' => $safe->getRouteKey()])
        ->assertSeeHtml('href="https://acme.test"');
    Livewire::test(ViewContact::class, ['record' => $unsafe->getRouteKey()])
        ->assertSee('javascript:alert(1)')
        ->assertDontSeeHtml('href="javascript:');
});
