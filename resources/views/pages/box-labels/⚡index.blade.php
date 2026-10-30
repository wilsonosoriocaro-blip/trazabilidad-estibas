<?php

use App\Domain\BoxLabels\Actions\ConsultProductionOrder;
use App\Domain\BoxLabels\Actions\GenerateBoxLabels;
use App\Domain\BoxLabels\Actions\PrintBoxLabels;
use App\Domain\BoxLabels\BoxLabelPayload;
use App\Domain\BoxLabels\BoxLabelQr;
use App\Domain\BoxLabels\BoxLabelZpl;
use App\Domain\BoxLabels\BoxLot;
use App\Domain\BoxLabels\BoxQr;
use App\Domain\BoxLabels\Itf14;
use App\Domain\BoxLabels\Exceptions\BoxLabelRuleViolation;
use App\Domain\Integration\Enums\MasterStatus;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Enums\Permission;
use App\Integrations\Sap\Dto\Quantity;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Models\BoxConversionFactor;
use App\Models\BoxLabel;
use App\Models\BoxLabelBatch;
use App\Models\BoxLabelPrint;
use App\Models\ProductionOrder;
use App\Services\ZebraPrintService;
use App\Support\Correlation\CorrelationId;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Box labels: look the production order up in this application's copy of
 * SAP (kept by the order board's refresh), check its header and
 * generate the QR labels (10 × 8 cm, ZPL for Zebra) for its boxes.
 */
new #[Title('Etiquetas de caja')] class extends Component {
    public function boot(): void
    {
        abort_unless(Auth::user()?->can(Permission::BoxLabelsPrint->value), 403);
    }

    public string $docNum = '';

    #[Locked]
    public ?int $orderId = null;

    public int $labels = 1;

    public string $productionDate = '';

    public string $expirationDate = '';

    /** Lot typed by a supervisor; empty means OF + Julian day. */
    public string $lotCode = '';

    #[Locked]
    public ?int $batchId = null;

    public string $printerIp = '192.168.1.154';

    public int $printerPort = 9100;

    public ?array $printerStatus = null;

    public function mount(): void
    {
        $this->productionDate = today()->toDateString();
        $this->printerIp = (string) config('services.zebra.ip', env('ZEBRA_PRINTER_IP', '192.168.1.154'));
        $this->printerPort = (int) config('services.zebra.port', env('ZEBRA_PRINTER_PORT', 9100));
        $this->checkPrinter();
    }

    public function checkPrinter(): void
    {
        $zebra = app(ZebraPrintService::class);
        $this->printerStatus = $zebra->getPrinterStatus($this->printerIp, $this->printerPort);
    }

    public function printBatchDirect(?int $batchId = null): void
    {
        $targetBatch = $batchId !== null
            ? BoxLabelBatch::query()->find($batchId)
            : $this->batch;

        if ($targetBatch === null || empty($targetBatch->zpl)) {
            Flux::toast(variant: 'danger', text: 'No hay etiquetas ZPL disponibles para imprimir.');
            return;
        }

        $result = app(PrintBoxLabels::class)->handle(Auth::user(), $targetBatch->id, $this->printerIp, $this->printerPort, $this->context());
        unset($this->order);

        if ($result['success']) {
            Flux::toast(variant: 'success', text: "{$targetBatch->labels} etiquetas transmitidas a la Zebra ({$this->printerIp}).");
        } else {
            Flux::toast(variant: 'danger', text: $result['message']);
        }

        $this->checkPrinter();
    }

    private function context(): TraceContext
    {
        return TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip());
    }

    #[Computed]
    public function order(): ?ProductionOrder
    {
        return $this->orderId === null ? null : ProductionOrder::query()->with(['item', 'boxLabelBatches.generator:id,name'])->find($this->orderId);
    }

    #[Computed]
    public function factor(): ?BoxConversionFactor
    {
        return $this->order === null ? null : BoxConversionFactor::query()->where('item_id', $this->order->item_id)->where('status', MasterStatus::Current)->first();
    }

    public function maxLabels(): ?int
    {
        if ($this->order?->planned_quantity === null || $this->factor === null) {
            return null;
        }

        return GenerateBoxLabels::maxLabels((string) $this->order->planned_quantity, (string) $this->factor->units_per_box);
    }

    public function printedLabels(): int
    {
        return (int) $this->order?->boxLabelBatches->sum('labels');
    }

    public function canChangeLot(): bool
    {
        return (bool) Auth::user()?->can(Permission::BoxLabelsChangeLot->value);
    }

    /** OF + Julian day of the production date being filled in. */
    public function defaultLot(): ?string
    {
        $production = $this->date($this->productionDate);

        return $this->order === null || $production === null ? null : BoxLot::code($this->order->ref, $production);
    }

    /** The lot the next batch will carry: the supervisor's, or the default one. */
    public function lot(): ?string
    {
        $typed = trim($this->lotCode);

        return $typed !== '' && $this->canChangeLot() ? $typed : $this->defaultLot();
    }

    public function defaultLotPrinted(): bool
    {
        $default = $this->defaultLot();

        return $default !== null && BoxLabelPrint::lotPrinted((int) $this->orderId, $default);
    }

    #[Computed]
    public function batch(): ?BoxLabelBatch
    {
        return $this->batchId === null ? null : BoxLabelBatch::query()->find($this->batchId);
    }

    private function date(string $value): ?CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('Y-m-d', $value)?->startOfDay() : null;
    }

    /**
     * What the next label would carry, while the form is being filled in.
     */
    public function preview(): ?BoxLabelPayload
    {
        $production = $this->date($this->productionDate);
        $expiration = $this->date($this->expirationDate);

        if ($this->order === null || $this->factor === null || Itf14::forBox($this->order->item->barcode, Itf14::indicator()) === null || $production === null || $expiration === null) {
            return null;
        }

        return new BoxLabelPayload(
            orderRef: $this->order->ref,
            itemCode: $this->order->item->code,
            itemDescription: $this->order->item->description,
            unitsPerBox: (new Quantity((string) $this->factor->units_per_box))->display(),
            uomName: $this->order->uom_name ?? $this->order->item->inventory_uom,
            lotCode: (string) $this->lot(),
            barcode: (string) Itf14::forBox($this->order->item->barcode, Itf14::indicator()),
            expirationDate: $expiration,
        );
    }

    public function consult(ConsultProductionOrder $consult): void
    {
        $this->validate(['docNum' => ['required', 'string', 'max:20']], attributes: ['docNum' => 'número de OF']);
        $this->reset('orderId', 'batchId', 'labels', 'lotCode');
        unset($this->order, $this->factor, $this->batch);

        try {
            $order = $consult->handle(Auth::user(), $this->docNum);
        } catch (BoxLabelRuleViolation $e) {
            $this->addError('docNum', $e->getMessage());

            return;
        }

        $this->orderId = $order->id;
        $this->suggestExpiration();
    }

    public function updatedProductionDate(): void
    {
        $this->suggestExpiration();
    }

    private function suggestExpiration(): void
    {
        $production = $this->date($this->productionDate);
        $days = $this->order?->item->shelf_life_days;

        if ($production !== null && $days !== null) {
            $this->expirationDate = $production->addDays($days)->toDateString();
        }
    }

    public function generate(GenerateBoxLabels $generate): void
    {
        $this->validate([
            'labels' => ['required', 'integer', 'min:1'],
            'productionDate' => ['required', 'date_format:Y-m-d'],
            'expirationDate' => ['required', 'date_format:Y-m-d'],
            'lotCode' => ['nullable', 'string', 'max:40'],
        ], attributes: ['labels' => 'cantidad de etiquetas', 'productionDate' => 'fecha de producción', 'expirationDate' => 'fecha de vencimiento', 'lotCode' => 'lote']);

        try {
            $batch = $generate->handle(Auth::user(), (int) $this->orderId, $this->labels, $this->date($this->productionDate) ?? today(), $this->date($this->expirationDate), $this->context(), trim($this->lotCode) === '' ? null : $this->lotCode);
        } catch (BoxLabelRuleViolation $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->batchId = $batch->id;
        $this->lotCode = '';
        unset($this->order, $this->batch);
        Flux::toast(variant: 'success', text: "{$batch->labels} etiquetas generadas. Lista para imprimir en Zebra o descargar ZPL.");
    }
}; ?>

