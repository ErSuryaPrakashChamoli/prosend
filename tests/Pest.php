<?php

use App\Enums\ApiKeyMode;
use App\Enums\ApiScope;
use App\Models\ApiKey;
use App\Models\SenderIdentity;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Issue an API key for tests and return it with its plaintext. By default it may send transactional email
 * from one verified sender identity and read its own email.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: ApiKey, 1: string, 2: SenderIdentity}
 */
function issueApiKey(array $attributes = [], ?SenderIdentity $senderIdentity = null): array
{
    $senderIdentity ??= SenderIdentity::factory()->create(['email' => 'billing@acme.test']);

    [$apiKey, $plaintext] = ApiKey::issue([
        'name' => 'Checkout service',
        'mode' => ApiKeyMode::Live,
        'scopes' => [ApiScope::EmailsSend->value, ApiScope::EmailsRead->value],
        'allowed_sender_identity_ids' => [$senderIdentity->id],
        'rate_limit_per_minute' => 60,
        ...$attributes,
    ]);

    return [$apiKey, $plaintext, $senderIdentity];
}

/**
 * The bytes of an Excel workbook (.xlsx) with the given rows. Dates get a date format, as Excel gives them.
 *
 * @param  list<list<mixed>>  $firstSheet
 * @param  list<list<mixed>>  $secondSheet
 */
function xlsxWorkbook(array $firstSheet, array $secondSheet = []): string
{
    $base = tempnam(sys_get_temp_dir(), 'prosend-test-');
    $path = $base.'.xlsx';
    $dateStyle = (new Style)->setFormat('yyyy-mm-dd hh:mm:ss');
    $toRow = fn (array $cells): Row => Row::fromValuesWithStyles($cells, columnStyles: array_map(
        fn (mixed $value): ?Style => $value instanceof DateTimeInterface ? $dateStyle : null,
        $cells,
    ));

    $writer = new XlsxWriter;
    $writer->openToFile($path);
    $writer->addRows(array_map($toRow, $firstSheet));

    if ($secondSheet !== []) {
        $writer->addNewSheetAndMakeItCurrent();
        $writer->addRows(array_map($toRow, $secondSheet));
    }

    $writer->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);
    unlink($base);

    return $bytes;
}
