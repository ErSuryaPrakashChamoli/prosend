<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Enums\ContactStage;
use App\Filament\Resources\Contacts\ContactResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\Locked;

class EditContact extends EditRecord
{
    protected static string $resource = ContactResource::class;

    /**
     * Sending email moves a contact to Contacted, possibly while this form is open, so the stage is only saved
     * when the user changed it. Otherwise saving a stale form would undo that move.
     */
    #[Locked]
    public ?string $stageWhenOpened = null;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->stageWhenOpened = $data['stage'] ?? null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $stage = ($data['stage'] ?? null) instanceof ContactStage ? $data['stage']->value : ($data['stage'] ?? null);

        if ($stage === $this->stageWhenOpened) {
            unset($data['stage']);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $this->stageWhenOpened = $this->getRecord()->stage->value;
    }
}
