<?php

use App\Enums\ConsentStatus;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Models\Contact;
use App\Models\User;
use App\Services\Imports\SpreadsheetConverter;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

it('imports contacts from the first worksheet of an Excel workbook', function () {
    $this->actingAs(User::factory()->sender()->create());
    $workbook = UploadedFile::fake()->createWithContent('contacts.xlsx', xlsxWorkbook([
        ['Email', 'First name', 'Job title'],
        ['jane@example.com', 'Jane', 'Head of Operations'],
        ['not an email', 'Bad', ''],
        ['sam@example.com', 'Sam', ''],
    ]));

    Livewire::test(ListContacts::class)
        ->callAction('import', data: [
            'file' => $workbook,
            'columnMap' => ['email' => 'Email', 'first_name' => 'First name', 'job_title' => 'Job title'],
            'default_consent_status' => ConsentStatus::Unknown->value,
            'confirmed_lawful_basis' => true,
        ])
        ->assertHasNoActionErrors();

    expect(Contact::query()->orderBy('email')->get()->map->only(['email', 'first_name', 'job_title'])->all())->toBe([
        ['email' => 'jane@example.com', 'first_name' => 'Jane', 'job_title' => 'Head of Operations'],
        ['email' => 'sam@example.com', 'first_name' => 'Sam', 'job_title' => null],
    ]);
});

it('rejects an .xlsx file that is not a readable workbook', function () {
    $this->actingAs(User::factory()->sender()->create());
    $notAWorkbook = UploadedFile::fake()->createWithContent('contacts.xlsx', "Email\njane@example.com\n");

    Livewire::test(ListContacts::class)
        ->callAction('import', data: [
            'file' => $notAWorkbook,
            'columnMap' => ['email' => 'Email'],
            'default_consent_status' => ConsentStatus::Unknown->value,
            'confirmed_lawful_basis' => true,
        ])
        ->assertHasActionErrors(['file']);

    expect(Contact::query()->count())->toBe(0);
});

it('still imports CSV files', function () {
    $this->actingAs(User::factory()->sender()->create());
    $csv = UploadedFile::fake()->createWithContent('contacts.csv', "Email,First name\njane@example.com,Jane\n");

    Livewire::test(ListContacts::class)
        ->callAction('import', data: [
            'file' => $csv,
            'columnMap' => ['email' => 'Email', 'first_name' => 'First name'],
            'default_consent_status' => ConsentStatus::Unknown->value,
            'confirmed_lawful_basis' => true,
        ])
        ->assertHasNoActionErrors();

    expect(Contact::query()->sole()->first_name)->toBe('Jane');
});

it('applies the consent chosen in the import form to new contacts', function () {
    $this->actingAs(User::factory()->sender()->create());
    $csv = UploadedFile::fake()->createWithContent('contacts.csv', "Email\njane@example.com\n");

    Livewire::test(ListContacts::class)
        ->callAction('import', data: [
            'file' => $csv,
            'columnMap' => ['email' => 'Email'],
            'default_consent_status' => ConsentStatus::Granted->value,
            'consent_source' => 'Website newsletter form',
            'confirmed_lawful_basis' => true,
        ])
        ->assertHasNoActionErrors();

    expect(Contact::query()->sole())
        ->consent_status->toBe(ConsentStatus::Granted)
        ->consent_source->toBe('Website newsletter form');
});

it('requires saying where consent was given when importing contacts as consented', function () {
    $this->actingAs(User::factory()->sender()->create());
    $csv = UploadedFile::fake()->createWithContent('contacts.csv', "Email\njane@example.com\n");

    Livewire::test(ListContacts::class)
        ->callAction('import', data: [
            'file' => $csv,
            'columnMap' => ['email' => 'Email'],
            'default_consent_status' => ConsentStatus::Granted->value,
            'consent_source' => '',
            'confirmed_lawful_basis' => true,
        ])
        ->assertHasActionErrors(['consent_source' => 'required']);

    expect(Contact::query()->count())->toBe(0);
});

it('reads a workbook as comma-separated even when cells are full of semicolons', function () {
    $this->actingAs(User::factory()->sender()->create());
    $rows = [['Email', 'Tags'], ...array_map(fn (int $number): array => ["person{$number}@example.com", 'customer;newsletter;vip'], range(1, 9))];
    $workbook = UploadedFile::fake()->createWithContent('contacts.xlsx', xlsxWorkbook($rows));

    Livewire::test(ListContacts::class)
        ->callAction('import', data: [
            'file' => $workbook,
            'columnMap' => ['email' => 'Email', 'tags' => 'Tags'],
            'default_consent_status' => ConsentStatus::Unknown->value,
            'confirmed_lawful_basis' => true,
        ])
        ->assertHasNoActionErrors();

    expect(Contact::query()->count())->toBe(9)
        ->and(Contact::query()->where('email', 'person1@example.com')->sole()->tags)->toBe(['customer;newsletter;vip']);
});

it('converts a workbook once however often the import form reads it', function () {
    $this->actingAs(User::factory()->sender()->create());
    $converter = Mockery::mock(SpreadsheetConverter::class, [SpreadsheetConverter::MAX_ROWS, SpreadsheetConverter::MAX_UNCOMPRESSED_BYTES])->makePartial();
    $converter->shouldReceive('convert')->once()->passthru();
    $this->app->instance(SpreadsheetConverter::class, $converter);
    $workbook = UploadedFile::fake()->createWithContent('contacts.xlsx', xlsxWorkbook([['Email'], ['jane@example.com']]));

    Livewire::test(ListContacts::class)
        ->mountAction('import')
        ->fillForm(['file' => $workbook])
        ->fillForm(['default_consent_status' => ConsentStatus::Granted->value, 'consent_source' => 'Website form'])
        ->fillForm(['columnMap' => ['email' => 'Email'], 'confirmed_lawful_basis' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Contact::query()->sole()->email)->toBe('jane@example.com');
});

it('explains why a workbook with an empty first sheet cannot be imported', function () {
    $this->actingAs(User::factory()->sender()->create());
    $workbook = UploadedFile::fake()->createWithContent('contacts.xlsx', xlsxWorkbook([[]], secondSheet: [['Email'], ['jane@example.com']]));

    Livewire::test(ListContacts::class)
        ->callAction('import', data: [
            'file' => $workbook,
            'columnMap' => ['email' => 'Email'],
            'default_consent_status' => ConsentStatus::Unknown->value,
            'confirmed_lawful_basis' => true,
        ])
        ->assertHasActionErrors(['file']);

    expect(Contact::query()->count())->toBe(0);
});
