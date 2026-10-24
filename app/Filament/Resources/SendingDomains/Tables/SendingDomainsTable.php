<?php

namespace App\Filament\Resources\SendingDomains\Tables;

use App\Models\SendingDomain;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SendingDomainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('smtpProvider'))
            ->defaultSort('domain')
            ->columns([
                TextColumn::make('domain')
                    ->description(fn (SendingDomain $record): ?string => $record->smtpProvider?->name)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('ready')
                    ->state(fn (SendingDomain $record): string => $record->isReady() ? 'Ready' : 'Not ready')
                    ->badge()
                    ->color(fn (SendingDomain $record): string => $record->isReady() ? 'success' : 'warning'),
                IconColumn::make('provider_authenticated')
                    ->label('Provider authenticated')
                    ->boolean()
                    ->placeholder('Unknown'),
                TextColumn::make('spf_status')->label('SPF')->badge(),
                TextColumn::make('dkim_status')->label('DKIM')->badge(),
                TextColumn::make('dmarc_status')->label('DMARC')->badge(),
                TextColumn::make('dns_checked_at')
                    ->label('Checked')
                    ->since()
                    ->placeholder('Never'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('No sending domains')
            ->emptyStateDescription('Add the domain of your From address to check SPF, DKIM, and DMARC.')
            ->emptyStateIcon(Heroicon::OutlinedGlobeAlt);
    }
}
