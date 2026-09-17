<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\PriceList;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\QuotationPaymentMilestone;
use Modules\Sales\Models\QuotationRevision;
use Modules\Sales\Models\SalesOrder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

function quotationPdfText(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'quotation-pdf-');
    file_put_contents($path, $content);

    try {
        $process = new Process(['pdftotext', '-layout', $path, '-']);
        $process->mustRun();

        return $process->getOutput();
    } finally {
        @unlink($path);
    }
}

function quotationPdfPageCount(string $content): int
{
    $path = tempnam(sys_get_temp_dir(), 'quotation-pdf-');
    file_put_contents($path, $content);

    try {
        $process = new Process(['pdfinfo', $path]);
        $process->mustRun();
        preg_match('/^Pages:\s+(\d+)$/m', $process->getOutput(), $matches);

        return (int) ($matches[1] ?? 0);
    } finally {
        @unlink($path);
    }
}

function quotationActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);
    auth()->login($user);
    request()->setUserResolver(fn (): User => $user);

    return $user;
}

/**
 * @return array{company: Company, branch: Branch, period: FinancialPeriod, currency: Currency, customer: Customer, store: BranchStore}
 */
function quotationContext(): array
{
    test()->seed(DefaultOperatingContextSeeder::class);
    test()->seed(CurrencySeeder::class);

    $company = Company::query()->where('status', 'active')->orderBy('id')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->orderBy('id')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->orderBy('id')->firstOrFail();
    $currency = Currency::query()->where('company_id', $company->getKey())->orderByDesc('is_main')->orderBy('id')->firstOrFail();
    $customer = Customer::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 9501,
        'doc_num' => 'Customer-09501',
        'name' => 'Quotation Customer',
        'status' => 'active',
    ]);
    $store = BranchStore::query()->create([
        'branch_id' => $branch->getKey(),
        'name' => 'Quotation Finished Goods',
        'position' => 1,
    ]);

    quotationSelectContext($company, $branch, $period);

    return compact('company', 'branch', 'period', 'currency', 'customer', 'store');
}

function quotationSelectContext(Company $company, Branch $branch, FinancialPeriod $period): void
{
    if (! request()->hasSession()) {
        request()->setLaravelSession(app('session.store'));
    }

    $context = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];

    session($context);
    test()->withSession($context);
}

/**
 * @return array{unit: ItemUnit, product: Product}
 */
function quotationProductFixture(Company $company): array
{
    $unit = ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 501,
        'doc_num' => 'Unit-00501',
        'name' => 'Piece',
        'status' => 'active',
    ]);

    $product = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 901,
        'doc_num' => 'Product-00901',
        'name' => 'Oak Desk',
        'barcode' => 'OD-901',
        'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);
    $currency = Currency::query()->where('company_id', $company->getKey())->orderByDesc('is_main')->orderBy('id')->firstOrFail();
    quotationSetPrice($product, $currency, '100');

    return compact('unit', 'product');
}

function quotationSetPrice(Product $product, Currency $currency, string $price): PriceList
{
    $priceList = PriceList::query()->firstOrCreate([
        'company_id' => $product->company_id,
        'customer_id' => null,
        'currency_id' => $currency->getKey(),
        'valid_from' => '2026-01-01',
    ], [
        'doc_number' => PriceList::query()->count() + 1,
        'doc_num' => 'PL-'.str_pad((string) (PriceList::query()->count() + 1), 5, '0', STR_PAD_LEFT),
        'price_list_date' => '2026-01-01',
        'valid_until' => null,
    ]);
    $priceList->lines()->updateOrCreate(['product_id' => $product->getKey()], [
        'line_number' => 1,
        'unit_price' => $price,
        'allowed_discount_type' => null,
        'allowed_discount_value' => 0,
    ]);

    return $priceList;
}

/**
 * @return array<string, mixed>
 */
