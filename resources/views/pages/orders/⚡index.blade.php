<?php

use App\Domain\Orders\Enums\OrderStage;
use App\Domain\Orders\Actions\UnlockOrderReceipts;
use App\Domain\Orders\OrderSyncStatus;
use App\Domain\Receipts\ReceiptLimit;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Models\ProductionOrder;
use App\Enums\Permission;
use App\Jobs\SyncReleasedOrdersFromSap;
use App\Queries\OrderBoardQuery;
use App\Support\Correlation\CorrelationId;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * KPI board of the production orders released in SAP B1: how far each one
 * got with confirmed receipts and how much of it is already in the CEDI.
 * SAP is only read when someone presses "Actualizar desde SAP".
 */
new #[Title('Tablero de OF')] class extends Component {
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::OrdersView->value), 403);
    }

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $stage = '';

    /** @var array<string, string> */
    public array $problems = [];

    /** True while this page waits for a run it started, so it reports the outcome once. */
    public bool $waiting = false;

    /**
     * Bringing every released order can take longer than a web request may
     * last: the work goes to the SAP queue and the page polls its status.
     */
    public function syncFromSap(): void
    {
        abort_unless(Auth::user()?->can(Permission::OrdersView->value), 403);

        if (OrderSyncStatus::busy()) {
            $this->waiting = true;
            Flux::toast(text: 'Ya hay una actualización desde SAP en curso.');

            return;
        }

        OrderSyncStatus::queued();
        SyncReleasedOrdersFromSap::dispatch((int) Auth::id(), app(CorrelationId::class)->get());
        $this->problems = [];
        $this->waiting = true;
    }

    /** Polled while waiting: when the run ends, shows its outcome and reloads the board. */
    public function checkSync(): void
    {
        if (! $this->waiting || OrderSyncStatus::busy()) {
            return;
        }

        $this->waiting = false;
        $status = OrderSyncStatus::get();
        $this->problems = $status['problems'] ?? [];

        if (($status['state'] ?? null) === 'failed') {
            Flux::toast(variant: 'danger', text: (string) ($status['error'] ?? 'La actualización desde SAP falló.'));
        } else {
            Flux::toast(variant: $this->problems === [] ? 'success' : 'warning', text: 'SAP tiene '.($status['released'] ?? 0).' órdenes liberadas. Tablero actualizado.');
        }

        unset($this->rows, $this->kpis);
    }

    /**
     * @return Collection<int, array{order: \App\Models\ProductionOrder, progress: \App\Domain\Orders\OrderProgress}>
     */
    #[Computed]
    public function rows(): Collection
    {
        return app(OrderBoardQuery::class)->rows($this->search, $this->stage);
    }

    /** Order whose receipts the supervisor is about to unlock, and why. */
    #[Locked]
    public ?int $unlockingId = null;

    public string $unlockReason = '';

    /**
     * Each listed order against the receipt limit, keyed by order id.
     *
     * @return array<int, array{limit: int, planned_micro: int|null, committed_micro: int, percent: int|null, reached: bool, unlocked: bool, blocked: bool}>
     */
    #[Computed]
    public function limits(): array
    {
        $limit = app(ReceiptLimit::class);
        $percent = $limit->percent();
        $orders = [];
        foreach ($this->rows as $row) {
            $orders[$row['order']->id] = $row['order'];
        }
        $committed = $limit->committedFor(array_keys($orders));

        $result = [];
        foreach ($orders as $id => $order) {
            $result[$id] = $limit->status($order, $percent, $committed[$id] ?? 0);
        }

        return $result;
    }

    public function askUnlock(int $orderId): void
    {
        abort_unless(Auth::user()?->can(Permission::OrdersUnlockReceipts->value), 403);
        $this->unlockingId = ProductionOrder::query()->findOrFail($orderId)->id;
        $this->unlockReason = '';
        $this->resetValidation();
        Flux::modal('unlock-receipts')->show();
    }

    public function unlock(UnlockOrderReceipts $unlock): void
    {
        $this->validate(['unlockReason' => ['required', 'string', 'max:300']], attributes: ['unlockReason' => 'motivo']);

        try {
            $order = $unlock->handle(Auth::user(), (int) $this->unlockingId, $this->unlockReason, TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip()));
        } catch (\DomainException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->reset('unlockingId', 'unlockReason');
        unset($this->limits);
        Flux::modal('unlock-receipts')->close();
        Flux::toast(variant: 'success', text: "Recibos habilitados de nuevo en la OF {$order->ref}.");
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function kpis(): array
    {
        return app(OrderBoardQuery::class)->kpis();
    }
}; ?>

