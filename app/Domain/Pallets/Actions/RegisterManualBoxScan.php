<?php

namespace App\Domain\Pallets\Actions;

use App\Domain\BoxLabels\BoxQr;
use App\Domain\Integration\Enums\MasterStatus;
use App\Domain\Pallets\Data\ManualBoxScan;
use App\Domain\Pallets\Data\ScanEventData;
use App\Domain\Pallets\Data\ScanOutcome;
use App\Domain\Pallets\Enums\ScanEventStatus;
use App\Domain\Pallets\Enums\ScanRejection;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Pallets\PalletQr;
use App\Domain\Pallets\ScanMasterResolver;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use App\Models\BoxLabel;
use App\Models\BoxLabelPrint;
use App\Models\Device;
use App\Models\Item;
use App\Models\PalletScanEvent;
use App\Models\PalletStandard;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\TraceLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * One box label read by hand at the production station.
 *
 * The box QR carries only the box's token (BoxQr), like the pallet QR. The
 * token must be one this application printed; the box it names gives the
 * order, product, lot and expiration. The order must still be released
 * and its product the one printed on the box. Then the box is counted with
 * the same rules as a sensor read (ProcessScanEvent): it goes to the open
 * pallet of that order + product + lot, which closes when it reaches the
 * standard.
 *
 * The box code is the read's event id: the database accepts it once, so
 * reading the same box again, a retried request or a reader firing twice
 * never counts twice.
 *
 * A fixed reader on the line (fromReader) goes through the same rules and
 * records its reads under the same device, so a box read by the line and
 * again by hand, in either order, is still counted once. The read keeps
 * the reader that read it (pallet_scan_events.read_by_device_id).
 */
class RegisterManualBoxScan
{
    public const DEVICE_CODE = 'LECTOR-MANUAL';

    public function __construct(
        private readonly ProcessScanEvent $process,
        private readonly ScanMasterResolver $masters,
        private readonly TraceLogger $trace,
    ) {}

    public function handle(User $user, string $scanned, TraceContext $ctx): ManualBoxScan
    {
        Gate::forUser($user)->authorize(Permission::PalletsOperate->value);

        return $this->count($scanned, $ctx);
    }

    /**
     * A box label read by a fixed reader on the line (a LECTOR device).
     */
    public function fromReader(Device $reader, string $scanned, TraceContext $ctx): ManualBoxScan
    {
        if (! $reader->active || $reader->kind !== 'LECTOR' || $reader->code === self::DEVICE_CODE) {
            throw new \InvalidArgumentException("El dispositivo {$reader->code} no es un lector de cajas activo.");
        }

        return $this->count($scanned, $ctx);
    }

