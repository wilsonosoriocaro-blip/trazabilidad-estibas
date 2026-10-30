<?php

namespace Tests\Feature\Pallets;

use App\Domain\BoxLabels\Actions\ConsultProductionOrder;
use App\Domain\BoxLabels\Actions\GenerateBoxLabels;
use App\Domain\BoxLabels\BoxQr;
use App\Domain\Devices\ManageDevices;
use App\Domain\Operations\Heartbeat;
use App\Domain\Operations\OperationalChecks;
use App\Domain\Orders\Actions\SyncReleasedOrders;
use App\Domain\Pallets\Actions\RegisterManualBoxScan;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Pallets\Readers\BoxReaderSession;
use App\Domain\Pallets\Readers\BoxReadOutcome;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Role;
use App\Models\BoxConversionFactor;
use App\Models\BoxLabel;
use App\Models\BoxLabelBatch;
use App\Models\Device;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use App\Models\TraceEvent;
use App\Models\User;
use App\Services\TraceLogger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * A fixed reader on the line reads the box QR and sends it as text over
 * TCP or serial; boxes:reader keeps it connected and counts each box with
 * the same rules as a read by hand.
 */
class BoxReaderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $operator;

    private BoxLabelBatch $batch;

    private Device $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->operator = $this->userWithRole(Role::ProductionOperator);
        config(['traceability.test_pallet_boxes' => 3]);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));

        $ctx = TraceContext::forUser($this->operator, Origin::Web, (string) Str::uuid());
        app(SyncReleasedOrders::class)->handle(null, $ctx);
        $order = app(ConsultProductionOrder::class)->handle($this->operator, '33128');
        BoxConversionFactor::factory()->create(['item_id' => $order->item_id, 'units_per_box' => '24']);
        $this->batch = app(GenerateBoxLabels::class)->handle($this->operator, $order->id, 10, CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2028-10-20'), $ctx);
        [$this->reader] = app(ManageDevices::class)->register('LEC-L1', 'Lector fijo línea 1', 'LECTOR', TraceContext::system(Origin::Console, (string) Str::uuid()));
    }

    private function box(int $n): string
    {
        return BoxQr::payload((string) BoxLabel::query()->where('box_label_batch_id', $this->batch->id)->where('sequence', $n)->value('qr_token'));
    }

    private function readerSession(): BoxReaderSession
    {
        return new BoxReaderSession($this->reader, app(RegisterManualBoxScan::class), app(TraceLogger::class));
    }

    private function counted(): int
    {
        return PalletScanEvent::query()->whereNotNull('pallet_id')->count();
    }

    public function test_each_line_the_reader_sends_counts_its_box_even_when_it_comes_in_pieces(): void
    {
        $session = $this->readerSession();
        $stream = "\x02".$this->box(1)."\x03\r\n".$this->box(2)."\r".$this->box(3)."\n";
        $reads = [];
        foreach (str_split($stream, 5) as $piece) {
            array_push($reads, ...$session->feed($piece));
        }

        $this->assertSame([BoxReadOutcome::Counted, BoxReadOutcome::Counted, BoxReadOutcome::Counted], array_map(fn ($r) => $r->outcome, $reads));
        // Three boxes with a standard of 3: the pallet closed on its own.
        $pallet = Pallet::query()->sole();
        $this->assertSame(3, $pallet->boxes_count);
        $this->assertSame(PhysicalStatus::Finished, $pallet->physical_status);
        // Each read keeps which reader read it.
        $this->assertSame([$this->reader->id], PalletScanEvent::query()->distinct()->pluck('read_by_device_id')->all());
    }

    public function test_a_box_read_twice_by_the_line_or_again_by_hand_counts_once(): void
    {
        $session = $this->readerSession();

        $reads = $session->feed($this->box(1)."\n".$this->box(1)."\n");
        $this->assertSame([BoxReadOutcome::Counted, BoxReadOutcome::AlreadyRead], array_map(fn ($r) => $r->outcome, $reads));

        // Read by hand after the line: refused as already read.
        try {
            app(RegisterManualBoxScan::class)->handle($this->operator, $this->box(1), TraceContext::forUser($this->operator, Origin::Web, (string) Str::uuid()));
            $this->fail('La caja ya estaba contada.');
        } catch (PalletRuleViolation $e) {
            $this->assertStringContainsString('ya fue leída', $e->getMessage());
        }

        // Read by hand first, then by the line: the line skips it.
        app(RegisterManualBoxScan::class)->handle($this->operator, $this->box(2), TraceContext::forUser($this->operator, Origin::Web, (string) Str::uuid()));
        $this->assertSame(BoxReadOutcome::AlreadyRead, $session->feed($this->box(2)."\n")[0]->outcome);

        $this->assertSame(2, $this->counted());
        $this->assertSame(0, TraceEvent::query()->where('event', TraceEventType::ScanRejected->value)->count());
    }

    public function test_a_box_the_reader_could_not_count_is_traced_on_the_reader_and_raises_an_alert(): void
    {
        $reads = $this->readerSession()->feed("NoRead\nTRZB:".str_repeat('X', 32)."\n");

        $this->assertSame([BoxReadOutcome::Rejected, BoxReadOutcome::Rejected], array_map(fn ($r) => $r->outcome, $reads));
        $this->assertSame(0, $this->counted());
        $traces = TraceEvent::query()->where('event', TraceEventType::ScanRejected->value)->where('subject_type', 'device')->where('subject_id', $this->reader->id)->get();
        $this->assertCount(2, $traces);
        $this->assertSame('NoRead', $traces[0]->context['read'] ?? null);

        $alert = collect(app(OperationalChecks::class)->run())->firstWhere('key', 'reader_not_counted');
        $this->assertNotNull($alert);
        $this->assertSame(2, $alert->count);
    }

    public function test_only_an_active_fixed_reader_can_count(): void
    {
        // The shared device of the box reads is created by the first read by hand.
        app(RegisterManualBoxScan::class)->handle($this->operator, $this->box(5), TraceContext::forUser($this->operator, Origin::Web, (string) Str::uuid()));
        $manual = Device::query()->where('code', RegisterManualBoxScan::DEVICE_CODE)->sole();
        $sensor = app(ManageDevices::class)->register('SEN-L1', 'Sensor', 'SENSOR', TraceContext::system(Origin::Console, (string) Str::uuid()))[0];
        $this->reader->forceFill(['active' => false])->save();

        foreach ([$this->reader, $manual, $sensor] as $device) {
            try {
                app(RegisterManualBoxScan::class)->fromReader($device, $this->box(1), TraceContext::forDevice($device, (string) Str::uuid()));
                $this->fail("{$device->code} no debía contar.");
            } catch (\InvalidArgumentException) {
            }
            $this->assertSame(1, Artisan::call('boxes:reader', ['device' => $device->code, '--connect' => '127.0.0.1:1', '--once' => true]));
        }
        $this->assertSame(1, $this->counted());
    }

    public function test_the_command_connects_to_a_reader_that_is_the_tcp_server(): void
    {
        $file = $this->bytes($this->box(1)."\r\n".$this->box(2)."\r\n");
        $process = proc_open([PHP_BINARY, base_path('tests/Support/fake-box-reader.php'), 'server', $file], [1 => ['pipe', 'w']], $pipes);
        $port = trim((string) fgets($pipes[1]));

        $status = Artisan::call('boxes:reader', ['device' => 'lec-l1', '--connect' => "127.0.0.1:{$port}", '--once' => true]);
        proc_close($process);

        $this->assertSame(0, $status, Artisan::output());
        $this->assertSame(2, $this->counted());
        $this->assertTrue(Heartbeat::isFresh(Heartbeat::reader('LEC-L1')));
    }

    public function test_the_command_waits_for_a_reader_that_is_the_tcp_client(): void
    {
        $free = stream_socket_server('tcp://127.0.0.1:0');
        $port = substr((string) strrchr((string) stream_socket_get_name($free, false), ':'), 1);
        fclose($free);
        $file = $this->bytes($this->box(3)."\n");
        $process = proc_open([PHP_BINARY, base_path('tests/Support/fake-box-reader.php'), 'client', $port, $file], [], $pipes);

        $status = Artisan::call('boxes:reader', ['device' => 'LEC-L1', '--listen' => "127.0.0.1:{$port}", '--once' => true]);
        proc_close($process);

        $this->assertSame(0, $status, Artisan::output());
        $this->assertSame(1, $this->counted());
    }

    public function test_a_reader_that_was_connected_and_no_longer_is_raises_an_alert(): void
    {
        $alerts = fn () => collect(app(OperationalChecks::class)->run())->pluck('key')->all();

        // Never connected: maybe not installed yet, no alert.
        $this->assertNotContains('reader:LEC-L1', $alerts());

        Heartbeat::record(Heartbeat::reader('LEC-L1'));
        $this->assertNotContains('reader:LEC-L1', $alerts());

        $this->travel(config()->integer('operations.heartbeat_max_age_minutes') + 1)->minutes();
        $this->assertContains('reader:LEC-L1', $alerts());
    }

    public function test_the_station_shows_each_line_read_in_green_or_red(): void
    {
        $station = fn () => Livewire::actingAs($this->operator)->test('pages::production.station');

        $station()->assertSee('Lectura automática de la línea')->assertSee('LEC-L1: sin conectar aún')->assertSee('Todavía no hay lecturas');

        Heartbeat::record(Heartbeat::reader('LEC-L1'));
        $this->readerSession()->feed($this->box(1)."\n");
        $box = (string) BoxLabel::query()->where('box_label_batch_id', $this->batch->id)->where('sequence', 1)->value('code');
        $station()->assertSee('LEC-L1: conectado')
            ->assertSeeHtml('data-line-result="ok"')
            ->assertSee($box)
            ->assertSee('EST-');

        $this->travel(5)->seconds();
        $this->readerSession()->feed("NoRead\n");
        $station()->assertSeeHtml('data-line-result="error"')
            ->assertSee('Caja no contada')
            ->assertSee('Lee esa caja a mano')
            ->assertSee('NoRead')
            ->assertSee('Últimas cajas no contadas por el lector');

        $this->travel(5)->seconds();
        $this->readerSession()->feed($this->box(2)."\n");
        $station()->assertSeeHtml('data-line-result="ok"')->assertSee('Últimas cajas no contadas por el lector');
    }

    public function test_the_station_hides_the_line_panel_without_fixed_readers(): void
    {
        $this->reader->forceFill(['active' => false])->save();

        Livewire::actingAs($this->operator)->test('pages::production.station')->assertDontSee('Lectura automática de la línea');
    }

    private function bytes(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'reader');
        file_put_contents($file, $content);
        $this->beforeApplicationDestroyed(fn () => @unlink($file));

        return $file;
    }
}
