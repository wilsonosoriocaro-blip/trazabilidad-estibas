<?php

namespace App\Domain\BoxLabels\Actions;

use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Models\BoxLabelBatch;
use App\Models\BoxLabelPrint;
use App\Models\User;
use App\Services\TraceLogger;
use App\Services\ZebraPrintService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sends a batch of box labels to the Zebra and, when the printer took it,
 * records the print: from then on the lot of that batch is on paper and
 * cannot be changed (GenerateBoxLabels).
 */
class PrintBoxLabels
{
    public function __construct(
        private readonly ZebraPrintService $zebra,
        private readonly TraceLogger $trace,
    ) {}

    /**
     * @return array<string, mixed> What ZebraPrintService::printToSocket answered.
     */
    public function handle(User $user, int $batchId, string $ip, int $port, TraceContext $ctx): array
    {
        Gate::forUser($user)->authorize(Permission::BoxLabelsPrint->value);

        $batch = BoxLabelBatch::query()->findOrFail($batchId);
        $result = $this->zebra->printToSocket($ip, $port, $batch->zpl);

        if (($result['success'] ?? false) === true) {
            DB::transaction(function () use ($user, $batch, $ip, $port, $ctx): void {
                $print = new BoxLabelPrint;
                $print->forceFill([
                    'box_label_batch_id' => $batch->id,
                    'production_order_id' => $batch->production_order_id,
                    'lot_code' => $batch->lot_code,
                    'labels' => $batch->labels,
                    'printer' => mb_substr(trim($ip).':'.$port, 0, 100),
                    'printed_by' => $user->id,
                    'printed_at' => now(),
                ])->save();

                $this->trace->record($ctx, TraceEventType::BoxLabelsPrinted, $batch->productionOrder()->firstOrFail(), context: [
                    'box_label_batch_id' => $batch->id,
                    'labels' => $batch->labels,
                    'lot_code' => $batch->lot_code,
                    'printer' => $print->printer,
                ]);
            });
        }

        return $result;
    }
}