function quotationPayload(Product $product, ItemUnit $unit, ?Currency $currency = null, array $overrides = []): array
{
    return [
        'customer_doc_num' => Customer::query()->forCompany((int) $product->company_id)->active()->value('doc_num'),
        'quotation_type' => Quotation::TypeProject,
        'project_name' => 'Hotel Lobby Fitout',
        'subject' => 'Lobby desks package',
        'quotation_date' => '2026-06-18',
        'valid_until' => '2026-07-18',
        'currency_doc_num' => $currency?->doc_num,
        'exchange_rate' => '1',
        'revision_date' => '2026-06-18',
        'change_reason' => 'Initial commercial proposal',
        'discount_type' => null,
        'discount_value' => '0',
        'terms' => '<p>General commercial terms.</p>',
        'payment_terms' => '<p>Payment by agreed milestones.</p>',
        'execution_terms' => '<p>Execution scope includes manufacturing and installation.</p>',
        'warranty_terms' => '<p>One year warranty.</p>',
        'delivery_terms' => '<p>Delivery to customer site.</p>',
        'technical_notes' => '<p>Use approved shop drawings.</p>',
        'notes' => '<p>Internal note snapshot.</p>',
        'customer_reference' => 'PO-QUOTE-001',
        'internal_notes' => 'Internal conversion note.',
        'lines' => [
            [
                'product_doc_num' => $product->doc_num,
                'description' => 'Custom oak reception desk',
                'unit_doc_num' => $unit->doc_num,
                'quantity' => '2',
                'unit_price' => '100',
                'discount_type' => null,
                'discount_value' => '0',
                'tax_rate' => '14',
                'notes' => 'Line note',
                'requested_date' => '2026-07-10',
                'specifications' => ['packaging' => 'Export carton', 'customer_specification' => 'Customer-approved oak finish'],
                'warehouse_notes' => 'Keep dry',
                'production_notes' => 'Priority cut',
            ],
        ],
        'payment_milestones' => [
            [
                'title' => 'Contract advance',
                'description' => 'Due on contract signature',
                'percentage' => '50',
                'amount' => '114',
                'due_type' => QuotationPaymentMilestone::DueOnContract,
                'notes' => 'Finance approval required',
            ],
        ],
        'execution_schedule_lines' => [
            [
                'phase_name' => 'Manufacturing',
                'description' => 'Workshop production',
                'start_date' => '2026-06-20',
                'end_date' => '2026-06-30',
                'duration_days' => '10',
                'responsibility' => 'Production',
                'notes' => 'Priority order',
            ],
        ],
        ...$overrides,
    ];
}

/**
 * @return array{actor: User, company: Company, quotation: Quotation, product: Product, unit: ItemUnit, currency: Currency}
 */
function createQuotationThroughHttp(array $extraPermissions = [], array $payloadOverrides = []): array
{
    $context = quotationContext();
    ['unit' => $unit, 'product' => $product] = quotationProductFixture($context['company']);
    foreach ($payloadOverrides['lines'] ?? [] as $line) {
        if (($line['product_doc_num'] ?? null) === $product->doc_num && filled($line['unit_price'] ?? null)) {
            quotationSetPrice($product, $context['currency'], (string) $line['unit_price']);
        }
    }
    $permissions = array_values(array_unique([
        'quotations.view',
        'quotations.create',
        'quotations.edit',
        'quotations.delete',
        'quotations.restore',
        'quotations.view_trashed',
        'quotations.revisions.view',
        'quotations.revisions.create',
        'quotations.mark_sent',
        'quotations.accept',
        'quotations.reject',
        'quotations.cancel',
        ...$extraPermissions,
    ]));
    $actor = quotationActor($permissions);
    test()->actingAs($actor)
        ->postJson(route('admin.sales.quotations.store'), quotationPayload($product, $unit, $context['currency'], $payloadOverrides))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.doc_num', 'QT-00001');

    return [
        'actor' => $actor,
        'company' => $context['company'],
        'quotation' => Quotation::query()->where('doc_num', 'QT-00001')->firstOrFail(),
        'product' => $product,
        'unit' => $unit,
        'currency' => $context['currency'],
    ];
}

test('quotation creation creates quotation revision one lines milestones and schedule', function (): void {
    $context = quotationContext();
    ['unit' => $unit, 'product' => $product] = quotationProductFixture($context['company']);
    $actor = quotationActor(['quotations.view', 'quotations.create']);

    $this->actingAs($actor)
        ->postJson(route('admin.sales.quotations.store'), quotationPayload($product, $unit, $context['currency']))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('reset_form', true)
        ->assertJsonPath('data.doc_num', 'QT-00001');

    $quotation = Quotation::query()->with('currentRevision.lines', 'currentRevision.paymentMilestones', 'currentRevision.executionScheduleLines')->firstOrFail();
    $revision = $quotation->currentRevision;

    expect($quotation->doc_num)->toBe('QT-00001')
        ->and($quotation->status)->toBe(Quotation::StatusDraft)
        ->and($revision)->toBeInstanceOf(QuotationRevision::class)
        ->and($revision->revision_number)->toBe(1)
        ->and($revision->revision_code)->toBe('QT-00001-R01')
        ->and((float) $revision->subtotal)->toBe(200.0)
        ->and((float) $revision->tax_amount)->toBe(28.0)
        ->and((float) $revision->total)->toBe(228.0)
        ->and($revision->lines)->toHaveCount(1)
        ->and(Str::isUuid($revision->lines->first()->public_uuid))->toBeTrue()
        ->and($revision->lines->first()->product_name_snapshot)->toBe('Oak Desk')
        ->and($revision->lines->first()->unit_name_snapshot)->toBe('Piece')
        ->and($revision->paymentMilestones)->toHaveCount(1)
        ->and($revision->executionScheduleLines)->toHaveCount(1);
});

