<?php

use App\Services\Imports\SpreadsheetConverter;
use App\Services\Imports\UnreadableSpreadsheet;
use League\Csv\Reader as CsvReader;

/**
 * Convert workbook bytes and read the result back the way Filament's importer does, with default CSV settings.
 *
 * @return list<list<string>>
 */
function convertWorkbook(string $workbook, ?SpreadsheetConverter $converter = null): array
{
    $path = tempnam(sys_get_temp_dir(), 'prosend-test-');
    file_put_contents($path, $workbook);
    $csv = fopen('php://temp', 'w+');

    try {
        ($converter ?? app(SpreadsheetConverter::class))->convert($path, $csv);
    } finally {
        unlink($path);
    }

    rewind($csv);

    return array_values(iterator_to_array(CsvReader::from($csv)->getRecords(), preserve_keys: false));
}

/**
 * A hand-built workbook, for files Excel can produce but OpenSpout's writer cannot: cached formula results, hidden
 * sheets, and declared ranges.
 *
 * @param  list<array{xml: string, hidden?: bool}>  $sheets  Each sheet's <sheetData> (and optional <dimension>) XML.
 */
function handBuiltWorkbook(array $sheets): string
{
    $path = tempnam(sys_get_temp_dir(), 'prosend-test-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $relationships = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    $overrides = '';
    $sheetList = '';
    $sheetRelationships = '';

    foreach ($sheets as $index => $sheet) {
        $number = $index + 1;
        $state = ($sheet['hidden'] ?? false) ? ' state="hidden"' : '';
        $overrides .= "<Override PartName=\"/xl/worksheets/sheet{$number}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        $sheetList .= "<sheet name=\"Sheet{$number}\" sheetId=\"{$number}\" r:id=\"rId{$number}\"{$state}/>";
        $sheetRelationships .= "<Relationship Id=\"rId{$number}\" Type=\"{$relationships}/worksheet\" Target=\"worksheets/sheet{$number}.xml\"/>";
        $zip->addFromString("xl/worksheets/sheet{$number}.xml", "<?xml version=\"1.0\" encoding=\"UTF-8\"?><worksheet xmlns=\"{$main}\">{$sheet['xml']}</worksheet>");
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$overrides.'</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="'.$relationships.'/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><workbook xmlns=\"{$main}\" xmlns:r=\"{$relationships}\"><sheets>{$sheetList}</sheets></workbook>");
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$sheetRelationships.'</Relationships>');
    $zip->close();

    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

function inlineCell(string $reference, string $text): string
{
    return "<c r=\"{$reference}\" t=\"inlineStr\"><is><t>".htmlspecialchars($text, ENT_XML1).'</t></is></c>';
}

it('converts the first worksheet with text, numbers, dates, and booleans as plain values', function () {
    $rows = convertWorkbook(xlsxWorkbook([
        ['Email', 'First name', 'Signed up', 'Phone', 'Consent'],
        ['jane@example.com', ' Jane ', new DateTimeImmutable('2026-03-01'), 447700900123, true],
        ['sam@example.com'],
        ['kim@example.com', 'Kim', new DateTimeImmutable('2026-03-02 14:30:00'), null, false, 'a note beyond the header'],
    ], secondSheet: [['Email'], ['ignored@example.com']]));

    expect($rows)->toBe([
        ['Email', 'First name', 'Signed up', 'Phone', 'Consent'],
        ['jane@example.com', 'Jane', '2026-03-01', '447700900123', 'TRUE'],
        ['sam@example.com', '', '', '', ''],
        ['kim@example.com', 'Kim', '2026-03-02 14:30:00', '', 'FALSE'],
    ]);
});

it('keeps values that are awkward in CSV exactly as they were', function () {
    $values = ['Jane \\', 'say "hi", ok', 'a\\"b', "two\nlines", 'semi;colon|pipe'];

    $rows = convertWorkbook(xlsxWorkbook([['Value'], ...array_map(fn (string $value): array => [$value], $values)]));

    expect(array_map(fn (array $row): string => trim($row[0]), array_slice($rows, 1)))->toBe($values);
});

it('imports the result of a formula, not the formula, and keeps text that only looks like one', function () {
    $rows = convertWorkbook(handBuiltWorkbook([['xml' => '<sheetData><row r="1">'.inlineCell('A1', 'Email').inlineCell('B1', 'Note').'</row>'
        .'<row r="2"><c r="A2" t="str"><f>LOWER("JANE")&amp;"@example.com"</f><v>jane@example.com</v></c>'.inlineCell('B2', '=not a formula').'</row></sheetData>']]));

    expect($rows)->toBe([['Email', 'Note'], ['jane@example.com', '=not a formula']]);
});

it('keeps numbers at full precision', function () {
    $rows = convertWorkbook(handBuiltWorkbook([['xml' => '<sheetData><row r="1">'.inlineCell('A1', 'Score').inlineCell('B1', 'Ratio').'</row>'
        .'<row r="2"><c r="A2"><v>12345678.1234567</v></c><c r="B2"><v>0.1</v></c></row></sheetData>']]));

    expect($rows)->toBe([['Score', 'Ratio'], ['12345678.1234567', '0.1']]);
});

it('drops header padding from the used range and rows that only have notes beyond the header', function () {
    $rows = convertWorkbook(handBuiltWorkbook([['xml' => '<dimension ref="A1:D3"/><sheetData>'
        .'<row r="1" spans="1:4">'.inlineCell('A1', 'Email').inlineCell('B1', 'Name').'</row>'
        .'<row r="2" spans="1:4">'.inlineCell('A2', 'jane@example.com').inlineCell('B2', 'Jane').inlineCell('D2', 'call back').'</row>'
        .'<row r="3" spans="1:4">'.inlineCell('D3', 'total: 1').'</row>'
        .'</sheetData>']]));

    expect($rows)->toBe([['Email', 'Name'], ['jane@example.com', 'Jane']]);
});

it('skips hidden worksheets', function () {
    $rows = convertWorkbook(handBuiltWorkbook([
        ['xml' => '<sheetData><row r="1">'.inlineCell('A1', 'Archived').'</row></sheetData>', 'hidden' => true],
        ['xml' => '<sheetData><row r="1">'.inlineCell('A1', 'Email').'</row><row r="2">'.inlineCell('A2', 'jane@example.com').'</row></sheetData>'],
    ]));

    expect($rows)->toBe([['Email'], ['jane@example.com']]);
});

it('refuses workbooks it cannot safely or usefully read', function (Closure $workbook, string $message, ?SpreadsheetConverter $converter = null) {
    expect(fn () => convertWorkbook($workbook(), $converter))
        ->toThrow(UnreadableSpreadsheet::class, $message);
})->with([
    'not a workbook' => [fn (): string => "Email\njane@example.com\n", 'This file is not a readable Excel workbook.'],
    'empty first visible sheet' => [fn (): string => handBuiltWorkbook([['xml' => '<sheetData/>'], ['xml' => '<sheetData><row r="1">'.inlineCell('A1', 'Email').'</row></sheetData>']]), 'The first visible worksheet is empty.'],
    'declared range far too wide' => [fn (): string => handBuiltWorkbook([['xml' => '<dimension ref="A1:XFD2"/><sheetData><row r="1">'.inlineCell('A1', 'Email').'</row></sheetData>']]), 'The workbook declares more than 500 columns.'],
    'row span that would exhaust memory' => [fn (): string => handBuiltWorkbook([['xml' => '<sheetData><row r="1" spans="1:500000000">'.inlineCell('A1', 'Email').'</row></sheetData>']]), 'The workbook declares more than 500 columns.'],
    'header far too wide' => [fn (): string => handBuiltWorkbook([['xml' => '<sheetData><row r="1">'.inlineCell('A1', 'Email').inlineCell('ZZ1', 'Notes').'</row></sheetData>']]), 'The header row has more than 500 columns.'],
    'too many rows' => [fn (): string => xlsxWorkbook([['Email'], ['a@example.com'], ['b@example.com'], ['c@example.com']]), 'The worksheet has more than 2 rows.', new SpreadsheetConverter(maxRows: 2)],
    'too large uncompressed' => [fn (): string => xlsxWorkbook([['Email'], ['a@example.com']]), 'This workbook is too large to import.', new SpreadsheetConverter(maxUncompressedBytes: 100)],
]);
