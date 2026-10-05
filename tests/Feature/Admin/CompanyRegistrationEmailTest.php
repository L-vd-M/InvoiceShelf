<?php

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\putJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $user = User::find(1);
    $this->company = $user->companies()->first();
    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($user, ['*']);
});

test('company registration number and email are saved and returned', function () {
    putJson('api/v1/company', [
        'name' => 'TARCZA (Pty) Ltd',
        'registration_number' => '2020/123456/07',
        'email' => 'accounts@tarcza.example',
        'address' => ['country_id' => 2],
    ])
        ->assertOk()
        ->assertJsonPath('data.registration_number', '2020/123456/07')
        ->assertJsonPath('data.email', 'accounts@tarcza.example');

    $this->assertDatabaseHas('companies', [
        'id' => $this->company->id,
        'registration_number' => '2020/123456/07',
        'email' => 'accounts@tarcza.example',
    ]);
});

test('company registration number and email are optional', function () {
    putJson('api/v1/company', [
        'name' => 'No Reg Co',
        'address' => ['country_id' => 2],
    ])->assertOk();

    expect($this->company->fresh()->registration_number)->toBeNull();
});

test('company email must be a valid address', function () {
    putJson('api/v1/company', [
        'name' => 'Bad Mail Co',
        'email' => 'not-an-email',
        'address' => ['country_id' => 2],
    ])->assertJsonValidationErrors('email');
});

test('registration number is limited to 50 characters', function () {
    putJson('api/v1/company', [
        'name' => 'Long Reg Co',
        'registration_number' => str_repeat('1', 51),
        'address' => ['country_id' => 2],
    ])->assertJsonValidationErrors('registration_number');
});

test('pdf fields expose company registration number and email', function () {
    $this->company->update([
        'registration_number' => '2020/123456/07',
        'email' => 'accounts@tarcza.example',
    ]);

    $invoice = Invoice::factory()->create(['company_id' => $this->company->id]);
    $fields = $invoice->fresh()->getFieldsArray();

    expect($fields['{COMPANY_REGISTRATION_NUMBER}'])->toBe('2020/123456/07')
        ->and($fields['{COMPANY_EMAIL}'])->toBe('accounts@tarcza.example');
});
