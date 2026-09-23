<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Production\Models\ProductionStage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * @return array{company: Company, first_factory: Branch, second_factory: Branch, administrative: Branch, period: FinancialPeriod}
 */
function productionStageBranchFixture(): array
{
    $company = Company::query()->create([
        'doc_number' => 88001,
        'doc_num' => 'Company-88001',
        'name' => 'Production stage scope company',
        'status' => 'active',
        'is_main' => true,
    ]);
    $branches = collect([
        ['doc_number' => 88101, 'doc_num' => 'Branch-88101', 'name' => 'Factory One', 'type' => Branch::TypeFactory],
        ['doc_number' => 88102, 'doc_num' => 'Branch-88102', 'name' => 'Factory Two', 'type' => Branch::TypeFactory],
        ['doc_number' => 88103, 'doc_num' => 'Branch-88103', 'name' => 'Head Office', 'type' => Branch::TypeAdministrative],
    ])->map(fn (array $attributes): Branch => Branch::query()->create([
        ...$attributes,
        'company_id' => $company->getKey(),
        'status' => 'active',
    ]));
    $period = FinancialPeriod::query()->create([
        'doc_number' => 88201,
        'doc_num' => 'Period-88201',
        'company_id' => $company->getKey(),
        'name' => '2026',
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);

    return [
        'company' => $company,
        'first_factory' => $branches[0],
        'second_factory' => $branches[1],
        'administrative' => $branches[2],
        'period' => $period,
    ];
}

/** @return array<string, int|string> */
function productionStageSession(array $fixture, Branch $branch): array
{
    return [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];
}

function productionStageActor(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $permissions = [
        'production.stages.view',
        'production.stages.create',
        'production.stages.edit',
        'production.orders.view',
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

test('production stage screens require a factory operating branch', function (): void {
    $fixture = productionStageBranchFixture();
    $actor = productionStageActor();

    $this->actingAs($actor)
        ->withSession(productionStageSession($fixture, $fixture['administrative']))
        ->get(route('admin.production.stages.index'))
        ->assertForbidden();

    $this->actingAs($actor)
        ->withSession(productionStageSession($fixture, $fixture['administrative']))
        ->post(route('admin.production.stages.store'), [
            'name' => 'Forbidden stage',
            'display_order' => 1,
            'status' => ProductionStage::StatusActive,
        ])
        ->assertForbidden();

    expect(ProductionStage::query()->exists())->toBeFalse();
});

test('legacy stages stay shared until first edit while new stages belong to the current factory', function (): void {
    $fixture = productionStageBranchFixture();
    $actor = productionStageActor();
    $legacyStage = ProductionStage::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => null,
        'code' => 'LEGACY-STAGE',
        'name' => 'Legacy shared stage',
        'display_order' => 1,
        'status' => ProductionStage::StatusActive,
    ]);

    $this->actingAs($actor)
        ->withSession(productionStageSession($fixture, $fixture['first_factory']))
        ->getJson(route('admin.production.stages.data'))
        ->assertOk()
        ->assertSee('Legacy shared stage');

    $this->actingAs($actor)
        ->withSession(productionStageSession($fixture, $fixture['second_factory']))
        ->getJson(route('admin.production.stages.data'))
        ->assertOk()
        ->assertSee('Legacy shared stage');

    $this->actingAs($actor)
        ->withSession(productionStageSession($fixture, $fixture['first_factory']))
        ->post(route('admin.production.stages.store'), [
            'name' => 'Factory one stage',
            'display_order' => 2,
            'status' => ProductionStage::StatusActive,
        ])
        ->assertRedirect();

    $factoryStage = ProductionStage::query()->where('name', 'Factory one stage')->firstOrFail();
    expect($factoryStage->branch_id)->toBe($fixture['first_factory']->getKey());

    $secondFactoryData = $this->actingAs($actor)
        ->withSession(productionStageSession($fixture, $fixture['second_factory']))
        ->getJson(route('admin.production.stages.data'))
        ->assertOk()
        ->json('data');

    expect(collect($secondFactoryData)->pluck('name')->all())->not->toContain('Factory one stage');

    $this->actingAs($actor)
        ->withSession(productionStageSession($fixture, $fixture['first_factory']))
        ->put(route('admin.production.stages.update', $legacyStage), [
            'name' => 'Claimed legacy stage',
            'display_order' => 1,
            'status' => ProductionStage::StatusActive,
        ])
        ->assertRedirect();

    expect($legacyStage->refresh()->branch_id)->toBe($fixture['first_factory']->getKey())
        ->and($legacyStage->name)->toBe('Claimed legacy stage');

    $this->actingAs($actor)
        ->withSession(productionStageSession($fixture, $fixture['second_factory']))
        ->get(route('admin.production.stages.show', $legacyStage))
        ->assertNotFound();
});

test('production order stage lookup only returns global and current factory stages', function (): void {
    $fixture = productionStageBranchFixture();
    $actor = productionStageActor();

    foreach ([
        ['branch_id' => null, 'code' => 'GLOBAL', 'name' => 'Global stage'],
        ['branch_id' => $fixture['first_factory']->getKey(), 'code' => 'FIRST', 'name' => 'First factory stage'],
        ['branch_id' => $fixture['second_factory']->getKey(), 'code' => 'SECOND', 'name' => 'Second factory stage'],
    ] as $index => $stage) {
        ProductionStage::query()->create([
            ...$stage,
            'company_id' => $fixture['company']->getKey(),
            'display_order' => $index + 1,
            'status' => ProductionStage::StatusActive,
        ]);
    }

    $response = $this->actingAs($actor)
        ->withSession(productionStageSession($fixture, $fixture['first_factory']))
        ->getJson(route('admin.production.work-orders.select2.order-stages'))
        ->assertOk();

    $payload = json_encode($response->json(), JSON_THROW_ON_ERROR);
    expect($payload)
        ->toContain('Global stage')
        ->toContain('First factory stage')
        ->not->toContain('Second factory stage');
});
