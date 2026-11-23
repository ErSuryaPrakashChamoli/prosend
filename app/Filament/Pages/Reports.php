<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Models\Campaign;
use App\Services\Analytics\OutreachReport;
use App\Services\Analytics\ReportExporter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Outreach results by campaign, person, team, mailbox, day, or client, for any period, with comparison of chosen
 * campaigns and CSV, Excel and printable (PDF) downloads.
 *
 * @property-read Schema $form
 */
class Reports extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Overview';

    protected static ?int $navigationSort = 2;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission(Permission::ViewReports) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'start_date' => now()->subDays(29)->toDateString(),
            'end_date' => now()->toDateString(),
            'grouping' => 'campaign',
            'campaign_ids' => [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->columns(['md' => 2, 'xl' => 4])
                    ->schema([
                        DatePicker::make('start_date')->label('From')->required()->maxDate(now())->live(),
                        DatePicker::make('end_date')->label('To')->required()->maxDate(now())->live(),
                        Select::make('grouping')
                            ->label('Group by')
                            ->options(fn (): array => collect(OutreachReport::GROUPINGS)->except(auth()->user()->isSuperAdmin() ? [] : ['client'])->all())
                            ->required()
                            ->selectablePlaceholder(false)
                            ->live(),
                        Select::make('campaign_ids')
                            ->label('Only these campaigns (compare)')
                            ->multiple()
                            ->options(fn (): array => Campaign::query()->latest('id')->limit(500)->pluck('name', 'id')->all())
                            ->searchable()
                            ->live(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            View::make('filament.pages.reports-table')
                ->viewData(fn (): array => [
                    'columns' => OutreachReport::COLUMNS,
                    'rows' => $this->rows(),
                    'totals' => $this->totals(),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $canExport = fn (): bool => auth()->user()->hasPermission(Permission::ExportReports);

        return [
            Action::make('csv')
                ->label('CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible($canExport)
                ->action(fn (ReportExporter $exporter): StreamedResponse => $exporter->csv($this->filename(), $this->rows(), OutreachReport::COLUMNS)),
            Action::make('xlsx')
                ->label('Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->visible($canExport)
                ->action(fn (ReportExporter $exporter): BinaryFileResponse => $exporter->xlsx($this->filename(), $this->rows(), OutreachReport::COLUMNS)),
            Action::make('print')
                ->label('Print or save as PDF')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->visible($canExport)
                ->url(fn (): string => route('filament.admin.reports.print', ['tenant' => Filament::getTenant(), ...$this->filters()]), shouldOpenInNewTab: true),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return app(OutreachReport::class)->rows($this->filters()['grouping'], $this->filters(), acrossClients: $this->filters()['grouping'] === 'client');
    }

    /**
     * @return array<string, mixed>
     */
    public function totals(): array
    {
        return app(OutreachReport::class)->withRates(app(OutreachReport::class)->totals($this->filters(), acrossClients: $this->filters()['grouping'] === 'client'));
    }

    /**
     * The filters as chosen, with grouping by client only for Super Admins.
     *
     * @return array{start_date: string, end_date: string, grouping: string, campaign_ids: list<int>}
     */
    public function filters(): array
    {
        $grouping = (string) ($this->data['grouping'] ?? 'campaign');

        if (! array_key_exists($grouping, OutreachReport::GROUPINGS) || ($grouping === 'client' && ! auth()->user()->isSuperAdmin())) {
            $grouping = 'campaign';
        }

        return [
            'start_date' => (string) ($this->data['start_date'] ?? now()->subDays(29)->toDateString()),
            'end_date' => (string) ($this->data['end_date'] ?? now()->toDateString()),
            'grouping' => $grouping,
            'campaign_ids' => array_values(array_map(intval(...), (array) ($this->data['campaign_ids'] ?? []))),
        ];
    }

    private function filename(): string
    {
        $filters = $this->filters();

        return Str::slug('outreach-'.$filters['grouping'].'-'.$filters['start_date'].'-to-'.$filters['end_date']);
    }
}
