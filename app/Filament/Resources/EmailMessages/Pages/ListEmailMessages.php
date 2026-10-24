<?php

namespace App\Filament\Resources\EmailMessages\Pages;

use App\Enums\MessageStatus;
use App\Filament\Exports\EmailMessageExporter;
use App\Filament\Resources\EmailMessages\EmailMessageResource;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListEmailMessages extends ListRecords
{
    protected static string $resource = EmailMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->label('Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->exporter(EmailMessageExporter::class),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'in_progress' => $this->statusTab('In progress', MessageStatus::inFlight()),
            'accepted' => $this->statusTab('Accepted', [MessageStatus::Accepted]),
            'delivered' => $this->statusTab('Delivered', [MessageStatus::Delivered]),
            'bounces' => $this->statusTab('Bounces', [MessageStatus::Bounced, MessageStatus::SoftBounced]),
            'complaints' => $this->statusTab('Spam complaints', [MessageStatus::Complained]),
            'failed' => $this->statusTab('Failed or unknown', [MessageStatus::Failed, MessageStatus::Unknown]),
        ];
    }

    /**
     * @param  list<MessageStatus>  $statuses
     */
    private function statusTab(string $label, array $statuses): Tab
    {
        return Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $statuses));
    }
}
