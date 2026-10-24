<?php

namespace App\Filament\Resources\SendingDomains;

use App\Filament\Resources\SendingDomains\Pages\CreateSendingDomain;
use App\Filament\Resources\SendingDomains\Pages\EditSendingDomain;
use App\Filament\Resources\SendingDomains\Pages\ListSendingDomains;
use App\Filament\Resources\SendingDomains\Pages\ViewSendingDomain;
use App\Filament\Resources\SendingDomains\Schemas\SendingDomainForm;
use App\Filament\Resources\SendingDomains\Schemas\SendingDomainInfolist;
use App\Filament\Resources\SendingDomains\Tables\SendingDomainsTable;
use App\Models\SendingDomain;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SendingDomainResource extends Resource
{
    protected static ?string $model = SendingDomain::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Deliverability';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Domain authentication';

    protected static ?string $modelLabel = 'sending domain';

    protected static ?string $recordTitleAttribute = 'domain';

    public static function form(Schema $schema): Schema
    {
        return SendingDomainForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SendingDomainInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SendingDomainsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSendingDomains::route('/'),
            'create' => CreateSendingDomain::route('/create'),
            'view' => ViewSendingDomain::route('/{record}'),
            'edit' => EditSendingDomain::route('/{record}/edit'),
        ];
    }
}