@php
    $order = $this->order;
    $preview = $this->preview();
    $max = $this->maxLabels();
    $printed = $this->printedLabels();
    $tz = config('app.timezone');
    // The first label: its printed text (with its box code) and its QR, which carries only the box token.
    $boxes = $this->batch === null ? collect() : BoxLabel::query()->where('box_label_batch_id', $this->batch->id)->orderBy('sequence')->get(['code', 'qr_token']);
    $nextBox = $preview === null ? null : BoxLot::boxCode($preview->lotCode, (int) BoxLabel::query()->where('production_order_id', $order->id)->where('lot_code', $preview->lotCode)->max('sequence') + 1);
    $shownPayload = $this->batch !== null
        ? $this->batch->qr_payload.($boxes->isEmpty() ? '' : "\nCaja: ".$boxes->first()->code)
        : $preview?->withBox((string) $nextBox)->encode();
    // Before generating there is no token yet: the preview QR shows the format with a sample.
    $shownQr = BoxQr::payload($boxes->first()?->qr_token ?? str_repeat('0', 32));

    // Código ZPL exacto de la primera etiqueta para la simulación térmica física
    $sampleZpl = null;
    if ($this->batch !== null && !empty($this->batch->zpl)) {
        $firstZplEnd = strpos($this->batch->zpl, '^XZ');
        $sampleZpl = $firstZplEnd !== false ? substr($this->batch->zpl, 0, $firstZplEnd + 3) : $this->batch->zpl;
    } elseif ($preview !== null) {
        $sampleZpl = BoxLabelZpl::render($preview->withBox((string) ($nextBox ?? '0001')), today(), $shownQr);
    }
