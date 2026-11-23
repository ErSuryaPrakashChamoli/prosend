<?php

namespace App\Services\Analytics;

use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a report as CSV or Excel. Like every ProSend export, a text cell starting like a formula gets a leading
 * apostrophe, so names such as "=HYPERLINK(…)" never run in a spreadsheet.
 */
class ReportExporter
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $columns
     */
    public function csv(string $filename, array $rows, array $columns): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows, $columns): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_values($columns), escape: '');

            foreach ($rows as $row) {
                fputcsv($out, $this->cells($row, $columns), escape: '');
            }

            fclose($out);
        }, "{$filename}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $columns
     */
    public function xlsx(string $filename, array $rows, array $columns): BinaryFileResponse
    {
        $path = sys_get_temp_dir().'/prosend-report-'.Str::random(32).'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_values($columns)));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($this->cells($row, $columns)));
        }

        $writer->close();

        return response()->download($path, "{$filename}.xlsx")->deleteFileAfterSend();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $columns
     * @return list<string|int|float>
     */
    private function cells(array $row, array $columns): array
    {
        return array_map(function (string $key) use ($row): string|int|float {
            $value = $row[$key] ?? '';

            return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'{$value}" : $value;
        }, array_keys($columns));
    }
}
