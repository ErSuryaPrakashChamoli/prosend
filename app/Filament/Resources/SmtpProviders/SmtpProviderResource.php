<?php

namespace App\Filament\Resources\SmtpProviders;

use App\Filament\Resources\SmtpProviders\Pages\CreateSmtpProvider;
use App\Filament\Resources\SmtpProviders\Pages\EditSmtpProvider;
use App\Filament\Resources\SmtpProviders\Pages\ListSmtpProviders;
use App\Filament\Resources\SmtpProviders\Pages\ViewSmtpProvider;
use App\Filament\Resources\SmtpProviders\Schemas\SmtpProviderForm;
use App\Filament\Resources\SmtpProviders\Schemas\SmtpProviderInfolist;
use App\Filament\Resources\SmtpProviders\Tables\SmtpProvidersTable;
use App\Models\SmtpProvider;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SmtpProviderResource extends Resource
{
    protected static ?string $model = SmtpProvider::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?string $navigationLabel = 'SMTP providers';

    protected static ?string $modelLabel = 'SMTP provider';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return SmtpProviderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SmtpProviderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmtpProvidersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmtpProviders::route('/'),
            'create' => CreateSmtpProvider::route('/create'),
            'view' => ViewSmtpProvider::route('/{record}'),
            'edit' => EditSmtpProvider::route('/{record}/edit'),
        ];
    }
}
