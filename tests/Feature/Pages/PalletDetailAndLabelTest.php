<?php

namespace Tests\Feature\Pages;

use App\Domain\Pallets\Actions\PrintLabel;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Role;
use App\Models\BoxLabelBatch;
use App\Models\Pallet;
use App\Models\PalletLabelPrint;
use App\Models\PalletScanEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PalletDetailAndLabelTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->admin = $this->userWithRole(Role::TraceabilityAdmin);
    }

    private function printed(?string $reason = null, ?Pallet $pallet = null): PalletLabelPrint
    {
        $pallet ??= Pallet::factory()->palletized()->create();

        return app(PrintLabel::class)->handle($this->admin, $pallet->id, $reason, TraceContext::forUser($this->admin, Origin::Web, (string) Str::uuid()));
    }

    public function test_the_detail_shows_identity_states_history_and_prints(): void
    {
        $print = $this->printed();
        $pallet = $print->pallet;

        $this->actingAs($this->admin)->get(route('pallets.show', $pallet))
            ->assertOk()
            ->assertSee($pallet->pallet_code)
            ->assertSee('Paletizado')
            ->assertSee('Etiqueta impresa')
            ->assertSee('#1 Original');

        $this->actingAs($this->admin)->get(route('pallets.show', 999999))->assertNotFound();
        $this->actingAs($this->userWithRole(Role::LogisticsOperator))->get(route('pallets.show', $pallet))->assertForbidden();
    }

    public function test_reprinting_from_the_detail_needs_a_reason(): void
    {
        $pallet = $this->printed()->pallet;

        Livewire::actingAs($this->admin)->test('pages::pallets.show', ['pallet' => $pallet])
            ->call('reprint')->assertHasErrors(['reprintReason' => 'required'])
            ->set('reprintReason', 'Ilegible')->call('reprint')
            ->assertRedirect(route('pallets.label', ['pallet' => $pallet->id, 'print' => 2]));
    }

    public function test_the_label_shows_what_the_floor_needs_and_a_qr_with_only_the_token(): void
    {
        $print = $this->printed();
        $pallet = $print->pallet;

        $html = $this->actingAs($this->admin)->get(route('pallets.label', ['pallet' => $pallet->id, 'print' => 1]))
            ->assertOk()
            ->assertSee($pallet->pallet_code)
            ->assertSee($pallet->production_order_ref)
            ->assertSee($pallet->item_code)
            ->assertSee($pallet->lot_code)
            ->assertDontSee('REIMPRESIÓN')
            ->getContent();

        $this->assertStringContainsString('<svg', (string) $html);
    }

    public function test_the_label_shows_the_expiration_of_its_lot_from_the_box_labels(): void
    {
        $pallet = Pallet::factory()->palletized()->create();
        $this->printed(pallet: $pallet);
        $url = route('pallets.label', ['pallet' => $pallet->id, 'print' => 1]);

        // No box labels for the lot yet: a dash, never a made-up date.
        $this->actingAs($this->admin)->get($url)->assertOk()->assertSeeInOrder(['Vence', '—']);

        BoxLabelBatch::factory()->create(['production_order_id' => $pallet->production_order_id, 'lot_code' => $pallet->lot_code, 'expiration_date' => '2028-10-20']);
        BoxLabelBatch::factory()->create(['production_order_id' => $pallet->production_order_id, 'lot_code' => 'OTRO-LOTE', 'expiration_date' => '2029-01-01']);

        $this->actingAs($this->admin)->get($url)->assertOk()->assertSeeInOrder(['Vence', '20/10/2028'])->assertDontSee('01/01/2029');
    }

    public function test_opening_the_label_again_records_nothing_and_a_reprint_is_marked(): void
    {
        $pallet = $this->printed()->pallet;
        $this->printed('Rota', $pallet);

        $this->actingAs($this->admin)->get(route('pallets.label', ['pallet' => $pallet->id, 'print' => 2]))->assertOk()->assertSee('REIMPRESIÓN');
        $this->actingAs($this->admin)->get(route('pallets.label', ['pallet' => $pallet->id, 'print' => 2]))->assertOk();

        $this->assertSame(2, PalletLabelPrint::count());
        $this->assertSame(2, $pallet->refresh()->label_print_count);
    }

    public function test_a_print_that_does_not_exist_or_belongs_to_another_pallet_is_404_and_access_needs_permission(): void
    {
        $pallet = $this->printed()->pallet;
        $other = Pallet::factory()->palletized()->create();

        $this->actingAs($this->admin)->get(route('pallets.label', ['pallet' => $pallet->id, 'print' => 2]))->assertNotFound();
        $this->actingAs($this->admin)->get(route('pallets.label', ['pallet' => $other->id, 'print' => 1]))->assertNotFound();
        $this->actingAs($this->userWithRole(Role::Viewer))->get(route('pallets.label', ['pallet' => $pallet->id, 'print' => 1]))->assertForbidden();
    }

    public function test_rejected_reads_page_lists_and_reprocesses(): void
    {
        config(['traceability.decisions.scan_ingestion_enabled' => true]);
        $event = PalletScanEvent::factory()->create(['rejection_reason' => 'ORDEN_DESCONOCIDA', 'production_order_ref' => 'OP-FANTASMA']);

        Livewire::actingAs($this->admin)->test('pages::pallets.rejected')
            ->assertSee('OP-FANTASMA')
            ->assertSee('La orden de producción no existe')
            ->call('reprocess', $event->id);

        $this->assertSame('RECHAZADO', $event->refresh()->processing_status->value);
        $this->actingAs($this->userWithRole(Role::LogisticsOperator))->get(route('pallets.rejected'))->assertForbidden();
    }
}