test('quotation rich text removes executable markup while preserving safe formatting', function (): void {
    $maliciousTerms = <<<'HTML'
<p onclick=alert(1)>Approved <strong>commercial terms</strong>
<a href=javascript:alert(2)>unsafe link</a>
<a href="https://example.com/terms">safe link</a>
<img src="data:text/html;base64,PHNjcmlwdD4=" onerror=alert(3)>
<script>alert(4)</script></p>
HTML;
    ['actor' => $actor, 'quotation' => $quotation] = createQuotationThroughHttp([], [
        'terms' => $maliciousTerms,
    ]);
    $storedTerms = (string) $quotation->currentRevision->terms_snapshot;

    expect($storedTerms)->toContain('<strong>commercial terms</strong>', 'https://example.com/terms')
        ->and(strtolower($storedTerms))->not->toContain(
            'javascript:',
            'data:text/html',
            'onclick',
            'onerror',
            '<script',
        );

    $this->actingAs($actor)
        ->get(route('admin.sales.quotations.show', $quotation))
        ->assertOk()
        ->assertDontSee('javascript:', false)
        ->assertDontSee('onerror', false)
        ->assertDontSee('alert(4)', false);
});

test('quotation grouped numeric input persists canonically and displays grouped totals', function (): void {
    $context = quotationContext();
    ['unit' => $unit, 'product' => $product] = quotationProductFixture($context['company']);
    $actor = quotationActor(['quotations.view', 'quotations.create', 'quotations.edit']);
    quotationSetPrice($product, $context['currency'], '2.5');
    $payload = quotationPayload($product, $unit, $context['currency'], [
        'lines' => [[
            'product_doc_num' => $product->doc_num,
            'description' => 'Grouped numeric line',
            'unit_doc_num' => $unit->doc_num,
            'quantity' => '1,250.5',
            'unit_price' => '2.5',
            'discount_type' => null,
            'discount_value' => '0',
            'tax_rate' => '0',
        ]],
        'payment_milestones' => [],
    ]);

    $response = $this->actingAs($actor)
        ->postJson(route('admin.sales.quotations.store'), $payload)
        ->assertOk()
        ->assertJsonPath('success', true);

    $quotation = Quotation::query()
        ->where('doc_num', $response->json('data.doc_num'))
        ->with('currentRevision.lines')
        ->firstOrFail();
    $line = $quotation->currentRevision->lines->sole();

    expect((string) $line->quantity)->toBe('1250.50000000')
        ->and((string) $line->unit_price)->toBe('2.5000')
        ->and((string) $quotation->currentRevision->total)->toBe('3126.2500');

    $this->actingAs($actor)
        ->get(route('admin.sales.quotations.edit', $quotation->doc_num))
        ->assertOk()
        ->assertSee('value="1,250.5"', false)
        ->assertSee('value="2.5"', false);

    $row = $this->actingAs($actor)
        ->getJson(route('admin.sales.quotations.data', ['draw' => 1, 'start' => 0, 'length' => 10]))
        ->assertOk()
        ->json('data.0');

    expect(strip_tags($row['total']))->toBe('3,126.25');

    $payload['lines'][0]['quantity'] = '1,2,3';

    $this->actingAs($actor)
        ->postJson(route('admin.sales.quotations.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lines.0.quantity']);
});

