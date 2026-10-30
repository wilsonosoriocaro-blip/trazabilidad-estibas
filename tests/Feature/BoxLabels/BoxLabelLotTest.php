<?php

namespace Tests\Feature\BoxLabels;

use App\Domain\BoxLabels\Actions\ConsultProductionOrder;
use App\Domain\BoxLabels\Actions\GenerateBoxLabels;
use App\Domain\BoxLabels\Actions\PrintBoxLabels;
use App\Domain\BoxLabels\Exceptions\BoxLabelRuleViolation;
use App\Domain\Orders\Actions\SyncReleasedOrders;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Role;
use App\Models\BoxConversionFactor;
use App\Models\BoxLabel;
use App\Models\BoxLabelBatch;
use App\Models\BoxLabelPrint;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\TraceEvent;
use App\Models\User;
use App\Services\ZebraPrintService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The lot of box labels is OF + Julian day; a supervisor may use another
 * one while the default lot has no Zebra prints. Every Zebra print is
 * recorded (downloading the ZPL is not a print).
 */
class BoxLabelLotTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $operator;

    private User $supervisor;

    /** What the fake Zebra answers. */
    private bool $zebraTakes = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->operator = $this->userWithRole(Role::ProductionOperator);
        $this->supervisor = $this->userWithRole(Role::ProductionSupervisor);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));
        app(SyncReleasedOrders::class)->handle(null, $this->ctx($this->operator));

        $test = $this;
        $this->app->instance(ZebraPrintService::class, new class($test) extends ZebraPrintService
        {
            public function __construct(private readonly BoxLabelLotTest $test) {}

            public function printToSocket(string $ip, int $port = 9100, string $zpl = '', int $timeout = 3): array
            {
                return $this->test->zebraAnswer();
            }

            public function getPrinterStatus(string $ip, int $port = 9100, float $timeout = 1.5): array
            {
                return ['online' => true, 'status_label' => 'Lista'];
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function zebraAnswer(): array
    {
        return $this->zebraTakes ? ['success' => true, 'message' => 'ok'] : ['success' => false, 'message' => 'Sin conexión con la Zebra'];
    }

    private function ctx(User $user): TraceContext
    {
        return TraceContext::forUser($user, Origin::Web, (string) Str::uuid());
    }

    private function ready(string $docNum = '33128'): ProductionOrder
    {
        $order = app(ConsultProductionOrder::class)->handle($this->operator, $docNum);
        BoxConversionFactor::factory()->create(['item_id' => $order->item_id, 'units_per_box' => '24']);
        Item::query()->whereKey($order->item_id)->update(['shelf_life_days' => 365]);

        return $order->refresh();
    }

    private function generate(User $user, ProductionOrder $order, int $labels, ?string $lot = null): BoxLabelBatch
    {
        return app(GenerateBoxLabels::class)->handle($user, $order->id, $labels, CarbonImmutable::parse('2026-10-20'), null, $this->ctx($user), $lot);
    }

    private function print(BoxLabelBatch $batch): array
    {
        return app(PrintBoxLabels::class)->handle($this->operator, $batch->id, '10.0.0.5', 9100, $this->ctx($this->operator));
    }

    private function refused(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail('Debía rechazarse: '.$message);
        } catch (BoxLabelRuleViolation $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_a_supervisor_uses_another_lot_and_the_boxes_carry_it(): void
    {
        $order = $this->ready();

        $batch = $this->generate($this->supervisor, $order, 3, ' 33128-R1 ');

        $this->assertSame('33128-R1', $batch->lot_code);
        $this->assertSame(['33128-R1-0001', '33128-R1-0002', '33128-R1-0003'], BoxLabel::query()->orderBy('sequence')->pluck('code')->all());
        $this->assertStringContainsString('^FDLOTE: 33128-R1^FS', $batch->zpl);
        $context = TraceEvent::query()->where('event', TraceEventType::BoxLabelsGenerated->value)->latest('id')->value('context');
        $this->assertSame(['33128-R1', '33128293'], [$context['lot_code'] ?? null, $context['default_lot_code'] ?? null]);

        // The default lot written by hand is just the default.
        $this->assertSame('33128293', $this->generate($this->supervisor, $order, 1, '33128293')->lot_code);
    }

    public function test_only_a_supervisor_changes_the_lot_and_only_to_a_valid_one(): void
    {
        $order = $this->ready();

        $this->refused(fn () => $this->generate($this->operator, $order, 1, 'OTRO'), 'Solo un supervisor');
        $this->refused(fn () => $this->generate($this->supervisor, $order, 1, 'LOTE CON ESPACIO'), 'letras, números');
        $this->refused(fn () => $this->generate($this->supervisor, $order, 1, str_repeat('A', 41)), 'hasta 40');
        $this->refused(fn () => $this->generate($this->supervisor, $order, 1, 'X^XZ'), 'letras, números');

        // An operator without a lot keeps generating with the default.
        $this->assertSame('33128293', $this->generate($this->operator, $order, 1)->lot_code);
        $this->assertSame(1, BoxLabelBatch::query()->count());
    }

    public function test_once_the_default_lot_is_printed_on_the_zebra_it_can_no_longer_be_changed(): void
    {
        $order = $this->ready();
        $batch = $this->generate($this->operator, $order, 2);

        // Downloading the ZPL is not a print.
        $this->actingAs($this->operator)->get(route('box-labels.zpl', $batch))->assertOk();
        $this->assertSame('33128-A', $this->generate($this->supervisor, $order, 1, '33128-A')->lot_code);

        // A print the Zebra did not take is not a print either.
        $this->zebraTakes = false;
        $this->assertFalse($this->print($batch)['success']);
        $this->assertSame(0, BoxLabelPrint::query()->count());

        $this->zebraTakes = true;
        $this->assertTrue($this->print($batch)['success']);
        $print = BoxLabelPrint::query()->sole();
        $this->assertSame([$batch->id, '33128293', 2, '10.0.0.5:9100', $this->operator->id], [$print->box_label_batch_id, $print->lot_code, $print->labels, $print->printer, $print->printed_by]);
        $this->assertSame(TraceEventType::BoxLabelsPrinted->value, TraceEvent::query()->latest('id')->value('event'));

        $this->refused(fn () => $this->generate($this->supervisor, $order, 1, '33128-B'), 'ya tiene etiquetas impresas');
        // The printed lot keeps getting labels.
        $this->assertSame('33128293-0003', BoxLabel::query()->where('box_label_batch_id', $this->generate($this->supervisor, $order, 1)->id)->value('code'));
    }

    public function test_a_lot_never_belongs_to_two_orders(): void
    {
        $first = $this->ready('33128');
        $second = $this->ready('33131');
        $this->generate($this->supervisor, $first, 1, 'COMPARTIDO');

        $this->refused(fn () => $this->generate($this->supervisor, $second, 1, 'COMPARTIDO'), 'ya es de la OF 33128');
        // Nor the default lot of an order taken by another one.
        $this->generate($this->supervisor, $first, 1, '33131293');
        $this->refused(fn () => $this->generate($this->operator, $second, 1), 'ya es de la OF 33128');
    }

    public function test_prints_are_evidence_and_never_change(): void
    {
        $this->print($this->generate($this->operator, $this->ready(), 1));

        $this->expectException(QueryException::class);
        DB::table('box_label_prints')->update(['labels' => 99]);
    }

    public function test_the_page_lets_only_the_supervisor_type_the_lot_and_warns_over_the_suggestion(): void
    {
        Livewire::actingAs($this->operator)->test('pages::box-labels.index')
            ->set('docNum', '33128')->call('consult')
            ->assertSee('33128293')
            ->assertDontSee('Vacío usa');

        $order = $this->ready();
        $page = Livewire::actingAs($this->supervisor)->test('pages::box-labels.index')
            ->set('docNum', '33128')->call('consult')
            ->assertSee('Vacío usa 33128293')
            ->assertSee('Sugeridas por la OF')
            ->set('labels', 40)
            ->assertSee('Supera lo sugerido por la OF en 6 etiquetas')
            ->set('lotCode', '33128-R2')
            ->call('generate')
            ->assertHasNoErrors();
        $this->assertSame(['33128-R2', 40], [BoxLabelBatch::query()->sole()->lot_code, BoxLabelBatch::query()->sole()->labels]);
        $page->assertSet('lotCode', '');

        // The supervisor's lot goes to print; the default lot stays editable until it is printed.
        $page->call('printBatchDirect')->assertSee('1 vez');
        $this->assertSame('33128-R2', BoxLabelPrint::query()->sole()->lot_code);
        $page->assertSee('Vacío usa 33128293');

        $this->print($this->generate($this->operator, $order, 1));
        Livewire::actingAs($this->supervisor)->test('pages::box-labels.index')
            ->set('docNum', '33128')->call('consult')
            ->assertSee('Ya tiene etiquetas impresas en la Zebra')
            ->assertDontSee('Vacío usa');
    }
}
