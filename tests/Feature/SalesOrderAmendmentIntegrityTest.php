<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;

require_once dirname(__DIR__).'/SalesCycleSupport.php';

beforeEach(function (): void {
    $this->fixture = salesCycleFixture();
    $this->actingAs($this->fixture['user'])->withSession(salesCycleSession($this->fixture));
    request()->setLaravelSession(app('session.store'));
    request()->session()->put(salesCycleSession($this->fixture));
});

test('direct sales amendment and reopen enforce their separate permissions', function (): void {
    $orders = app(SalesOrderService::class);
    $order = $orders->approve($orders->create(salesCycleOrderPayload($this->fixture)));
    $this->fixture['user']->revokePermissionTo('sales_orders.reopen');
    expect(fn () => $orders->reopen($order, 'Denied synthetic attempt.'))->toThrow(AuthorizationException::class);
    expect($order->fresh()->status)->toBe(SalesOrder::StatusApproved);
    $this->fixture['user']->givePermissionTo('sales_orders.reopen');
    $order = $orders->reopen($order, 'Authorized synthetic amendment.');
    $before = [$order->attributesToArray(), $order->lines()->get()->map->attributesToArray()->all(), DB::table('activity_log')->count()];
    $this->fixture['user']->revokePermissionTo('sales_orders.edit');
    expect(fn () => $orders->update($order, [...salesCycleOrderPayload($this->fixture), 'amendment_token' => $order->amendmentToken()]))->toThrow(AuthorizationException::class);
    expect([$order->fresh()->attributesToArray(), $order->lines()->get()->map->attributesToArray()->all(), DB::table('activity_log')->count()])->toBe($before);
});

test('a direct reopened sales amendment requires a current token and rejects stale replay atomically', function (): void {
    $orders = app(SalesOrderService::class);
    $payload = salesCycleOrderPayload($this->fixture);
    $order = $orders->reopen($orders->approve($orders->create($payload)), 'Synthetic version acceptance.');
    expect(fn () => $orders->update($order, $payload))->toThrow(DomainException::class, __('sales_ui.amendment_stale'));
    $payload['amendment_token'] = $order->amendmentToken();
    $payload['lines'][0]['quantity'] = '101';
    $payload['payment_schedules'][0]['amount'] = '1110';
    $payload['status'] = SalesOrder::StatusCancelled;
    $payload['approved_at'] = null;
    $payload['reopened_at'] = null;
    $payload['reopen_snapshot'] = null;
    $updated = $orders->update($order, $payload);
    expect($updated->status)->toBe(SalesOrder::StatusReopened)->and($updated->approved_at)->not->toBeNull()
        ->and($updated->reopened_at)->not->toBeNull()->and($updated->reopen_snapshot)->not->toBeNull();
    $snapshot = [$updated->attributesToArray(), $updated->lines()->get()->map->attributesToArray()->all(), $updated->paymentSchedules()->get()->map->attributesToArray()->all(), DB::table('activity_log')->count()];
    $payload['lines'][0]['quantity'] = '102';
    $payload['payment_schedules'][0]['amount'] = '1120';
    expect(fn () => $orders->update($order, $payload))->toThrow(DomainException::class, __('sales_ui.amendment_stale'));
    expect([$updated->fresh()->attributesToArray(), $updated->lines()->get()->map->attributesToArray()->all(), $updated->paymentSchedules()->get()->map->attributesToArray()->all(), DB::table('activity_log')->count()])->toBe($snapshot);
});

test('unchanged manual closure survives reapproval while an approved quantity amendment resumes fulfillment', function (): void {
    $orders = app(SalesOrderService::class);
    $payload = salesCycleOrderPayload($this->fixture);
    $order = $orders->approve($orders->create($payload));
    $order->update(['status' => SalesOrder::StatusClosed]);
    $order->statusHistory()->create(['from_status' => SalesOrder::StatusApproved, 'to_status' => SalesOrder::StatusClosed, 'changed_at' => now(), 'changed_by' => $this->fixture['user']->id, 'reason' => 'Synthetic legacy manual closure.']);
    $order = $orders->reopen($order, 'Review original closure without new demand.');
    expect($order->reopen_snapshot['status'])->toBe(SalesOrder::StatusClosed);
    $order = $orders->update($order, [...$payload, 'amendment_token' => $order->amendmentToken()]);
    $order = $orders->approve($orders->submit($order));
    expect($order->status)->toBe(SalesOrder::StatusClosed)->and($order->reopen_snapshot)->toBeNull();
    $order = $orders->reopen($order, 'Synthetic customer asks for one additional unit.');
    $payload['amendment_token'] = $order->amendmentToken();
    $payload['lines'][0]['quantity'] = '101';
    $payload['payment_schedules'][0]['amount'] = '1110';
    $order = $orders->approve($orders->submit($orders->update($order, $payload)));
    expect($order->status)->toBe(SalesOrder::StatusApproved)->and($order->reopen_snapshot)->toBeNull()
        ->and($order->canCancelSafely())->toBeFalse()->and($order->isEditable())->toBeFalse();
});
