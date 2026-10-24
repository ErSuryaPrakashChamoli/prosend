<?php

namespace App\Filament\Exports;

use App\Models\EmailMessage;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class EmailMessageExporter extends Exporter
{
    protected static ?string $model = EmailMessage::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('to_email')->label('Recipient'),
            ExportColumn::make('to_name')->label('Name'),
            ExportColumn::make('campaign.name')->label('Campaign'),
            ExportColumn::make('subject'),
            ExportColumn::make('purpose')->formatStateUsing(fn ($state): string => $state->getLabel()),
            ExportColumn::make('status')->formatStateUsing(fn ($state): string => $state->getLabel()),
            ExportColumn::make('attempts'),
            ExportColumn::make('queued_at'),
            ExportColumn::make('accepted_at')->label('Accepted by provider at'),
            ExportColumn::make('delivered_at')->label('Delivered at'),
            ExportColumn::make('bounce_type')->formatStateUsing(fn ($state): string => $state?->getLabel() ?? ''),
            ExportColumn::make('error_code'),
            ExportColumn::make('error_message')->label('Error'),
            ExportColumn::make('provider_message_id')->label('Message-ID'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your email export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
