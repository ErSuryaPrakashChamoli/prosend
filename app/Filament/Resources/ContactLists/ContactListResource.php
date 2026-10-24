<?php

namespace App\Filament\Resources\ContactLists;

use App\Filament\Resources\ContactLists\Pages\CreateContactList;
use App\Filament\Resources\ContactLists\Pages\EditContactList;
use App\Filament\Resources\ContactLists\Pages\ListContactLists;
use App\Filament\Resources\ContactLists\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\ContactLists\Schemas\ContactListForm;
use App\Filament\Resources\ContactLists\Tables\ContactListsTable;
use App\Models\ContactList;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ContactListResource extends Resource
{
    protected static ?string $model = ContactList::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Audiences';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Lists';

    protected static ?string $modelLabel = 'list';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ContactListForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContactListsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ContactsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactLists::route('/'),
            'create' => CreateContactList::route('/create'),
            'edit' => EditContactList::route('/{record}/edit'),
        ];
    }
}
