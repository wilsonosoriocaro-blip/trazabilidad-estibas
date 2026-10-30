<?php

use App\Domain\Pallets\Actions\CloseManually;
use App\Domain\Pallets\Actions\ConfirmLabelByScan;
use App\Domain\Pallets\Actions\RegisterManualBoxScan;
use App\Domain\Pallets\Actions\ConfirmLabeled;
use App\Domain\Pallets\Actions\ConfirmPalletized;
use App\Domain\Pallets\Actions\PrintLabel;
use App\Domain\Pallets\Enums\LabelConfirmation;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Enums\ScanEventStatus;
use App\Domain\Pallets\Enums\ScanRejection;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Pallets\PalletLabelZpl;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Permission;
use App\Models\Pallet;
use App\Models\PalletStandard;
use App\Models\PalletScanEvent;
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
 * Production station: manual reading of box labels (each read fills the
 * pallet of its order and lot), open pallets filling up, pallets waiting to
 * be palletized and labeled, confirming the label by reading it, and recent
 * rejected reads. Every action calls a use case that checks permission and
 * state again on the server.
 */
new #[Title('Producción')] class extends Component {
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::PalletsOperate->value), 403);
    }

    public string $printerIp = '192.168.1.154';

    public int $printerPort = 9100;

    public ?array $printerStatus = null;

    public function mount(): void
    {
        $this->printerIp = (string) config('services.zebra.ip', env('ZEBRA_PRINTER_IP', '192.168.1.154'));
        $this->printerPort = (int) config('services.zebra.port', env('ZEBRA_PRINTER_PORT', 9100));
        $this->checkPrinter();
    }

    public function checkPrinter(): void
    {
        $zebra = app(ZebraPrintService::class);
        $this->printerStatus = $zebra->getPrinterStatus($this->printerIp, $this->printerPort);
    }

    #[Locked]
    public ?int $closingId = null;

    public string $closeReason = '';

    #[Locked]
    public ?int $confirmingId = null;

    #[Locked]
    public ?int $reprintingId = null;

    public string $reprintReason = '';

    public string $scannedQr = '';

    /**
     * Last box read by hand: what the label said and where the box went.
     *
     * @var array{order: string, item: string, description: string, lot: string, box: string, expiration: string, pallet: ?string, boxes: ?int, standard: ?int, closed: bool, test_standard: bool}|null
     */
    #[Locked]
    public ?array $lastBox = null;

    #[Locked]
    public ?string $boxError = null;

    #[Locked]
    public ?string $labelScanMessage = null;

    #[Locked]
    public bool $labelScanOk = false;

    private function context(): TraceContext
    {
        return TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip());
    }

    /**
     * @return Collection<int, Pallet>
     */
    #[Computed]
    public function open(): Collection
    {
        return Pallet::query()->with(['item:id,description', 'standard:id,sap_source_key'])->where('physical_status', PhysicalStatus::Stacking)->orderBy('started_at')->get();
    }

    /**
     * Closed pallets still on the floor: waiting to be palletized or labeled.
     *
     * @return Collection<int, Pallet>
     */
    #[Computed]
    public function pending(): Collection
    {
        return Pallet::query()->with('item:id,description')
            ->whereIn('physical_status', [PhysicalStatus::Finished, PhysicalStatus::Palletized])
            ->orderBy('finished_at')
            ->limit(50)
            ->get();
    }

    /**
     * @return Collection<int, PalletScanEvent>
     */
    #[Computed]
    public function rejected(): Collection
    {
        return PalletScanEvent::query()->with('device:id,code')
            ->where('processing_status', ScanEventStatus::Rejected)
            ->where('received_at', '>=', now()->subHours(8))
            ->latest('received_at')
            ->limit(10)
            ->get();
    }

    public function labelMode(): ?LabelConfirmation
    {
        return LabelConfirmation::configured();
    }

    public function rejectionLabel(?string $reason): string
    {
        return ScanRejection::tryFrom((string) $reason)?->label() ?? (string) $reason;
    }

    /**
     * A box label read with the hand reader (or the camera).
     */
    public function scanBox(string $scanned, ?string $scanId = null): void
    {
        $this->boxError = null;

        try {
            // The box code inside the QR is the read's id; the page's own id is not needed here.
            $scan = app(RegisterManualBoxScan::class)->handle(Auth::user(), $scanned, $this->context());
        } catch (PalletRuleViolation $e) {
            $this->boxError = $e->getMessage();
            $this->lastBox = null;

            return;
        } finally {
            unset($this->open, $this->pending);
        }

        $this->lastBox = [
            'order' => $scan->orderRef,
            'item' => $scan->itemCode,
            'description' => $scan->itemDescription,
            'lot' => $scan->lotCode,
            'box' => $scan->boxCode,
            'expiration' => $scan->expiration->format('d/m/Y'),
            'pallet' => $scan->result->palletCode,
            'boxes' => $scan->result->boxesCount,
            'standard' => $scan->result->standard,
            'closed' => $scan->result->palletClosed,
            'test_standard' => $scan->testStandard,
        ];
    }

    /**
     * The QR of the label already stuck on a pallet, read to confirm it is applied.
     */
    public function scanLabel(string $scanned, string $scanId): void
    {
        try {
            $pallet = app(ConfirmLabelByScan::class)->handle(Auth::user(), $scanned, $this->context());
            $this->labelScanOk = true;
            $this->labelScanMessage = "Etiqueta de la estiba {$pallet->pallet_code} confirmada. Ya se puede pedir su recibo.";
        } catch (PalletRuleViolation $e) {
            $this->labelScanOk = false;
            $this->labelScanMessage = $e->getMessage();
        } finally {
            unset($this->pending);
        }
    }

    public function askClose(int $palletId): void
    {
        $this->closingId = $palletId;
        $this->closeReason = '';
        $this->resetValidation();
        Flux::modal('close-pallet')->show();
    }

    public function close(CloseManually $close): void
    {
        $this->validate(['closeReason' => ['nullable', 'string', 'max:300']], attributes: ['closeReason' => 'motivo']);

        $this->run(function () use ($close): string {
            $pallet = $close->handle(Auth::user(), (int) $this->closingId, $this->closeReason, $this->context());

            return "Estiba {$pallet->pallet_code} cerrada con {$pallet->boxes_count} cajas.";
        });

        Flux::modal('close-pallet')->close();
    }

    public function palletize(int $palletId, ConfirmPalletized $palletize): void
    {
        $this->run(fn () => 'Estiba '.$palletize->handle(Auth::user(), $palletId, $this->context())->pallet_code.' paletizada.');
    }

    public function printLabel(int $palletId, PrintLabel $print): void
    {
        $this->print($print, $palletId, null);
    }

    public function askReprint(int $palletId): void
    {
        $this->reprintingId = $palletId;
        $this->reprintReason = '';
        $this->resetValidation();
        Flux::modal('reprint-label')->show();
    }

    public function reprint(PrintLabel $print): void
    {
        $this->validate(['reprintReason' => ['required', 'string', 'max:300']], attributes: ['reprintReason' => 'motivo']);

        $this->print($print, (int) $this->reprintingId, $this->reprintReason);
        Flux::modal('reprint-label')->close();
    }

    /**
     * Records the print, generates vertical ZPL and transmits directly to the Zebra printer.
     */
    private function print(PrintLabel $print, int $palletId, ?string $reason): void
    {
        $printed = null;
        $this->run(function () use ($palletId, $print, $reason, &$printed): string {
            $printed = $print->handle(Auth::user(), $palletId, $reason, $this->context());

            return 'Etiqueta registrada.';
        });

        if ($printed !== null) {
            $pallet = Pallet::query()->with('item:id,description')->find($palletId);
            if ($pallet) {
                $zpl = PalletLabelZpl::render($pallet, $printed);
                $zebra = app(ZebraPrintService::class);
                $result = $zebra->printToSocket($this->printerIp, $this->printerPort, $zpl);

                if ($result['success']) {
                    Flux::toast(variant: 'success', text: "Etiqueta de estiba {$pallet->pallet_code} impresa en Zebra ({$this->printerIp}).");
                } else {
                    Flux::toast(variant: 'warning', text: "Etiqueta registrada pero falló el envío a Zebra: {$result['message']}");
                }
                $this->checkPrinter();
            }
        }
    }

    public function askConfirmLabel(int $palletId): void
    {
        $this->confirmingId = $palletId;
        $this->scannedQr = '';
        $this->resetValidation();
        Flux::modal('confirm-label')->show();
    }

    public function confirmLabel(ConfirmLabeled $confirm): void
    {
        $this->validate(['scannedQr' => ['nullable', 'string', 'max:100']]);

        $ok = $this->run(fn () => 'Etiqueta de '.$confirm->handle(Auth::user(), (int) $this->confirmingId, $this->scannedQr, $this->context())->pallet_code.' confirmada.');

        if ($ok) {
            Flux::modal('confirm-label')->close();
        }
    }

    /**
     * Runs a use case and turns a rule violation into a message for the operator.
     */
    private function run(callable $operation): bool
    {
        try {
            Flux::toast(variant: 'success', text: $operation());
        } catch (PalletRuleViolation $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return false;
        } finally {
            unset($this->open, $this->pending);
        }

        return true;
    }
}; ?>

