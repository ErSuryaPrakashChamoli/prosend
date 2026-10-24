<?php

namespace App\Filament\Resources\EmailTemplates\RelationManagers;

use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateVersion;
use App\Services\Sending\EmailPreviewer;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Version history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        /** @var EmailTemplate $template */
        $template = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('version')
            ->defaultSort('version', 'desc')
            ->columns([
                TextColumn::make('version')
                    ->prefix('v')
                    ->badge()
                    ->color(fn (EmailTemplateVersion $record): string => $record->version === $template->version ? 'success' : 'gray')
                    ->description(fn (EmailTemplateVersion $record): ?string => $record->version === $template->version ? 'Current' : null),
                TextColumn::make('subject')
                    ->limit(60),
                TextColumn::make('creator.name')
                    ->label('Saved by')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Saved')
                    ->dateTime(),
            ])
            ->recordActions([
                Action::make('preview')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modalHeading(fn (EmailTemplateVersion $record): string => "Version {$record->version}")
                    ->modalWidth(Width::SevenExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function (EmailTemplateVersion $record, EmailPreviewer $previewer) use ($template) {
                        $rendered = $previewer->render($record->subject, $record->body, $template->purpose, null, auth()->user()->email, $record->text_body);

                        return view('filament.email-preview', ['subject' => $rendered->subject, 'html' => $previewer->html($rendered, null)]);
                    }),
                Action::make('restore')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn (EmailTemplateVersion $record): bool => $record->version !== $template->version && EmailTemplateResource::canEdit($template))
                    ->requiresConfirmation()
                    ->modalDescription(fn (EmailTemplateVersion $record): string => "The content of version {$record->version} is saved as a new version. Nothing in the history is lost.")
                    ->action(function (EmailTemplateVersion $record) use ($template): void {
                        $template->restore($record);

                        Notification::make()->success()->title("Restored version {$record->version} as version {$template->version}")->send();

                        $this->redirect(EmailTemplateResource::getUrl('view', ['record' => $template]));
                    }),
            ]);
    }
}