test('quotation totals stay consistent across save view edit and print without readonly recalculation', function (): void {
    $context = quotationContext();
    ['unit' => $unit, 'product' => $firstProduct] = quotationProductFixture($context['company']);
    $secondProduct = Product::query()->create([
        'company_id' => $context['company']->getKey(),
        'doc_number' => 902,
        'doc_num' => 'Product-00902',
        'name' => 'Clear Carton',
        'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);
    quotationSetPrice($firstProduct, $context['currency'], '400');
    quotationSetPrice($secondProduct, $context['currency'], '725');
    $actor = quotationActor(['quotations.view', 'quotations.create', 'quotations.edit', 'quotations.print']);
    $lines = [
        [
            'product_doc_num' => $firstProduct->doc_num,
            'description' => 'White PP item',
            'unit_doc_num' => $unit->doc_num,
            'quantity' => '10',
            'unit_price' => '400',
            'discount_type' => null,
            'discount_value' => '0',
            'tax_rate' => '5',
        ],
        [
            'product_doc_num' => $secondProduct->doc_num,
            'description' => 'Clear PS item',
            'unit_doc_num' => $unit->doc_num,
            'quantity' => '5',
            'unit_price' => '725',
            'discount_type' => null,
            'discount_value' => '0',
            'tax_rate' => '5',
        ],
    ];
    $payload = quotationPayload($firstProduct, $unit, $context['currency'], [
        'quotation_type' => Quotation::TypeStandard,
        'project_name' => null,
        'discount_type' => null,
        'discount_value' => '0',
        'lines' => $lines,
        'payment_milestones' => [],
        'execution_schedule_lines' => [],
    ]);

    $response = $this->actingAs($actor)
        ->postJson(route('admin.sales.quotations.store'), $payload)
        ->assertOk();
    $quotation = Quotation::query()
        ->with('currentRevision.lines')
        ->where('doc_num', $response->json('data.doc_num'))
        ->sole();

    expect((string) $quotation->currentRevision->subtotal)->toBe('7625.0000')
        ->and((string) $quotation->currentRevision->discount_amount)->toBe('0.0000')
        ->and((string) $quotation->currentRevision->tax_amount)->toBe('381.2500')
        ->and((string) $quotation->currentRevision->total)->toBe('8006.2500')
        ->and($quotation->currentRevision->lines->pluck('line_total')->map(fn ($value): string => (string) $value)->all())
        ->toBe(['4200.0000', '3806.2500']);

    $showHtml = $this->actingAs($actor)
        ->get(route('admin.sales.quotations.show', $quotation))
        ->assertOk()
        ->assertSee('data-readonly="1"', false)
        ->getContent();
    $show = HTMLDocument::createFromString($showHtml, LIBXML_NOERROR);

    expect(trim($show->querySelector('.js-quotation-subtotal')->textContent))->toBe('7,625')
        ->and(trim($show->querySelector('.js-quotation-discount')->textContent))->toBe('0')
        ->and(trim($show->querySelector('.js-quotation-tax')->textContent))->toBe('381.25')
        ->and(trim($show->querySelector('.js-quotation-total')->textContent))->toBe('8,006.25')
        ->and(collect($show->querySelectorAll('.js-quotation-line-total'))->map(fn ($node): string => trim($node->textContent))->all())
        ->toBe(['4,200', '3,806.25']);

    $this->actingAs($actor)
        ->get(route('admin.sales.quotations.edit', $quotation))
        ->assertOk()
        ->assertSee('data-readonly="0"', false)
        ->assertSee('name="discount_type"', false)
        ->assertSee('name="discount_value"', false);

    $this->actingAs($actor)
        ->putJson(route('admin.sales.quotations.update', $quotation), $payload)
        ->assertOk();
    expect((string) $quotation->refresh()->currentRevision->total)->toBe('8006.2500');

    $print = $this->actingAs($actor)
        ->withSession(['locale' => 'en'])
        ->get(route('admin.sales.quotations.print', $quotation))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    expect(quotationPdfText($print->getContent()))->toContain('8,006.25');

    $script = file_get_contents(public_path('assets/js/modules/Sales/quotations.js'));
    expect($script)->toContain('if (!readonly) {')
        ->toContain("\$row.find('.js-quotation-line-discount-amount').text(decimal(discount))");
});

test('quotation project and discount contracts reject contradictory input', function (): void {
    $context = quotationContext();
    ['unit' => $unit, 'product' => $product] = quotationProductFixture($context['company']);
    $actor = quotationActor(['quotations.create']);

    $this->actingAs($actor)
        ->postJson(route('admin.sales.quotations.store'), quotationPayload($product, $unit, $context['currency'], [
            'project_name' => null,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('project_name');

    $payload = quotationPayload($product, $unit, $context['currency'], [
        'quotation_type' => Quotation::TypeStandard,
        'project_name' => 'Must be cleared',
        'discount_type' => null,
        'discount_value' => '10',
    ]);
    $this->postJson(route('admin.sales.quotations.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('discount_value');
});

test('draft revision can be updated in place', function (): void {
    ['actor' => $actor, 'quotation' => $quotation, 'product' => $product, 'unit' => $unit, 'currency' => $currency] = createQuotationThroughHttp();
    $originalRevisionId = $quotation->current_revision_id;
    quotationSetPrice($product, $currency, '120');

    $this->actingAs($actor)
        ->putJson(route('admin.sales.quotations.update', $quotation->doc_num), quotationPayload($product, $unit, $currency, [
            'subject' => 'Updated lobby desks package',
            'lines' => [
                [
                    'product_doc_num' => $product->doc_num,
                    'description' => 'Updated custom desk',
                    'unit_doc_num' => $unit->doc_num,
                    'quantity' => '3',
                    'unit_price' => '120',
                    'tax_rate' => '10',
                ],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $quotation->refresh()->load('currentRevision.lines');

    expect($quotation->current_revision_id)->toBe($originalRevisionId)
        ->and($quotation->subject)->toBe('Updated lobby desks package')
        ->and($quotation->currentRevision->lines)->toHaveCount(1)
        ->and((float) $quotation->currentRevision->subtotal)->toBe(360.0)
        ->and((float) $quotation->currentRevision->tax_amount)->toBe(36.0)
        ->and((float) $quotation->currentRevision->total)->toBe(396.0);
});

test('sent revision cannot be edited directly', function (): void {
    ['actor' => $actor, 'quotation' => $quotation, 'product' => $product, 'unit' => $unit, 'currency' => $currency] = createQuotationThroughHttp();

    $this->actingAs($actor)
        ->postJson(route('admin.sales.quotations.mark-sent', $quotation->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($actor)
        ->putJson(route('admin.sales.quotations.update', $quotation->doc_num), quotationPayload($product, $unit, $currency, [
            'subject' => 'Should not persist',
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.document.0', __('quotations.messages.revision_not_draft'));

    expect($quotation->refresh()->subject)->toBe('Lobby desks package');
});

test('creating a new revision copies lines snapshots milestones and schedule', function (): void {
    ['actor' => $actor, 'quotation' => $quotation] = createQuotationThroughHttp();

    $this->actingAs($actor)
        ->postJson(route('admin.sales.quotations.mark-sent', $quotation->doc_num))
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.sales.quotations.revisions.create', $quotation->doc_num), ['change_reason' => 'Customer requested alternate pricing'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.revision_code', 'QT-00001-R02');

    $quotation->refresh()->load('currentRevision.lines', 'currentRevision.paymentMilestones', 'currentRevision.executionScheduleLines', 'revisions');
    $oldRevision = QuotationRevision::query()->where('revision_code', 'QT-00001-R01')->firstOrFail();
    $newRevision = $quotation->currentRevision;

    expect($oldRevision->status)->toBe(QuotationRevision::StatusSuperseded)
        ->and($newRevision->revision_number)->toBe(2)
        ->and($newRevision->status)->toBe(QuotationRevision::StatusDraft)
        ->and($newRevision->change_reason)->toBe('Customer requested alternate pricing')
        ->and($newRevision->lines)->toHaveCount(1)
        ->and($newRevision->lines->first()->product_name_snapshot)->toBe('Oak Desk')
        ->and($newRevision->lines->first()->line_total)->toBe('228.0000')
        ->and($newRevision->paymentMilestones)->toHaveCount(1)
        ->and($newRevision->executionScheduleLines)->toHaveCount(1);
});

test('revision history is rendered on quotation pages', function (): void {
    ['actor' => $actor, 'quotation' => $quotation] = createQuotationThroughHttp();

    $this->actingAs($actor)
        ->get(route('admin.sales.quotations.show', $quotation->doc_num))
        ->assertOk()
        ->assertSee(__('quotations.tabs.revisions'))
        ->assertSee('QT-00001-R01', false)
        ->assertDontSee('data-id=', false);
});

test('quotation status transitions work', function (): void {
    ['actor' => $actor, 'quotation' => $quotation] = createQuotationThroughHttp();

    $this->actingAs($actor)
        ->postJson(route('admin.sales.quotations.mark-sent', $quotation->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($quotation->refresh()->status)->toBe(Quotation::StatusSent)
        ->and($quotation->currentRevision->status)->toBe(QuotationRevision::StatusSent);

    $this->actingAs($actor)
        ->postJson(route('admin.sales.quotations.accept', $quotation->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($quotation->refresh()->status)->toBe(Quotation::StatusAccepted)
        ->and($quotation->currentRevision->status)->toBe(QuotationRevision::StatusAccepted);
});

test('accepted quotation converts once into a fully linked sales order without re-entry', function (): void {
    ['actor' => $actor, 'quotation' => $quotation] = createQuotationThroughHttp([
        'quotations.print',
        'sales_orders.create',
        'sales_orders.view',
        'sales_orders.view_prices',
    ], [
        'valid_until' => now()->addMonth()->toDateString(),
        'lines' => [[
            'product_doc_num' => 'Product-00901',
            'description' => 'Canonical quoted line',
            'unit_doc_num' => 'Unit-00501',
            'quantity' => '2',
            'unit_price' => '100',
            'discount_type' => null,
            'discount_value' => '0',
            'tax_rate' => '14',
            'requested_date' => now()->addDays(10)->toDateString(),
            'specifications' => ['packaging' => 'Export carton', 'customer_specification' => 'Approved finish'],
            'notes' => 'Customer line note',
            'warehouse_notes' => 'Keep dry',
            'production_notes' => 'Priority cut',
        ]],
    ]);

    $this->actingAs($actor)->postJson(route('admin.sales.quotations.mark-sent', $quotation))->assertOk();
    $this->actingAs($actor)->postJson(route('admin.sales.quotations.accept', $quotation))->assertOk();

    $quotationPdf = $this->actingAs($actor)->get(route('admin.sales.quotations.print', $quotation));
    $quotationPdf->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($quotationPdf->headers->get('content-disposition'))->toStartWith('inline; filename=')
        ->and($quotationPdf->getContent())->toStartWith('%PDF-')
        ->and(quotationPdfText($quotationPdf->getContent()))->toContain('Canonical quoted line')->not->toContain('Export carton');

    $response = $this->actingAs($actor)
        ->postJson(route('admin.sales.quotations.convert', $quotation))
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.doc_num', 'SO-00001');

    $order = SalesOrder::query()->with(['lines', 'paymentSchedules', 'quotation', 'quotationRevision'])->sole();
    $sourceLine = $quotation->refresh()->currentRevision->lines()->sole();

    expect($quotation->status)->toBe(Quotation::StatusConverted)
        ->and($order->quotation_id)->toBe($quotation->getKey())
        ->and($order->quotation_revision_id)->toBe($quotation->current_revision_id)
        ->and($order->customer_reference)->toBe('PO-QUOTE-001')
        ->and($order->internal_notes)->toBe('Internal conversion note.')
        ->and($order->total_amount)->toBe($quotation->currentRevision->total)
        ->and($order->lines)->toHaveCount(1)
        ->and($order->lines->sole()->quotation_revision_line_id)->toBe($sourceLine->getKey())
        ->and($order->lines->sole()->price_list_line_id)->toBe($sourceLine->price_list_line_id)
        ->and($order->lines->sole()->allowed_discount_type)->toBe($sourceLine->allowed_discount_type)
        ->and($order->lines->sole()->allowed_discount_value)->toBe($sourceLine->allowed_discount_value)
        ->and($order->lines->sole()->base_quantity)->toBe('2.00000000')
        ->and($order->lines->sole()->specifications)->toBe(['packaging' => 'Export carton', 'customer_specification' => 'Approved finish'])
        ->and($order->lines->sole()->warehouse_notes)->toBe('Keep dry')
        ->and($order->lines->sole()->production_notes)->toBe('Priority cut')
        ->and($order->paymentSchedules)->toHaveCount(1)
        ->and($order->paymentSchedules->sum('amount'))->toEqual(228.0);

    $this->actingAs($actor)
        ->get(route('admin.sales.quotations.show', $quotation))
        ->assertOk()
        ->assertSee($order->doc_num);

    $this->actingAs($actor)
        ->get(route('admin.sales.sales-orders.show', $order))
        ->assertOk()
        ->assertSee($quotation->doc_num)
        ->assertSee(sprintf('R%02d', $quotation->currentRevision->revision_number))
        ->assertDontSee($quotation->currentRevision->revision_code);

    $this->actingAs($actor)
        ->postJson(route('admin.sales.quotations.convert', $quotation))
        ->assertUnprocessable();

    expect(SalesOrder::query()->count())->toBe(1)
        ->and($response->json('data.url'))->toContain($order->doc_num);
});

test('25-line quotation remains complete across English and Arabic mPDF pages', function (): void {
    $lines = collect(range(1, 25))->map(fn (int $lineNumber): array => [
        'product_doc_num' => 'Product-00901',
        'description' => sprintf('QUOTE-STRESS-%02d English multi-page quotation description with Arabic content وصف عربي متعدد الصفحات لاختبار اكتمال عرض السعر', $lineNumber),
        'unit_doc_num' => 'Unit-00501',
        'quantity' => '1',
        'unit_price' => '10',
        'discount_type' => null,
        'discount_value' => '0',
        'tax_rate' => '14',
        'requested_date' => now()->addMonth()->toDateString(),
        'specifications' => ['packaging' => 'Stress carton', 'customer_specification' => 'Preserve every row'],
        'notes' => 'Multi-page line',
        'warehouse_notes' => 'No clipping',
        'production_notes' => 'Keep row together',
    ])->all();
    ['actor' => $actor, 'quotation' => $quotation] = createQuotationThroughHttp(['quotations.print'], ['lines' => $lines]);

    foreach (['en', 'ar'] as $locale) {
        $response = $this->actingAs($actor)
            ->withSession(['locale' => $locale])
            ->get(route('admin.sales.quotations.print', $quotation));
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        expect($response->headers->get('content-disposition'))->toStartWith('inline; filename=')
            ->and($response->getContent())->toStartWith('%PDF-')
            ->and(quotationPdfPageCount($response->getContent()))->toBeGreaterThan(1);

        $text = quotationPdfText($response->getContent());
        if ($directory = getenv('PROCUREMENT_PRINT_SAMPLES')) {
            file_put_contents($directory.'/quotation-multipage-'.$locale.'.pdf', $response->getContent());
        }
        foreach (range(1, 25) as $lineNumber) {
            expect($text)->toContain(sprintf('QUOTE-STRESS-%02d', $lineNumber));
        }
        $linePages = collect(explode("\f", $text))->filter(fn (string $page): bool => str_contains($page, 'QUOTE-STRESS'));
        expect($linePages->count())->toBeGreaterThan(1)
            ->and($linePages->every(fn (string $page): bool => str_contains($page, '#')))->toBeTrue()
            ->and(preg_match_all('/\d+\/\d+/', $text))->toBeGreaterThan(1)
            ->and($text)->toContain('285');
    }
});

test('quotation rejects forged internal inventory products server side', function (): void {
    $context = quotationContext();
    ['unit' => $unit, 'product' => $product] = quotationProductFixture($context['company']);
    $raw = Product::query()->create([
        'company_id' => $context['company']->getKey(),
        'doc_number' => 902,
        'doc_num' => 'Product-RAW-00902',
        'name' => 'Raw Resin',
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);
    $actor = quotationActor(['quotations.create']);

    $payload = quotationPayload($product, $unit, $context['currency']);
    $payload['lines'][0]['product_doc_num'] = $raw->doc_num;

    $this->actingAs($actor)
        ->postJson(route('admin.sales.quotations.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lines.0.product_doc_num']);

    expect(Quotation::query()->count())->toBe(0);
});

test('quotation product picker excludes trashed products and legacy drafts require replacement', function (): void {
    ['actor' => $actor, 'quotation' => $quotation, 'product' => $product, 'unit' => $unit, 'currency' => $currency] = createQuotationThroughHttp();

    $product->delete();

    $picker = $this->actingAs($actor)
        ->getJson(route('admin.sales.select2.quotation-products', ['q' => $product->doc_num]))
        ->assertOk()
        ->json();

    expect(json_encode($picker, JSON_THROW_ON_ERROR))->not->toContain($product->doc_num);

    $response = $this->actingAs($actor)
        ->putJson(route('admin.sales.quotations.update', $quotation->doc_num), quotationPayload($product, $unit, $currency))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lines.0.product_doc_num']);

    expect($response->json('errors')['lines.0.product_doc_num'][0] ?? null)
        ->toBe(__('quotations.messages.deleted_product_requires_replacement', [
            'product' => $product->doc_num,
        ]));

    $this->actingAs($actor)
        ->get(route('admin.sales.quotations.edit', $quotation->doc_num))
        ->assertOk()
        ->assertSee($product->name);
});

test('active sales documents prevent deleting a referenced product', function (): void {
    ['actor' => $actor, 'quotation' => $quotation, 'product' => $product] = createQuotationThroughHttp(['products.delete']);

    $this->actingAs($actor)
        ->deleteJson(route('admin.products.destroy', $product->doc_num))
        ->assertConflict()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', __('products.messages.active_document_delete_blocked', ['document' => $quotation->doc_num]));

    expect($product->fresh()?->trashed())->toBeFalse();
});

test('quotations data table returns expected public columns', function (): void {
    ['actor' => $actor] = createQuotationThroughHttp();

    $row = $this->actingAs($actor)
        ->getJson(route('admin.sales.quotations.data', ['draw' => 1, 'start' => 0, 'length' => 10]))
        ->assertOk()
        ->json('data.0');

    expect($row)->toHaveKeys([
        'checkbox',
        'doc_num',
        'customer',
        'subject_project',
        'quotation_type',
        'current_revision',
        'status',
        'currency',
        'total',
        'quotation_date',
        'valid_until',
        'created_by',
        'updated_by',
        'actions',
        'edit_url',
        'can_edit',
    ])
        ->and($row['doc_num'])->toContain('QT-00001')
        ->and($row)->not->toHaveKey('id')
        ->and(json_encode($row, JSON_THROW_ON_ERROR))->not->toContain('data-id=');
});

test('quotation permissions are discovered by registry and seeder', function (): void {
    $this->seed(PermissionSeeder::class);

    $permissions = app(PermissionRegistryService::class)->all();

    foreach ([
        'quotations.view',
        'quotations.create',
        'quotations.clone',
        'quotations.edit',
        'quotations.delete',
        'quotations.view_trashed',
        'quotations.restore',
        'quotations.document_number.control',
        'quotations.document_number_settings.update',
        'quotations.revisions.view',
        'quotations.revisions.create',
        'quotations.mark_sent',
        'quotations.accept',
        'quotations.reject',
        'quotations.cancel',
        'quotations.print',
        'quotations.attachments.manage',
    ] as $permission) {
        expect($permissions)->toContain($permission)
            ->and(Permission::query()->where('name', $permission)->exists())->toBeTrue();
    }
});

test('quotations soft delete and restore by document number', function (): void {
    ['actor' => $actor, 'quotation' => $quotation] = createQuotationThroughHttp();

    $this->actingAs($actor)
        ->deleteJson(route('admin.sales.quotations.destroy', $quotation->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(Quotation::withTrashed()->where('doc_num', $quotation->doc_num)->first()?->trashed())->toBeTrue();

    $this->actingAs($actor)
        ->patchJson(route('admin.sales.quotations.restore', $quotation->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(Quotation::query()->where('doc_num', $quotation->doc_num)->exists())->toBeTrue();
});

test('quotation document number settings can be updated', function (): void {
    quotationContext();
    $actor = quotationActor(['quotations.view', 'quotations.document_number_settings.update']);

    $this->actingAs($actor)
        ->putJson(route('admin.sales.quotations.document-number-settings.update'), [
            'prefix' => 'QX-',
            'padding' => 4,
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.prefix', 'QX-')
        ->assertJsonPath('data.padding', 4);
});

test('quotation tables include required operational detail structures', function (): void {
    expect(Schema::hasColumns('quotations', ['doc_number', 'doc_num', 'branch_id', 'customer_reference', 'internal_notes', 'print_identity_snapshot', 'current_revision_id', 'deleted_by', 'restored_by', 'restored_at']))->toBeTrue()
        ->and(Schema::hasColumns('quotation_revisions', ['revision_number', 'revision_code', 'terms_snapshot', 'payment_terms_snapshot', 'execution_terms_snapshot', 'warranty_terms_snapshot', 'delivery_terms_snapshot', 'technical_notes_snapshot']))->toBeTrue()
        ->and(Schema::hasColumns('quotation_revision_lines', ['public_uuid', 'product_name_snapshot', 'unit_name_snapshot', 'specs_snapshot', 'conversion_factor', 'base_quantity', 'requested_date', 'specifications', 'warehouse_notes', 'production_notes']))->toBeTrue()
        ->and(Schema::hasColumns('quotation_payment_milestones', ['title', 'percentage', 'amount', 'due_type', 'due_date']))->toBeTrue()
        ->and(Schema::hasColumns('quotation_execution_schedule_lines', ['phase_name', 'start_date', 'end_date', 'duration_days']))->toBeTrue();
});

test('quotation exchange rate keeps maximum accepted precision before persistence', function (): void {
    $context = quotationContext();
    ['unit' => $unit, 'product' => $product] = quotationProductFixture($context['company']);
    $actor = quotationActor(['quotations.create']);
    $currency = Currency::query()->create([
        'company_id' => $context['company']->getKey(),
        'doc_number' => 902,
        'doc_num' => 'Currency-00902',
        'name' => 'Precision Currency',
        'code' => 'PRC',
        'minor_unit_name' => 'Part',
        'minor_unit_factor' => 100,
        'is_main' => false,
        'status' => 'active',
    ]);
    quotationSetPrice($product, $currency, '100');
    $capturedExchangeRate = null;

    Quotation::creating(function (Quotation $quotation) use (&$capturedExchangeRate): void {
        $capturedExchangeRate = $quotation->getAttributes()['exchange_rate'] ?? null;
    });

    $this->actingAs($actor)
        ->postJson(route('admin.sales.quotations.store'), quotationPayload($product, $unit, $currency, [
            'exchange_rate' => '999,999,999,999.999999',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($capturedExchangeRate)->toBe('999999999999.999999');
});

test('quotation prints company identity without commercial or tax registration numbers', function (): void {
    ['quotation' => $quotation, 'company' => $company, 'actor' => $actor] = createQuotationThroughHttp(['quotations.print']);
    $company->forceFill(['show_company_identity_on_prints' => false])->save();
    $identity = $quotation->print_identity_snapshot ?? [];
    $quotation->forceFill(['print_identity_snapshot' => [
        ...$identity, 'company_id' => $company->getKey(), 'name' => 'QUOTATION IDENTITY REQUIRED', 'legal_name' => 'QUOTATION IDENTITY REQUIRED',
        'address' => 'Factory Road 42', 'email' => 'factory@example.test',
        'commercial_register_number' => 'CR-SHOULD-NOT-PRINT',
        'tax_card_number' => 'TAX-CARD-SHOULD-NOT-PRINT',
        'vat_registration_number' => 'VAT-SHOULD-NOT-PRINT',
        'logo_source' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAKAAAAAyCAIAAABUA0cyAAAACXBIWXMAAA7EAAAOxAGVKw4bAAABR0lEQVR4nO3bUY6CMBgA4XWz91hvocfYPSnX4BgcxYcmTfNTaolFzTjfk8GChBGoJJ5+L39f4vp+9Q7oWAaGMzCcgeEMDGdgOAPDGRjOwHAGhjMwnIHhDAxnYDgDwxkYzsBwBob76Rm0zFN1+fn6H8aUS6rrpgF3N1gOqC6sbrBnfz5NV+CkcbDyoV/mqXGUl3lKA0KzsOVyYV6luhvrdxUMuETnHuHsXMfrKRHWap/xYcuNj/5Yjwbe2+O4g16e8dbNdlyiq/fF56ve1PNr6wZj7sEv8W778552BG64e48caGvypaoxv4PTDKucHm8Z9VXonHzpwAcd6wY9PfrnwzbuMeYSvSXNevbOzsJaXocfcfLPZ2w+i4YzMJyB4QwMZ2A4A8MZGM7AcAaGMzCcgeEMDGdgOAPDGRjOwHAGhjMwnIHhDAx3A4Npkgj1aQnLAAAAAElFTkSuQmCC',
    ]])->save();
    foreach (['ar', 'en'] as $locale) {
        $pdf = $this->actingAs($actor)->withSession(['locale' => $locale])->get(route('admin.sales.quotations.print', $quotation))
            ->assertOk()->assertHeader('content-type', 'application/pdf')->getContent();
        expect(quotationPdfText($pdf))->toContain('QUOTATION IDENTITY REQUIRED')->toContain('factory@example.test')
            ->not->toContain('CR-SHOULD-NOT-PRINT', 'TAX-CARD-SHOULD-NOT-PRINT', 'VAT-SHOULD-NOT-PRINT')
            ->and(substr_count($pdf, '/Subtype /Image'))->toBeGreaterThan(0);
        if ($directory = getenv('PROCUREMENT_PRINT_SAMPLES')) {
            file_put_contents($directory.'/quotation-identity-'.$locale.'.pdf', $pdf);
        }
    }
});
