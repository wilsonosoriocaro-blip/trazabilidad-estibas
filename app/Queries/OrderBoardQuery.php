<?php

namespace App\Queries;

use App\Domain\Orders\Enums\OrderStage;
use App\Domain\Orders\OrderProgress;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Models\ProductionOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * KPI board of production orders released in SAP. The stage comes from each
 * order's progress, so filtering by it and sorting by urgency happen after
 * computing it. Closed orders show only under the "Cerrada en SAP" filter,
 * and only those that reached "ready to close" here (they went through the
 * process in this application), newest closure first. Planned and
 * cancelled orders never show. Released orders whose boxes are already
 * being read are listed first.
 */
class OrderBoardQuery
{
    /** Most urgent first. */
    private const STAGE_ORDER = [
        OrderStage::OverProduced->value => 0,
        OrderStage::ReadyToClose->value => 1,
        OrderStage::InProgress->value => 2,
        OrderStage::NoProgress->value => 3,
        OrderStage::Closed->value => 4,
    ];

    public function __construct(private readonly OrderProgressQuery $progress) {}

    /**
     * @return Collection<int, array{order: ProductionOrder, progress: OrderProgress}>
     */
    public function rows(string $search = '', string $stage = ''): Collection
    {
        $like = '%'.addcslashes(trim($search), '%_\\').'%';

        $closed = $stage === OrderStage::Closed->value;

        $orders = ($closed ? $this->closedThroughApp() : $this->released())
            ->with('item:id,code,description')
            ->when(trim($search) !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('ref', 'ilike', $like)
                ->orWhereHas('item', fn ($i) => $i->where('code', 'ilike', $like)->orWhere('description', 'ilike', $like))))
            ->get();

        $progress = $this->progress->for(array_values(array_map('intval', $orders->modelKeys())));

        return $orders
            ->map(fn (ProductionOrder $order) => ['order' => $order, 'progress' => $progress[$order->id]])
            ->filter(fn (array $row) => $closed || $stage === '' || $row['progress']->stage()->value === $stage)
            ->sort($closed
                ? fn (array $a, array $b) => [$b['order']->sap_closed_at->timestamp ?? 0, $b['order']->id] <=> [$a['order']->sap_closed_at->timestamp ?? 0, $a['order']->id]
                // Orders whose boxes are already being read come first, then by urgency and due date.
                : fn (array $a, array $b) => [$a['progress']->readBoxes > 0 ? 0 : 1, self::STAGE_ORDER[$a['progress']->stage()->value], $a['order']->due_date->timestamp ?? PHP_INT_MAX, -$a['order']->id]
                <=> [$b['progress']->readBoxes > 0 ? 0 : 1, self::STAGE_ORDER[$b['progress']->stage()->value], $b['order']->due_date->timestamp ?? PHP_INT_MAX, -$b['order']->id])
            ->values();
    }

    /**
     * KPIs over every released order. Units are added up only across orders
     * that share the unit, which is why they are grouped by unit.
     *
     * @return array{orders: int, ready: int, over: int, no_progress: int, in_cedi: int, pending_in_sap: int, closed: int, units: array<string, array{planned: int, received: int, transferred: int}>, reading: int, in_progress: int, boxes: array{planned: int, read: int, received: int, transferred: int}}
     */
    public function kpis(): array
    {
        $rows = $this->rows();
        $kpis = ['orders' => $rows->count(), 'ready' => 0, 'over' => 0, 'no_progress' => 0, 'in_cedi' => 0, 'pending_in_sap' => 0, 'closed' => $this->closedThroughApp()->count(), 'units' => [],
            'reading' => 0, 'in_progress' => 0, 'boxes' => ['planned' => 0, 'read' => 0, 'received' => 0, 'transferred' => 0]];

        foreach ($rows as ['order' => $order, 'progress' => $p]) {
            $stage = $p->stage();
            $kpis['ready'] += (int) ($stage === OrderStage::ReadyToClose);
            $kpis['over'] += (int) ($stage === OrderStage::OverProduced);
            $kpis['no_progress'] += (int) ($stage === OrderStage::NoProgress);
            $kpis['in_cedi'] += (int) ($stage->closable() && $p->allInCedi());
            $kpis['pending_in_sap'] += (int) ($p->pendingInSap > 0);
            $kpis['reading'] += (int) ($p->readBoxes > 0);
            $kpis['in_progress'] += (int) ($stage === OrderStage::InProgress);
            // Boxes add up across products and units, so they give one overall picture of the plant.
            $kpis['boxes']['planned'] += (int) $p->plannedBoxes();
            $kpis['boxes']['read'] += $p->readBoxes;
            $kpis['boxes']['received'] += $p->receivedBoxes;
            $kpis['boxes']['transferred'] += $p->transferredBoxes;

            $uom = $order->uom_name ?? 'UNIDAD';
            $kpis['units'][$uom] ??= ['planned' => 0, 'received' => 0, 'transferred' => 0];
            $kpis['units'][$uom]['planned'] += (int) $p->plannedMicro;
            $kpis['units'][$uom]['received'] += $p->receivedMicro;
            $kpis['units'][$uom]['transferred'] += $p->transferredMicro;
        }

        return $kpis;
    }

    /**
     * @return Builder<ProductionOrder>
     */
    private function released(): Builder
    {
        return ProductionOrder::query()->where('sap_status', ProductionOrderStatus::Released->value);
    }

    /**
     * Closed in SAP after reaching "ready to close" here.
     *
     * @return Builder<ProductionOrder>
     */
    private function closedThroughApp(): Builder
    {
        return ProductionOrder::query()->where('sap_status', ProductionOrderStatus::Closed->value)->whereNotNull('ready_to_close_notified_at');
    }
}
