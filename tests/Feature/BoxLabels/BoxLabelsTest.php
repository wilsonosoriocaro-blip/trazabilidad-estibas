<?php

namespace Tests\Feature\BoxLabels;

use App\Domain\BoxLabels\Actions\ConsultProductionOrder;
use App\Domain\BoxLabels\Actions\GenerateBoxLabels;
use App\Domain\BoxLabels\BoxLabelPayload;
use App\Domain\BoxLabels\BoxLabelZpl;
use App\Domain\BoxLabels\BoxLot;
use App\Domain\BoxLabels\BoxQr;
use App\Domain\BoxLabels\Exceptions\BoxLabelRuleViolation;
use App\Domain\BoxLabels\Itf14;
use App\Domain\Orders\Actions\SyncReleasedOrders;
use App\Domain\Orders\ProductionOrderCache;
use App\Domain\Orders\SyncResult;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Role;
use App\Integrations\Sap\Contracts\SapGateway;
use App\Integrations\Sap\Dto\Quantity;
use App\Integrations\Sap\Dto\SapProductionOrder;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Integrations\Sap\Fake\FakeSapCatalog;
use App\Integrations\Sap\Fake\FakeSapGateway;
use App\Models\BoxConversionFactor;
use App\Models\BoxLabel;
use App\Models\BoxLabelBatch;
use App\Models\Item;
use App\Models\Pallet;
use App\Models\ProductionOrder;
use App\Models\TraceEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Box labels: a production order is looked up in SAP by number; only a
 * released one is stored and can get labels. Labels carry OF, product,
 * units per box, lot (OF + Julian day), EAN and expiration, and are
 * printed as ZPL on 10 × 8 cm.
 */
class BoxLabelsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->operator = $this->userWithRole(Role::ProductionOperator);
    }

    private function sap(): FakeSapGateway
    {
        $sap = app(SapGateway::class);
        $this->assertInstanceOf(FakeSapGateway::class, $sap);

        return $sap;
    }

    private function ctx(?User $user = null): TraceContext
    {
        return TraceContext::forUser($user ?? $this->operator, Origin::Web, (string) Str::uuid());
    }

    private function consult(string $docNum, ?User $user = null): ProductionOrder
    {
        return app(ConsultProductionOrder::class)->handle($user ?? $this->operator, $docNum);
    }

    /** The order board's refresh: brings the released orders from SAP. */
    private function syncFromSap(): SyncResult
    {
        return app(SyncReleasedOrders::class)->handle(null, $this->ctx());
    }

    /** The application already has this SAP order, in the status SAP gave it. */
    private function stored(string $docNum): ProductionOrder
    {
        return DB::transaction(fn () => app(ProductionOrderCache::class)->store(FakeSapCatalog::productionOrders()[$docNum], null, $this->ctx(), TraceEventType::ProductionOrderRefreshed));
    }

    /**
     * Order 33128 brought from SAP and found, its product with 24 units per box.
     */
    private function ready(string $unitsPerBox = '24', ?int $shelfLifeDays = 365): ProductionOrder
    {
        $this->syncFromSap();
        $order = $this->consult('33128');
        BoxConversionFactor::factory()->create(['item_id' => $order->item_id, 'units_per_box' => $unitsPerBox]);
        Item::query()->whereKey($order->item_id)->update(['shelf_life_days' => $shelfLifeDays]);

        return $order->refresh();
    }

    private function generate(ProductionOrder $order, int $labels, ?string $production = '2026-10-20', ?string $expiration = null): BoxLabelBatch
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));

        return app(GenerateBoxLabels::class)->handle($this->operator, $order->id, $labels, CarbonImmutable::parse($production), $expiration === null ? null : CarbonImmutable::parse($expiration), $this->ctx());
    }

    private function expectViolation(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail('Debía rechazarse: '.$message);
        } catch (BoxLabelRuleViolation $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_a_released_order_is_found_in_the_application_without_asking_sap(): void
    {
        $this->syncFromSap();
        // Any call to SAP from here on would fail: the lookup must not make one.
        $this->sap()->failNextLookups(10);
        $traces = TraceEvent::count();

        $order = $this->consult(' 33128 ');

        $this->assertSame('33128', $order->ref);
        $this->assertSame(34128, $order->sap_doc_entry);
        $this->assertSame('LIBERADO', $order->sap_status);
        $this->assertSame('Estándar', $order->sap_order_type);
        $this->assertSame('800.000000', $order->refresh()->planned_quantity);
        $this->assertSame(['UNIDAD', '04', 100, 'Aux Producción 1', 'Manual', 'Pedido de cliente'], [$order->uom_name, $order->warehouse_code, $order->priority, $order->sap_user, $order->sap_origin, $order->sap_linked_to]);
        $this->assertSame(['2026-02-17', '2026-02-17', '2026-02-24'], [$order->order_date?->toDateString(), $order->start_date?->toDateString(), $order->due_date?->toDateString()]);
        $this->assertSame('33128', $order->sap_header['doc_num']);

        $item = Item::query()->findOrFail($order->item_id);
        $this->assertSame(['PT1350023', 'GELATINA OLIMPICA SIN SABOR 30 GR', FakeSapCatalog::barcode('PT1350023')], [$item->code, $item->description, $item->barcode]);
        // A read: nothing stored, nothing traced.
        $this->assertSame($traces, TraceEvent::count());
    }

    public function test_orders_not_in_the_application_or_not_released_are_refused(): void
    {
        // Released in SAP but the board was never refreshed: not here yet.
        $this->expectViolation(fn () => $this->consult('33128'), 'no está en la aplicación. Actualiza el Tablero de OF');
        $this->expectViolation(fn () => $this->consult('99999'), 'no está en la aplicación');

        foreach (['33129' => 'Planificado', '33130' => 'Cerrado', '33133' => 'Cancelado'] as $docNum => $status) {
            $this->stored((string) $docNum);
            $this->expectViolation(fn () => $this->consult((string) $docNum), "está «{$status}»");
        }

        $this->expectViolation(fn () => $this->consult('OF-33128'), 'solo números');
        $this->assertSame(0, $this->sap()->receiptWriteCalls());
    }

    public function test_refreshing_the_board_updates_the_header_but_keeps_the_local_shelf_life(): void
    {
        $order = $this->ready(shelfLifeDays: 540);
        $sapOrder = FakeSapCatalog::productionOrders()['33128'];
        $this->sap()->putProductionOrder(new SapProductionOrder(
            $sapOrder->docEntry, $sapOrder->docNum, $sapOrder->type, $sapOrder->status, $sapOrder->itemCode, $sapOrder->itemDescription,
            new Quantity('1000'), $sapOrder->uomName, $sapOrder->warehouse, 50, $sapOrder->orderDate, $sapOrder->startDate, $sapOrder->dueDate,
            $sapOrder->userName, $sapOrder->origin, $sapOrder->linkedTo, $sapOrder->itemBarcode, $sapOrder->itemInventoryUom,
        ));

        $this->syncFromSap();
        $again = $this->consult('33128');

        $this->assertSame($order->id, $again->id);
        $this->assertSame(['1000.000000', 50], [$again->refresh()->planned_quantity, $again->priority]);
        $this->assertSame(540, Item::query()->findOrFail($order->item_id)->shelf_life_days);
        $this->assertSame(1, ProductionOrder::query()->where('ref', '33128')->count());
    }

    public function test_an_order_whose_product_changed_in_sap_is_refused_once_it_has_pallets(): void
    {
        $other = Item::factory()->create(['code' => 'PT-OTRO']);
        $local = ProductionOrder::factory()->for($other)->create(['ref' => '33128']);
        Pallet::factory()->create(['production_order_id' => $local->id]);

        $result = $this->syncFromSap();

        $this->assertStringContainsString('otro producto', $result->problems['33128'] ?? '');
        $this->assertSame($other->id, $local->refresh()->item_id);
    }

    public function test_the_lot_is_the_order_number_and_the_julian_day(): void
    {
        $this->assertSame('33128293', BoxLot::code('33128', CarbonImmutable::parse('2026-10-20')));
        $this->assertSame('33128005', BoxLot::code('33128', CarbonImmutable::parse('2026-01-05')));
        $this->assertSame('33128366', BoxLot::code('33128', CarbonImmutable::parse('2028-12-31')));
    }

    public function test_the_qr_reads_as_the_lines_of_the_label_and_the_ean14_goes_apart(): void
    {
        $order = $this->ready();

        $batch = $this->generate($order, 30);

        $this->assertSame(
            "OF 33128\nPT1350023\nGELATINA OLIMPICA SIN SABOR 30 GR\nLote: 33128293\nVence: 20/10/2027\nContenido: 24 UNIDAD por caja",
            $batch->qr_payload,
        );
        $this->assertSame(['33128293', '2026-10-20', '2027-10-20', 30], [$batch->lot_code, $batch->production_date->toDateString(), $batch->expiration_date->toDateString(), $batch->labels]);
        $this->assertSame(FakeSapCatalog::barcode('PT1350023'), $batch->barcode);
        $this->assertStringNotContainsString($batch->barcode, $batch->qr_payload);
        $this->assertSame(
            ['order' => '33128', 'item' => 'PT1350023', 'description' => 'GELATINA OLIMPICA SIN SABOR 30 GR', 'lot' => '33128293', 'expiration' => '20/10/2027', 'content' => '24 UNIDAD por caja', 'box' => '33128293-0001'],
            BoxLabelPayload::decode(str_replace("\n", "\r\n", $batch->qr_payload."\nCaja: 33128293-0001")),
        );
        $this->assertSame(TraceEventType::BoxLabelsGenerated->value, TraceEvent::query()->latest('id')->value('event'));
    }

    public function test_the_zpl_is_a_10_by_8_cm_label_printed_as_many_times_as_asked(): void
    {
        $batch = $this->generate($this->ready(), 25);
        $tokens = BoxLabel::query()->where('box_label_batch_id', $batch->id)->orderBy('sequence')->pluck('qr_token')->all();
        $first = BoxQr::payload((string) $tokens[0]);

        $this->assertStringStartsWith("^XA\n", $batch->zpl);
        $this->assertStringEndsWith("^XZ\n", $batch->zpl);
        $this->assertStringContainsString('^PW800', $batch->zpl);
        $this->assertStringContainsString('^LL640', $batch->zpl);
        $this->assertStringContainsString('^CI28', $batch->zpl);
        // The QR carries only the box token (like the pallet QR).
        $this->assertStringContainsString('^BQN,2,6^FH_^FDMA,'.$first.'^FS', $batch->zpl);
        $this->assertStringNotContainsString('^FDMA,OF 33128', $batch->zpl);
        // EAN-14 as ITF-14: check digit already in the data, (01) and the digits underneath, no bearer-bar box.
        $this->assertStringContainsString('^B2N,150,N,N,N^FD'.$batch->barcode.'^FS', $batch->zpl);
        $this->assertSame(0, substr_count($batch->zpl, '^GB'), 'Sin recuadro alrededor del código');
        $this->assertStringContainsString('^FD(01)'.$batch->barcode.'^FS', $batch->zpl);
        $this->assertStringContainsString('^FDGELATINA OLIMPICA SIN SABOR 30 GR^FS', $batch->zpl);
        $this->assertStringContainsString('^FDLOTE: 33128293^FS', $batch->zpl);
        $this->assertStringContainsString('^FDCAJA : 24 UNIDADES^FS', $batch->zpl);
        // One label per box: its own token in the QR and its box code printed under it.
        $this->assertSame(25, substr_count($batch->zpl, '^XA'));
        $this->assertSame(25, substr_count($batch->zpl, '^PQ1,0,1,Y'));
        $this->assertSame(25, count(array_unique($tokens)));
        $this->assertStringContainsString('^FDCaja 33128293-0001^FS', $batch->zpl);
        $this->assertStringContainsString('^FDCaja 33128293-0025^FS', $batch->zpl);
        $this->assertStringContainsString('^FDMA,'.BoxQr::payload((string) $tokens[24]).'^FS', $batch->zpl);
    }

    public function test_every_box_label_has_its_own_code_continuing_the_lot(): void
    {
        $order = $this->ready();
        $first = $this->generate($order, 3);
        $second = $this->generate($order, 2);

        $this->assertSame(
            ['33128293-0001', '33128293-0002', '33128293-0003', '33128293-0004', '33128293-0005'],
            BoxLabel::query()->orderBy('id')->pluck('code')->all(),
        );
        $this->assertSame([3, 2], [BoxLabel::query()->where('box_label_batch_id', $first->id)->count(), BoxLabel::query()->where('box_label_batch_id', $second->id)->count()]);

        // Evidence: a printed box code never changes or disappears.
        $this->expectException(QueryException::class);
        BoxLabel::query()->whereKey(BoxLabel::query()->value('id'))->update(['code' => 'X']);
    }

    public function test_text_from_sap_can_never_inject_zpl_commands(): void
    {
        $payload = new BoxLabelPayload('1', 'PT^X', 'DESCRIPCION ^XZ^XA~JA_X', '1', null, '1001', FakeSapCatalog::barcode('PT1'), CarbonImmutable::parse('2027-01-01'));

        $zpl = BoxLabelZpl::render($payload, CarbonImmutable::parse('2026-01-01'), 'TRZB:a_b^XZ~JA');

        $this->assertSame(1, substr_count($zpl, '^XZ'));
        $this->assertSame(1, substr_count($zpl, '^XA'));
        $this->assertStringNotContainsString('~JA', $zpl);
        // Inside the QR (^FH_), a literal underscore must not start a hex escape.
        $this->assertStringContainsString('^FDMA,TRZB:a_5Fb', $zpl);
    }

    public function test_the_expiration_comes_from_the_shelf_life_or_is_typed_and_must_be_after_production(): void
    {
        $order = $this->ready(shelfLifeDays: null);

        $this->expectViolation(fn () => $this->generate($order, 1), 'fecha de vencimiento');
        $this->expectViolation(fn () => $this->generate($order, 1, expiration: '2026-10-20'), 'fecha de vencimiento');
        $this->assertSame('2027-04-30', $this->generate($order, 1, expiration: '2027-04-30')->expiration_date->toDateString());
    }

    public function test_a_lot_keeps_one_expiration_date_across_batches(): void
    {
        $order = $this->ready(shelfLifeDays: null);
        $this->generate($order, 2, expiration: '2028-10-20');

        // Same order and day means the same lot: a second batch can't print another expiration.
        $this->expectViolation(fn () => $this->generate($order, 1, expiration: '2028-11-01'), 'ya tiene etiquetas con vencimiento 20/10/2028');
        $this->generate($order, 1, expiration: '2028-10-20');
        // Another day is another lot, free to have its own date.
        $this->generate($order, 1, production: '2026-10-19', expiration: '2028-11-01');

        $this->assertSame(3, BoxLabelBatch::query()->count());
    }

    public function test_an_order_can_get_more_labels_than_its_planned_quantity_suggests(): void
    {
        // 800 units at 24 per box: 33 full boxes and one of 8, so 34 labels suggested.
        $order = $this->ready();
        $this->assertSame(34, GenerateBoxLabels::maxLabels('800.000000', '24.000000'));

        $this->generate($order, 30);
        // Misprints and damaged boxes: going over the suggestion is allowed and stays on record.
        $this->generate($order, 10);
        $this->expectViolation(fn () => $this->generate($order, 0), 'al menos 1');

        $this->assertSame(40, (int) BoxLabelBatch::query()->sum('labels'));
        $this->assertSame('33128293-0040', BoxLabel::query()->orderByDesc('sequence')->value('code'));
        $context = TraceEvent::query()->where('event', TraceEventType::BoxLabelsGenerated->value)->latest('id')->value('context');
        $this->assertSame([34, 30, 10], [$context['suggested_labels'] ?? null, $context['labels_before'] ?? null, $context['labels'] ?? null]);
    }

    public function test_labels_need_a_consulted_order_a_barcode_units_per_box_and_a_past_production_date(): void
    {
        $notConsulted = ProductionOrder::factory()->create();
        $this->expectViolation(fn () => $this->generate($notConsulted, 1, expiration: '2027-01-01'), 'Busca la orden de fabricación');

        $this->syncFromSap();
        $order = $this->consult('33128');
        $this->expectViolation(fn () => $this->generate($order, 1, expiration: '2027-01-01'), 'unidades por caja');

        BoxConversionFactor::factory()->create(['item_id' => $order->item_id, 'units_per_box' => '24']);
        Item::query()->whereKey($order->item_id)->update(['barcode' => null]);
        $this->expectViolation(fn () => $this->generate($order, 1, expiration: '2027-01-01'), 'no tiene código EAN-14');

        // A wrong check digit (13 or 14 digits) or any other length is refused.
        foreach (['2000013500237', '12000013500234', '123456'] as $wrong) {
            Item::query()->whereKey($order->item_id)->update(['barcode' => $wrong]);
            $this->expectViolation(fn () => $this->generate($order, 1, expiration: '2027-01-01'), 'no es un EAN-13 ni un EAN-14 válido');
        }

        Item::query()->whereKey($order->item_id)->update(['barcode' => FakeSapCatalog::barcode('PT1350023')]);
        $this->expectViolation(fn () => $this->generate($order, 1, production: '2026-10-21', expiration: '2027-01-01'), 'no puede ser futura');

        $this->assertSame(0, BoxLabelBatch::count());
    }

    public function test_the_box_ean14_is_made_from_the_unit_ean13_sap_has(): void
    {
        // GS1: indicator 1, the first 12 digits of the EAN-13 and a new check digit.
        $this->assertSame('17700304364722', Itf14::forBox('7700304364725'));
        $this->assertSame('27700304364729', Itf14::forBox('7700304364725', 2));
        $this->assertSame('12000013500233', Itf14::forBox('12000013500233'));
        $this->assertNull(Itf14::forBox('7700304364726'));
        $this->assertNull(Itf14::forBox('7700304364725', 0));
        $this->assertNull(Itf14::forBox(null));

        $order = $this->ready();
        Item::query()->whereKey($order->item_id)->update(['barcode' => '7700304364725']);

        $batch = $this->generate($order, 1);

        $this->assertSame('17700304364722', $batch->barcode);
        $this->assertStringContainsString('^FD17700304364722^FS', $batch->zpl);
        $this->assertStringContainsString('^FD(01)17700304364722^FS', $batch->zpl);
    }

    public function test_a_generated_batch_is_evidence_and_never_changes(): void
    {
        $batch = $this->generate($this->ready(), 2);

        $this->expectException(QueryException::class);
        BoxLabelBatch::query()->whereKey($batch->id)->update(['labels' => 200]);
    }

    public function test_only_people_with_box_labels_print_consult_and_generate(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->consult('33128', $this->userWithRole(Role::LogisticsOperator));
    }

    public function test_the_page_consults_shows_the_header_generates_and_offers_the_zpl(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));
        $item = Item::factory()->create(['code' => 'PT1350023', 'shelf_life_days' => 365]);
        BoxConversionFactor::factory()->create(['item_id' => $item->id, 'units_per_box' => '24']);
        $this->syncFromSap();
        $this->stored('33129');

        $page = Livewire::actingAs($this->operator)->test('pages::box-labels.index')
            ->set('docNum', '33129')->call('consult')
            ->assertHasErrors('docNum')
            ->assertSee('está «Planificado» en SAP')
            ->set('docNum', '33128')->call('consult')
            ->assertHasNoErrors()
            ->assertSee('GELATINA OLIMPICA SIN SABOR 30 GR')
            ->assertSee('Liberado')
            ->assertSee('Aux Producción 1')
            ->assertSee('Pedido de cliente')
            ->assertSee('33128293')
            ->assertSet('expirationDate', '2027-10-20')
            ->set('labels', 12)
            ->call('generate');

        $batch = BoxLabelBatch::sole();
        $page->assertSee('Descargar ZPL (12 etiquetas)')
            ->assertSee(BoxQr::payload((string) BoxLabel::query()->where('box_label_batch_id', $batch->id)->orderBy('sequence')->value('qr_token')))
            ->assertSee('Caja 33128293-0001')
            ->assertSee(Itf14::readable($batch->barcode));

        $response = $this->actingAs($this->operator)->get(route('box-labels.zpl', $batch));
        $response->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="OF33128-lote33128293-12cajas.zpl"');
        $this->assertSame($batch->zpl, $response->getContent());
        $this->assertSame(TraceEventType::BoxLabelsDownloaded->value, TraceEvent::query()->latest('id')->value('event'));
    }

    public function test_the_page_and_the_download_need_the_permission(): void
    {
        $batch = $this->generate($this->ready(), 1);

        $this->actingAs($this->userWithRole(Role::TraceabilityAdmin))->get(route('box-labels.index'))->assertOk();
        foreach ([Role::LogisticsOperator, Role::Viewer] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->get(route('box-labels.index'))->assertForbidden();
            $this->actingAs($user)->get(route('box-labels.zpl', $batch))->assertForbidden();
        }
    }

    public function test_the_ean14_check_digit_and_itf14_pattern_follow_gs1(): void
    {
        // GS1's own example GTIN-14.
        $this->assertSame(7, Itf14::checkDigit('1001234500001'));
        $this->assertTrue(Itf14::isValid('10012345000017'));
        $this->assertFalse(Itf14::isValid('10012345000018'));
        $this->assertSame('1 00 12345 00001 7', Itf14::readable('10012345000017'));

        // Start (4 narrow), 7 pairs of 10 elements, stop (wide bar, narrow space, narrow bar).
        $elements = Itf14::elements('10012345000017');
        $this->assertCount(4 + 70 + 3, $elements);
        $this->assertSame([[true, false], [false, false], [true, false], [false, false]], array_slice($elements, 0, 4));
        $this->assertSame([[true, true], [false, false], [true, false]], array_slice($elements, -3));
        // Every digit has exactly two wide elements.
        $this->assertSame(14 * 2 + 1, count(array_filter($elements, fn (array $e) => $e[1])));
    }

    public function test_the_simulator_knows_the_real_finished_products(): void
    {
        $this->assertCount(40, FakeSapCatalog::ITEMS);
        $this->assertSame('GELATINA AZUCARADA SAN VALENTIN MORA 1000 GR', FakeSapCatalog::ITEMS['PT1350041']);
        $this->assertSame(ProductionOrderStatus::Released, FakeSapCatalog::productionOrders()['33128']->status);
        $this->assertMatchesRegularExpression('/^1200\d{10}$/', FakeSapCatalog::barcode('PT1350002'));
        $this->assertTrue(Itf14::isValid(FakeSapCatalog::barcode('PT1350002')));
    }
}
