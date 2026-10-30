<?php

namespace Tests\Concurrency;

use App\Domain\BoxLabels\Actions\ConsultProductionOrder;
use App\Domain\Orders\Actions\SyncReleasedOrders;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Role;
use App\Models\BoxConversionFactor;
use App\Models\BoxLabel;
use App\Models\BoxLabelBatch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * Several people generating labels for the same order at once: there is no
 * limit per order, and the box codes of the lot never repeat or skip.
 */
class BoxLabelConcurrencyTest extends ConcurrencyTestCase
{
    public function test_parallel_batches_continue_the_lot_without_gaps_or_repeats(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->assignRole(Role::ProductionOperator->value);
        // Order 33128: 800 units, at 24 per box 34 labels suggested; 48 are asked for.
        app(SyncReleasedOrders::class)->handle(null, TraceContext::forUser($user, Origin::Web, (string) Str::uuid()));
        $order = app(ConsultProductionOrder::class)->handle($user, '33128');
        BoxConversionFactor::factory()->create(['item_id' => $order->item_id, 'units_per_box' => '24']);

        $summaries = $this->runInParallel(array_fill(0, 12, ['mode' => 'box_labels', 'user_id' => $user->id, 'order_id' => $order->id, 'labels' => 4]));

        $this->assertSame(12, $this->total($summaries, 'accepted'));
        $this->assertSame(48, (int) BoxLabelBatch::query()->sum('labels'));
        $this->assertSame(range(1, 48), BoxLabel::query()->orderBy('sequence')->pluck('sequence')->map(fn ($n) => (int) $n)->all());
    }
}
