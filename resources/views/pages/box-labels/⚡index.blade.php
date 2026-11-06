<?php

use App\Domain\BoxLabels\Actions\ConsultProductionOrder;
use App\Domain\BoxLabels\Actions\GenerateBoxLabels;
use App\Domain\BoxLabels\Actions\ManagePrintJob;
use App\Domain\BoxLabels\Actions\PrintBoxLabels;
use App\Domain\BoxLabels\BoxLabelPayload;
use App\Domain\BoxLabels\BoxLabelQr;
use App\Domain\BoxLabels\BoxLabelZpl;
use App\Domain\BoxLabels\BoxLot;
use App\Domain\BoxLabels\BoxQr;
use App\Domain\BoxLabels\Itf14;
use App\Domain\BoxLabels\LabelFormatConfig;
use App\Domain\BoxLabels\Exceptions\BoxLabelRuleViolation;
use App\Domain\BoxLabels\Services\ZebraSessionLock;
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
use App\Models\BoxLabelPrintJob;
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

    public string $printerIp = '192.168.1.155';

    public int $printerPort = 9100;

    public int $printSpeed = 6;

    public ?int $activeJobId = null;

    public ?int $lastToastedJobId = null;

    public bool $isPrinting = false;

    public ?int $resumeSequence = null;

    public ?array $printerStatus = null;

    public function mount(): void
    {
        $this->productionDate = today()->toDateString();
        $this->printerIp = (string) config('services.zebra.ip', env('ZEBRA_PRINTER_IP', '192.168.1.155'));
        $this->printerPort = (int) config('services.zebra.port', env('ZEBRA_PRINTER_PORT', 9100));
        $this->printSpeed = LabelFormatConfig::printSpeed();
        $this->checkPrinter();
    }

    public function setZebraSpeed(int $speed): void
    {
        $speed = max(2, min(14, $speed));
        LabelFormatConfig::setPrintSpeed($speed);
        $this->printSpeed = $speed;
        Flux::modal('zebra-speed-modal')->close();
        Flux::toast(variant: 'success', text: "Velocidad de impresión ajustada a {$speed} ips.");
    }

    public function checkPrinter(): void
    {
        $zebra = app(ZebraPrintService::class);
        $this->printerStatus = $zebra->getPrinterStatus($this->printerIp, $this->printerPort);
    }

    #[Computed]
    public function activeJob(): ?BoxLabelPrintJob
    {
        return $this->activeJobId === null ? null : BoxLabelPrintJob::query()->with('batch')->find($this->activeJobId);
    }

    public function openJobMonitor(int $jobId): void
    {
        $this->activeJobId = $jobId;
        $job = BoxLabelPrintJob::query()->find($jobId);
        if ($job) {
            $this->resumeSequence = $job->next_sequence;
            $this->isPrinting = ($job->status === 'printing');
        }
        Flux::modal('zebra-print-monitor-modal')->show();
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

        // Validación de bloqueo de sesión exclusivo para la impresora Zebra
        $lock = app(ZebraSessionLock::class)->getActiveLockForOther($this->printerIp, $this->printerPort, $this->activeJobId, session()->getId());
        if ($lock !== null) {
            Flux::toast(variant: 'warning', text: "La impresora está en uso por {$lock['user_name']} (Lote {$lock['lot_code']}, Trabajo #{$lock['job_id']}). No se permiten impresiones concurrentes.");
            return;
        }

        $manageJob = app(ManagePrintJob::class);
        $job = $manageJob->createOrGetJob(Auth::user(), $targetBatch->id, $this->printerIp, $this->printerPort);
        $this->activeJobId = $job->id;
        $this->resumeSequence = $job->next_sequence;

        // Si ya estaba completado, permitir reimprimir reiniciando el trabajo
        if ($job->status === 'completed') {
            $job->forceFill([
                'printed_labels' => 0,
                'pending_labels' => $job->total_labels,
                'last_printed_sequence' => 0,
                'next_sequence' => $job->start_sequence,
                'status' => 'printing',
                'error_reason' => null,
                'started_at' => now(),
                'completed_at' => null,
            ])->save();
            $this->resumeSequence = $job->start_sequence;
        }

        // Siempre abrir el monitor interactivo y procesar de forma estrictamente granular
        $this->isPrinting = true;
        Flux::modal('zebra-print-monitor-modal')->show();

        // Ejecutar el primer paso inmediatamente
        $this->processActivePrintJob();
    }

    public function processActivePrintJob(): void
    {
        if ($this->activeJobId === null) {
            $this->isPrinting = false;
            return;
        }

        $job = BoxLabelPrintJob::query()->with('batch')->find($this->activeJobId);
        if (! $job) {
            $this->activeJobId = null;
            $this->isPrinting = false;
            return;
        }

        // Si el trabajo ya está completado, detener polling y mantener el modal visible para el usuario
        if ($job->status === 'completed' || $job->pending_labels <= 0) {
            $this->isPrinting = false;
            return;
        }

        // Si no está en estado de impresión activa (ej: error o cancelado), detener polling
        if ($job->status !== 'printing') {
            $this->isPrinting = false;
            return;
        }

        $manageJob = app(ManagePrintJob::class);
        $result = $manageJob->processChunk($job, $this->context());
        $freshJob = $job->fresh();
        $this->resumeSequence = $freshJob?->next_sequence ?? $this->resumeSequence;

        unset($this->order);

        if (($result['status'] ?? '') === 'completed' || ($freshJob?->status ?? '') === 'completed') {
            $this->isPrinting = false;
            $this->checkPrinter();
            if ($this->lastToastedJobId !== $job->id) {
                $this->lastToastedJobId = $job->id;
                Flux::toast(variant: 'success', text: "¡Trabajo completado! Se imprimieron {$job->total_labels} etiquetas en la Zebra.");
            }
            return;
        }

        if (($result['status'] ?? '') === 'paused_error' || ($freshJob?->status ?? '') === 'paused_error') {
            $this->isPrinting = false;
            $this->checkPrinter();
            // El modal se queda abierto para mostrar la causa y el botón de reanudar
        }
    }

    public function resumePrintJob(?int $jobId = null, ?int $fromSeq = null): void
    {
        $id = $jobId ?? $this->activeJobId;
        if (! $id) {
            return;
        }

        $lock = app(ZebraSessionLock::class)->getActiveLockForOther($this->printerIp, $this->printerPort, $id, session()->getId());
        if ($lock !== null) {
            Flux::toast(variant: 'warning', text: "La impresora está en uso por {$lock['user_name']} (Lote {$lock['lot_code']}, Trabajo #{$lock['job_id']}).");
            return;
        }

        $job = BoxLabelPrintJob::query()->find($id);
        if ($job) {
            $job->printer_ip = $this->printerIp;
            $job->printer_port = $this->printerPort;
            $job->save();

            $targetSeq = $fromSeq ?? $this->resumeSequence ?? $job->next_sequence;
            app(ManagePrintJob::class)->resumeJob($job, (int) $targetSeq);
            $job->refresh();

            $this->activeJobId = $job->id;
            $this->resumeSequence = $job->next_sequence;

            // Intentar despausar la Zebra remotamente mediante comando ~PS
            $zebra = app(ZebraPrintService::class);
            $zebra->unpausePrinter($this->printerIp, $this->printerPort);

            $this->isPrinting = true;
            Flux::modal('zebra-print-monitor-modal')->show();
            $this->processActivePrintJob();
        }
    }

    public function pausePrintJob(?int $jobId = null): void
    {
        $id = $jobId ?? $this->activeJobId;
        if (! $id) {
            return;
        }

        $job = BoxLabelPrintJob::query()->find($id);
        if ($job) {
            $this->isPrinting = false;
            app(ManagePrintJob::class)->pauseJob($job);
            $job->refresh();

            $this->activeJobId = $job->id;
            $this->resumeSequence = $job->next_sequence;
            $this->checkPrinter();
            Flux::toast(variant: 'info', text: "Impresión pausada físicamente en la caja #{$job->next_sequence}.");
        }
    }

    public function cancelPrintJob(?int $jobId = null): void
    {
        $id = $jobId ?? $this->activeJobId;
        if (! $id) {
            return;
        }

        $job = BoxLabelPrintJob::query()->find($id);
        if ($job) {
            $this->isPrinting = false;
            app(ManagePrintJob::class)->cancelJob($job);
            app(ZebraSessionLock::class)->release($this->printerIp, $this->printerPort, $job->id);
            Flux::toast(variant: 'warning', text: "Trabajo cancelado. Quedaron {$job->pending_labels} etiquetas sin imprimir.");
            Flux::modal('zebra-print-monitor-modal')->close();
            $this->activeJobId = null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getActivePrinterLock(): ?array
    {
        return app(ZebraSessionLock::class)->getActiveLockForOther($this->printerIp, $this->printerPort, $this->activeJobId, session()->getId());
    }

    public function forceUnlockPrinter(): void
    {
        app(ZebraSessionLock::class)->forceRelease($this->printerIp, $this->printerPort);
        Flux::toast(variant: 'info', text: 'Bloqueo exclusivo de impresora liberado manualmente.');
    }

    private function context(): TraceContext
    {
        return TraceContext::forUser(Auth::user(), Origin::Web, app(CorrelationId::class)->get(), request()->ip());
    }

    #[Computed]
    public function order(): ?ProductionOrder
    {
        return $this->orderId === null ? null : ProductionOrder::query()->with(['item', 'boxLabelBatches.generator:id,name', 'boxLabelBatches.latestPrintJob'])->find($this->orderId);
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

    public function currentLastSequence(): int
    {
        if ($this->order === null) {
            return 0;
        }

        $lot = $this->lot();
        if ($lot === null) {
            return (int) ($this->order->last_box_sequence ?? 0);
        }

        $currentRun = (int) ($this->order->current_box_run ?? 1);
        if ($currentRun < 1) {
            $currentRun = 1;
        }

        $maxSeq = (int) BoxLabel::query()
            ->where('production_order_id', $this->order->id)
            ->where('lot_code', $lot)
            ->where('run_number', $currentRun)
            ->max('sequence');

        if ($maxSeq === 0) {
            $maxSeq = (int) BoxLabel::query()
                ->where('production_order_id', $this->order->id)
                ->where('lot_code', $lot)
                ->max('sequence');
        }

        return $maxSeq;
    }

    public function requestGenerate(): void
    {
        $this->validate([
            'labels' => ['required', 'integer', 'min:1', 'max:3000'],
            'productionDate' => ['required', 'date_format:Y-m-d'],
            'expirationDate' => ['required', 'date_format:Y-m-d'],
            'lotCode' => ['nullable', 'string', 'max:40'],
        ], attributes: ['labels' => 'cantidad de etiquetas', 'productionDate' => 'fecha de producción', 'expirationDate' => 'fecha de vencimiento', 'lotCode' => 'lote']);

        $lastSeq = $this->currentLastSequence();

        // Si ya existen etiquetas generadas para este lote, mostrar modal Flux
        // para que el operador decida si continuar consecutivo o reiniciar a #0001
        if ($lastSeq > 0) {
            Flux::modal('confirm-box-consecutive')->show();

            return;
        }

        $this->executeGenerate(restartSequence: false);
    }

    public function executeGenerate(bool $restartSequence = false): void
    {
        $this->validate([
            'labels' => ['required', 'integer', 'min:1', 'max:3000'],
            'productionDate' => ['required', 'date_format:Y-m-d'],
            'expirationDate' => ['required', 'date_format:Y-m-d'],
            'lotCode' => ['nullable', 'string', 'max:40'],
        ], attributes: ['labels' => 'cantidad de etiquetas', 'productionDate' => 'fecha de producción', 'expirationDate' => 'fecha de vencimiento', 'lotCode' => 'lote']);

        try {
            $batch = app(GenerateBoxLabels::class)->handle(
                Auth::user(),
                (int) $this->orderId,
                $this->labels,
                $this->date($this->productionDate) ?? today(),
                $this->date($this->expirationDate),
                $this->context(),
                trim($this->lotCode) === '' ? null : $this->lotCode,
                $restartSequence,
            );
        } catch (BoxLabelRuleViolation $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
            Flux::modal('confirm-box-consecutive')->close();

            return;
        }

        Flux::modal('confirm-box-consecutive')->close();

        $this->batchId = $batch->id;
        $this->lotCode = '';
        unset($this->order, $this->batch);

        $rangeInfo = "Cajas #{$batch->first_sequence} a #{$batch->last_sequence}";
        if ($batch->is_reset) {
            $rangeInfo .= ' (Consecutivo reiniciado a #1)';
        }

        Flux::toast(variant: 'success', text: "{$batch->labels} etiquetas generadas ({$rangeInfo}). Lista para imprimir en Zebra o descargar ZPL.");
    }

    public function generate(bool $restartSequence = false): void
    {
        $this->executeGenerate($restartSequence);
    }
}; ?>

@php
    $order = $this->order;
    $preview = $this->preview();
    $max = $this->maxLabels();
    $printed = $this->printedLabels();
    $lastSeq = $this->currentLastSequence();
    $tz = config('app.timezone');
    // The first label: its printed text (with its box code) and its QR, which carries only the box token.
    $boxes = $this->batch === null ? collect() : BoxLabel::query()->where('box_label_batch_id', $this->batch->id)->orderBy('sequence')->get(['code', 'qr_token']);
    $nextBoxSeq = $lastSeq > 0 ? $lastSeq + 1 : 1;
    $nextBox = $preview === null ? null : BoxLot::boxCode($preview->lotCode, $nextBoxSeq);
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

        {{-- Estado en tiempo real de la Zebra y control de velocidad --}}
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

            <flux:modal.trigger name="zebra-speed-modal">
                <flux:button size="xs" variant="filled" icon="adjustments-horizontal" title="Ajustar velocidad de impresión">
                    Velocidad: {{ $printSpeed }} ips
                </flux:button>
            </flux:modal.trigger>

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
                    <dt class="text-zinc-500">Último consecutivo en BD</dt>
                    <dd class="font-medium tabular-nums">
                        @if ($lastSeq > 0)
                            <span class="font-mono text-emerald-600 dark:text-emerald-400 font-semibold">Caja #{{ $lastSeq }}</span>
                            <span class="text-xs text-zinc-500">(próxima: #{{ $lastSeq + 1 }})</span>
                        @else
                            <span class="text-zinc-500">Sin cajas generadas aún</span>
                        @endif
                    </dd>
                    <dt class="text-zinc-500">Sugeridas por la OF</dt>
                    <dd class="font-medium tabular-nums">{{ $max ?? '—' }} <span class="text-xs font-normal text-zinc-500">(se puede generar más)</span></dd>
                </dl>

                <form wire:submit="requestGenerate" class="flex flex-col gap-4">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <flux:input wire:model.live.debounce.400ms="labels" type="number" min="1" max="3000" label="Cantidad de etiquetas (máx 3.000)" required />
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
                    <flux:button type="submit" variant="primary" icon="qr-code" wire:loading.attr="disabled" wire:target="requestGenerate, executeGenerate">Generar etiquetas</flux:button>
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
            @php($printerLock = $this->getActivePrinterLock())
            <div>
                @if ($printerLock)
                    <div class="mb-4 flex items-center justify-between rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/60 dark:text-amber-200">
                        <div class="flex items-center gap-2">
                            <flux:icon icon="lock-closed" class="size-5 text-amber-600 dark:text-amber-400 shrink-0" />
                            <div>
                                <strong>Impresora Zebra en uso exclusivo:</strong> {{ $printerLock['user_name'] }} está imprimiendo el lote <code class="font-mono font-bold">{{ $printerLock['lot_code'] }}</code> (Trabajo #{{ $printerLock['job_id'] }}). Por control de búfer, no se permiten impresiones concurrentes.
                            </div>
                        </div>
                        <flux:button size="xs" variant="ghost" wire:click="forceUnlockPrinter" class="text-amber-700 hover:text-amber-900 dark:text-amber-300">Liberar bloqueo</flux:button>
                    </div>
                @endif
                <flux:heading size="lg" level="2" class="mb-3">Etiquetas generadas para esta orden</flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Generadas</flux:table.column>
                        <flux:table.column>Lote</flux:table.column>
                        <flux:table.column>Rango de cajas</flux:table.column>
                        <flux:table.column>Vence</flux:table.column>
                        <flux:table.column align="end">Etiquetas</flux:table.column>
                        <flux:table.column>Por</flux:table.column>
                        <flux:table.column align="end">Estado Impresión</flux:table.column>
                        <flux:table.column align="end"><span class="sr-only">Acciones</span></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($order->boxLabelBatches->sortByDesc('id') as $b)
                            @php($job = $b->latestPrintJob)
                            <flux:table.row :key="$b->id">
                                <flux:table.cell class="whitespace-nowrap text-xs tabular-nums">{{ $b->generated_at->timezone($tz)->format('d/m/Y H:i') }}</flux:table.cell>
                                <flux:table.cell class="font-mono">{{ $b->lot_code }}</flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap font-mono text-xs">
                                    #{{ $b->first_sequence }} - #{{ $b->last_sequence }}
                                    @if ($b->is_reset)
                                        <flux:badge color="amber" size="sm" class="ml-1">Reinicio (C{{ $b->run_number }})</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>{{ $b->expiration_date->format('d/m/Y') }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums font-semibold">{{ $b->labels }}</flux:table.cell>
                                <flux:table.cell>{{ $b->generator->name }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    @if ($job && in_array($job->status, ['paused_error', 'cancelled'], true) && $job->pending_labels > 0)
                                        <div class="flex items-center justify-end gap-1">
                                            <flux:badge color="rose" size="sm" icon="exclamation-circle" title="{{ $job->error_reason ?? 'Pausada' }}">
                                                Pausada ({{ $job->printed_labels }}/{{ $job->total_labels }})
                                            </flux:badge>
                                        </div>
                                    @elseif ($job && $job->status === 'printing')
                                        <div class="flex items-center justify-end gap-1">
                                            <flux:badge color="blue" size="sm" icon="arrow-path" class="animate-pulse">
                                                Imprimiendo ({{ $job->printed_labels }}/{{ $job->total_labels }})
                                            </flux:badge>
                                        </div>
                                    @elseif (($prints[$b->id] ?? 0) > 0 || ($job && $job->status === 'completed'))
                                        <flux:badge color="emerald" size="sm" icon="check">
                                            {{ ($prints[$b->id] ?? 0) > 1 ? $prints[$b->id].' veces' : 'Impresa' }}
                                        </flux:badge>
                                    @else
                                        <span class="text-xs text-zinc-400">Sin imprimir</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($job && in_array($job->status, ['paused_error', 'cancelled'], true) && $job->pending_labels > 0)
                                            @if ($printerLock && ($printerLock['job_id'] ?? null) !== $job->id)
                                                <flux:button size="sm" variant="ghost" disabled icon="lock-closed" title="Impresora ocupada por {{ $printerLock['user_name'] }}">En uso</flux:button>
                                            @else
                                                <flux:button size="sm" variant="danger" icon="play" wire:click="resumePrintJob({{ $job->id }})" title="Reanudar desde caja #{{ $job->next_sequence }}">Reanudar #{{ $job->next_sequence }}</flux:button>
                                            @endif
                                        @elseif ($job && $job->status === 'printing')
                                            <flux:button size="sm" variant="subtle" icon="arrow-path" wire:click="openJobMonitor({{ $job->id }})" title="Ver progreso de impresión">Monitorear</flux:button>
                                        @elseif ($printerLock)
                                            <flux:button size="sm" variant="ghost" disabled icon="lock-closed" title="Impresora ocupada por {{ $printerLock['user_name'] }}">En uso</flux:button>
                                        @else
                                            <flux:button size="sm" variant="ghost" icon="printer" wire:click="printBatchDirect({{ $b->id }})" title="Imprimir directo en Zebra">Imprimir</flux:button>
                                        @endif
                                        <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('box-labels.zpl', $b)">ZPL</flux:button>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif

        {{-- Modal de control y decisión de consecutivo de cajas (Flux) --}}
        <flux:modal name="confirm-box-consecutive" class="w-full max-w-lg">
            <div class="flex flex-col gap-4">
                <div class="flex items-start gap-3">
                    <div class="rounded-full bg-amber-100 p-2 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400">
                        <flux:icon icon="exclamation-triangle" class="size-6" />
                    </div>
                    <div>
                        <flux:heading size="lg">Control de consecutivo de cajas</flux:heading>
                        <flux:subheading>
                            Esta orden ya tiene <strong class="text-zinc-900 dark:text-white">{{ $lastSeq }}</strong> etiquetas generadas para el lote <strong class="font-mono text-zinc-900 dark:text-white">{{ $this->lot() }}</strong>.
                        </flux:subheading>
                    </div>
                </div>

                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-sm space-y-3 dark:border-zinc-700 dark:bg-zinc-800/60">
                    <div class="text-zinc-700 dark:text-zinc-200">
                        Vas a generar <strong class="text-zinc-900 dark:text-white">{{ $labels }}</strong> etiquetas nuevas. Elige cómo deseas continuar la numeración:
                    </div>

                    {{-- Opción 1: Continuar consecutivo --}}
                    <div class="rounded-lg border border-emerald-300 bg-white p-3.5 shadow-sm dark:border-emerald-700/60 dark:bg-zinc-900 flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-emerald-700 dark:text-emerald-400 flex items-center gap-1.5">
                                <flux:icon icon="arrow-right-circle" class="size-4" />
                                Continuar consecutivo (Faltantes)
                            </span>
                            <flux:badge color="green" size="sm">Recomendado</flux:badge>
                        </div>
                        <p class="text-xs text-zinc-600 dark:text-zinc-400 leading-relaxed">
                            Continúa la secuencia donde quedó. Generará las cajas desde la <strong>#{{ $lastSeq + 1 }}</strong> hasta la <strong>#{{ $lastSeq + (int) $labels }}</strong>
                            @if ($preview !== null)
                                (ej. <code class="font-mono text-emerald-600 dark:text-emerald-400">{{ BoxLot::boxCode((string) $preview->lotCode, $lastSeq + 1) }}</code>).
                            @endif
                        </p>
                        <div class="pt-1">
                            <flux:button variant="primary" class="w-full justify-center" icon="arrow-right-circle" wire:click="executeGenerate(false)" wire:loading.attr="disabled" wire:target="executeGenerate">
                                Continuar desde Caja #{{ $lastSeq + 1 }} (hasta #{{ $lastSeq + (int) $labels }})
                            </flux:button>
                        </div>
                    </div>

                    {{-- Opción 2: Reiniciar consecutivo --}}
                    <div class="rounded-lg border border-amber-300 bg-white p-3.5 shadow-sm dark:border-amber-700/60 dark:bg-zinc-900 flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-amber-700 dark:text-amber-400 flex items-center gap-1.5">
                                <flux:icon icon="arrow-path" class="size-4" />
                                Reiniciar consecutivo
                            </span>
                            <flux:badge color="amber" size="sm">Nueva corrida</flux:badge>
                        </div>
                        <p class="text-xs text-zinc-600 dark:text-zinc-400 leading-relaxed">
                            Empieza una nueva numeración desde la <strong>Caja - 0001</strong> hasta la <strong>#{{ (int) $labels }}</strong>
                            @if ($preview !== null)
                                (ej. <code class="font-mono text-amber-600 dark:text-amber-400">{{ BoxLot::boxCode((string) $preview->lotCode, 1) }}</code>).
                            @endif
                            Se registrará como una corrida nueva en el historial para no mezclar las cajas.
                        </p>
                        <div class="pt-1">
                            <flux:button variant="filled" class="w-full justify-center text-amber-700 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-950/40" icon="arrow-path" wire:click="executeGenerate(true)" wire:loading.attr="disabled" wire:target="executeGenerate">
                                Empezar desde Caja - 0001 (hasta #{{ (int) $labels }})
                            </flux:button>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancelar</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        </flux:modal>
    @endif

    {{-- Modal para regular la velocidad de la impresora Zebra (Flux) --}}
    <flux:modal name="zebra-speed-modal" class="w-full max-w-md">
        <div class="flex flex-col gap-4">
            <div class="flex items-start gap-3">
                <div class="rounded-full bg-indigo-100 p-2 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400">
                    <flux:icon icon="bolt" class="size-6" />
                </div>
                <div>
                    <flux:heading size="lg">Velocidad de impresión Zebra</flux:heading>
                    <flux:subheading>
                        Regula la velocidad en pulgadas por segundo (IPS) para la Zebra ZT411 ({{ $printerIp }}). El cambio se guarda inmediatamente.
                    </flux:subheading>
                </div>
            </div>

            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-sm space-y-3 dark:border-zinc-700 dark:bg-zinc-800/60">
                <div class="flex items-center justify-between">
                    <span class="text-zinc-700 dark:text-zinc-200 font-medium">Velocidad activa:</span>
                    <flux:badge color="indigo" size="lg" class="font-bold">{{ $printSpeed }} ips (~{{ round($printSpeed * 25.4) }} mm/s)</flux:badge>
                </div>

                <div class="text-xs text-zinc-500">Selecciona una velocidad rápida predefinida:</div>

                <div class="grid grid-cols-2 gap-2">
                    @foreach ([
                        3 => ['label' => '3 IPS', 'desc' => 'Muy lenta (~76 mm/s)'],
                        4 => ['label' => '4 IPS', 'desc' => 'Moderada (~102 mm/s)'],
                        6 => ['label' => '6 IPS', 'desc' => 'Recomendada (~152 mm/s)'],
                        8 => ['label' => '8 IPS', 'desc' => 'Rápida (~203 mm/s)'],
                        10 => ['label' => '10 IPS', 'desc' => 'Muy rápida (~254 mm/s)'],
                        12 => ['label' => '12 IPS', 'desc' => 'Máxima (~305 mm/s)'],
                    ] as $spd => $info)
                        <button type="button" wire:click="setZebraSpeed({{ $spd }})"
                            class="flex flex-col items-start p-2.5 rounded-lg border text-left transition-all {{ $printSpeed === $spd ? 'border-indigo-600 bg-indigo-50 dark:border-indigo-400 dark:bg-indigo-950/40 text-indigo-900 dark:text-indigo-200 ring-2 ring-indigo-500/30' : 'border-zinc-200 bg-white hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800 text-zinc-800 dark:text-zinc-200' }}">
                            <div class="flex items-center justify-between w-full">
                                <span class="font-bold text-sm">{{ $info['label'] }}</span>
                                @if ($printSpeed === $spd)
                                    <flux:icon icon="check" class="size-4 text-indigo-600 dark:text-indigo-400" />
                                @endif
                            </div>
                            <span class="text-[11px] text-zinc-500 dark:text-zinc-400 leading-tight mt-0.5">{{ $info['desc'] }}</span>
                        </button>
                    @endforeach
                </div>

                <div class="pt-2 border-t border-zinc-200 dark:border-zinc-700">
                    <div class="flex items-end gap-2">
                        <div class="flex-1">
                            <flux:input wire:model="printSpeed" type="number" min="2" max="14" label="O ingresa un valor exacto (2 a 14 IPS)" />
                        </div>
                        <flux:button type="button" variant="primary" wire:click="setZebraSpeed($wire.printSpeed)">Guardar</flux:button>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cerrar</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>

    {{-- Modal interactivo de monitoreo de impresión por sub-lotes y detección física (Flux) --}}
    <flux:modal name="zebra-print-monitor-modal" class="w-full max-w-lg">
        @php($job = $this->activeJob)
        @if ($job)
            <div class="flex flex-col gap-4" @if ($isPrinting && $job && $job->status === 'printing') wire:poll.200ms="processActivePrintJob" @endif>
                <div class="flex items-start justify-between">
                    <div>
                        <flux:heading size="lg">Monitor de Impresión Zebra ZT411</flux:heading>
                        <flux:subheading>Lote: <span class="font-mono font-medium text-zinc-900 dark:text-white">{{ $job->lot_code }}</span> · Orden #{{ $job->production_order_id }}</flux:subheading>
                    </div>
                    <div>
                        @if ($job->status === 'completed')
                            <flux:badge color="emerald" size="lg" icon="check-circle">Completado</flux:badge>
                        @elseif ($job->status === 'paused_error')
                            <flux:badge color="amber" size="lg" icon="pause-circle">Pausada ({{ $job->printed_labels }}/{{ $job->total_labels }})</flux:badge>
                        @elseif ($job->status === 'printing')
                            <flux:badge color="blue" size="lg" icon="arrow-path" class="animate-pulse">Imprimiendo ({{ $job->printed_labels }}/{{ $job->total_labels }})...</flux:badge>
                        @else
                            <flux:badge color="zinc" size="lg">{{ ucfirst($job->status) }}</flux:badge>
                        @endif
                    </div>
                </div>

                {{-- Barra de Progreso Físico --}}
                <div class="space-y-1.5">
                    <div class="flex justify-between text-xs font-medium">
                        <span>Avance físico: <strong>{{ $job->printed_labels }}</strong> de <strong>{{ $job->total_labels }}</strong> etiquetas</span>
                        <span class="font-bold">{{ $job->progressPercent() }}%</span>
                    </div>
                    <div class="w-full bg-zinc-200 dark:bg-zinc-800 rounded-full h-3 overflow-hidden">
                        <div class="h-3 rounded-full transition-all duration-300 {{ $job->status === 'paused_error' ? 'bg-amber-500' : ($job->status === 'completed' ? 'bg-emerald-500' : 'bg-blue-600') }}"
                             style="width: {{ $job->progressPercent() }}%"></div>
                    </div>
                </div>

                {{-- Detalle de Consecutivo y Sub-lotes --}}
                <div class="grid grid-cols-2 gap-2 text-xs bg-zinc-50 dark:bg-zinc-900/60 p-3 rounded-lg border border-zinc-200 dark:border-zinc-800">
                    <div>
                        <span class="text-zinc-500">Última caja impresa:</span>
                        <div class="font-mono font-semibold text-zinc-900 dark:text-white text-sm">
                            {{ $job->last_printed_sequence > 0 ? '#'.str_pad((string)$job->last_printed_sequence, 4, '0', STR_PAD_LEFT) : 'Ninguna aún' }}
                        </div>
                    </div>
                    <div>
                        <span class="text-zinc-500">Siguiente caja a imprimir:</span>
                        <div class="font-mono font-semibold text-blue-600 dark:text-blue-400 text-sm">
                            {{ $job->pending_labels > 0 ? '#'.str_pad((string)($resumeSequence ?? $job->next_sequence), 4, '0', STR_PAD_LEFT) : '—' }}
                        </div>
                    </div>
                </div>

                {{-- Alerta de error físico si ocurrió --}}
                @if ($job->status === 'paused_error')
                    <div class="p-3.5 rounded-lg bg-amber-50 border border-amber-200 text-amber-900 dark:bg-amber-950/40 dark:border-amber-900 dark:text-amber-200 text-sm space-y-1.5">
                        <div class="font-semibold flex items-center gap-1.5">
                            <flux:icon icon="exclamation-triangle" class="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                            <span>Impresión detenida: {{ $job->error_reason ?? 'Error físico o pausa en la impresora' }}</span>
                        </div>
                        <p class="text-xs text-amber-800 dark:text-amber-300 leading-relaxed">
                            La Zebra se detuvo de forma segura sin perder la cuenta física. Se imprimieron <strong>{{ $job->printed_labels }}</strong> de <strong>{{ $job->total_labels }}</strong> etiquetas (quedan <strong>{{ $job->pending_labels }}</strong> pendientes). Despause la máquina en su panel frontal o corrija el rollo/cabezal y presione <strong>Reanudar</strong> para continuar exactamente en la <strong>Caja #{{ str_pad((string)($resumeSequence ?? $job->next_sequence), 4, '0', STR_PAD_LEFT) }}</strong>.
                        </p>
                    </div>
                @endif

                @if ($job->status === 'completed')
                    <div class="p-3.5 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 dark:bg-emerald-950/40 dark:border-emerald-900 dark:text-emerald-200 text-sm">
                        <div class="font-semibold flex items-center gap-1.5">
                            <flux:icon icon="check-circle" class="size-4 text-emerald-600 dark:text-emerald-400" />
                            <span>¡Todas las {{ $job->total_labels }} etiquetas fueron impresas exitosamente!</span>
                        </div>
                    </div>
                @endif

                <div class="flex items-center justify-between pt-2">
                    @if ($job->status === 'paused_error')
                        <div class="flex flex-col gap-2.5 w-full">
                            <div class="flex items-end gap-2">
                                <div class="w-36">
                                    <flux:input wire:model.live="resumeSequence" type="number" min="{{ $job->start_sequence }}" max="{{ $job->end_sequence }}" label="Desde Caja #" />
                                </div>
                                <div class="flex-1">
                                    <flux:button variant="danger" icon="play" class="w-full" wire:click="resumePrintJob({{ $job->id }})">
                                        Reanudar desde Caja #{{ str_pad((string)($resumeSequence ?? $job->next_sequence), 4, '0', STR_PAD_LEFT) }} ({{ max(1, $job->end_sequence - (int)($resumeSequence ?? $job->next_sequence) + 1) }} pendientes)
                                    </flux:button>
                                </div>
                            </div>
                            <div class="flex justify-end">
                                <flux:button variant="ghost" size="sm" wire:click="cancelPrintJob({{ $job->id }})">Cancelar etiquetas restantes</flux:button>
                            </div>
                        </div>
                    @elseif ($job->status === 'printing')
                        <div class="flex items-center gap-2 text-xs text-zinc-500">
                            <flux:icon icon="arrow-path" class="size-4 animate-spin text-blue-500" />
                            <span>Imprimiendo de forma granular (1 a 1)...</span>
                        </div>
                        <flux:button variant="ghost" size="sm" wire:click="pausePrintJob({{ $job->id }})">Pausar</flux:button>
                    @else
                        <flux:spacer />
                        <flux:modal.close>
                            <flux:button variant="primary">Aceptar</flux:button>
                        </flux:modal.close>
                    @endif
                </div>
            </div>
        @else
            <div class="p-4 text-center text-zinc-500">
                No hay ningún trabajo de impresión activo seleccionado.
            </div>
        @endif
    </flux:modal>
</section>
