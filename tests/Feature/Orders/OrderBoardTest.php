<?php

namespace Tests\Feature\Orders;

use App\Domain\Audits\Actions\StartBlindCount;
use App\Domain\Audits\Actions\SubmitBlindCount;
use App\Domain\Orders\Actions\SyncReleasedOrders;
use App\Domain\Orders\Actions\UnlockOrderReceipts;
use App\Domain\Orders\Enums\OrderStage;
use App\Domain\Orders\OrderProgress;
use App\Domain\Orders\OrderSyncStatus;
use App\Domain\Pallets\PalletQr;
use App\Domain\Shared\ActorType;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Role;
use App\Integrations\Sap\Dto\SapProductionOrder;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Integrations\Sap\Fake\FakeSapCatalog;
use App\Integrations\Sap\Fake\FakeScenario;
use App\Jobs\SyncReleasedOrdersFromSap;
use App\Models\Pallet;
use App\Models\ProductionOrder;
use App\Models\TraceEvent;
use App\Models\User;
use App\Notifications\OrderReadyToClose;
use App\Queries\OrderBoardQuery;
use App\Queries\OrderProgressQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\BuildsReceipts;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * KPI board of the production orders released in SAP: sync from SAP,
 * progress by confirmed receipts and transfer to the CEDI, the "ready to
 * close in SAP" alert and the screen.
 */
