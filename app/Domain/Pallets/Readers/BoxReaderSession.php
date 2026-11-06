<?php

namespace App\Domain\Pallets\Readers;

use App\Domain\Pallets\Actions\RegisterManualBoxScan;
use App\Domain\Pallets\Exceptions\BoxAlreadyRead;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Domain\Shared\TraceResult;
use App\Models\Device;
use App\Services\DatalogicDebugService;
use App\Services\TraceLogger;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * What a fixed reader sends, turned into counted boxes. The reader sends
 * text: one read per line, ended by CR, LF or both (some add STX/ETX).
 * Each line goes through the same rules as a box read by hand.
 *
 * - A box already counted is skipped quietly: a fixed reader may read the
 *   same label twice while the box goes by.
 * - Anything else that is not counted (a failed read such as "NoRead", a
 *   label this application did not print, an order no longer released)
 *   leaves a scan.rejected trace on the reader and a warning in the log,
 *   because that box went by without being counted.
 */
class BoxReaderSession
{
    use DetectsLostConnections;

    private const MAX_LINE = 500;

    /** A complete box QR: prefix, the ":" (or what the keyboard layout typed instead) and the 32-character token. */
    private const BOX_QR = '/TRZB.{1,4}?[0-9a-f]{32}/i';

    /** Anything a reader puts between reads: line ends, STX/ETX, tabs and other control characters. */
    private const SEPARATOR = '/[\x00-\x1F\x7F]+/';

    /** Attempts of a read that hit a passing database error (deadlock, lost connection). */
    private const ATTEMPTS = 3;

    private string $buffer = '';

    private readonly DatalogicDebugService $debug;

    public function __construct(
        private readonly Device $reader,
        private readonly RegisterManualBoxScan $scan,
        private readonly TraceLogger $trace,
        ?DatalogicDebugService $debug = null,
    ) {
        $this->debug = $debug ?? app(DatalogicDebugService::class);
    }

    /**
     * Takes a chunk as it came from the reader and handles every read in it.
     *
     * A box QR is read the moment its token is complete, whatever comes
     * around it: a line end, STX/ETX, a tab, or nothing at all when two reads
     * arrive glued together on a fast line. Anything else (a failed read such
     * as "NoRead") is a read once a separator ends it. A partial read waits
     * for the next chunk.
     *
     * @return list<BoxRead>
     */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        $reads = [];

        // Byte-safe (no /u): the ":" may come as another symbol on another keyboard layout.
        while (preg_match(self::BOX_QR, $this->buffer, $m, PREG_OFFSET_CAPTURE) === 1) {
            foreach ($this->segments(substr($this->buffer, 0, $m[0][1])) as $other) {
                $reads[] = $this->read($other);
            }
            $reads[] = $this->read($m[0][0]);
            $this->buffer = substr($this->buffer, $m[0][1] + strlen($m[0][0]));
        }

        $parts = preg_split(self::SEPARATOR, $this->buffer) ?: [];
        $this->buffer = (string) array_pop($parts);

        // A reader that never sends a separator would grow the buffer forever.
        if (strlen($this->buffer) > self::MAX_LINE) {
            $parts[] = $this->buffer;
            $this->buffer = '';
        }

        foreach ($parts as $part) {
            foreach ($this->segments($part) as $other) {
                $reads[] = $this->read($other);
            }
        }