@php
    use App\Support\Quantities;
    $kpis = $this->kpis;
    $pct = fn (int $part, int $whole) => $whole === 0 ? 0 : (int) min(100, intdiv($part * 100, $whole));
    $lastSync = \App\Models\ProductionOrder::query()->where('sap_status', 'LIBERADO')->max('synced_at');
@endphp

<section class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">Tablero de OF</flux:heading>
            <flux:subheading>Órdenes de fabricación liberadas en SAP B1: avance por recibos y transferencia a CEDI</flux:subheading>
        </div>
        <div class="text-end">
            @if ($waiting || OrderSyncStatus::busy())
                <div wire:poll.3s="checkSync">
                    <flux:button icon="arrow-path" icon:class="animate-spin" disabled>Actualizando desde SAP…</flux:button>
                </div>
            @else
                <flux:button icon="arrow-path" wire:click="syncFromSap">Actualizar desde SAP</flux:button>
            @endif
            <div class="mt-1 text-xs text-zinc-500">Última actualización: {{ $lastSync ? \Carbon\CarbonImmutable::parse($lastSync)->timezone(config('app.timezone'))->format('d/m/Y H:i') : 'nunca' }}</div>
        </div>
    </div>

    @if ($problems !== [])
        <flux:callout variant="warning" icon="exclamation-triangle" heading="Algunas órdenes no se pudieron actualizar">
            <flux:callout.text>
                <ul class="list-disc ps-5">
                    @foreach ($problems as $ref => $problem)<li><strong>OF {{ $ref }}:</strong> {{ $problem }}</li>@endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif

    {{-- KPIs: one overall picture in boxes (they add up across products and units), and the orders that need attention. --}}
    @php
        $b = $kpis['boxes'];
    @endphp
    <div class="grid gap-4 lg:grid-cols-3" data-of-kpis>
        {{-- Orders --}}
        <div class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-700">
            <div class="flex items-center gap-2 text-sm font-medium text-zinc-500 dark:text-zinc-400"><flux:icon.clipboard-document-list variant="mini" /> OF liberadas</div>
            <div class="mt-1 text-5xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $kpis['orders'] }}</div>
            @php
                $segments = [['En lectura', $kpis['reading'], 'bg-amber-500'], ['Sin lectura', max(0, $kpis['orders'] - $kpis['reading']), 'bg-zinc-300 dark:bg-zinc-600']];
            @endphp
            <div class="mt-3 flex h-3 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800" role="img" aria-label="{{ $kpis['reading'] }} OF con cajas leídas de {{ $kpis['orders'] }}">
                @foreach ($segments as [$label, $n, $color])
                    @if ($n > 0)<div class="h-full {{ $color }}" style="width: {{ $pct($n, max(1, $kpis['orders'])) }}%"></div>@endif
                @endforeach
            </div>
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-amber-500"></span><strong class="tabular-nums">{{ $kpis['reading'] }}</strong> con cajas leídas</span>
                <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-zinc-300 dark:bg-zinc-600"></span><strong class="tabular-nums">{{ max(0, $kpis['orders'] - $kpis['reading']) }}</strong> sin lectura</span>
            </div>
        </div>

        {{-- Overall progress in boxes --}}
        <div class="rounded-2xl border border-zinc-200 p-5 lg:col-span-2 dark:border-zinc-700">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div class="flex items-center gap-2 text-sm font-medium text-zinc-500 dark:text-zinc-400"><flux:icon.cube variant="mini" /> Avance en cajas de las OF liberadas</div>
                <div class="text-xs text-zinc-500 tabular-nums">{{ number_format($b['planned'], 0, ',', '.') }} cajas planificadas</div>
            </div>
            <div class="mt-3 grid gap-4 sm:grid-cols-3">
                @foreach ([['Leídas', $b['read'], 'bg-amber-500', 'text-amber-700 dark:text-amber-400'], ['Recibidas en 04', $b['received'], 'bg-blue-500', 'text-blue-700 dark:text-blue-400'], ['En CEDI', $b['transferred'], 'bg-violet-500', 'text-violet-700 dark:text-violet-400']] as [$label, $n, $bar, $text])
                    <div>
                        <div class="flex items-baseline justify-between">
                            <span class="text-sm font-medium {{ $text }}">{{ $label }}</span>
                            <span class="text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $pct($n, $b['planned']) }}%</span>
                        </div>
                        <div class="mt-1 h-2.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"><div class="h-full rounded-full {{ $bar }}" style="width: {{ $pct($n, $b['planned']) }}%"></div></div>
                        <div class="mt-1 text-xs text-zinc-500 tabular-nums">{{ number_format($n, 0, ',', '.') }} cajas</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- What needs someone --}}
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <button type="button" wire:click="$set('stage', '{{ OrderStage::ReadyToClose->value }}')" @class(['rounded-xl border-2 p-4 text-start transition hover:shadow-sm', 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950' => $kpis['ready'] > 0, 'border-zinc-200 dark:border-zinc-700' => $kpis['ready'] === 0]) data-kpi="ready">
            <div class="flex items-center gap-2 text-sm font-medium text-emerald-800 dark:text-emerald-300"><flux:icon.check-badge variant="mini" /> Listas para cerrar en SAP</div>
            <div class="mt-1 text-3xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $kpis['ready'] }}</div>
            <div class="text-xs text-zinc-500">{{ $kpis['in_cedi'] }} ya completas en CEDI</div>
        </button>
        <button type="button" wire:click="$set('stage', '{{ OrderStage::OverProduced->value }}')" @class(['rounded-xl border-2 p-4 text-start transition hover:shadow-sm', 'border-red-500 bg-red-50 dark:bg-red-950' => $kpis['over'] > 0, 'border-zinc-200 dark:border-zinc-700' => $kpis['over'] === 0]) data-kpi="over">
            <div class="flex items-center gap-2 text-sm font-medium text-red-700 dark:text-red-300"><flux:icon.exclamation-triangle variant="mini" /> Sobreproducción</div>
            <div class="mt-1 text-3xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $kpis['over'] }}</div>
            <div class="text-xs text-zinc-500">recibido por encima de lo planificado</div>
        </button>
        <div @class(['rounded-xl border-2 p-4', 'border-amber-400 bg-amber-50 dark:border-amber-600 dark:bg-amber-950' => $kpis['pending_in_sap'] > 0, 'border-zinc-200 dark:border-zinc-700' => $kpis['pending_in_sap'] === 0]) data-kpi="pending">
            <div class="flex items-center gap-2 text-sm font-medium text-amber-800 dark:text-amber-300"><flux:icon.clock variant="mini" /> Pendientes en SAP</div>
            <div class="mt-1 text-3xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $kpis['pending_in_sap'] }}</div>
            <div class="text-xs text-zinc-500">OF con documentos por conciliar</div>
        </div>
        <button type="button" wire:click="$set('stage', '{{ OrderStage::Closed->value }}')" class="rounded-xl border-2 border-zinc-200 p-4 text-start transition hover:shadow-sm dark:border-zinc-700" data-kpi="closed">
            <div class="flex items-center gap-2 text-sm font-medium text-zinc-600 dark:text-zinc-300"><flux:icon.lock-closed variant="mini" /> Cerradas en SAP</div>
            <div class="mt-1 text-3xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $kpis['closed'] }}</div>
            <div class="text-xs text-zinc-500">pasaron por la aplicación</div>
        </button>
    </div>

    @if ($kpis['pending_in_sap'] > 0)
        <flux:callout variant="warning" icon="clock">{{ $kpis['pending_in_sap'] }} OF con recibos o transferencias pendientes de conciliar con SAP. Esas cantidades todavía no suman.</flux:callout>
    @endif

    <div class="grid items-end gap-3 sm:grid-cols-3">
        <flux:input wire:model.live.debounce.400ms="search" label="Buscar" placeholder="OF, código o descripción" clearable />
        <flux:select wire:model.live="stage" label="Estado">
            <flux:select.option value="">Todos</flux:select.option>
            @foreach ([OrderStage::NoProgress, OrderStage::InProgress, OrderStage::ReadyToClose, OrderStage::OverProduced, OrderStage::Closed] as $s)<flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option>@endforeach
        </flux:select>
    </div>

    <div wire:loading.class="opacity-60" class="transition-opacity">
        @if ($this->rows->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
                @if ($stage === OrderStage::Closed->value)
                    Todavía no hay órdenes cerradas en SAP que hayan pasado por la aplicación. Se ven aquí cuando una OF que llegó a «Lista para cerrar» se cierra en SAP y se actualiza el tablero.
                @else
                    No hay órdenes liberadas con esos filtros. Usa «Actualizar desde SAP» para traerlas.
                @endif
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>OF</flux:table.column>
                    <flux:table.column>Producto</flux:table.column>
                    <flux:table.column align="end">Planificado</flux:table.column>
                    <flux:table.column>Cajas leídas</flux:table.column>
                    <flux:table.column>Recibido en 04</flux:table.column>
                    <flux:table.column>Transferido a CEDI</flux:table.column>
                    <flux:table.column>Estado</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->rows as ['order' => $order, 'progress' => $p])
                        @php
                            $rowStage = $p->stage();
                        @endphp
                        <flux:table.row :key="$order->id">
                            <flux:table.cell>
                                <span class="font-mono font-semibold">{{ $order->ref }}</span>
                                @if ($order->due_date)<div class="text-xs text-zinc-500">Fin {{ $order->due_date->format('d/m/Y') }}</div>@endif
                            </flux:table.cell>
                            <flux:table.cell class="max-w-64 whitespace-normal"><div>{{ $order->item->code }}</div><div class="text-xs text-zinc-500">{{ $order->item->description }}</div></flux:table.cell>
                            <flux:table.cell align="end" class="whitespace-nowrap tabular-nums">
                                {{ Quantities::format((int) $p->plannedMicro) }} <span class="text-xs text-zinc-500">{{ $order->uom_name }}</span>
                                <div class="text-xs text-zinc-500">{{ $p->plannedBoxes() === null ? 'sin unidades por caja' : number_format($p->plannedBoxes(), 0, ',', '.').' cajas' }}</div>
                            </flux:table.cell>
                            {{-- Same layout for each stage: progress bar, then units and boxes. --}}
                            @foreach ([[$p->readPercent(), $p->readMicro(), $p->readBoxes, 'bg-amber-500'], [$p->receivedPercent(), $p->receivedMicro, $p->receivedBoxes, 'bg-blue-500'], [$p->transferredPercent(), $p->transferredMicro, $p->transferredBoxes, 'bg-violet-500']] as [$percent, $micro, $boxes, $color])
                                <flux:table.cell class="min-w-44">
                                    <div class="flex items-center gap-2">
                                        <div class="h-2.5 w-28 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"><div class="h-full rounded-full {{ $color }}" style="width: {{ $percent }}%"></div></div>
                                        <span class="w-10 text-end text-sm font-medium tabular-nums">{{ $percent }}%</span>
                                    </div>
                                    <div class="mt-0.5 text-xs text-zinc-500 tabular-nums">{{ $micro === null ? '—' : Quantities::format($micro) }} {{ $order->uom_name }} · {{ number_format($boxes, 0, ',', '.') }} {{ $boxes === 1 ? 'caja' : 'cajas' }}</div>
                                </flux:table.cell>
                            @endforeach
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$rowStage->color()">{{ $rowStage->label() }}</flux:badge>
                                @if ($rowStage === OrderStage::Closed && $order->sap_closed_at)<div class="mt-1 text-xs text-zinc-500">el {{ $order->sap_closed_at->timezone(config('app.timezone'))->format('d/m/Y') }}</div>@endif
                                @if ($rowStage->closable() && $p->allInCedi())<div class="mt-1"><flux:badge size="sm" color="violet">Completa en CEDI</flux:badge></div>@endif
                                @if ($p->pendingInSap > 0)<div class="mt-1 text-xs text-amber-700 dark:text-amber-400">Pendiente de conciliar en SAP</div>@endif
                                @php
                                    $lim = $this->limits[$order->id] ?? null;
                                @endphp
                                @if ($lim && $lim['blocked'] && $rowStage !== OrderStage::Closed)
                                    <div class="mt-1" data-receipt-limit="blocked"><flux:badge size="sm" color="red" icon="lock-closed">Recibos bloqueados</flux:badge></div>
                                    <div class="mt-0.5 text-xs text-red-700 dark:text-red-400">Llegó al {{ $lim['limit'] }} %: cerrar la OF en SAP</div>
                                    @can('orders.unlock_receipts')
                                        <flux:button size="xs" class="mt-1" icon="lock-open" wire:click="askUnlock({{ $order->id }})">Habilitar recibos</flux:button>
                                    @endcan
                                @elseif ($lim && $lim['unlocked'] && $rowStage !== OrderStage::Closed)
                                    <div class="mt-1 text-xs text-zinc-500" data-receipt-limit="unlocked">Recibos habilitados después del {{ $lim['limit'] }} %</div>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    @can('orders.unlock_receipts')
        <flux:modal name="unlock-receipts" class="w-full max-w-md">
            <form wire:submit="unlock" class="flex flex-col gap-4">
                @php
                    $unlockOrder = $unlockingId ? ProductionOrder::query()->find($unlockingId, ['id', 'ref']) : null;
                @endphp
                <flux:heading size="lg">Habilitar recibos{{ $unlockOrder ? ' · OF '.$unlockOrder->ref : '' }}</flux:heading>
                <flux:text>La OF llegó al límite de recibos y debería cerrarse en SAP. Si producción continúa con esta OF, al habilitarla vuelve a aceptar recibos hasta que se cierre en SAP. Queda registrado quién la habilitó y por qué.</flux:text>
                <flux:input wire:model="unlockReason" label="Motivo" maxlength="300" required />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled">Cancelar</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="unlock">Habilitar recibos</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</section>
