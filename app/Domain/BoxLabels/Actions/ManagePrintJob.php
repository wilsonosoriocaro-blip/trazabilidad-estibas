<?php

namespace App\Domain\BoxLabels\Actions;

use App\Domain\BoxLabels\Services\ZebraSessionLock;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Enums\Permission;
use App\Models\BoxLabelBatch;
use App\Models\BoxLabelPrint;
use App\Models\BoxLabelPrintJob;
use App\Models\User;
use App\Services\TraceLogger;
use App\Services\ZebraPrintService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ManagePrintJob
{
    public function __construct(
        private readonly ZebraPrintService $zebra,
        private readonly TraceLogger $trace,
        private readonly ZebraSessionLock $sessionLock,
    ) {}

    /**
     * Obtiene o crea un trabajo de impresión para el lote especificado.
     */
    public function createOrGetJob(User $user, int $batchId, string $ip, int $port, ?int $chunkSize = null): BoxLabelPrintJob
    {
        Gate::forUser($user)->authorize(Permission::BoxLabelsPrint->value);

        $batch = BoxLabelBatch::query()->with('boxLabels')->findOrFail($batchId);

        // Granularidad adaptativa: lotes pequeños (<=50) se imprimen 1 a 1 para sincronización exacta.
        // Lotes medianos y grandes se transmiten en micro-lotes de 5 para no desbordar el búfer de RAM de la Zebra.
        $defaultChunk = match (true) {
            $batch->labels <= 50 => 1,
            default => 5,
        };
        $effectiveChunk = $chunkSize !== null ? max(1, min(10, $chunkSize)) : $defaultChunk;

        // Si ya hay un trabajo activo o en pausa para este lote, retomarlo
        $existingJob = BoxLabelPrintJob::query()
            ->where('box_label_batch_id', $batchId)
            ->whereIn('status', ['pending', 'printing', 'paused_error'])
            ->latest('id')
            ->first();

        if ($existingJob) {
            $existingJob->printer_ip = trim($ip);
            $existingJob->printer_port = $port;
            $existingJob->chunk_size = $effectiveChunk;
            $existingJob->save();

            return $existingJob;
        }

        $minSeq = (int) ($batch->boxLabels()->min('sequence') ?? 1);
        $maxSeq = (int) ($batch->boxLabels()->max('sequence') ?? ($minSeq + $batch->labels - 1));

        $startOdo = $this->zebra->queryOdometer(trim($ip), $port, 1.0, 1);

        $job = new BoxLabelPrintJob;
        $job->forceFill([
            'box_label_batch_id' => $batch->id,
            'production_order_id' => $batch->production_order_id,
            'lot_code' => $batch->lot_code,
            'total_labels' => $batch->labels,
            'printed_labels' => 0,
            'pending_labels' => $batch->labels,
            'start_sequence' => $minSeq,
            'end_sequence' => $maxSeq,
            'last_printed_sequence' => 0,
            'next_sequence' => $minSeq,
            'status' => 'printing',
            'error_reason' => null,
            'printer_ip' => trim($ip),
            'printer_port' => $port,
            'chunk_size' => $effectiveChunk,
            'start_odometer' => $startOdo,
            'last_odometer' => $startOdo,
            'created_by' => $user->id,
            'started_at' => now(),
        ]);
        $job->save();

        return $job;
    }

    /**
     * Procesa el siguiente sub-lote (chunk) del trabajo de impresión.
     *
     * @return array<string, mixed>
     */
    public function processChunk(BoxLabelPrintJob $job, TraceContext $ctx): array
    {
        if ($job->status === 'completed' || $job->pending_labels <= 0) {
            return [
                'success' => true,
                'status' => 'completed',
                'job' => $job->fresh(),
                'message' => 'El trabajo de impresión ya ha concluido con todas las etiquetas impresas.',
            ];
        }

        $allLabels = $this->zebra->splitZplIntoLabels($job->batch->zpl ?? '');
        $totalLabelsInZpl = count($allLabels);

        if ($totalLabelsInZpl === 0) {
            $job->status = 'paused_error';
            $job->error_reason = 'El lote no contiene formatos ZPL válidos.';
            $job->save();

            return [
                'success' => false,
                'status' => 'paused_error',
                'job' => $job->fresh(),
                'message' => 'No se encontraron etiquetas ZPL en el lote.',
            ];
        }

        $offset = $job->printed_labels;
        $chunkLabels = array_slice($allLabels, $offset, $job->chunk_size);
        $countInChunk = count($chunkLabels);

        if ($countInChunk === 0) {
            // Ya no quedan etiquetas por enviar
            $job->status = 'completed';
            $job->pending_labels = 0;
            $job->completed_at = now();
            $job->save();

            $this->sessionLock->release($job->printer_ip, $job->printer_port, $job->id);
            $this->recordPrintCompletion($job, $ctx);

            return [
                'success' => true,
                'status' => 'completed',
                'job' => $job->fresh(),
                'message' => 'Todas las etiquetas han sido impresas.',
            ];
        }

        // Bloqueo de sesión exclusivo de la impresora Zebra: asegura que ninguna otra pestaña o usuario interfiera
        $user = $job->user ?? User::query()->find($job->created_by);
        if ($user) {
            $sessId = session()->isStarted() ? session()->getId() : 'job-'.$job->id;
            $lockStatus = $this->sessionLock->acquireOrRenew($job->printer_ip, $job->printer_port, $job, $user, $sessId);
            if (! $lockStatus['acquired']) {
                $job->status = 'paused_error';
                $job->error_reason = $lockStatus['message'];
                $job->save();

                return [
                    'success' => false,
                    'status' => 'paused_error',
                    'job' => $job->fresh(),
                    'message' => $lockStatus['message'],
                    'error_reason' => $lockStatus['message'],
                ];
            }
        }

        $chunkZpl = implode("\n", $chunkLabels);
        $result = $this->zebra->printChunk(
            $job->printer_ip,
            $job->printer_port,
            $chunkZpl,
            $countInChunk
        );

        if ($result['success']) {
            $job->printed_labels += $countInChunk;
            $job->pending_labels = max(0, $job->total_labels - $job->printed_labels);
            $job->last_printed_sequence = $job->start_sequence + $job->printed_labels - 1;
            $job->next_sequence = $job->last_printed_sequence + 1;
            $job->error_reason = null;

            if ($job->pending_labels <= 0) {
                $job->status = 'completed';
                $job->completed_at = now();
                $job->save();

                $this->sessionLock->release($job->printer_ip, $job->printer_port, $job->id);
                $this->recordPrintCompletion($job, $ctx);
            } else {
                $job->status = 'printing';
                $job->save();
            }

            return [
                'success' => true,
                'status' => $job->status,
                'job' => $job->fresh(),
                'message' => "Sub-lote impreso ({$job->printed_labels} de {$job->total_labels}).",
            ];
        }

        // Si ocurrió error físico (falta papel, cabezal abierto, pausa en panel, etc.)
        $job->status = 'paused_error';
        $job->error_reason = $result['error_reason'] ?? $result['message'];
        $job->save();

        return [
            'success' => false,
            'status' => 'paused_error',
            'job' => $job->fresh(),
            'message' => $result['message'],
            'error_reason' => $job->error_reason,
        ];
    }

    /**
     * Pausa inmediatamente un trabajo activo desde la aplicación: detiene el motor físico
     * de la Zebra (~PP), purga el búfer no impreso (~JA) y sincroniza el conteo exacto con el odómetro.
     */
    public function pauseJob(BoxLabelPrintJob $job): void
    {
        // 1. Enviar pausa de motor y purga de búfer inmediatamente
        $this->zebra->pausePrinter($job->printer_ip, $job->printer_port);

        // 2. Si hay odómetro inicial, sincronizar con el odómetro real actual
        $currentOdo = $this->zebra->queryOdometer($job->printer_ip, $job->printer_port, 1.0, 1);
        if ($currentOdo !== null && $job->start_odometer !== null && $currentOdo >= $job->start_odometer) {
            $physicalDiff = $currentOdo - $job->start_odometer;
            $actualPrinted = min($job->total_labels, max($job->printed_labels, $physicalDiff));
            $job->printed_labels = $actualPrinted;
            $job->pending_labels = max(0, $job->total_labels - $actualPrinted);
            $job->last_printed_sequence = $actualPrinted > 0 ? ($job->start_sequence + $actualPrinted - 1) : 0;
            $job->next_sequence = $job->start_sequence + $actualPrinted;
            $job->last_odometer = $currentOdo;
        }

        $job->status = 'paused_error';
        $job->error_reason = 'Pausada desde la aplicación';
        $job->save();
    }

    /**
     * Reanuda un trabajo pausado por error permitiendo especificar exactamente desde qué caja continuar.
     */
    public function resumeJob(BoxLabelPrintJob $job, ?int $fromSequence = null): void
    {
        // Purgar cualquier formato viejo que haya quedado en el búfer de recepción de la Zebra (~JA)
        // y asegurar que el motor quede despausado (~PS) para evitar impresiones fuera de control.
        $this->zebra->cancelAllInReceiveBuffer($job->printer_ip, $job->printer_port);
        $this->zebra->unpausePrinter($job->printer_ip, $job->printer_port);

        if ($fromSequence !== null && $fromSequence >= $job->start_sequence && $fromSequence <= $job->end_sequence) {
            $printed = $fromSequence - $job->start_sequence;
            $job->printed_labels = $printed;
            $job->pending_labels = max(0, $job->total_labels - $printed);
            $job->last_printed_sequence = $printed > 0 ? ($fromSequence - 1) : 0;
            $job->next_sequence = $fromSequence;
        }

        // Si no tenía odómetro inicial registrado, capturarlo como referencia relativa
        if ($job->start_odometer === null) {
            $currentOdo = $this->zebra->queryOdometer($job->printer_ip, $job->printer_port, 1.0, 1);
            if ($currentOdo !== null) {
                $job->start_odometer = $currentOdo - $job->printed_labels;
                $job->last_odometer = $currentOdo;
            }
        }

        $job->status = 'printing';
        $job->error_reason = null;
        $job->save();
    }

    /**
     * Cancela las etiquetas pendientes de un trabajo.
     */
    public function cancelJob(BoxLabelPrintJob $job): void
    {
        $job->status = 'cancelled';
        $job->completed_at = now();
        $job->save();

        $this->sessionLock->release($job->printer_ip, $job->printer_port, $job->id);
    }

    /**
     * Registra la finalización en la tabla histórica de impresiones y auditoría.
     */
    private function recordPrintCompletion(BoxLabelPrintJob $job, TraceContext $ctx): void
    {
        DB::transaction(function () use ($job, $ctx): void {
            $alreadyRecorded = BoxLabelPrint::query()
                ->where('box_label_batch_id', $job->box_label_batch_id)
                ->where('printed_at', '>=', $job->started_at ?? now()->subHour())
                ->exists();

            if ($alreadyRecorded) {
                return;
            }

            $print = new BoxLabelPrint;
            $print->forceFill([
                'box_label_batch_id' => $job->box_label_batch_id,
                'production_order_id' => $job->production_order_id,
                'lot_code' => $job->lot_code,
                'labels' => $job->printed_labels,
                'printer' => mb_substr(trim($job->printer_ip).':'.$job->printer_port, 0, 100),
                'printed_by' => $job->created_by,
                'printed_at' => now(),
            ])->save();

            $order = $job->productionOrder;
            if ($order) {
                $this->trace->record($ctx, TraceEventType::BoxLabelsPrinted, $order, context: [
                    'box_label_batch_id' => $job->box_label_batch_id,
                    'labels' => $job->printed_labels,
                    'lot_code' => $job->lot_code,
                    'printer' => $print->printer,
                ]);
            }
        });
    }
}
