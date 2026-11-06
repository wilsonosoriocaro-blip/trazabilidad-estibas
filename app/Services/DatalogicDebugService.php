<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Servicio de instrumentación y depuración en vivo para el lector Datalogic (LEC-L1)
 * en la estación de producción.
 *
 * Registra cada byte recibido por TCP, el análisis sintáctico de los tokens,
 * las validaciones de negocio en base de datos, y qué acciones internas y
 * externas se ejecutan o se omiten en cada intento de lectura.
 */
class DatalogicDebugService
{
    public const CACHE_KEY = 'datalogic:live_debug:events';

    public const STATS_KEY = 'datalogic:live_debug:stats';

    public const MAX_EVENTS = 40;

    /**
     * Registra un paquete TCP recibido directamente desde el socket de red.
     */
    public function recordTcpChunk(string $deviceCode, string $remote, string $chunk): void
    {
        $bytesLen = strlen($chunk);
        $printable = addcslashes($chunk, "\0..\37\177..\377");

        $stats = $this->getStats();
        $stats['total_tcp_chunks'] = ($stats['total_tcp_chunks'] ?? 0) + 1;
        $stats['total_tcp_bytes'] = ($stats['total_tcp_bytes'] ?? 0) + $bytesLen;
        $stats['last_tcp_at'] = CarbonImmutable::now()->timezone(config('app.timezone', 'America/Bogota'))->format('Y-m-d H:i:s.v');
        $stats['last_remote'] = $remote;
        $this->saveStats($stats);

        Log::channel('single')->debug('datalogic.tcp_chunk', [
            'device' => $deviceCode,
            'remote' => $remote,
            'bytes' => $bytesLen,
            'raw_preview' => Str::limit($printable, 120),
        ]);
    }

    /**
     * Registra el ciclo de vida completo de un evento de lectura de caja,
     * documentando detalladamente qué acciones se ejecutaron y cuáles NO se ejecutaron.
     *
     * @param  array{
     *     device: string,
     *     raw_input: string,
     *     outcome: 'counted'|'duplicate'|'rejected',
     *     summary: string,
     *     box_code?: ?string,
     *     pallet_code?: ?string,
     *     order_ref?: ?string,
     *     lot_code?: ?string,
     *     reason?: ?string,
     *     actions_done: list<string>,
     *     actions_not_done: list<string>,
     *     details?: array<string, mixed>
     * } $data
     */
    public function recordScanEvent(array $data): void
    {
        $now = CarbonImmutable::now()->timezone(config('app.timezone', 'America/Bogota'));
        $raw = $data['raw_input'];
        $printable = addcslashes($raw, "\0..\37\177..\377");

        $event = [
            'id' => (string) Str::uuid(),
            'timestamp' => $now->format('Y-m-d H:i:s.v'),
            'time' => $now->format('H:i:s.v'),
            'device' => $data['device'],
            'raw_input' => $raw,
            'raw_printable' => $printable,
            'raw_bytes_len' => strlen($raw),
            'raw_hex' => bin2hex($raw),
            'outcome' => $data['outcome'], // 'counted', 'duplicate', 'rejected'
            'summary' => $data['summary'],
            'box_code' => $data['box_code'] ?? null,
            'pallet_code' => $data['pallet_code'] ?? null,
            'order_ref' => $data['order_ref'] ?? null,
            'lot_code' => $data['lot_code'] ?? null,
            'reason' => $data['reason'] ?? null,
            'actions_done' => $data['actions_done'],
            'actions_not_done' => $data['actions_not_done'],
            'details' => $data['details'] ?? [],
        ];

        // Guardar en el buffer circular de Cache
        $events = $this->getRecent(self::MAX_EVENTS - 1);
        array_unshift($events, $event);
        $events = array_slice($events, 0, self::MAX_EVENTS);
        Cache::put(self::CACHE_KEY, $events, now()->addHours(8));

        // Actualizar estadísticas agregadas
        $stats = $this->getStats();
        $stats['total_reads'] = ($stats['total_reads'] ?? 0) + 1;
        match ($data['outcome']) {
            'counted' => $stats['total_counted'] = ($stats['total_counted'] ?? 0) + 1,
            'duplicate' => $stats['total_duplicate'] = ($stats['total_duplicate'] ?? 0) + 1,
            default => $stats['total_rejected'] = ($stats['total_rejected'] ?? 0) + 1,
        };
        $stats['last_read_at'] = $event['timestamp'];
        $stats['last_outcome'] = $data['outcome'];
        $stats['last_summary'] = $data['summary'];
        $this->saveStats($stats);

        // Registro estructurado en log local
        Log::channel('single')->info('datalogic.scan_debug', [
            'time' => $event['time'],
            'outcome' => $data['outcome'],
            'summary' => $data['summary'],
            'raw' => Str::limit($printable, 100),
            'box' => $event['box_code'],
            'pallet' => $event['pallet_code'],
            'actions_done_count' => count($event['actions_done']),
            'actions_not_done_count' => count($event['actions_not_done']),
        ]);
    }

    /**
     * Devuelve la lista de eventos de depuración recientes ordenados por fecha descendente.
     *
     * @return list<array<string, mixed>>
     */
    public function getRecent(int $limit = 30): array
    {
        $events = Cache::get(self::CACHE_KEY, []);

        if (! is_array($events)) {
            return [];
        }

        /** @var list<array<string, mixed>> $slice */
        $slice = array_values(array_slice($events, 0, max(1, min(self::MAX_EVENTS, $limit))));

        return $slice;
    }

    /**
     * Obtiene estadísticas agregadas de depuración en vivo.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $stats = Cache::get(self::STATS_KEY);

        if (! is_array($stats)) {
            return [
                'total_reads' => 0,
                'total_counted' => 0,
                'total_duplicate' => 0,
                'total_rejected' => 0,
                'total_tcp_chunks' => 0,
                'total_tcp_bytes' => 0,
                'last_tcp_at' => null,
                'last_read_at' => null,
                'last_outcome' => null,
                'last_summary' => null,
                'last_remote' => null,
            ];
        }

        return $stats;
    }

    /**
     * Guarda las estadísticas agregadas en cache.
     *
     * @param  array<string, mixed>  $stats
     */
    private function saveStats(array $stats): void
    {
        Cache::put(self::STATS_KEY, $stats, now()->addHours(8));
    }

    /**
     * Limpia la bitácora de eventos y contadores de depuración.
     */
    public function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::STATS_KEY);
    }
}
