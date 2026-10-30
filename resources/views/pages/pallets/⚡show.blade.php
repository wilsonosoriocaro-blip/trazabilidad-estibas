<?php

use App\Domain\Integration\Enums\PalletHold;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Pallets\Actions\PrintLabel;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Pallets\PalletLabelZpl;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Transfers\Actions\RequestInventoryTransfers;
use App\Domain\Transfers\Enums\TransferStatus;
use App\Domain\Transfers\Exceptions\TransferRuleViolation;
use App\Domain\Transfers\Enums\TransferPurpose;
use App\Domain\Transfers\InventoryTransferLifecycle;
use App\Enums\Permission;
use App\Jobs\ReconcileInventoryTransfer;
use App\Models\InventoryTransfer;
use App\Models\Pallet;
use App\Models\PalletLabelPrint;
use App\Queries\PalletTimeline;
use App\Services\ZebraPrintService;
use App\Support\Correlation\CorrelationId;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One pallet: identity, the four status dimensions, its box reads, the
 * label prints, the SAP documents that hold it and its full history.
 *
 * The stock transfer 04 → 05 is requested by the approved blind count.
 * Here, with transfers.reconcile, it can be retried after a failure,
 * reconciled or closed as failed.
 */
new #[Title('Detalle de estiba')] class extends Component {
    #[Locked]
    public int $palletId;

    public string $reprintReason = '';

    public string $failReason = '';

    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::PalletsView->value), 403);
    }

    public function mount(Pallet $pallet): void
    {
        $this->palletId = $pallet->id;
    }

    #[Computed]
    public function pallet(): Pallet
    {
        return Pallet::query()->with(['item:id,code,description', 'standard:id,version,sap_source_key'])->findOrFail($this->palletId);
    }

    /**
     * @return Collection<int, PalletLabelPrint>
     */
    #[Computed]
    public function prints(): Collection
    {
        return PalletLabelPrint::query()->with('printer:id,name')->where('pallet_id', $this->palletId)->orderBy('print_number')->get();
    }

    /**
     * The transfer still waiting to know whether it exists in SAP, if any.
     */
    #[Computed]
    public function pendingTransfer(): ?InventoryTransfer
    {
        return InventoryTransfer::query()
            ->where('purpose', TransferPurpose::Cedi)
            ->where('status', SapDocumentStatus::PendingReconciliation)
            ->whereHas('lines', fn ($q) => $q->where('pallet_id', $this->palletId)->where('hold_status', PalletHold::Active))
            ->latest('id')
            ->first();
    }

    private function context(): TraceContext
    {
        return TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip());
    }

    public function retryTransfer(RequestInventoryTransfers $request): void
    {
        abort_unless(Auth::user()?->can(Permission::TransfersReconcile->value), 403);

        try {
            $request->handle(Auth::user(), [$this->palletId], $this->context());
        } catch (TransferRuleViolation $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: 'Transferencia solicitada. Se envía a SAP en segundo plano.');
        unset($this->pallet, $this->pendingTransfer);
    }

    public function reconcileTransfer(): void
    {
        abort_unless(Auth::user()?->can(Permission::TransfersReconcile->value), 403);

        if ($this->pendingTransfer !== null) {
            ReconcileInventoryTransfer::dispatch($this->pendingTransfer->id);
            Flux::toast(text: 'Conciliación en cola.');
        }
    }

    public function failTransfer(InventoryTransferLifecycle $lifecycle): void
    {
        abort_unless(Auth::user()?->can(Permission::TransfersReconcile->value), 403);
        $this->validate(['failReason' => ['required', 'string', 'max:300']], attributes: ['failReason' => 'motivo']);

        if ($this->pendingTransfer === null) {
            return;
        }

        try {
            $lifecycle->failAfterProof($this->pendingTransfer->id, $this->context(), $this->failReason);
        } catch (TransferRuleViolation $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->reset('failReason');
        Flux::toast(variant: 'success', text: 'Transferencia marcada como fallida. Se puede solicitar otra vez.');
        unset($this->pallet, $this->pendingTransfer);
    }

    public function reprint(PrintLabel $print): void
    {
        $this->validate(['reprintReason' => ['required', 'string', 'max:300']], attributes: ['reprintReason' => 'motivo']);

        try {
            $printed = $print->handle(Auth::user(), $this->palletId, $this->reprintReason, TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip()));
            $pallet = $this->pallet;
            if ($pallet) {
                $zpl = PalletLabelZpl::render($pallet, $printed);
                $ip = (string) config('services.zebra.ip', env('ZEBRA_PRINTER_IP', '192.168.1.154'));
                $port = (int) config('services.zebra.port', env('ZEBRA_PRINTER_PORT', 9100));
                $zebra = app(ZebraPrintService::class);
                $result = $zebra->printToSocket($ip, $port, $zpl);

                if ($result['success']) {
                    Flux::toast(variant: 'success', text: "Reimpresión de {$pallet->pallet_code} transmitida a la Zebra ({$ip}).");
                } else {
                    Flux::toast(variant: 'warning', text: "Reimpresión registrada pero falló envío a Zebra: {$result['message']}");
                }
            }
        } catch (PalletRuleViolation $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->redirectRoute('pallets.label', ['pallet' => $this->palletId, 'print' => $printed->print_number]);
    }
}; ?>

