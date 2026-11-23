<?php

namespace App\Filament\Imports;

use App\Services\Imports\SpreadsheetConverter;
use App\Services\Imports\UnreadableSpreadsheet;
use Closure;
use Filament\Actions\ImportAction;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Str;
use League\Csv\Reader as CsvReader;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Filament's import action, also accepting Excel workbooks. A workbook's first visible worksheet is converted to CSV
 * whenever the action reads the file, so mapping, validation and processing are the same as for a CSV file.
 */
class SpreadsheetImportAction extends ImportAction
{
    /**
     * @var list<string>
     */
    public const SPREADSHEET_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        // Older file-type databases report an .xlsx file as a plain zip archive.
        'application/zip',
    ];

    /**
     * Whether the stream handed out last is a converted workbook. Filament reads a stream and then asks for its
     * delimiter, and a converted workbook is always comma-separated, so the delimiter is never guessed for it.
     */
    private bool $isReadingSpreadsheet = false;

    protected function setUp(): void
    {
        parent::setUp();

        $schema = $this->schema;

        $this->schema(function () use ($schema): array {
            $components = $this->evaluate($schema);

            foreach ($components as $component) {
                if ($component instanceof FileUpload && $component->getName() === 'file') {
                    $component
                        ->acceptedFileTypes([...$component->getAcceptedFileTypes(), ...self::SPREADSHEET_MIME_TYPES])
                        ->helperText('A CSV file or an Excel workbook (.xlsx). From a workbook, only the first visible worksheet is imported.');
                }
            }

            return $components;
        });
    }

    /**
     * @return array<mixed>
     */
    public function getFileValidationRules(): array
    {
        $rules = array_map(
            fn (mixed $rule): mixed => $rule === 'extensions:csv,txt' ? 'extensions:csv,txt,xlsx' : $rule,
            parent::getFileValidationRules(),
        );

        return [
            fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (! $value instanceof TemporaryUploadedFile || ! static::isSpreadsheet($value)) {
                    return;
                }

                try {
                    $this->convertedCsvPath($value);
                } catch (UnreadableSpreadsheet $exception) {
                    $fail($exception->getMessage());
                }
            },
            ...$rules,
        ];
    }

    /**
     * @return resource|false
     */
    public function getUploadedFileStream(TemporaryUploadedFile $file)
    {
        $this->isReadingSpreadsheet = static::isSpreadsheet($file);

        if (! $this->isReadingSpreadsheet) {
            return parent::getUploadedFileStream($file);
        }

        try {
            return fopen($this->convertedCsvPath($file), 'r');
        } catch (UnreadableSpreadsheet) {
            return false;
        }
    }

    public function getCsvDelimiter(?CsvReader $reader = null): ?string
    {
        return $this->isReadingSpreadsheet ? ',' : parent::getCsvDelimiter($reader);
    }

    public static function isSpreadsheet(TemporaryUploadedFile $file): bool
    {
        return strtolower($file->getClientOriginalExtension()) === 'xlsx';
    }

    /**
     * The workbook is converted once and the CSV kept next to the upload, because Filament reads the file in several
     * requests while the form is filled in. Livewire deletes old temporary uploads, and with them these copies.
     *
     * @throws UnreadableSpreadsheet
     */
    private function convertedCsvPath(TemporaryUploadedFile $file): string
    {
        $workbookPath = $this->localWorkbookPath($file);
        $csvPath = $workbookPath.'.csv';

        if (is_file($csvPath)) {
            return $csvPath;
        }

        $partialPath = $csvPath.'.'.Str::random(8).'.part';
        $csv = fopen($partialPath, 'w');

        try {
            app(SpreadsheetConverter::class)->convert($workbookPath, $csv);
        } catch (UnreadableSpreadsheet $exception) {
            fclose($csv);
            @unlink($partialPath);

            throw $exception;
        }

        fclose($csv);
        rename($partialPath, $csvPath);

        return $csvPath;
    }

    /**
     * OpenSpout needs a file path. Uploads on a remote disk are copied to a local file that is removed when the
     * request ends, whatever happens.
     */
    private function localWorkbookPath(TemporaryUploadedFile $file): string
    {
        $path = $file->getRealPath();

        if (is_string($path) && is_file($path)) {
            return $path;
        }

        $copyPath = sys_get_temp_dir().'/prosend-xlsx-'.Str::random(16).'.xlsx';
        register_shutdown_function(function () use ($copyPath): void {
            @unlink($copyPath);
            @unlink($copyPath.'.csv');
        });

        $source = $file->readStream();
        $copy = fopen($copyPath, 'w');

        try {
            if ($source === false || $copy === false || stream_copy_to_stream($source, $copy) === false) {
                throw UnreadableSpreadsheet::notAWorkbook();
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($copy)) {
                fclose($copy);
            }
        }

        return $copyPath;
    }
}
