<?php

namespace App\Filament\Exports;

use App\Models\Contact;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class ContactExporter extends Exporter
{
    protected static ?string $model = Contact::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('email')->label('Email address'),
            ExportColumn::make('first_name'),
            ExportColumn::make('last_name'),
            ExportColumn::make('company'),
            ExportColumn::make('phone'),
            ExportColumn::make('source'),
            ExportColumn::make('tags')->listAsJson(),
            ExportColumn::make('lists.name')->label('Lists'),
            ExportColumn::make('consent_status')->formatStateUsing(fn ($state): string => $state->getLabel()),
            ExportColumn::make('consent_source'),
            ExportColumn::make('consented_at'),
            ExportColumn::make('unsubscribed_at'),
            ExportColumn::make('suppression.reason')->label('Suppression')->formatStateUsing(fn ($state): string => $state->getLabel()),
            ExportColumn::make('created_at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your contact export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
