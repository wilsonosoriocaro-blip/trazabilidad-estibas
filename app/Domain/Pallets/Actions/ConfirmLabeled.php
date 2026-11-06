<?php

namespace App\Domain\Pallets\Actions;

use App\Domain\BoxLabels\BoxQr;
use App\Domain\Pallets\Enums\LabelConfirmation;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Pallets\Enums\ScanEventStatus;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Domain\Shared\TraceResult;
use App\Enums\Permission;
use App\Models\BoxLabel;
use App\Models\Device;
use App\Models\Pallet;
use App\Models\PalletScanEvent;
use App\Models\User;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The printed label is on the pallet. Refused until DP-03 picks a mode.
 * With "scan" the operator, on the pallet chosen on screen (whose label was
 * just printed), reads the QR of one box of the physical pallet where the
 * label was stuck: that box must have been counted in this pallet. Reading
 * the label proved only that it was printed, not where it went.
 */
class ConfirmLabeled
{
    public function __construct(private readonly TraceLogger $trace) {}

    public function handle(User $user, int $palletId, ?string $boxQr, TraceContext $ctx): Pallet
    {
        Gate::forUser($user)->authorize(Permission::PalletsLabel->value);

        $mode = LabelConfirmation::configured() ?? throw PalletRuleViolation::labelConfirmationUndecided();

        if ($mode === LabelConfirmation::Print) {
            throw PalletRuleViolation::labelConfirmationIsAutomatic();
        }

        $box = $mode === LabelConfirmation::Scan ? $this->boxOnThePallet($palletId, $boxQr, $ctx) : null;

        return DB::transaction(function () use ($user, $palletId, $mode, $ctx, $box): Pallet {
            $pallet = Pallet::query()->lockForUpdate()->findOrFail($palletId);

            match ($pallet->physical_status) {
                PhysicalStatus::Labeled => throw PalletRuleViolation::alreadyLabeled($pallet->pallet_code),
                PhysicalStatus::Stacking, PhysicalStatus::Finished => throw PalletRuleViolation::notPalletized($pallet->pallet_code),
                PhysicalStatus::Palletized => null,
            };

            if ($pallet->label_print_count === 0) {
                throw PalletRuleViolation::labelNotPrinted($pallet->pallet_code);
            }

            $pallet->forceFill(['physical_status' => PhysicalStatus::Labeled, 'labeled_at' => now(), 'labeled_by' => $user->id])->save();

            $this->trace->record($ctx, TraceEventType::PalletLabeled, $pallet,
                from: ['physical_status' => PhysicalStatus::Palletized->value],
                to: ['physical_status' => PhysicalStatus::Labeled->value, 'confirmation' => $mode->value],
                context: $box === null ? null : ['box_code' => $box],
            );

            return $pallet;
        });
    }

    /**
     * The box read on the pallet where the label was stuck must be one counted
     * in this pallet. A box of another pallet means the label is on the wrong
     * one: that is refused and left on record (outside any transaction, so it
     * stays). Returns the box code.
     */
    private function boxOnThePallet(int $palletId, ?string $boxQr, TraceContext $ctx): string
    {
        $pallet = Pallet::query()->findOrFail($palletId);

        if ($boxQr === null || trim($boxQr) === '') {
            throw PalletRuleViolation::labelBoxMissing();
        }

        $token = BoxQr::tokenFrom($boxQr) ?? throw PalletRuleViolation::labelBoxNotABox();
        $code = BoxLabel::query()->where('qr_token', $token)->value('code') ?? throw PalletRuleViolation::labelBoxNotABox();

        // Every box read, by hand or by a fixed reader, is counted under the shared box-read device.
        // Soporta tanto eventos con external_event_id como token (actual) o como código legible (anterior)
        $boxPalletId = PalletScanEvent::query()
            ->where('device_id', Device::query()->where('code', RegisterManualBoxScan::DEVICE_CODE)->value('id'))
            ->where(function ($q) use ($token, $code) {
                $q->where('external_event_id', $token)
                    ->orWhere('external_event_id', 'LIKE', $token.'-%')
                    ->orWhere('external_event_id', $code);
            })
            ->where('processing_status', ScanEventStatus::Accepted)
            ->value('pallet_id');

        if ($boxPalletId === null) {
            throw PalletRuleViolation::labelBoxNotRead((string) $code);
        }

        if ((int) $boxPalletId !== $palletId) {
            $other = (string) Pallet::query()->whereKey($boxPalletId)->value('pallet_code');
            $this->trace->record($ctx, TraceEventType::PalletLabelMismatch, $pallet, result: TraceResult::Rejected,
                reason: "Al confirmar la etiqueta de {$pallet->pallet_code} se leyó la caja {$code}, que es de {$other}.",
                context: ['box_code' => $code, 'box_pallet' => $other]);

            throw PalletRuleViolation::labelOnWrongPallet($pallet->pallet_code, $other);
        }

        return (string) $code;
    }
}
