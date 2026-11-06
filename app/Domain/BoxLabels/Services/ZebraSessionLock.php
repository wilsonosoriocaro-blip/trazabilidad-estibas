<?php

namespace App\Domain\BoxLabels\Services;

use App\Models\BoxLabelPrintJob;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Control de concurrencia y bloqueo de sesión exclusivo para la impresora Zebra.
 *
 * Evita colisiones de búfer cuando múltiples pestañas, usuarios o estaciones
 * intentan enviar formatos ZPL en paralelo al puerto 9100 de la impresora.
 */
class ZebraSessionLock
{
    public const DEFAULT_TTL_SECONDS = 90;

    public static function lockKey(string $ip, int $port): string
    {
        $sanitizedIp = preg_replace('/[^a-zA-Z0-9_.-]/', '_', trim($ip)) ?? 'default';

        return "zebra_lock:{$sanitizedIp}:{$port}";
    }

    /**
     * Intenta adquirir o renovar el bloqueo exclusivo de la impresora.
     *
     * @return array{acquired: bool, lock: array<string, mixed>|null, message: string}
     */
    public function acquireOrRenew(string $ip, int $port, BoxLabelPrintJob $job, User $user, string $sessionId): array
    {
        $key = self::lockKey($ip, $port);
        $currentLock = Cache::get($key);

        $now = time();

        // Si ya hay un bloqueo activo
        if (is_array($currentLock) && ($currentLock['expires_at'] ?? 0) > $now) {
            $sameJob = ($currentLock['job_id'] ?? null) === $job->id;
            $sameSession = ($currentLock['session_id'] ?? null) === $sessionId && ($currentLock['user_id'] ?? null) === $user->id;

            if ($sameJob || $sameSession) {
                // Renovar el bloqueo
                $currentLock['job_id'] = $job->id;
                $currentLock['lot_code'] = $job->lot_code;
                $currentLock['last_active_at'] = $now;
                $currentLock['expires_at'] = $now + self::DEFAULT_TTL_SECONDS;
                Cache::put($key, $currentLock, self::DEFAULT_TTL_SECONDS);

                return [
                    'acquired' => true,
                    'lock' => $currentLock,
                    'message' => 'Bloqueo renovado con éxito.',
                ];
            }

            // Pertenece a otra sesión o trabajo
            return [
                'acquired' => false,
                'lock' => $currentLock,
                'message' => "La impresora Zebra ({$ip}) está en uso exclusivo por {$currentLock['user_name']} imprimiendo el lote {$currentLock['lot_code']} (Trabajo #{$currentLock['job_id']}). Espere a que finalice para evitar colisión de búfer.",
            ];
        }

        // Impresora libre: adquirir bloqueo
        $lockData = [
            'printer_ip' => trim($ip),
            'printer_port' => $port,
            'job_id' => $job->id,
            'batch_id' => $job->box_label_batch_id,
            'lot_code' => $job->lot_code,
            'total_labels' => $job->total_labels,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'session_id' => $sessionId,
            'locked_at' => $now,
            'last_active_at' => $now,
            'expires_at' => $now + self::DEFAULT_TTL_SECONDS,
        ];

        Cache::put($key, $lockData, self::DEFAULT_TTL_SECONDS);

        return [
            'acquired' => true,
            'lock' => $lockData,
            'message' => 'Bloqueo de impresora adquirido.',
        ];
    }

    /**
     * Comprueba si la impresora está bloqueada por OTRA sesión o trabajo de impresión.
     *
     * @return array<string, mixed>|null
     */
    public function getActiveLockForOther(string $ip, int $port, ?int $currentJobId = null, ?string $currentSessionId = null): ?array
    {
        $key = self::lockKey($ip, $port);
        $lock = Cache::get($key);

        if (! is_array($lock)) {
            return null;
        }

        if (($lock['expires_at'] ?? 0) <= time()) {
            Cache::forget($key);

            return null;
        }

        // Si es el mismo trabajo de impresión activo
        if ($currentJobId !== null && ($lock['job_id'] ?? null) === $currentJobId) {
            return null;
        }

        // Si es la misma sesión y aún no se ha seleccionado otro trabajo
        if ($currentSessionId !== null && ($lock['session_id'] ?? null) === $currentSessionId && $currentJobId === null) {
            return null;
        }

        return $lock;
    }

    /**
     * Libera el bloqueo de la impresora si corresponde al trabajo o sesión.
     */
    public function release(string $ip, int $port, ?int $jobId = null): void
    {
        $key = self::lockKey($ip, $port);
        $lock = Cache::get($key);

        if (is_array($lock)) {
            if ($jobId !== null && ($lock['job_id'] ?? null) !== $jobId) {
                return;
            }
            Cache::forget($key);
        }
    }

    /**
     * Libera incondicionalmente el bloqueo (para uso administrativo o emergencia).
     */
    public function forceRelease(string $ip, int $port): void
    {
        Cache::forget(self::lockKey($ip, $port));
    }
}
