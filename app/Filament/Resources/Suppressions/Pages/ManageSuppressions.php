<?php

namespace App\Filament\Resources\Suppressions\Pages;

use App\Enums\SuppressionReason;
use App\Filament\Resources\Suppressions\SuppressionResource;
use App\Models\Suppression;
use App\Services\AuditLogger;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageSuppressions extends ManageRecords
{
    protected static string $resource = SuppressionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Suppress address')
                ->mutateDataUsing(fn (array $data): array => [
                    ...$data,
                    'source' => 'manual',
                    'created_by' => auth()->id(),
                ])
                ->after(fn (Suppression $record, AuditLogger $auditLogger) => $auditLogger->record('suppression.added', $record, [
                    'email' => $record->email,
                    'reason' => $record->reason->value,
                ])),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tab = fn (string $label, SuppressionReason ...$reasons): Tab => Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('reason', $reasons));

        return [
            'all' => Tab::make('All'),
            'bounces' => $tab('Bounces', SuppressionReason::HardBounce, SuppressionReason::InvalidAddress),
            'complaints' => $tab('Spam complaints', SuppressionReason::Complaint),
            'unsubscribes' => $tab('Unsubscribes', SuppressionReason::Unsubscribed),
            'manual' => $tab('Added manually', SuppressionReason::Manual),
        ];
    }
}
