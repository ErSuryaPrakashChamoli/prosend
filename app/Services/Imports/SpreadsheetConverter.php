<?php

namespace App\Services\Imports;

use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Number;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Reader\XLSX\Sheet;
use XMLReader;
use ZipArchive;

/**
 * Turns the first visible worksheet of an Excel workbook (.xlsx) into CSV, so Excel files go through exactly the
 * same import, validation and consent rules as CSV files. The workbook is read row by row, never loaded whole.
 */
class SpreadsheetConverter
{
    /**
     * OpenSpout allocates every row to the column count a workbook declares, so a tiny file declaring millions of
     * columns would exhaust memory. Declarations are checked before OpenSpout reads anything.
     */
    public const MAX_COLUMNS = 500;

    public const MAX_ROWS = 100_000;

    /**
     * Uncompressed size of everything in the workbook, which stops zip bombs.
     */
    public const MAX_UNCOMPRESSED_BYTES = 200 * 1024 * 1024;

    public function __construct(
        private int $maxRows = self::MAX_ROWS,
        private int $maxUncompressedBytes = self::MAX_UNCOMPRESSED_BYTES,
    ) {}

    /**
     * Write the first visible worksheet to the CSV stream. Its first row is the header; empty header cells at the
     * end, and anything beyond them, are dropped.
     *
     * @param  resource  $csv
     *
     * @throws UnreadableSpreadsheet With a message for the person who uploaded the file.
     */
    public function convert(string $workbookPath, $csv): void
    {
        $this->ensureSafeToRead($workbookPath);

        $reader = new Reader;

        try {
            $reader->open($workbookPath);

            try {
                $this->copySheet($this->firstVisibleSheet($reader), $csv);
            } finally {
                $reader->close();
            }
        } catch (OpenSpoutException) {
            throw UnreadableSpreadsheet::notAWorkbook();
        }
    }

