<?php

use App\Actions\Deliverability\CheckSenderWithProvider;
use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('marks the sender verified when Brevo reports it active', function () {
    Http::fake(['https://api.brevo.com/v3/senders?domain=acme.test' => Http::response(['senders' => [
        ['id' => 1, 'name' => 'Acme', 'email' => 'News@Acme.test', 'active' => true, 'ips' => []],
    ]])]);
    $user = User::factory()->admin()->create();
    $sender = SenderIdentity::factory()->unverified()->for(SmtpProvider::factory()->state(['api_key' => 'xkeysib-test']))->create(['email' => 'news@acme.test']);

    $result = app(CheckSenderWithProvider::class)->handle($sender, $user);

    expect($result)->toBe('Brevo confirms news@acme.test is an active sender.');
    expect($sender->refresh())
        ->isVerified()->toBeTrue()
        ->verified_by->toBe($user->id);
});

it('removes the verification when Brevo has not activated the sender', function () {
    Http::fake(['https://api.brevo.com/v3/senders*' => Http::response(['senders' => [
        ['id' => 1, 'name' => 'Acme', 'email' => 'news@acme.test', 'active' => false, 'ips' => []],
    ]])]);
    $sender = SenderIdentity::factory()->for(SmtpProvider::factory()->state(['api_key' => 'xkeysib-test']))->create(['email' => 'news@acme.test']);

    $result = app(CheckSenderWithProvider::class)->handle($sender, User::factory()->admin()->create());

    expect($result)->toContain('not activated yet');
    expect($sender->refresh()->isVerified())->toBeFalse();
});

it('is only offered for Brevo providers with an API key', function () {
    $withKey = SenderIdentity::factory()->for(SmtpProvider::factory()->state(['api_key' => 'xkeysib-test']))->create();
    $withoutKey = SenderIdentity::factory()->create();

    expect(CheckSenderWithProvider::isAvailable($withKey))->toBeTrue()
        ->and(CheckSenderWithProvider::isAvailable($withoutKey))->toBeFalse();
});
