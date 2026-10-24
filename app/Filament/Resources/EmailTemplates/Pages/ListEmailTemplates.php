<?php

namespace App\Filament\Resources\EmailTemplates\Pages;

use App\Enums\TemplateStatus;
use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Models\EmailTemplate;
use App\Services\Templates\SampleTemplates;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListEmailTemplates extends ListRecords
{
    protected static string $resource = EmailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('installSamples')
                ->label('Add sample templates')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->visible(fn (): bool => EmailTemplateResource::canCreate())
                ->requiresConfirmation()
                ->modalDescription('Adds welcome, email verification, password reset, account notification, order update, newsletter, and announcement templates. Existing templates with the same names are left unchanged.')
                ->action(function (SampleTemplates $samples): void {
                    $installed = $samples->install();

                    Notification::make()->success()->title($installed > 0 ? "{$installed} sample templates added" : 'All sample templates already exist')->send();
                }),
            CreateAction::make(),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')
                ->badge(EmailTemplate::query()->where('status', TemplateStatus::Active)->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TemplateStatus::Active)),
            'drafts' => Tab::make('Drafts')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TemplateStatus::Draft)),
            'archived' => Tab::make('Archived')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TemplateStatus::Archived)),
            'all' => Tab::make('All'),
        ];
    }
}
