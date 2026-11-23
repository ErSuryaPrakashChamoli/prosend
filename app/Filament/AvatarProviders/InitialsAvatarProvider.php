<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Draws user and client avatars locally: the name's initials on a gradient, as an inline SVG. Filament's default
 * fetches them from ui-avatars.com, which sends every user's and client's name to a third party on each page view.
 * The gradient is chosen from the name, so a client keeps its colour and clients are told apart at a glance.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    /**
     * Gradient start and end colours: ProSend blue and neighbouring cool hues, so avatars sit with the brand.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const GRADIENTS = [
        ['#6894ff', '#2145cf'],
        ['#8f8cff', '#4338ca'],
        ['#b28cff', '#6d28d9'],
        ['#5cc8ff', '#0369a1'],
        ['#4fd1c5', '#0f766e'],
        ['#94a3b8', '#334155'],
    ];

    public function get(Model|Authenticatable $record): string
    {
        $name = trim(Filament::getNameForDefaultAvatar($record));
        $initials = $this->initials($name);
        [$from, $to] = self::GRADIENTS[crc32($name) % count(self::GRADIENTS)];
        $fontSize = mb_strlen($initials) > 1 ? 25 : 28;
        $text = htmlspecialchars($initials, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
            <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{$from}"/><stop offset="1" stop-color="{$to}"/></linearGradient></defs>
            <rect width="64" height="64" fill="url(#g)"/>
            <text x="32" y="32" dominant-baseline="central" text-anchor="middle" fill="#fff" font-family="Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif" font-size="{$fontSize}" font-weight="600" letter-spacing="0.5">{$text}</text>
            </svg>
            SVG;

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Up to two initials: the first letters of the first and last words. Leading punctuation is skipped, so a
     * "[SYSTEM] Admin" account becomes "SA".
     */
    public function initials(string $name): string
    {
        $letters = collect(preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $word): string => mb_substr(preg_replace('/^[^\p{L}\p{N}]+/u', '', $word) ?? '', 0, 1))
            ->filter(fn (string $letter): bool => $letter !== '')
            ->values();

        if ($letters->isEmpty()) {
            return '?';
        }

        return mb_strtoupper($letters->first().($letters->count() > 1 ? $letters->last() : ''));
    }
}