@php
    $pallet = $this->pallet;
    $timeline = app(PalletTimeline::class);
    $scans = $timeline->scanSummary($pallet);
    $tz = config('app.timezone');
@endphp

<section class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" class="font-mono">{{ $pallet->pallet_code }}</flux:heading>
            <flux:subheading>{{ $pallet->item_code }} · {{ $pallet->item->description }} · Orden {{ $pallet->production_order_ref }} · Lote {{ $pallet->lot_code }}</flux:subheading>
        </div>
        <flux:button icon="arrow-left" :href="route('pallets.index')" wire:navigate variant="ghost">Volver al tablero</flux:button>
    </div>

    <dl class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><dt class="text-xs text-zinc-500">Cajas</dt><dd class="text-2xl font-semibold tabular-nums">{{ $pallet->boxes_count }} / {{ $pallet->standard_boxes_snapshot }}</dd><dd class="text-xs text-zinc-500">{{ number_format($pallet->completionPercent(), 1, ',', '.') }} % · estándar v{{ $pallet->standard->version }}</dd></div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><dt class="text-xs text-zinc-500">Físico</dt><dd class="font-medium">{{ $pallet->physical_status->label() }}</dd><dd class="text-xs text-zinc-500">{{ $pallet->close_type?->label() }}</dd></div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><dt class="text-xs text-zinc-500">Recepción</dt><dd class="font-medium">{{ $pallet->receipt_status->label() }}</dd></div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><dt class="text-xs text-zinc-500">Auditoría</dt><dd class="font-medium">{{ $pallet->audit_status->label() }}</dd></div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><dt class="text-xs text-zinc-500">Calidad</dt><dd class="font-medium">{{ $pallet->quality_status->label() }}</dd></div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><dt class="text-xs text-zinc-500">Traslado</dt><dd class="font-medium">{{ $pallet->transfer_status->label() }}</dd></div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><dt class="text-xs text-zinc-500">Lecturas</dt><dd class="font-medium tabular-nums">{{ $scans['accepted'] }} aceptadas</dd><dd class="text-xs text-zinc-500">{{ $scans['late'] }} tardías</dd></div>
    </dl>

    <div class="grid gap-6 lg:grid-cols-2">
        <div>
            <flux:heading size="lg" level="2" class="mb-3">Historia</flux:heading>
            <ol class="flex flex-col gap-3 border-s border-zinc-200 ps-4 dark:border-zinc-700">
                @foreach ($timeline->entries($pallet) as $entry)
                    <li>
                        <div class="text-xs tabular-nums text-zinc-500">{{ $entry['at']->timezone($tz)->format('d/m/Y H:i:s') }} · {{ $entry['who'] }}</div>
                        <div class="font-medium text-zinc-800 dark:text-white">{{ $entry['title'] }}</div>
                        @if ($entry['detail'])<div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $entry['detail'] }}</div>@endif
                    </li>
                @endforeach
            </ol>
            @if ($scans['first'])
                <flux:text class="mt-3 text-xs">Primera caja {{ $scans['first']->timezone($tz)->format('d/m/Y H:i:s') }} · última {{ $scans['last']?->timezone($tz)->format('d/m/Y H:i:s') }}</flux:text>
            @endif
        </div>

        <div class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg" level="2" class="mb-3">Etiquetas</flux:heading>
                @forelse ($this->prints as $p)
                    <div class="flex justify-between gap-2 border-b border-zinc-100 py-2 text-sm dark:border-zinc-800" wire:key="print-{{ $p->id }}">
                        <span>#{{ $p->print_number }} {{ $p->kind->value === 'ORIGINAL' ? 'Original' : 'Reimpresión' }} · {{ $p->printer->name }} · {{ $p->printed_at->timezone($tz)->format('d/m/Y H:i') }}</span>
                        <span class="text-zinc-500">{{ $p->reason }}</span>
                    </div>
                @empty
                    <flux:text>Sin impresiones.</flux:text>
                @endforelse

                @if ($pallet->label_print_count > 0)
                    @can('pallets.reprint')
                        <form wire:submit="reprint" class="mt-3 flex items-end gap-2">
                            <flux:input wire:model="reprintReason" label="Motivo de reimpresión" maxlength="300" class="flex-1" />
                            <flux:button type="submit" icon="printer" wire:loading.attr="disabled" wire:target="reprint">Reimprimir</flux:button>
                        </form>
                    @endcan
                @endif
            </div>

            <div>
                <flux:heading size="lg" level="2" class="mb-3">Documentos SAP</flux:heading>
                @forelse ($timeline->documents($pallet) as $doc)
                    <div class="border-b border-zinc-100 py-2 text-sm dark:border-zinc-800">
                        <div class="font-medium">{{ $doc['kind'] }} · {{ $doc['status'] }}</div>
                        <div class="text-xs text-zinc-500">DocNum {{ $doc['doc_num'] ?? '—' }} · DocEntry {{ $doc['doc_entry'] ?? '—' }} · línea {{ $doc['sap_line_num'] ?? '—' }} · cantidad {{ $doc['sap_quantity'] }} · {{ $doc['request_uuid'] }}</div>
                    </div>
                @empty
                    <flux:text>Ningún documento todavía.</flux:text>
                @endforelse

                @can('transfers.reconcile')
                    @if (in_array($pallet->transfer_status, [TransferStatus::Ready, TransferStatus::Failed], true))
                        <div class="mt-3 rounded-lg border border-amber-300 p-3 text-sm dark:border-amber-800">
                            <div class="mb-2">{{ $pallet->transfer_status === TransferStatus::Failed ? 'La transferencia 04 → 05 falló en SAP.' : 'La estiba está auditada y sin transferencia 04 → 05.' }}</div>
                            <flux:button size="sm" variant="primary" icon="arrow-path" wire:click="retryTransfer" wire:loading.attr="disabled" wire:target="retryTransfer"
                                wire:confirm="¿Solicitar en SAP la transferencia de 04 a 05 de esta estiba?">Solicitar transferencia</flux:button>
                        </div>
                    @elseif ($this->pendingTransfer !== null)
                        <div class="mt-3 flex flex-col gap-3 rounded-lg border border-amber-300 p-3 text-sm dark:border-amber-800">
                            <div>La transferencia está pendiente de conciliación: no se sabe todavía si existe en SAP.</div>
                            @if (($this->pendingTransfer->reconciliation['mismatch'] ?? false) === true)
                                <div class="font-medium text-red-600">El documento en SAP no coincide con lo pedido: revisar a mano.</div>
                            @endif
                            <div><flux:button size="sm" icon="arrow-path" wire:click="reconcileTransfer">Conciliar</flux:button></div>
                            <form wire:submit="failTransfer" class="flex items-end gap-2">
                                <flux:input wire:model="failReason" label="Motivo para marcar fallida (solo si SAP confirma que no existe)" maxlength="300" class="flex-1" />
                                <flux:button type="submit" size="sm" variant="danger" wire:loading.attr="disabled" wire:target="failTransfer">Marcar fallida</flux:button>
                            </form>
                        </div>
                    @endif
                @endcan
            </div>
        </div>
    </div>
</section>
