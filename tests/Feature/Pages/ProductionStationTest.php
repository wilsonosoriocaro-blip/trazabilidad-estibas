<?php

namespace Tests\Feature\Pages;

use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Enums\Role;
use App\Models\Pallet;
use App\Models\PalletCloseReason;
use App\Models\PalletLabelPrint;
use App\Models\PalletScanEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ProductionStationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->operator = $this->userWithRole(Role::ProductionOperator);
    }

    public function test_only_people_who_operate_production_open_the_station(): void
    {
        $this->actingAs($this->operator)->get(route('production.station'))->assertOk();
        $this->actingAs($this->userWithRole(Role::TraceabilityAdmin))->get(route('production.station'))->assertOk();
        $this->actingAs($this->userWithRole(Role::LogisticsOperator))->get(route('production.station'))->assertForbidden();
        $this->actingAs($this->userWithRole(Role::Viewer))->get(route('production.station'))->assertForbidden();

        Livewire::actingAs($this->userWithRole(Role::LogisticsOperator))->test('pages::production.station')->assertForbidden();
    }

    public function test_it_shows_the_open_pallet_with_its_progress(): void
    {
        Pallet::factory()->withBoxes(30)->create(['pallet_code' => 'EST-00000042', 'lot_code' => 'L-77']);

        Livewire::actingAs($this->operator)->test('pages::production.station')
            ->assertSee('EST-00000042')
            ->assertSee('L-77')
            ->assertSee('30')
            ->assertSee('75,0 %');
    }

    public function test_closing_an_incomplete_pallet_from_the_station(): void
    {
        $pallet = Pallet::factory()->withBoxes(3)->create();
        PalletCloseReason::query()->create(['name' => 'Fin de lote', 'sort_order' => 20]);
        $shift = PalletCloseReason::query()->create(['name' => 'Cambio de turno', 'sort_order' => 10]);
        PalletCloseReason::query()->create(['name' => 'Ya no se usa', 'active' => false]);

        $station = Livewire::actingAs($this->operator)->test('pages::production.station')
            ->call('askClose', $pallet->id)
            ->assertSeeInOrder(['Cambio de turno', 'Fin de lote'])
            ->assertDontSee('Ya no se usa');

        $station->call('close')->assertHasErrors(['closeReasonId' => 'required']);
        $this->assertSame(PhysicalStatus::Stacking, $pallet->refresh()->physical_status);

        $station->set('closeReasonId', (string) $shift->id)->set('closeNote', 'Se acabó el turno B')->call('close')->assertHasNoErrors();

        $this->assertSame(PhysicalStatus::Finished, $pallet->refresh()->physical_status);
        $this->assertSame($this->operator->id, $pallet->closed_by);
        $this->assertSame($shift->id, $pallet->close_reason_id);
        $this->assertSame('Se acabó el turno B', $pallet->close_note);
    }

    public function test_without_configured_reasons_the_station_explains_and_does_not_close(): void
    {
        $pallet = Pallet::factory()->withBoxes(3)->create();

        Livewire::actingAs($this->operator)->test('pages::production.station')
            ->call('askClose', $pallet->id)
            ->assertSee('No hay motivos de cierre configurados')
            ->call('close')
            ->assertHasErrors(['closeReasonId']);

        $this->assertSame(PhysicalStatus::Stacking, $pallet->refresh()->physical_status);
    }

    public function test_the_pallet_being_closed_cannot_be_swapped_from_the_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->operator)->test('pages::production.station')->set('closingId', 1);
    }

    public function test_a_refused_operation_shows_the_reason_and_changes_nothing(): void
    {
        $empty = Pallet::factory()->create();

        $reason = PalletCloseReason::query()->create(['name' => 'Fin de lote']);

        Livewire::actingAs($this->operator)->test('pages::production.station')
            ->call('askClose', $empty->id)
            ->set('closeReasonId', (string) $reason->id)
            ->call('close');

        $this->assertSame(PhysicalStatus::Stacking, $empty->refresh()->physical_status);
    }

    public function test_palletize_then_print_opens_the_label(): void
    {
        $pallet = Pallet::factory()->finished()->create();

        $station = Livewire::actingAs($this->operator)->test('pages::production.station')->call('palletize', $pallet->id);
        $this->assertSame(PhysicalStatus::Palletized, $pallet->refresh()->physical_status);

        $station->call('printLabel', $pallet->id);
        $this->assertSame(1, PalletLabelPrint::query()->where('pallet_id', $pallet->id)->count());
    }

    public function test_reprint_from_the_station_requires_a_reason(): void
    {
        $pallet = Pallet::factory()->palletized()->create();
        $station = Livewire::actingAs($this->operator)->test('pages::production.station')->call('printLabel', $pallet->id);

        $station->call('askReprint', $pallet->id)->set('reprintReason', '')->call('reprint')->assertHasErrors(['reprintReason' => 'required']);
        $station->set('reprintReason', 'Se mojó')->call('reprint');
        $this->assertSame(2, PalletLabelPrint::query()->where('pallet_id', $pallet->id)->count());
    }

    public function test_it_says_when_label_confirmation_and_scan_ingestion_are_pending(): void
    {
        config(['traceability.decisions.label_confirmation' => null, 'traceability.decisions.scan_ingestion_enabled' => false]);
        $pallet = Pallet::factory()->palletized()->create();
        Livewire::actingAs($this->operator)->test('pages::production.station')->call('printLabel', $pallet->id);

        Livewire::actingAs($this->operator)->test('pages::production.station')
            ->assertSee('Confirmación de etiqueta pendiente (DP-03)')
            ->assertSee('Sensor automático apagado (DP-01)')
            ->assertDontSeeHtml('wire:click="askConfirmLabel');
    }

    public function test_recent_rejected_reads_are_listed_with_their_reason(): void
    {
        PalletScanEvent::factory()->create(['rejection_reason' => 'ESTANDAR_AUSENTE', 'lot_code' => 'L-RECH']);

        Livewire::actingAs($this->operator)->test('pages::production.station')
            ->assertSee('El producto no tiene estándar de cajas por estiba')
            ->assertSee('L-RECH');
    }
}
