<?php

use App\Models\SenderIdentity;
use App\Models\SmtpProvider;

it('clears the verification when the address changes', function () {
    $senderIdentity = SenderIdentity::factory()->create(['email' => 'news@acme.test']);

    $senderIdentity->update(['email' => 'hello@acme.test']);

    expect($senderIdentity->refresh()->verified_at)->toBeNull();
});

it('keeps the verification when only the name changes', function () {
    $senderIdentity = SenderIdentity::factory()->create();

    $senderIdentity->update(['name' => 'Acme News']);

    expect($senderIdentity->refresh()->verified_at)->not->toBeNull();
});

it('clears the verification when the sender moves to another provider', function () {
    $senderIdentity = SenderIdentity::factory()->create();

    $senderIdentity->update(['smtp_provider_id' => SmtpProvider::factory()->create()->id]);

    expect($senderIdentity->refresh()->verified_at)->toBeNull();
});
