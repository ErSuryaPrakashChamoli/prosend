<?php

namespace App\Filament\Resources\EmailTemplates\Tables;

use App\Enums\EmailPurpose;
use App\Enums\TemplateCategory;
use App\Models\EmailTemplate;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EmailTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (EmailTemplate $record): string => $record->subject)
                    ->searchable(['name', 'subject'])
                    ->sortable(),
                TextColumn::make('category')
                    ->toggleable(),
                TextColumn::make('purpose')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('version')
                    ->prefix('v')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('purpose')
                    ->label('Type')
                    ->options(EmailPurpose::class),
                SelectFilter::make('category')
                    ->options(TemplateCategory::class),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('No templates')
            ->emptyStateDescription('Create a template, or add the sample templates to start from.')
            ->emptyStateIcon(Heroicon::OutlinedDocumentText);
    }
}