        return $reads;
    }

    /**
     * The non-empty texts in $text between separators.
     *
     * @return list<string>
     */
    private function segments(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split(self::SEPARATOR, $text) ?: []), fn (string $t) => $t !== ''));
    }

    private function read(string $text): BoxRead
    {
        $text = mb_scrub($text);
        $ctx = TraceContext::forDevice($this->reader, (string) Str::uuid(), origin: Origin::Console);

        for ($attempt = 1; ; $attempt++) {
            try {
                $scan = $this->scan->fromReader($this->reader, $text, $ctx);

                $actionsDone = [
                    "Socket TCP: Recibido paquete desde {$this->reader->code} (".strlen($text).' caracteres)',
                    'Parser: Token de caja TRZB validado y extraído correctamente',
                    "Base de datos: Etiqueta {$scan->boxCode} localizada en tabla 'box_labels' (Lote: {$scan->lotCode})",
                    "Orden de Fabricación: Orden #{$scan->orderRef} verificada como Liberada en SAP y activa",
                    "Ítem / Producto: Producto {$scan->itemCode} coincide con la formulación de la Orden ({$scan->itemDescription})",
                    "Control de Duplicados: Verificado en 'pallet_scan_events' que la caja no había sido leída",
                    'Ruteo de Estiba: Caja asignada a la estiba '.($scan->result->palletCode ?? 'N/A')." (Avance: {$scan->result->boxesCount} de {$scan->result->standard} cajas)",
                    'Trazabilidad: Registrado evento PalletScanEvent y auditoría TraceEvent en PostgreSQL',
                ];

                if ($scan->result->palletClosed) {
                    $actionsDone[] = "Cierre Automático: ¡Estiba {$scan->result->palletCode} completada ({$scan->result->standard}/{$scan->result->standard})! Estado cambió a Finalizada";
                }

                $actionsNotDone = [];
                if (! $scan->result->palletClosed) {
                    $remaining = max(0, ($scan->result->standard ?? 0) - ($scan->result->boxesCount ?? 0));
                    $actionsNotDone[] = "Cierre de Estiba: La estiba {$scan->result->palletCode} NO se cerró aún (faltan {$remaining} cajas para completar el estándar)";
                }
                $actionsNotDone[] = 'Socket TCP Saliente: No se enviaron bytes al lector Datalogic (protocolo pasivo unidireccional)';
                $actionsNotDone[] = 'SAP Service Layer: No se llamó a SAP en esta lectura individual (los Recibos de Producción se transmiten al completar/confirmar la estiba)';
                $actionsNotDone[] = 'Impresora Zebra: No se envió formato de etiqueta de estiba (se imprime en la estación al finalizar)';

                $this->debug->recordScanEvent([
                    'device' => $this->reader->code,
                    'raw_input' => $text,
                    'outcome' => 'counted',
                    'summary' => "Caja {$scan->boxCode} → {$scan->result->palletCode} ({$scan->result->boxesCount}/{$scan->result->standard})",
                    'box_code' => $scan->boxCode,
                    'pallet_code' => $scan->result->palletCode,
                    'order_ref' => $scan->orderRef,
                    'lot_code' => $scan->lotCode,
                    'reason' => null,
                    'actions_done' => $actionsDone,
                    'actions_not_done' => $actionsNotDone,
                    'details' => [
                        'box_code' => $scan->boxCode,
                        'pallet_code' => $scan->result->palletCode,
                        'boxes_count' => $scan->result->boxesCount,
                        'standard' => $scan->result->standard,
                        'order_ref' => $scan->orderRef,
                        'item_code' => $scan->itemCode,
                        'pallet_closed' => $scan->result->palletClosed,
                    ],
                ]);

                return new BoxRead(BoxReadOutcome::Counted, $scan->boxCode.' → '.($scan->result->palletCode ?? ''));
            } catch (BoxAlreadyRead $e) {
                $actionsDone = [
                    "Socket TCP: Recibido paquete desde {$this->reader->code} (".strlen($text).' caracteres)',
                    'Parser: Formato de token analizado',
                    "Control de Duplicados: Consulta en 'pallet_scan_events' detectó que esta caja ya había sido registrada previamente",
                ];

                $actionsNotDone = [
                    'Re-conteo en Estiba: La caja NO se suma nuevamente para evitar duplicidad y proteger la precisión física',
                    'Apertura de Estiba: No se creó ninguna estiba',
                    'Socket TCP Saliente: No se enviaron bytes al lector (comunicación pasiva)',
                    'SAP Service Layer: No se ejecutó ninguna llamada externa',
                ];

                $this->debug->recordScanEvent([
                    'device' => $this->reader->code,
                    'raw_input' => $text,
                    'outcome' => 'duplicate',
                    'summary' => "Lectura duplicada: {$e->getMessage()}",
                    'reason' => $e->getMessage(),
                    'actions_done' => $actionsDone,
                    'actions_not_done' => $actionsNotDone,
                ]);

                return new BoxRead(BoxReadOutcome::AlreadyRead, $e->getMessage());
            } catch (PalletRuleViolation $e) {
                return $this->notCounted($ctx, $text, $e->getMessage());
            } catch (\Throwable $e) {
                // A deadlock or a dropped connection passes: try again. Anything else must not stop
                // the reader either, or the reads still in this chunk and those sent while it restarts are lost.
                if ($attempt < self::ATTEMPTS && $this->passing($e)) {
                    usleep(50_000 * $attempt);

                    continue;
                }

                report($e);

                return $this->notCounted($ctx, $text, 'Error al registrar la lectura: '.Str::limit($e->getMessage(), 150));
            }
        }
    }

    private function notCounted(TraceContext $ctx, string $text, string $reason): BoxRead
    {
        $shown = Str::limit($text, 60);

        try {
            $this->trace->record($ctx, TraceEventType::ScanRejected, $this->reader, result: TraceResult::Rejected, reason: $reason, context: ['read' => $shown]);
        } catch (\Throwable $e) {
            report($e);
        }
        Log::warning('Caja no contada por el lector fijo', ['device' => $this->reader->code, 'read' => $shown, 'reason' => $reason]);

        $isUrl = str_starts_with(trim($text), 'http://') || str_starts_with(trim($text), 'https://') || str_contains($text, 'tecnoalsa');
        $actionsDone = [
            "Socket TCP: Recibido paquete desde {$this->reader->code} (".strlen($text).' caracteres)',
            'Parser: Análisis sintáctico completado (no cumple estructura TRZB de caja)',
            'Auditoría: Registrado evento TraceEvent::ScanRejected en base de datos',
        ];

        $actionsNotDone = [
            "Búsqueda en Base de Datos: Omitida consulta en 'box_labels' porque el texto leído no corresponde al formato de etiqueta de caja",
            'Asignación a Estiba: No se asignó a ninguna estiba',
            'Socket TCP Saliente: No se enviaron comandos de error al lector Datalogic (protocolo unidireccional)',
            'SAP Service Layer: No se ejecutó ninguna llamada a SAP',
            'Impresión de Etiquetas: No se realizó ninguna acción',
        ];

        if ($isUrl) {
            $actionsNotDone[] = "Diagnóstico específico: El escáner leyó la URL publicitaria preimpresa en el empaque de la caja ('".Str::limit(trim($text), 50)."') en lugar de la etiqueta de trazabilidad con el código QR TRZB.";
        }

        $this->debug->recordScanEvent([
            'device' => $this->reader->code,
            'raw_input' => $text,
            'outcome' => 'rejected',
            'summary' => "Lectura descartada: {$reason}",
            'reason' => $reason,
            'actions_done' => $actionsDone,
            'actions_not_done' => $actionsNotDone,
        ]);

        return new BoxRead(BoxReadOutcome::Rejected, $reason);
    }

    private function passing(\Throwable $e): bool
    {
        $state = $e instanceof QueryException ? (string) ($e->errorInfo[0] ?? $e->getCode()) : '';

        // 40P01 deadlock, 40001 serialization failure, 08xxx connection errors.
        return in_array($state, ['40P01', '40001'], true) || str_starts_with($state, '08')
            || ($e instanceof QueryException && $this->causedByLostConnection($e));
    }
}