@endphp

<section class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Etiquetas de caja</flux:heading>
            <flux:subheading>QR para las cajas de producto. Formato exacto 10 × 8 cm para impresora Zebra (ZPL)</flux:subheading>
        </div>

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
    </div>

    <form wire:submit="consult" class="flex max-w-xl flex-col gap-2">
        <flux:label for="doc-num">Número de orden de fabricación</flux:label>
        <div class="flex items-start gap-2">
            <flux:input id="doc-num" wire:model="docNum" placeholder="Ej. 33128" inputmode="numeric" autocomplete="off" autofocus class="flex-1" />
            <flux:button type="submit" variant="primary" icon="magnifying-glass" wire:loading.attr="disabled" wire:target="consult">Buscar OF</flux:button>
        </div>
        <flux:error name="docNum" />
    </form>

    @if ($order !== null)
        <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg" level="2">Orden de fabricación {{ $order->ref }}</flux:heading>
                <flux:badge color="green">{{ ProductionOrderStatus::tryFrom((string) $order->sap_status)?->label() ?? $order->sap_status }}</flux:badge>
            </div>
            <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    'Tipo' => $order->sap_order_type,
                    'Nº producto' => $order->item->code,
                    'Descripción producto' => $order->item->description,
                    'Cantidad planificada' => $order->planned_quantity === null ? null : (new Quantity((string) $order->planned_quantity))->display().' '.$order->uom_name,
                    'Almacén' => $order->warehouse_code,
                    'Prioridad' => $order->priority,
                    'Fecha orden de fabricación' => $order->order_date?->format('d/m/Y'),
                    'Fecha de inicio' => $order->start_date?->format('d/m/Y'),
                    'Fecha de finalización' => $order->due_date?->format('d/m/Y'),
                    'Usuario' => $order->sap_user,
                    'Origen' => $order->sap_origin,
                    'Vinculado a' => $order->sap_linked_to,
                ] as $label => $value)
                    <div class="flex justify-between gap-3 border-b border-zinc-100 py-1 dark:border-zinc-800">
                        <dt class="text-zinc-500">{{ $label }}</dt>
                        <dd class="text-end font-medium">{{ $value ?? '—' }}</dd>
                    </div>
                @endforeach
            </dl>
            <flux:text class="mt-3 text-xs">Datos de SAP actualizados el {{ $order->synced_at?->timezone($tz)->format('d/m/Y H:i') }} desde el Tablero de OF. Si algo cambió en SAP, actualiza el tablero.</flux:text>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:heading size="lg" level="2">Generar etiquetas</flux:heading>

                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-zinc-500">Unidades por caja</dt>
                    <dd class="font-medium">{{ $this->factor ? (new Quantity((string) $this->factor->units_per_box))->display().' '.($order->uom_name ?? $order->item->inventory_uom) : 'Sin definir (DP-02)' }}</dd>
                    <dt class="text-zinc-500">Código EAN-14 de la caja</dt>
                    <dd class="font-mono font-medium">
                        {{ Itf14::forBox($order->item->barcode, Itf14::indicator()) ?? ($order->item->barcode ? 'Código no válido' : 'Sin código en SAP') }}
                        @if ($order->item->barcode && strlen(trim($order->item->barcode)) === 13)<div class="text-xs font-normal text-zinc-500">Desde el EAN-13 de SAP {{ $order->item->barcode }}</div>@endif
                    </dd>
                    <dt class="text-zinc-500">Etiquetas generadas</dt>
                    <dd class="font-medium tabular-nums">{{ $printed }}</dd>
                    <dt class="text-zinc-500">Sugeridas por la OF</dt>
                    <dd class="font-medium tabular-nums">{{ $max ?? '—' }} <span class="text-xs font-normal text-zinc-500">(se puede generar más)</span></dd>
                </dl>

                <form wire:submit="generate" class="flex flex-col gap-4">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <flux:input wire:model.live.debounce.400ms="labels" type="number" min="1" label="Cantidad de etiquetas" required />
                        <flux:input wire:model.live="productionDate" type="date" label="Fecha de producción" required />
                        <flux:input wire:model.live="expirationDate" type="date" label="Fecha de vencimiento" required />
                    </div>
                    @php($defaultLot = $this->defaultLot())
                    @if ($this->canChangeLot())
                        @if ($this->defaultLotPrinted())
                            <flux:text class="text-sm">Lote: <span class="font-mono font-medium">{{ $defaultLot }}</span>. Ya tiene etiquetas impresas en la Zebra: no se puede cambiar.</flux:text>
                        @else
                            <div>
                                <flux:input wire:model.live.debounce.400ms="lotCode" label="Lote" :placeholder="$defaultLot" maxlength="40" autocomplete="off"
                                    description="Vacío usa {{ $defaultLot ?? 'OF + día juliano' }} (OF + día juliano). Solo cámbialo si el lote es otro; se puede mientras no haya etiquetas impresas de ese lote." />
                            </div>
                        @endif
                    @else
                        <flux:text class="text-sm">Lote: <span class="font-mono font-medium">{{ $defaultLot ?? '—' }}</span> (OF + día juliano de la fecha de producción)</flux:text>
                    @endif
                    @php($over = $max === null ? 0 : $printed + (int) $labels - $max)
                    @if ($over > 0)
                        <flux:callout variant="warning" icon="exclamation-triangle" heading="Supera lo sugerido por la OF en {{ $over }} etiquetas" text="Se permite para reponer etiquetas mal impresas o cajas averiadas. Queda registrado." />
                    @endif
                    <flux:button type="submit" variant="primary" icon="qr-code" wire:loading.attr="disabled" wire:target="generate"
                        wire:confirm="¿Generar {{ $labels }} etiquetas de caja para la OF {{ $order->ref }} con el lote {{ $this->lot() }}?{{ $over > 0 ? ' Supera en '.$over.' las sugeridas por la OF.' : '' }}">Generar etiquetas</flux:button>
                </form>
            </div>

            <div class="flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg" level="2">{{ $this->batch ? 'Etiquetas generadas' : 'Vista previa' }}</flux:heading>
                    @if ($sampleZpl !== null)
                        <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 flex items-center gap-1.5">
                            <span class="inline-block h-2 w-2 rounded-full bg-emerald-500"></span>
                            Simulación Zebra ZT411 · 100 × 80 mm
                        </span>
                    @endif
                </div>

                @if ($sampleZpl !== null)
                    @php($data = BoxLabelPayload::decode($shownPayload))
                    @php($ean = $this->batch?->barcode ?? (string) Itf14::forBox($order->item->barcode, Itf14::indicator()))

                    {{-- Visor térmico reactivo fiel al resultado de la Zebra (igual que zebra.test) --}}
                    <div x-data="{
                        zpl: @js($sampleZpl),
                        imgUrl: null,
                        loading: false,
                        init() {
                            this.loadThermal();
                            this.$watch('zpl', () => this.loadThermal());
                        },
                        async loadThermal() {
                            if (!this.zpl) return;
                            this.loading = true;
                            try {
                                const res = await fetch('{{ route('box-labels.preview') }}', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                    },
                                    body: JSON.stringify({ zpl: this.zpl, dpmm: '8dpmm', width: '4', height: '3.15' })
                                });
                                if (res.ok) {
                                    const blob = await res.blob();
                                    if (this.imgUrl) URL.revokeObjectURL(this.imgUrl);
                                    this.imgUrl = URL.createObjectURL(blob);
                                }
                            } catch (e) {
                                console.error('Thermal preview error:', e);
                            } finally {
                                this.loading = false;
                            }
                        }
                    }" class="w-full max-w-md rounded-xl border border-zinc-200 bg-zinc-50 p-2 shadow-md dark:border-zinc-700 dark:bg-zinc-800/50">
                        <div class="relative flex items-center justify-center min-h-[250px] rounded-lg bg-white p-1 border border-zinc-300 dark:border-zinc-600 shadow-sm overflow-hidden">
                            {{-- Indicador de carga --}}
                            <div x-show="loading && !imgUrl" class="absolute inset-0 flex flex-col items-center justify-center bg-white/95 gap-2 text-zinc-600 z-10">
                                <svg class="animate-spin h-6 w-6 text-emerald-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                <span class="text-xs font-semibold">Generando simulación física Zebra ZT411...</span>
                            </div>

                            {{-- Render térmico oficial del motor ZPL --}}
                            <template x-if="imgUrl">
                                <img :src="imgUrl" alt="Etiqueta Zebra ZT411 100x80mm" class="w-full h-auto block select-none" />
                            </template>

                            {{-- Respaldo visual con fuentes y márgenes idénticos a ZebraDesigner si aún no carga --}}
                            <div x-show="!imgUrl && !loading" class="w-full aspect-[10/8] p-4 text-black flex flex-col justify-between select-none font-sans">
                                <div class="text-center">
                                    <div class="text-xl font-black tracking-wide leading-none uppercase">
                                        {{ $data['item'] ?? ($preview?->itemCode ?? '') }}
                                    </div>
                                    <div class="text-sm font-black leading-tight mt-1 uppercase line-clamp-2">
                                        {{ $data['description'] ?? ($preview?->itemDescription ?? '') }}
                                    </div>
                                </div>
                                <div class="flex items-center justify-between gap-2 px-1">
                                    <div class="space-y-1 text-sm font-black tracking-tight">
                                        <div>LOTE: <span class="font-mono">{{ $data['lot'] ?? ($preview?->lotCode ?? '') }}</span></div>
                                        <div>VENCE: <span class="font-mono">{{ $preview?->expirationDate->format('m/Y') ?? '' }}</span></div>
                                        <div>CAJA : {{ $preview?->unitsPerBox ?? ($this->factor ? (new Quantity((string) $this->factor->units_per_box))->display() : '') }} UNIDADES</div>
                                    </div>
                                    <div class="shrink-0 flex items-center justify-center">
                                        <div class="h-24 w-24 [&_svg]:h-full [&_svg]:w-full">{!! BoxLabelQr::svg($shownQr) !!}</div>
                                    </div>
                                </div>
                                <div class="grid grid-cols-12 gap-2 items-center">
                                    <div class="col-span-8 flex flex-col items-center">
                                        @if (Itf14::isValid($ean))
                                            <div class="w-full [&_svg]:h-16 [&_svg]:w-full">{!! Itf14::svg($ean) !!}</div>
                                        @endif
                                    </div>
                                    <div class="col-span-4 flex justify-center items-center">
                                        <img src="/images/logo.png" alt="Tecnoal" class="h-12 w-auto object-contain grayscale contrast-200" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <flux:text class="text-xs">
                        El QR lleva solo el código único de la caja; la OF, el PT, el lote y el vencimiento se toman de la aplicación al leerlo.
                        @if ($boxes->count() > 1) Cajas de {{ $boxes->first()->code }} a {{ $boxes->last()->code }}.@endif
                        Caja de muestra: <strong>Caja {{ $data['box'] ?? ($boxes->first()?->code ?? ($nextBox ?? '0001')) }}</strong> · EAN legible: <strong>{{ Itf14::readable($ean) }}</strong>
                    </flux:text>
                    <pre class="rounded bg-zinc-100 p-2 font-mono text-xs dark:bg-zinc-800">{{ $shownQr }}</pre>

                    @if ($this->batch)
                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            <flux:button variant="primary" icon="printer" wire:click="printBatchDirect" wire:loading.attr="disabled" wire:target="printBatchDirect">
                                Imprimir en Zebra ({{ $this->batch->labels }} etiquetas)
                            </flux:button>
                            <flux:button variant="subtle" icon="arrow-down-tray" :href="route('box-labels.zpl', $this->batch)">
                                Descargar ZPL ({{ $this->batch->labels }} etiquetas)
                            </flux:button>
                        </div>
                    @endif
                @else
                    <flux:text>Completa unidades por caja, código EAN y fechas para ver la etiqueta.</flux:text>
                @endif
            </div>
        </div>

        @if ($order->boxLabelBatches->isNotEmpty())
            @php($prints = BoxLabelPrint::query()->where('production_order_id', $order->id)->selectRaw('box_label_batch_id, count(*) as n')->groupBy('box_label_batch_id')->pluck('n', 'box_label_batch_id')->map(fn ($n) => (int) $n)->all())
            <div>
                <flux:heading size="lg" level="2" class="mb-3">Etiquetas generadas para esta orden</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Generadas</flux:table.column>
                        <flux:table.column>Lote</flux:table.column>
                        <flux:table.column>Vence</flux:table.column>
                        <flux:table.column align="end">Etiquetas</flux:table.column>
                        <flux:table.column>Por</flux:table.column>
                        <flux:table.column align="end">Impresa en Zebra</flux:table.column>
                        <flux:table.column align="end"><span class="sr-only">Acciones</span></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($order->boxLabelBatches->sortByDesc('id') as $b)
                            <flux:table.row :key="$b->id">
                                <flux:table.cell class="whitespace-nowrap text-xs tabular-nums">{{ $b->generated_at->timezone($tz)->format('d/m/Y H:i') }}</flux:table.cell>
                                <flux:table.cell class="font-mono">{{ $b->lot_code }}</flux:table.cell>
                                <flux:table.cell>{{ $b->expiration_date->format('d/m/Y') }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $b->labels }}</flux:table.cell>
                                <flux:table.cell>{{ $b->generator->name }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ ($prints[$b->id] ?? 0) === 0 ? 'No' : $prints[$b->id].' '.(($prints[$b->id] ?? 0) === 1 ? 'vez' : 'veces') }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    <div class="flex items-center justify-end gap-1">
                                        <flux:button size="sm" variant="ghost" icon="printer" wire:click="printBatchDirect({{ $b->id }})" title="Imprimir directo en Zebra">Imprimir</flux:button>
                                        <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('box-labels.zpl', $b)">ZPL</flux:button>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    @endif
</section>
