<?php

namespace App\Filament\Resources\Members\Pages;

use App\Actions\Clients\InviteClientMember;
use App\Enums\SystemRole;
use App\Filament\Resources\Members\MemberResource;
use App\Models\Client;
use App\Models\Role;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageMembers extends ManageRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label('Invite someone')
                ->icon(Heroicon::OutlinedUserPlus)
                ->authorize(fn (): bool => MemberResource::canCreate())
                ->modalDescription('People without an account get an email with a link to choose their password.')
                ->schema([
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            $problem = InviteClientMember::problemWith(Filament::getTenant(), (string) $value);

                            if ($problem !== null) {
                                $fail($problem);
                            }
                        }),
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Used for new accounts only.'),
                    Select::make('role_id')
                        ->label('Role')
                        ->options(fn (): array => MemberResource::assignableRoleOptions())
                        ->default(fn (): ?int => Role::query()->where('key', SystemRole::SalesExecutive->value)->value('id'))
                        ->required(),
                ])
                ->action(function (array $data, InviteClientMember $invite): void {
                    /** @var Client $client */
                    $client = Filament::getTenant();

                    $role = Role::query()->findOrFail($data['role_id']);
                    abort_unless(auth()->user()->canAssignRole($role, $client), 403);

                    $invite->handle($client, $data['email'], $data['name'], $role, auth()->user());

                    Notification::make()
                        ->title("{$data['email']} can now work in {$client->name}")
                        ->success()
                        ->send();
                }),
        ];
    }
}
