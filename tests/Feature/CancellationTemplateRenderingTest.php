<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Finance\Models\Cheque;
use Spatie\Permission\Models\Permission;

require_once dirname(__DIR__).'/SalesCycleSupport.php';

test('company print components render identity and authorization while respecting the print policy', function (string $locale): void {
    app()->setLocale($locale);
    $company = new Company([
        'name' => 'Synthetic template company', 'legal_name' => 'Synthetic legal identity',
        'commercial_register_number' => 'SYNTHETIC-REGISTER', 'authorized_signatory_name' => 'Synthetic signatory',
        'show_company_identity_on_prints' => false,
    ]);
    $identity = app(CompanyPrintIdentityService::class)->forCompany($company);
    $template = <<<'BLADE'
        <x-company-print-header :identity="$identity" :company="$company" :policy="$policy" />
        <x-company-print-authorization :identity="$identity" :company="$company" :policy="$policy" />
    BLADE;

    expect(Blade::render($template, compact('identity', 'company') + ['policy' => 'report']))
        ->toContain('Synthetic legal identity', 'SYNTHETIC-REGISTER', 'Synthetic signatory');
    expect(Blade::render($template, compact('identity', 'company') + ['policy' => 'quotation']))
        ->toContain('Synthetic legal identity', 'Synthetic signatory')->not->toContain('SYNTHETIC-REGISTER');
    expect(Blade::render($template, compact('identity', 'company') + ['policy' => 'operational']))
        ->not->toContain('Synthetic legal identity', 'Synthetic signatory');
    $company->show_company_identity_on_prints = true;
    expect(Blade::render($template, compact('identity', 'company') + ['policy' => 'operational']))
        ->toContain('Synthetic legal identity', 'Synthetic signatory');
})->with(['ar', 'en']);

test('collected cheque correction displays the native missing receipt blocker without changing history', function (string $locale): void {
    $fixture = salesCycleFixture();
    foreach (['cheques.view', 'cheques.cancel', 'customer_receipts.cancel'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->actingAs($fixture['user'])->withSession([...salesCycleSession($fixture), 'locale' => $locale]);
    $cheque = Cheque::query()->create([
        'doc_number' => 99001, 'doc_num' => 'SYNTHETIC-COLLECTED-TEMPLATE',
        'company_id' => $fixture['company']->id, 'currency_id' => $fixture['currency']->id,
        'cheque_type' => Cheque::TypeReceived, 'cheque_number' => 'SYNTHETIC-99001',
        'cheque_date' => now()->toDateString(), 'due_date' => now()->toDateString(),
        'amount' => '10', 'amount_base' => '10', 'exchange_rate' => '1',
        'reason' => 'Synthetic historical cheque rendering regression.',
        'status' => Cheque::StatusCollected, 'collected_at' => now(),
    ]);
    $before = [$cheque->fresh()->getRawOriginal(), DB::table('journal_entries')->count(), DB::table('cheque_collection_corrections')->count()];
    $this->get(route('admin.finance.cheques.collection-correction', $cheque->doc_num))->assertOk()
        ->assertSee(__('cheque_collection_correction.native_receipt_required'))
        ->assertDontSee(route('admin.finance.cheques.collection-corrections.prepare', $cheque->doc_num), false);
    expect([$cheque->fresh()->getRawOriginal(), DB::table('journal_entries')->count(), DB::table('cheque_collection_corrections')->count()])->toBe($before);
    $fixture['user']->revokePermissionTo('cheques.view');
    $this->get(route('admin.finance.cheques.collection-correction', $cheque->doc_num))->assertForbidden();
})->with(['ar', 'en']);