    private function count(string $scanned, TraceContext $ctx): ManualBoxScan
    {
        // The QR only names the box; everything else comes from the label printed for it.
        $token = BoxQr::tokenFrom($scanned)
            ?? throw (PalletQr::tokenFrom($scanned) !== null ? PalletRuleViolation::palletQrInBoxField() : PalletRuleViolation::notABoxLabel());
        $box = BoxLabel::query()->with('batch')->where('qr_token', $token)->first() ?? throw PalletRuleViolation::unknownBoxQr();
        $batch = $box->batch;
        $boxCode = $box->code;
        $lot = $box->lot_code;

        $order = ProductionOrder::query()->findOrFail($box->production_order_id);
        if ($order->sap_status !== ProductionOrderStatus::Released->value || ! $order->is_open) {
            throw PalletRuleViolation::orderNotReleased($order->ref, ProductionOrderStatus::tryFrom((string) $order->sap_status)?->label() ?? (string) $order->sap_status);
        }

        // The order's product in SAP must still be the one printed on the box.
        $item = Item::query()->findOrFail($order->item_id);
        if ($item->id !== $batch->item_id) {
            throw PalletRuleViolation::boxItemMismatch($order->ref, $item->code, $batch->item_code);
        }

        // Already read: se valida por el TOKEN único de la etiqueta.
        // Si el lote físico fue impreso más de una vez (ejemplo: 2 copias físicas de la etiqueta colocadas
        // en 2 cajas reales de producto terminado), se permite registrar cada copia física individualmente
        // ($token, $token-c2, etc.) hasta el número de copias impresas.
        // Un filtro antirrebote (debounce < 4s) previene que la misma caja sea leída múltiples veces
        // en ráfaga por el sensor fijo o manual.
        $previousScans = PalletScanEvent::query()->with('pallet:id,pallet_code')
            ->where('device_id', $this->device()->id)
            ->where(function ($q) use ($token) {
                $q->where('external_event_id', $token)
                    ->orWhere('external_event_id', 'LIKE', $token.'-c%');
            })
            ->orderBy('id', 'asc')
            ->get();

        $acceptedScans = $previousScans->where('processing_status', ScanEventStatus::Accepted);
        $scannedCount = $acceptedScans->count();

        $totalPrintedCopies = max(1, BoxLabelPrint::query()
            ->where('box_label_batch_id', $box->box_label_batch_id)
            ->count());

        // 1. Antirrebote (debounce): si la lectura anterior ocurrió hace menos de 4 segundos,
        // es el mismo bulto/caja física pasando frente al sensor óptico
        $latestScan = $previousScans->last();
        if ($latestScan !== null) {
            $secondsSinceLast = $latestScan->created_at?->diffInSeconds(now());
            if ($secondsSinceLast !== null && $secondsSinceLast < 4) {
                $lastPallet = $latestScan->pallet;
                throw $lastPallet !== null
                    ? PalletRuleViolation::boxAlreadyRead($boxCode, $lastPallet->pallet_code)
                    : PalletRuleViolation::boxHasRejectedRead($boxCode);
            }
        }

        // 2. Control de copias impresas vs leídas:
        if ($scannedCount < $totalPrintedCopies) {
            $effectiveEventId = $scannedCount === 0 ? $token : "{$token}-c".($scannedCount + 1);
        } else {
            $lastPallet = $acceptedScans->last()?->pallet;
            throw $lastPallet !== null
                ? PalletRuleViolation::boxAlreadyRead($boxCode, $lastPallet->pallet_code)
                : PalletRuleViolation::boxHasRejectedRead($boxCode);
        }

        $testStandard = $this->ensureStandard($item, $ctx);

        // Checked before counting so a refused box leaves no rejected read under its code.
        $rejection = $this->masters->resolve(new ScanEventData($effectiveEventId, $order->ref, $item->code, $lot, CarbonImmutable::now()), now());
        if ($rejection instanceof ScanRejection) {
            throw PalletRuleViolation::boxRejected(mb_strtolower($rejection->label()));
        }

        $result = $this->process->handleManual($this->device(), new ScanEventData($effectiveEventId, $order->ref, $item->code, $lot, CarbonImmutable::now()), $ctx);

        return match ($result->outcome) {
            ScanOutcome::Accepted => new ManualBoxScan($order->ref, $item->code, $item->description, $lot, CarbonImmutable::parse($batch->expiration_date->toDateString()), $boxCode, $result, $testStandard),
            ScanOutcome::Duplicate => throw ($result->palletCode !== null && $result->rejection === null
                ? PalletRuleViolation::boxAlreadyRead($boxCode, $result->palletCode)
                : PalletRuleViolation::boxHasRejectedRead($boxCode)),
            ScanOutcome::Rejected => throw PalletRuleViolation::boxRejected(mb_strtolower($result->rejection?->label() ?? 'regla no cumplida')),
        };
    }

    /**
     * While the real pallet standard is not loaded (it will come from SAP), a
     * product without one gets the test standard (TRACE_TEST_PALLET_BOXES boxes
     * per pallet), marked PRUEBA so it is visible and easy to replace.
     * Returns whether the current standard is that test one.
     */
    private function ensureStandard(Item $item, TraceContext $ctx): bool
    {
        $current = PalletStandard::query()->where('item_id', $item->id)->where('status', MasterStatus::Current)->first();

        if ($current !== null) {
            return $current->sap_source_key === PalletStandard::TEST_SOURCE;
        }

        $boxes = config()->integer('traceability.test_pallet_boxes');

        if ($boxes <= 0) {
            return false;
        }

        DB::transaction(function () use ($item, $boxes, $ctx): void {
            Item::query()->whereKey($item->id)->lockForUpdate()->first();

            if (PalletStandard::query()->where('item_id', $item->id)->where('status', MasterStatus::Current)->exists()) {
                return;
            }

            $standard = new PalletStandard;
            $standard->forceFill([
                'item_id' => $item->id,
                'version' => (int) PalletStandard::query()->where('item_id', $item->id)->max('version') + 1,
                'boxes_per_pallet' => $boxes,
                'sap_source_key' => PalletStandard::TEST_SOURCE,
                'status' => MasterStatus::Current,
                'synced_at' => now(),
            ])->save();

            $this->trace->record($ctx, TraceEventType::MastersSynced, $item,
                to: ['boxes_per_pallet' => $boxes, 'version' => $standard->version],
                context: ['source' => PalletStandard::TEST_SOURCE, 'note' => 'Estándar de estiba de prueba mientras se carga el real en SAP.'],
            );
        });

        return true;
    }

    private function device(): Device
    {
        $existing = Device::query()->where('code', self::DEVICE_CODE)->first();
        if ($existing !== null) {
            return $existing;
        }

        $device = new Device;
        $device->forceFill([
            'code' => self::DEVICE_CODE,
            'name' => 'Lectura manual en la estación de producción',
            'kind' => 'LECTOR',
            // No token: nothing authenticates as this device through the API.
            'token_hash' => hash('sha256', Str::random(64)),
            'active' => true,
        ]);

        try {
            // Its own savepoint: two first reads at once both try to create it, and the loser just reads it.
            DB::transaction(fn () => $device->save());
        } catch (UniqueConstraintViolationException) {
            return Device::query()->where('code', self::DEVICE_CODE)->sole();
        }

        return $device;
    }
}