<section class="flex w-full flex-col gap-6" wire:poll.5s.visible>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Producción</flux:heading>
            <flux:subheading>Estibas en proceso y por despachar a paletizado</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            {{-- Estado en tiempo real de la Zebra --}}
            <div class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                @if ($printerStatus && ($printerStatus['online'] ?? false))
                    <span class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-xs font-medium text-zinc-700 dark:text-zinc-200">
                        Zebra ZT411 ({{ $printerIp }}): <strong class="text-emerald-600 dark:text-emerald-400">{{ $printerStatus['status_label'] }}</strong>
                    </span>
                @else
                    <span class="inline-block h-2.5 w-2.5 rounded-full bg-rose-500"></span>
                    <span class="text-xs font-medium text-zinc-700 dark:text-zinc-200">
                        Zebra ({{ $printerIp }}): <strong class="text-rose-600 dark:text-rose-400">{{ $printerStatus['status_label'] ?? 'Sin conexión' }}</strong>
                    </span>
                @endif
                <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="checkPrinter" wire:loading.attr="disabled" wire:target="checkPrinter">
                    Verificar
                </flux:button>
            </div>

            @if (! config('traceability.decisions.scan_ingestion_enabled'))
                <flux:badge color="amber" icon="exclamation-triangle" size="lg">Sensor apagado (DP-01)</flux:badge>
            @endif
        </div>
    </div>

    @can('pallets.operate')
        <div class="grid gap-4 rounded-xl border border-zinc-200 p-5 lg:grid-cols-2 dark:border-zinc-700">
            <div class="flex flex-col gap-3">
                <flux:heading size="lg" level="2">Lectura manual de cajas</flux:heading>
                <flux:text>Lee el QR de cada caja. La aplicación busca esa caja, valida su OF y la suma a la estiba de esa OF y ese lote. Cada caja se cuenta una sola vez.</flux:text>
                <x-scanner action="scanBox" label="Etiqueta de la caja" placeholder="Lee el QR de la caja" camera />
            </div>
            <div aria-live="polite">
                @if ($boxError)
                    <div role="alert" class="flex h-full flex-col justify-center gap-1 rounded-xl border border-red-300 bg-red-50 p-5 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
                        <div class="text-lg font-semibold">Caja no contada</div>
                        <div>{{ $boxError }}</div>
                    </div>
                @elseif ($lastBox)
                    @php($percent = $lastBox['standard'] ? min(100, intdiv(($lastBox['boxes'] ?? 0) * 100, $lastBox['standard'])) : 0)
                    <div class="flex h-full flex-col gap-3 rounded-xl border border-emerald-300 bg-emerald-50 p-5 dark:border-emerald-800 dark:bg-emerald-950">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-lg font-semibold text-zinc-900 dark:text-white">Caja contada</span>
                            @if ($lastBox['test_standard'])<flux:badge color="amber" size="sm">Estándar de prueba</flux:badge>@endif
                        </div>
                        <dl class="grid grid-cols-[6rem_1fr] gap-x-3 gap-y-1 text-sm">
                            <dt class="text-zinc-500">OF</dt><dd class="font-mono font-semibold">{{ $lastBox['order'] }}</dd>
                            <dt class="text-zinc-500">Producto</dt><dd>{{ $lastBox['item'] }} · {{ $lastBox['description'] }}</dd>
                            <dt class="text-zinc-500">Lote</dt><dd class="font-mono">{{ $lastBox['lot'] }}</dd>
                            <dt class="text-zinc-500">Caja</dt><dd class="font-mono">{{ $lastBox['box'] }}</dd>
                            <dt class="text-zinc-500">Vence</dt><dd>{{ $lastBox['expiration'] }}</dd>
                        </dl>
                        @if ($lastBox['pallet'])
                            <div>
                                <div class="flex items-baseline justify-between">
                                    <span class="font-mono text-xl font-semibold">{{ $lastBox['pallet'] }}</span>
                                    <span class="text-3xl font-bold tabular-nums">{{ $lastBox['boxes'] }}<span class="text-lg font-normal text-zinc-500"> / {{ $lastBox['standard'] }}</span></span>
                                </div>
                                <div class="mt-2 h-3 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"><div class="h-full bg-emerald-600" style="width: {{ $percent }}%"></div></div>
                                @if ($lastBox['closed'])<div class="mt-2 font-semibold text-emerald-800 dark:text-emerald-200">Estiba completa y cerrada. La siguiente caja abre una estiba nueva.</div>@endif
                            </div>
                        @endif
                    </div>
                @else
                    <div class="flex h-full items-center justify-center rounded-xl border border-dashed border-zinc-300 p-5 text-center text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">Aquí verás la OF, el lote y cómo va la estiba con cada caja leída.</div>
                @endif
            </div>
        </div>
    @endcan

    <div>
        <flux:heading size="lg" level="2" class="mb-3">En estibado</flux:heading>
        @if ($this->open->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">No hay estibas abiertas.</div>
        @else
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->open as $pallet)
                    @php($percent = $pallet->completionPercent())
                    <article wire:key="open-{{ $pallet->id }}" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700" aria-label="Estiba {{ $pallet->pallet_code }}">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <div class="font-mono text-2xl font-semibold text-zinc-900 dark:text-white">{{ $pallet->pallet_code }}</div>
                                <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $pallet->item_code }} · {{ $pallet->item->description }}</div>
                                <div class="text-sm text-zinc-500 dark:text-zinc-400">Orden {{ $pallet->production_order_ref }} · Lote {{ $pallet->lot_code }}</div>
                                @if ($pallet->standard?->sap_source_key === PalletStandard::TEST_SOURCE)<flux:badge color="amber" size="sm" class="mt-1">Estándar de prueba</flux:badge>@endif
                            </div>
                        </div>
                        <div>
                            <div class="flex items-baseline justify-between">
                                <span class="text-4xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $pallet->boxes_count }}<span class="text-xl font-normal text-zinc-500"> / {{ $pallet->standard_boxes_snapshot }}</span></span>
                                <span class="text-lg tabular-nums text-zinc-600 dark:text-zinc-300">{{ number_format($percent, 1, ',', '.') }} %</span>
                            </div>
                            <div class="mt-2 h-4 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}">
                                <div class="h-full bg-emerald-600" style="width: {{ min(100, $percent) }}%"></div>
                            </div>
                        </div>
                        <flux:button size="base" variant="filled" icon="lock-closed" wire:click="askClose({{ $pallet->id }})" :disabled="$pallet->boxes_count < 1">
                            Cerrar estiba incompleta
                        </flux:button>
                    </article>
                @endforeach
            </div>
        @endif
    </div>

    @if ($this->labelMode() === LabelConfirmation::Scan)
        @can('pallets.label')
            <div class="grid gap-4 rounded-xl border border-zinc-200 p-5 lg:grid-cols-2 dark:border-zinc-700">
                <div class="flex flex-col gap-3">
                    <flux:heading size="lg" level="2">Confirmar etiqueta pegada</flux:heading>
                    <flux:text>Después de pegar la etiqueta en la estiba paletizada, lee su QR. Con eso queda etiquetada.</flux:text>
                    <x-scanner action="scanLabel" label="QR de la estiba" placeholder="Lee el QR de la etiqueta de la estiba" camera />
                </div>
                <div aria-live="polite">
                    @if ($labelScanMessage)
                        <div @class(['flex h-full items-center rounded-xl border p-5', 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' => $labelScanOk, 'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200' => ! $labelScanOk]) role="status">{{ $labelScanMessage }}</div>
                    @endif
                </div>
            </div>
        @endcan
    @endif

    <div>
        <flux:heading size="lg" level="2" class="mb-3">Por paletizar y etiquetar</flux:heading>
        @if ($this->pending->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-6 text-center text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">Nada pendiente.</div>
        @else
            <div class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($this->pending as $pallet)
                    <div wire:key="pending-{{ $pallet->id }}" class="flex flex-wrap items-center justify-between gap-4 p-4">
                        <div>
                            <div class="font-mono text-lg font-semibold text-zinc-900 dark:text-white">{{ $pallet->pallet_code }}</div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $pallet->item_code }} · Lote {{ $pallet->lot_code }} · {{ $pallet->boxes_count }} cajas ({{ $pallet->close_type?->label() }})</div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($pallet->physical_status === PhysicalStatus::Finished)
                                @can('pallets.palletize')
                                    <flux:button variant="primary" icon="cube" wire:click="palletize({{ $pallet->id }})" wire:loading.attr="disabled" wire:target="palletize({{ $pallet->id }})">Confirmar paletizado</flux:button>
                                @endcan
                            @else
                                @can('pallets.label')
                                    @if ($pallet->label_print_count === 0)
                                        <flux:button variant="primary" icon="printer" wire:click="printLabel({{ $pallet->id }})" wire:loading.attr="disabled" wire:target="printLabel({{ $pallet->id }})">Imprimir etiqueta</flux:button>
                                    @else
                                        @can('pallets.reprint')
                                            <flux:button icon="printer" wire:click="askReprint({{ $pallet->id }})">Reimprimir</flux:button>
                                        @endcan
                                        @if ($this->labelMode() === LabelConfirmation::Button || $this->labelMode() === LabelConfirmation::Scan)
                                            <flux:button variant="primary" icon="check" wire:click="askConfirmLabel({{ $pallet->id }})">Confirmar etiqueta aplicada</flux:button>
                                        @elseif ($this->labelMode() === null)
                                            <flux:badge color="zinc">Confirmación de etiqueta pendiente (DP-03)</flux:badge>
                                        @endif
                                    @endif
                                @endcan
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    @if ($this->rejected->isNotEmpty())
        <div>
            <flux:heading size="lg" level="2" class="mb-3">Lecturas rechazadas (últimas 8 horas)</flux:heading>
            <div class="flex flex-col divide-y divide-red-200 rounded-xl border border-red-200 dark:divide-red-900 dark:border-red-900" role="alert">
                @foreach ($this->rejected as $event)
                    <div wire:key="rej-{{ $event->id }}" class="flex flex-wrap justify-between gap-2 p-3 text-sm">
                        <span class="font-medium text-red-700 dark:text-red-400">{{ $this->rejectionLabel($event->rejection_reason) }}</span>
                        <span class="text-zinc-600 dark:text-zinc-300">Orden {{ $event->production_order_ref }} · {{ $event->item_code }} · Lote {{ $event->lot_code }} · {{ $event->device->code }} · {{ $event->received_at->timezone(config('app.timezone'))->format('H:i:s') }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <flux:modal name="close-pallet" class="w-full max-w-md">
        <form wire:submit="close" class="flex flex-col gap-4">
            <flux:heading size="lg">Cerrar estiba incompleta</flux:heading>
            <flux:text>La estiba se cierra con las cajas que tiene ahora. No recibe más lecturas y la siguiente caja abre una estiba nueva.</flux:text>
            <flux:input wire:model="closeReason" label="Motivo (opcional)" maxlength="300" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="close">Cerrar estiba</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="reprint-label" class="w-full max-w-md">
        <form wire:submit="reprint" class="flex flex-col gap-4">
            <flux:heading size="lg">Reimprimir etiqueta</flux:heading>
            <flux:text>La reimpresión no crea otra estiba ni cambia la cantidad. Queda registrada con tu usuario y el motivo.</flux:text>
            <flux:input wire:model="reprintReason" label="Motivo" maxlength="300" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="reprint">Reimprimir</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="confirm-label" class="w-full max-w-md">
        <form wire:submit="confirmLabel" class="flex flex-col gap-4">
            <flux:heading size="lg">Confirmar etiqueta aplicada</flux:heading>
            @if ($this->labelMode() === LabelConfirmation::Scan)
                <flux:text>Lee con el escáner el QR de la etiqueta ya pegada en la estiba.</flux:text>
                <flux:input wire:model="scannedQr" label="Código leído" autofocus autocomplete="off" />
                <x-qr-camera-button model="scannedQr" submit="confirmLabel" />
            @else
                <flux:text>Confirma que la etiqueta quedó pegada y legible en la estiba.</flux:text>
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="confirmLabel">Confirmar</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
