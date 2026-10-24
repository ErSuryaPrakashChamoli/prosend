<?php

namespace App\Filament\Resources\SendingDomains\Schemas;

use App\Enums\DnsCheckStatus;
use App\Enums\ProviderType;
use App\Models\SendingDomain;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class SendingDomainInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Callout::make('Ready for sending')
                    ->description(fn (SendingDomain $record): string => ($record->hasProviderCheck()
                        ? 'The provider authenticated this domain, and its DKIM and DMARC records are in place.'
                        : 'DKIM and DMARC records are in place. The provider\'s own verdict is not available, so confirm the domain shows as authenticated in the provider.')
                        .' This does not guarantee inbox placement.')
                    ->success()
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->visible(fn (SendingDomain $record): bool => $record->isReady())
                    ->columnSpanFull(),
                Callout::make('Not ready yet')
                    ->description(fn (SendingDomain $record): string => implode(' ', $record->readinessIssues()))
                    ->warning()
                    ->visible(fn (SendingDomain $record): bool => ! $record->isReady())
                    ->columnSpanFull(),
                Section::make('1. Verified by the provider')
                    ->description('The provider\'s own verdict. Only the provider can confirm it signs your email.')
                    ->columnSpan(1)
                    ->schema([
                        IconEntry::make('provider_verified')
                            ->label('Ownership verified')
                            ->boolean()
                            ->placeholder('Unknown'),
                        IconEntry::make('provider_authenticated')
                            ->label('Domain authenticated')
                            ->boolean()
                            ->placeholder('Unknown'),
                        TextEntry::make('provider_checked_at')
                            ->label('Last checked with provider')
                            ->since()
                            ->placeholder(fn (SendingDomain $record): string => $record->smtpProvider?->type === ProviderType::Brevo
                                ? 'Never. Add a Brevo API key to the provider to check automatically.'
                                : 'Not available for this provider'),
                        TextEntry::make('last_error')
                            ->label('Problem')
                            ->color('danger')
                            ->visible(fn (SendingDomain $record): bool => filled($record->last_error)),
                    ]),
                Section::make('2. Found in DNS')
                    ->description('What public DNS shows right now. A record existing is not proof that it is correct.')
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('spf_status')
                            ->label('SPF')
                            ->badge()
                            ->helperText(fn (SendingDomain $record): string => static::spfHelp($record)),
                        TextEntry::make('dkim_status')
                            ->label('DKIM')
                            ->badge()
                            ->helperText(fn (SendingDomain $record): string => static::dkimHelp($record)),
                        TextEntry::make('dmarc_status')
                            ->label('DMARC')
                            ->badge()
                            ->helperText(fn (SendingDomain $record): string => static::dmarcHelp($record)),
                        TextEntry::make('spf_record')
                            ->label('SPF record found')
                            ->fontFamily('mono')
                            ->size('xs')
                            ->placeholder('None'),
                        KeyValueEntry::make('dkim_records')
                            ->label('DKIM records found')
                            ->placeholder('None'),
                        TextEntry::make('dmarc_record')
                            ->label('DMARC record found')
                            ->fontFamily('mono')
                            ->size('xs')
                            ->placeholder('None'),
                        TextEntry::make('dns_checked_at')
                            ->label('Last DNS check')
                            ->since()
                            ->placeholder('Never'),
                    ]),
                Section::make('3. Alignment')
                    ->description('DMARC passes only when SPF or DKIM is aligned with the From domain.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('dkim_alignment')
                            ->label('DKIM alignment')
                            ->state(fn (SendingDomain $record): string => $record->provider_authenticated
                                ? 'Aligned: the provider authenticated the domain, so it signs email with '.$record->domain.'.'
                                : 'Not confirmed: until the provider authenticates the domain, email may be signed with the provider\'s own domain.'),
                        TextEntry::make('spf_alignment')
                            ->label('SPF alignment')
                            ->state(fn (SendingDomain $record): string => $record->smtpProvider?->type === ProviderType::Brevo
                                ? 'Usually not aligned: Brevo uses its own bounce (Return-Path) domain, so DMARC relies on aligned DKIM.'
                                : 'Depends on the Return-Path domain your provider uses. Check the headers of a received test email.'),
                    ]),
                Section::make('Records to publish')
                    ->description('Add these at your DNS host, exactly as shown. Changes can take up to 48 hours to appear.')
                    ->columnSpanFull()
                    ->visible(fn (SendingDomain $record): bool => filled($record->provider_dns_records))
                    ->schema([
                        RepeatableEntry::make('provider_records')
                            ->hiddenLabel()
                            ->state(fn (SendingDomain $record): array => collect($record->provider_dns_records ?? [])
                                ->map(fn (array $dnsRecord, string $name): array => [
                                    'name' => str($name)->replace('_', ' ')->title()->toString(),
                                    'type' => $dnsRecord['type'] ?? '',
                                    'host' => $dnsRecord['host_name'] ?? '',
                                    'value' => $dnsRecord['value'] ?? '',
                                    'status' => ($dnsRecord['status'] ?? false) ? 'Detected by Brevo' : 'Not detected yet',
                                ])
                                ->values()
                                ->all())
                            ->table([
                                TableColumn::make('Record'),
                                TableColumn::make('Type'),
                                TableColumn::make('Host'),
                                TableColumn::make('Value'),
                                TableColumn::make('Brevo status'),
                            ])
                            ->schema([
                                TextEntry::make('name'),
                                TextEntry::make('type'),
                                TextEntry::make('host')->copyable()->fontFamily('mono')->size('xs'),
                                TextEntry::make('value')->copyable()->fontFamily('mono')->size('xs'),
                                TextEntry::make('status')->badge()->color(fn (string $state): string => $state === 'Detected by Brevo' ? 'success' : 'warning'),
                            ]),
                    ]),
                Section::make('How to authenticate with Brevo')
                    ->columnSpanFull()
                    ->collapsed(fn (SendingDomain $record): bool => $record->isReady())
                    ->visible(fn (SendingDomain $record): bool => $record->smtpProvider?->type === ProviderType::Brevo)
                    ->schema([
                        TextEntry::make('guide')
                            ->hiddenLabel()
                            ->state(fn (SendingDomain $record): array => [
                                "In Brevo, open Senders, Domains & Dedicated IPs > Domains and add {$record->domain}.",
                                'Publish the Brevo code (TXT) and the DKIM records Brevo shows. Newer accounts get two CNAME records (brevo1._domainkey and brevo2._domainkey); older accounts get one TXT record (mail._domainkey).',
                                "Publish a DMARC record at _dmarc.{$record->domain}, starting with v=DMARC1; p=none; and tighten the policy to quarantine or reject once reports look clean. If you already have one, keep it rather than replacing it.",
                                'If you use SPF, keep a single SPF record and add include:spf.brevo.com to it if Brevo asks for it.',
                                'Click Authenticate in Brevo, then Check now here. DNS changes can take up to 48 hours.',
                            ])
                            ->listWithLineBreaks()
                            ->bulleted(),
                    ]),
            ]);
    }

    private static function spfHelp(SendingDomain $record): string
    {
        return match ($record->spf_status) {
            DnsCheckStatus::Missing => 'No SPF record. Optional for Brevo, which relies on DKIM, but recommended.',
            DnsCheckStatus::Invalid => 'Several SPF records, or one that allows every server ("+all"). Receivers treat this as an error. Merge into one record.',
            DnsCheckStatus::Incomplete => 'The SPF record does not include include:spf.brevo.com.',
            DnsCheckStatus::Found => 'SPF record found.',
            DnsCheckStatus::Unknown => 'Not checked yet.',
        };
    }

    private static function dkimHelp(SendingDomain $record): string
    {
        return match ($record->dkim_status) {
            DnsCheckStatus::Missing => 'No DKIM record for the selectors '.implode(', ', $record->selectors()).'. Publish the DKIM record from your provider.',
            DnsCheckStatus::Incomplete => 'Only one of the two Brevo DKIM CNAME records (brevo1, brevo2) was found. Publish both.',
            DnsCheckStatus::Found => 'DKIM record found. A record in DNS does not prove email is signed; the provider confirms that.',
            DnsCheckStatus::Invalid, DnsCheckStatus::Unknown => 'Not checked yet.',
        };
    }

    private static function dmarcHelp(SendingDomain $record): string
    {
        return match ($record->dmarc_status) {
            DnsCheckStatus::Missing => 'No DMARC record. Gmail and Yahoo require one for bulk senders.',
            DnsCheckStatus::Invalid => 'The DMARC record is invalid: there are several, or it has no valid p= policy.',
            DnsCheckStatus::Found => match ($record->dmarc_policy) {
                'none' => 'Policy p=none: monitoring only. Consider quarantine once reports show your email passes.',
                default => "Policy p={$record->dmarc_policy}: failing email is ".($record->dmarc_policy === 'reject' ? 'rejected.' : 'sent to spam.'),
            },
            DnsCheckStatus::Incomplete, DnsCheckStatus::Unknown => 'Not checked yet.',
        };
    }
}
