<?php

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Filament\Pages\Dashboard;
use App\Models\User;

/**
 * Decode an avatar data URI back to its SVG markup.
 */
function avatarSvg(string $dataUri): string
{
    return base64_decode(str($dataUri)->after('data:image/svg+xml;base64,')->toString());
}

it('renders avatars locally without sending names to ui-avatars.com', function () {
    $this->actingAs(User::factory()->admin()->create(['name' => 'Ada Lovelace']));

    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSee('data:image/svg+xml;base64,', escape: false)
        ->assertDontSee('ui-avatars.com');
});

it('draws the initials of the first and last words', function (string $name, string $initials) {
    $user = User::factory()->make(['name' => $name]);

    $svg = avatarSvg((new InitialsAvatarProvider)->get($user));

    expect($svg)->toContain(">{$initials}</text>");
})->with([
    'two words' => ['Ada Lovelace', 'AL'],
    'three words' => ['Mary Ann Evans', 'ME'],
    'one word' => ['admin', 'A'],
    'leading punctuation' => ['[SYSTEM] Admin', 'SA'],
    'non-latin' => ['élodie ünal', 'ÉÜ'],
]);

it('falls back to a question mark when the name has no letters', function () {
    expect((new InitialsAvatarProvider)->initials('  --  '))->toBe('?');
});

it('does not carry markup from a name into the svg', function () {
    $user = User::factory()->make(['name' => '<svg onload=alert(1)> "x"']);

    $svg = avatarSvg((new InitialsAvatarProvider)->get($user));

    expect($svg)
        ->not->toContain('onload')
        ->toContain('>SX</text>');
});
