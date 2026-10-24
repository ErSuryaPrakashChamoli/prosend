<?php

namespace App\Filament\Resources\EmailMessages;

use App\Filament\Resources\EmailMessages\Pages\ListEmailMessages;
use App\Filament\Resources\EmailMessages\Pages\ViewEmailMessage;
use App\Filament\Resources\EmailMessages\Schemas\EmailMessageInfolist;
use App\Filament\Resources\EmailMessages\Tables\EmailMessagesTable;
use App\Models\EmailMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class EmailMessageResource extends Resource
{
    protected static ?string $model = EmailMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Overview';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Email activity';

    protected static ?string $modelLabel = 'email';

    protected static ?string $slug = 'email-activity';

    protected static ?string $recordTitleAttribute = 'subject';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return EmailMessageInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmailMessagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailMessages::route('/'),
            'view' => ViewEmailMessage::route('/{record}'),
        ];
    }
}