    /**
     * @throws UnreadableSpreadsheet
     */
    private function ensureSafeToRead(string $workbookPath): void
    {
        $zip = new ZipArchive;

        if ($zip->open($workbookPath, ZipArchive::RDONLY) !== true) {
            throw UnreadableSpreadsheet::notAWorkbook();
        }

        try {
            $uncompressedBytes = 0;
            $worksheets = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);

                if ($entry === false) {
                    throw UnreadableSpreadsheet::notAWorkbook();
                }

                $uncompressedBytes += $entry['size'];

                if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $entry['name']) === 1) {
                    $worksheets[] = $entry['name'];
                }
            }

            if ($uncompressedBytes > $this->maxUncompressedBytes) {
                throw new UnreadableSpreadsheet('This workbook is too large to import. Split it into smaller files or save it as CSV.');
            }
        } finally {
            $zip->close();
        }

        foreach ($worksheets as $worksheet) {
            $this->ensureDeclaredColumnsAreSane('zip://'.$workbookPath.'#'.$worksheet);
        }
    }

    /**
     * Reads only the declarations OpenSpout sizes rows from: the sheet's dimension and each row's spans.
     *
     * @throws UnreadableSpreadsheet
     */
    private function ensureDeclaredColumnsAreSane(string $worksheetUri): void
    {
        $xml = new XMLReader;

        if (! @$xml->open($worksheetUri, flags: LIBXML_NONET)) {
            throw UnreadableSpreadsheet::notAWorkbook();
        }

        try {
            while (@$xml->read()) {
                if ($xml->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                $declaredColumns = match ($xml->localName) {
                    'dimension' => $this->lastColumnNumber((string) $xml->getAttribute('ref')),
                    'row' => (int) substr((string) strrchr((string) $xml->getAttribute('spans'), ':'), 1),
                    default => 0,
                };

                if ($declaredColumns > self::MAX_COLUMNS) {
                    throw new UnreadableSpreadsheet('The workbook declares more than '.Number::format(self::MAX_COLUMNS).' columns. Delete the unused columns, or save the sheet as CSV.');
                }
            }
        } finally {
            $xml->close();
        }
    }

    /**
     * The column number of the last cell in a range such as "A1:XFD2000", or 0 when there is no range.
     */
    private function lastColumnNumber(string $range): int
    {
        if (preg_match('/:([A-Z]+)\d+$/', $range, $matches) !== 1) {
            return 0;
        }

        return array_reduce(str_split($matches[1]), fn (int $number, string $letter): int => $number * 26 + ord($letter) - 64, 0);
    }

    /**
     * @throws UnreadableSpreadsheet
     */
    private function firstVisibleSheet(Reader $reader): Sheet
    {
        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->isVisible()) {
                return $sheet;
            }
        }

        throw UnreadableSpreadsheet::empty();
    }

    /**
     * @param  resource  $csv
     *
     * @throws UnreadableSpreadsheet
     */
    private function copySheet(Sheet $sheet, $csv): void
    {
        $columnCount = null;
        $writtenRows = 0;

        foreach ($sheet->getRowIterator() as $row) {
            $cells = $row->getCells();

            // The header decides the width: empty cells at its end, often padding from the sheet's used range, are dropped.
            if ($columnCount === null) {
                $columnCount = $this->headerWidth($cells);

                if ($columnCount === 0) {
                    throw UnreadableSpreadsheet::empty();
                }

                if ($columnCount > self::MAX_COLUMNS) {
                    throw new UnreadableSpreadsheet('The header row has more than '.Number::format(self::MAX_COLUMNS).' columns. Delete the unused columns, or save the sheet as CSV.');
                }
            }

            $values = [];

            for ($column = 0; $column < $columnCount; $column++) {
                $values[] = isset($cells[$column]) ? $this->cellText($cells[$column]) : '';
            }

            // Rows with values only beyond the header, such as notes, are not contacts.
            if (implode('', $values) === '') {
                continue;
            }

            // The header plus the maximum number of contacts.
            if ($writtenRows > $this->maxRows) {
                throw new UnreadableSpreadsheet('The worksheet has more than '.Number::format($this->maxRows).' rows. Split it into smaller files.');
            }

            fputcsv($csv, array_map($this->csvSafe(...), $values), ',', '"', '\\');
            $writtenRows++;
        }

        if ($columnCount === null) {
            throw UnreadableSpreadsheet::empty();
        }
    }

    /**
     * @param  array<int, Cell>  $cells
     */
    private function headerWidth(array $cells): int
    {
        $width = 0;

        foreach ($cells as $column => $cell) {
            if ($this->cellText($cell) !== '') {
                $width = max($width, $column + 1);
            }
        }

        return $width;
    }

    private function cellText(Cell $cell): string
    {
        // A formula's result is what the person sees in Excel, so that is what is imported. OpenSpout also reads text
        // that merely starts with "=" as a formula, without a result; that text is kept as it is.
        $value = match (true) {
            $cell instanceof FormulaCell => $cell->getComputedValue() ?? $cell->getValue(),
            $cell instanceof ErrorCell => null,
            default => $cell->getValue(),
        };

        return match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $this->dateText($value),
            $value instanceof DateInterval => sprintf('%02d:%02d:%02d', ($value->days === false ? $value->d : $value->days) * 24 + $value->h, $value->i, $value->s),
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_float($value) => is_finite($value) && floor($value) === $value && abs($value) < 1e15 ? sprintf('%.0f', $value) : var_export($value, true),
            default => trim((string) $value),
        };
    }

    private function dateText(DateTimeInterface $value): string
    {
        // Excel stores a time without a date on its epoch day.
        if (in_array($value->format('Y-m-d'), ['1899-12-30', '1899-12-31'], true)) {
            return $value->format('H:i:s');
        }

        return $value->format($value->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i:s');
    }

    /**
     * The CSV reader treats a backslash before a quote as escaping it, so a value ending in a backslash would swallow
     * the rest of the file. A trailing space keeps the backslash literal; the importer trims values anyway.
     */
    private function csvSafe(string $value): string
    {
        return str_ends_with($value, '\\') ? $value.' ' : $value;
    }
}