class OrderBoardTest extends TestCase
{
    use BuildsReceipts, CreatesUsers, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->admin = $this->userWithRole(Role::TraceabilityAdmin);
    }

    private function ctx(?User $user = null): TraceContext
    {
        return TraceContext::forUser($user ?? $this->admin, Origin::Web, (string) Str::uuid());
    }

    private function unlockReceipts(ProductionOrder $order): void
    {
        $supervisor = $this->userWithRole(Role::ProductionSupervisor);
        app(UnlockOrderReceipts::class)->handle($supervisor, $order->id, 'Sigue la producción', $this->ctx($supervisor));
    }

    private function sync(): void
    {
        $result = app(SyncReleasedOrders::class)->handle($this->admin, $this->ctx());
        $this->assertNull($result->error);
    }

    private function order(string $ref): ProductionOrder
    {
        return ProductionOrder::query()->where('ref', $ref)->sole();
    }

    private function progress(ProductionOrder $order): OrderProgress
    {
        return app(OrderProgressQuery::class)->forOrder($order->id) ?? $this->fail('Sin avance');
    }

    /** One labeled pallet of 40 boxes; at 10 per box that is 400 units, half of OF 33128. */
    private function receive(ProductionOrder $order, string $lot): Pallet
    {
        $pallet = $this->labeledPallets([$lot], unitsPerBox: '10', order: $order)->sole();
        $this->requestReceipts($this->admin, [$pallet]);

        return $pallet->refresh();
    }

    private function transferToCedi(Pallet $pallet): void
    {
        $counter = $this->userWithRole(Role::LogisticsOperator);
        $view = app(StartBlindCount::class)->handle($counter, PalletQr::payload($pallet), $this->ctx($counter));
        app(SubmitBlindCount::class)->handle($counter, $view->auditId, $pallet->boxes_count, $this->ctx($counter));
    }

    public function test_the_sync_brings_every_order_released_in_sap_and_nothing_else(): void
    {
        $result = app(SyncReleasedOrders::class)->handle($this->admin, $this->ctx());

        $this->assertSame(4, $result->released);
        $this->assertSame([], $result->problems);
        $this->assertEqualsCanonicalizing(['33128', '33131', '33132', '33134'], ProductionOrder::query()->pluck('ref')->all());
        $this->assertSame('800.000000', $this->order('33128')->planned_quantity);
        $this->assertSame('GELATINA OLIMPICA SIN SABOR 30 GR', $this->order('33128')->item->description);
        $this->assertSame(4, TraceEvent::query()->where('event', TraceEventType::ProductionOrderRefreshed->value)->count());
    }

    public function test_the_console_command_brings_the_released_orders_without_a_user(): void
    {
        $this->assertSame(0, Artisan::call('sap:sync-orders'));
        $output = Artisan::output();

        $this->assertStringContainsString('SAP tiene 4 órdenes de fabricación liberadas', $output);
        $this->assertStringContainsString('GELATINA OLIMPICA SIN SABOR 30 GR', $output);
        $this->assertNull($this->order('33128')->consulted_by);
        $this->assertSame(ActorType::System, TraceEvent::query()->where('event', TraceEventType::ProductionOrderRefreshed->value)->first()?->actor_type);
    }

    public function test_an_order_closed_in_sap_leaves_the_board_on_the_next_sync(): void
    {
        $this->sync();
        $sapOrder = FakeSapCatalog::productionOrders()['33128'];
        $this->sap()->putProductionOrder(new SapProductionOrder(...[...get_object_vars($sapOrder), 'status' => ProductionOrderStatus::Closed]));

        $this->sync();

        $order = $this->order('33128');
        $this->assertSame('CERRADO', $order->sap_status);
        $this->assertFalse($order->is_open);
        $this->assertNotContains('33128', app(OrderBoardQuery::class)->rows()->map(fn (array $r) => $r['order']->ref)->all());
    }

    public function test_when_sap_does_not_answer_nothing_changes(): void
    {
        $this->sync();
        $this->sap()->failNextLookups();

        $result = app(SyncReleasedOrders::class)->handle($this->admin, $this->ctx());

        $this->assertSame('SAP no respondió. El tablero sigue con los datos de la última actualización.', $result->error);
        $this->assertSame(4, ProductionOrder::query()->where('sap_status', 'LIBERADO')->count());
    }

    public function test_an_order_released_here_that_sap_no_longer_has_is_kept_and_reported(): void
    {
        ProductionOrder::factory()->create(['ref' => '99999', 'sap_status' => 'LIBERADO']);

        $result = app(SyncReleasedOrders::class)->handle($this->admin, $this->ctx());

        $this->assertSame(['99999' => 'SAP ya no tiene una orden con ese número. Queda como estaba para revisarla.'], $result->problems);
        $this->assertSame('LIBERADO', $this->order('99999')->sap_status);
    }

    public function test_progress_follows_confirmed_receipts_and_the_transfer_to_the_cedi(): void
    {
        $this->sync();
        $order = $this->order('33128');
        $this->assertSame(OrderStage::NoProgress, $this->progress($order)->stage());

        $first = $this->receive($order, 'L-1');
        $p = $this->progress($order);
        $this->assertSame([OrderStage::InProgress, 50, 0], [$p->stage(), $p->receivedPercent(), $p->transferredPercent()]);

        $this->transferToCedi($first);
        $p = $this->progress($order);
        $this->assertSame([50, 50], [$p->receivedPercent(), $p->transferredPercent()]);
        $this->assertTrue($p->allInCedi());

        $this->receive($order, 'L-2');
        $p = $this->progress($order);
        $this->assertSame([OrderStage::ReadyToClose, 100, 50], [$p->stage(), $p->receivedPercent(), $p->transferredPercent()]);
        $this->assertFalse($p->allInCedi());

        // Past the receipt limit, more needs the supervisor's go-ahead.
        $this->unlockReceipts($order);
        $this->receive($order, 'L-3');
        $this->assertSame(OrderStage::OverProduced, $this->progress($order)->stage());
    }

    public function test_a_receipt_not_confirmed_yet_does_not_count(): void
    {
        $this->sync();
        $order = $this->order('33128');
        $this->sap()->queueReceiptScenarios(FakeScenario::TimeoutWithoutCreating);
        $this->receive($order, 'L-1');

        $p = $this->progress($order);
        $this->assertSame(OrderStage::NoProgress, $p->stage());
        $this->assertSame(1, $p->pendingInSap);
    }

    public function test_the_last_partial_box_still_counts_as_the_planned_quantity(): void
    {
        // 800 units at 24 per box: 33 full boxes and one of 8. Receipts go in whole boxes.
        $progress = fn (int $received, ?int $perBox = 24) => new OrderProgress(1, 'LIBERADO', 800_000_000, $perBox === null ? null : $perBox * 1_000_000, $received * 1_000_000, 0, 0);

        $this->assertSame(OrderStage::InProgress, $progress(792)->stage());
        $this->assertSame(OrderStage::ReadyToClose, $progress(800)->stage());
        $this->assertSame(OrderStage::ReadyToClose, $progress(816)->stage());
        $this->assertSame(OrderStage::OverProduced, $progress(840)->stage());
        // Without a conversion factor there are no boxes to reason about: the plan is the limit.
        $this->assertSame(OrderStage::OverProduced, $progress(816, null)->stage());
    }

    public function test_the_ready_to_close_alert_goes_once_to_the_people_who_follow_the_board(): void
    {
        Notification::fake();
        $operator = $this->userWithRole(Role::ProductionOperator);
        $this->sync();
        $order = $this->order('33128');

        $this->receive($order, 'L-1');
        Notification::assertNothingSent();

        $this->receive($order, 'L-2');
        Notification::assertSentTo($this->admin, OrderReadyToClose::class, fn (OrderReadyToClose $n) => $n->orderId === $order->id);
        Notification::assertNotSentTo($operator, OrderReadyToClose::class);
        $this->assertNotNull($order->refresh()->ready_to_close_notified_at);
        $this->assertSame(1, TraceEvent::query()->where('event', TraceEventType::ProductionOrderReadyToClose->value)->count());

        $this->unlockReceipts($order);
        $this->receive($order, 'L-3');
        $this->sync();
        Notification::assertSentToTimes($this->admin, OrderReadyToClose::class, 1);

        $data = (new OrderReadyToClose($order->id))->toArray($this->admin);
        $this->assertSame('OF 33128 lista para cerrar en SAP', $data['title']);
        $this->assertStringContainsString('Recibido 1.200 de 800 UNIDAD. Hay sobreproducción', $data['body']);
    }

    public function test_an_order_not_released_in_sap_never_raises_the_alert(): void
    {
        Notification::fake();
        $order = ProductionOrder::factory()->create(['planned_quantity' => '400', 'sap_status' => null]);

        $this->receive($order, 'L-1');

        Notification::assertNothingSent();
        $this->assertNull($order->refresh()->ready_to_close_notified_at);
    }

    public function test_the_button_runs_in_the_background_one_at_a_time(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)->test('pages::orders.index')->call('syncFromSap')->assertSet('waiting', true)->assertSee('Actualizando desde SAP');
        Queue::assertPushedOn('sap', SyncReleasedOrdersFromSap::class);
        $this->assertTrue(OrderSyncStatus::busy());

        // A second click while it runs queues nothing more.
        Livewire::actingAs($this->admin)->test('pages::orders.index')->call('syncFromSap');
        Queue::assertPushed(SyncReleasedOrdersFromSap::class, 1);

        // The job itself, with SAP down: the board is told, nothing changes.
        $this->sap()->failNextLookups();
        (new SyncReleasedOrdersFromSap($this->admin->id, (string) Str::uuid()))->handle(app(SyncReleasedOrders::class));
        $this->assertFalse(OrderSyncStatus::busy());
        $this->assertSame('failed', OrderSyncStatus::get()['state'] ?? null);
        $this->assertSame(0, ProductionOrder::query()->count());

        (new SyncReleasedOrdersFromSap($this->admin->id, (string) Str::uuid()))->handle(app(SyncReleasedOrders::class));
        $this->assertSame(['done', 4], [OrderSyncStatus::get()['state'] ?? null, OrderSyncStatus::get()['released'] ?? null]);
        $this->assertSame(Origin::Job, TraceEvent::query()->where('event', TraceEventType::ProductionOrderRefreshed->value)->latest('id')->first()?->origin);
    }

    public function test_the_kpis_add_up_the_released_orders(): void
    {
        $this->sync();
        $order = $this->order('33128');
        $pallet = $this->receive($order, 'L-1');
        $this->transferToCedi($pallet);
        $this->receive($order, 'L-2');

        $kpis = app(OrderBoardQuery::class)->kpis();

        $this->assertSame(4, $kpis['orders']);
        $this->assertSame(1, $kpis['ready']);
        $this->assertSame(3, $kpis['no_progress']);
        $this->assertSame(0, $kpis['in_cedi']);
        // 800 + 600 + 400 + 240 planned; 800 received; 400 in the CEDI.
        $this->assertSame(['planned' => 2_040_000_000, 'received' => 800_000_000, 'transferred' => 400_000_000], $kpis['units']['UNIDAD']);
    }

    public function test_only_people_with_orders_view_sync_or_open_the_board(): void
    {
        $operator = $this->userWithRole(Role::ProductionOperator);

        $this->actingAs($operator)->get(route('orders.index'))->assertForbidden();
        $this->actingAs($this->userWithRole(Role::Viewer))->get(route('orders.index'))->assertForbidden();

        $this->expectException(AuthorizationException::class);
        app(SyncReleasedOrders::class)->handle($operator, $this->ctx($operator));
    }

    public function test_the_board_shows_the_kpis_and_the_released_orders_most_urgent_first(): void
    {
        $this->actingAs($this->admin)->get(route('orders.index'))->assertOk()->assertSee('No hay órdenes liberadas');

        // The button queues the work (sync queue in tests) and the poll shows the outcome.
        Livewire::actingAs($this->admin)->test('pages::orders.index')
            ->call('syncFromSap')->assertSet('waiting', true)
            ->call('checkSync')->assertSet('waiting', false)
            ->assertSee('33128')->assertDontSee('33129');

        $this->receive($this->order('33134'), 'L-1');
        ProductionOrder::factory()->create(['ref' => '66666', 'sap_status' => 'CERRADO', 'is_open' => false]);

        $this->actingAs($this->admin)->get(route('orders.index'))
            ->assertOk()
            ->assertSeeInOrder(['OF liberadas', '4', 'con cajas leídas', 'Avance en cajas', 'Leídas', 'Recibidas en 04', 'En CEDI', 'Listas para cerrar en SAP', 'Sobreproducción', 'Pendientes en SAP', 'Cerradas en SAP'])
            ->assertSeeInOrder(['33134', 'Sobreproducción', '33128', 'Sin avance'])
            ->assertDontSee('66666');

        Livewire::actingAs($this->admin)->test('pages::orders.index')
            ->set('stage', OrderStage::OverProduced->value)
            ->assertSee('GELATINA MERCALDAS')->assertDontSee('GELATINA OLIMPICA')
            ->set('stage', '')->set('search', 'olimpica')
            ->assertSee('GELATINA OLIMPICA')->assertDontSee('GELATINA MERCALDAS');
    }

    public function test_orders_whose_boxes_are_being_read_come_first(): void
    {
        $this->sync();
        // 33131 has boxes read but nothing received yet; 33128 has nothing read.
        Pallet::factory()->withBoxes(5)->create(['production_order_id' => $this->order('33131')->id]);

        $refs = app(OrderBoardQuery::class)->rows()->map(fn (array $r) => $r['order']->ref)->values()->all();
        $this->assertSame('33131', $refs[0]);
        $this->assertGreaterThan(array_search('33131', $refs, true), array_search('33128', $refs, true));

        $this->assertSame(1, app(OrderBoardQuery::class)->kpis()['reading']);
        $this->assertSame(5, app(OrderBoardQuery::class)->kpis()['boxes']['read']);
    }

    private function closeInSap(string $ref): void
    {
        $sapOrder = FakeSapCatalog::productionOrders()[$ref];
        $this->sap()->putProductionOrder(new SapProductionOrder(...[...get_object_vars($sapOrder), 'status' => ProductionOrderStatus::Closed]));
    }

    public function test_an_order_that_reached_ready_to_close_and_was_closed_in_sap_shows_as_closed_in_sap(): void
    {
        Notification::fake();
        $this->travelTo('2026-10-23 09:00');
        $this->sync();
        $order = $this->order('33128');
        $this->receive($order, 'L-1');
        $this->transferToCedi($this->receive($order, 'L-2'));
        $this->assertNotNull($order->refresh()->ready_to_close_notified_at);

        // 33128 went through the process here; 33131 is closed in SAP without having reached it.
        $this->closeInSap('33128');
        $this->closeInSap('33131');
        $this->travelTo('2026-10-24 15:00');
        $this->sync();

        $board = app(OrderBoardQuery::class);
        $refs = fn (string $stage = '') => $board->rows(stage: $stage)->map(fn (array $r) => $r['order']->ref)->all();
        $this->assertNotContains('33128', $refs());
        $this->assertSame(['33128'], $refs(OrderStage::Closed->value));
        $this->assertSame(OrderStage::Closed, $board->rows(stage: OrderStage::Closed->value)->sole()['progress']->stage());
        $this->assertSame(1, $board->kpis()['closed']);
        $this->assertSame('2026-10-24 15:00', $order->refresh()->sap_closed_at?->format('Y-m-d H:i'));
        $this->assertSame(1, TraceEvent::query()->where('event', TraceEventType::ProductionOrderClosedInSap->value)->where('subject_id', $order->id)->count());

        // A later refresh keeps the date it was first seen closed, and does not trace it again.
        $this->travelTo('2026-10-25 08:00');
        $this->sync();
        $this->assertSame('2026-10-24 15:00', $order->refresh()->sap_closed_at?->format('Y-m-d H:i'));
        $this->assertSame(1, TraceEvent::query()->where('event', TraceEventType::ProductionOrderClosedInSap->value)->where('subject_id', $order->id)->count());

        Livewire::actingAs($this->admin)->test('pages::orders.index')
            ->assertSee('Cerradas en SAP')
            ->set('stage', OrderStage::Closed->value)
            ->assertSee('33128')
            ->assertSee('Cerrada en SAP')
            ->assertSee('el 24/10/2026')
            ->assertDontSee('33131');
    }

    public function test_each_stage_shows_units_and_boxes_from_read_to_the_cedi(): void
    {
        $this->sync();
        $order = $this->order('33128');
        // 40 boxes at 10 per box: received and later in the CEDI.
        $this->transferToCedi($this->receive($order, 'L-1'));
        // Another 40 boxes read but not received yet.
        $this->labeledPallets(['L-2'], unitsPerBox: '10', order: $order);

        $p = $this->progress($order);
        $this->assertSame([80, 80, 40, 40], [$p->plannedBoxes(), $p->readBoxes, $p->receivedBoxes, $p->transferredBoxes]);
        $this->assertSame([800_000_000, 100], [$p->readMicro(), $p->readPercent()]);
        $this->assertSame([400_000_000, 400_000_000], [$p->receivedMicro, $p->transferredMicro]);

        Livewire::actingAs($this->admin)->test('pages::orders.index')
            ->assertSee('Cajas leídas')
            ->assertSee('80 cajas')
            ->assertSee('800 UNIDAD · 80 cajas')
            ->assertSee('400 UNIDAD · 40 cajas');
    }

    public function test_without_units_per_box_the_boxes_still_show_and_the_units_do_not(): void
    {
        $progress = new OrderProgress(1, 'LIBERADO', 800_000_000, null, 0, 0, 0, readBoxes: 12);

        $this->assertNull($progress->plannedBoxes());
        $this->assertNull($progress->readMicro());
        $this->assertSame([12, 0], [$progress->readBoxes, $progress->readPercent()]);
    }
}
