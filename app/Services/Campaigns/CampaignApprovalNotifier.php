<?php

namespace App\Services\Campaigns;

use App\Enums\UserRole;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Tells the people involved in a campaign's approval what changed, in the panel's notification list.
 */
class CampaignApprovalNotifier
{
    /**
     * Notify every administrator who may approve the campaign now, except the person who caused the change.
     */
    public function notifyApprovers(Campaign $campaign, string $title, string $body, ?User $except = null): void
    {
        $approvers = User::query()
            ->where('role', UserRole::Admin)
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->get()
            ->filter(fn (User $user): bool => $user->can('approve', $campaign));

        if ($approvers->isEmpty()) {
            return;
        }

        $this->notification($campaign, $title, $body)->sendToDatabase($approvers);
    }

    /**
     * Notify the person who submitted the campaign, unless they made the change themselves.
     */
    public function notifySubmitter(Campaign $campaign, string $title, string $body, ?User $except = null): void
    {
        $submitter = $campaign->submitter;

        if ($submitter === null || $submitter->is($except)) {
            return;
        }

        $this->notification($campaign, $title, $body)->sendToDatabase($submitter);
    }

    private function notification(Campaign $campaign, string $title, string $body): Notification
    {
        return Notification::make()
            ->title($title)
            ->body($body)
            ->actions([
                Action::make('view')
                    ->label('Open campaign')
                    ->url(CampaignResource::getUrl('view', ['record' => $campaign])),
            ]);
    }
}
