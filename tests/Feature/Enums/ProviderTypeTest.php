<?php

use App\Enums\ProviderType;

it('defaults to the hosts, ports and login in Mailtrap\'s documentation', function () {
    expect(ProviderType::Mailtrap)
        ->defaultHost()->toBe('live.smtp.mailtrap.io')
        ->defaultPort()->toBe(587)
        ->defaultUsername()->toBe('api')
        ->isSandbox()->toBeFalse();
    expect(ProviderType::MailtrapSandbox)
        ->defaultHost()->toBe('sandbox.smtp.mailtrap.io')
        ->defaultPort()->toBe(2525)
        ->defaultUsername()->toBeNull()
        ->isSandbox()->toBeTrue();
});

it('only accepts the matching Mailtrap hosts for each Mailtrap type', function () {
    expect(ProviderType::Mailtrap->hostProblem('live.smtp.mailtrap.io'))->toBeNull()
        ->and(ProviderType::Mailtrap->hostProblem('bulk.smtp.mailtrap.io'))->toBeNull()
        ->and(ProviderType::Mailtrap->hostProblem('sandbox.smtp.mailtrap.io'))->not->toBeNull()
        ->and(ProviderType::MailtrapSandbox->hostProblem('Sandbox.SMTP.mailtrap.io'))->toBeNull()
        ->and(ProviderType::MailtrapSandbox->hostProblem('live.smtp.mailtrap.io'))->not->toBeNull();
});

it('keeps Mailtrap hosts out of the other provider types', function (ProviderType $type) {
    expect($type->hostProblem('sandbox.smtp.mailtrap.io'))->toContain('Choose a Mailtrap provider type')
        ->and($type->hostProblem('smtp.mailtrap.io'))->not->toBeNull()
        ->and($type->hostProblem('smtp.example-mailtrap.io.net'))->toBeNull()
        ->and($type->hostProblem('smtp-relay.brevo.com'))->toBeNull();
})->with([ProviderType::Brevo, ProviderType::Smtp]);

it('never offers a switch between sandbox and live sending', function () {
    expect(ProviderType::MailtrapSandbox->compatibleTypes())->toBe([ProviderType::MailtrapSandbox])
        ->and(ProviderType::Brevo->compatibleTypes())->toBe([ProviderType::Brevo, ProviderType::Smtp, ProviderType::Mailtrap]);
});
