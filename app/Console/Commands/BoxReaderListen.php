<?php

namespace App\Console\Commands;

use App\Domain\Operations\Heartbeat;
use App\Domain\Pallets\Actions\RegisterManualBoxScan;
use App\Domain\Pallets\Readers\BoxReaderSession;
use App\Domain\Pallets\Readers\BoxReadOutcome;
use App\Models\Device;
use App\Services\DatalogicDebugService;
use App\Services\TraceLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a fixed box reader connected and counts every box it reads. It
 * runs under Supervisor, one process per reader, and reconnects on its
 * own. While connected it beats Heartbeat::reader(), so /operations warns
 * when a reader that was working is no longer connected.
 */
#[Signature('boxes:reader
    {device : Código del lector registrado con devices:register --kind=LECTOR}
    {--connect= : IP:puerto del lector cuando el lector es el servidor TCP}
    {--listen= : IP:puerto donde esperar al lector cuando el lector es el cliente TCP}
    {--serial= : Puerto serial, por ejemplo /dev/ttyUSB0}
    {--baud=9600 : Velocidad del puerto serial}
    {--idle-minutes=10 : Por TCP, reconecta si no llega nada en este tiempo (una conexión caída sin aviso)}
    {--once : Termina cuando se cierra la conexión, sin reconectar (para probar)}')]
#[Description('Conecta un lector fijo de cajas por TCP o serial y cuenta cada caja que lee.')]
class BoxReaderListen extends Command
{
    private const WAIT_SECONDS = 5;

    private const MAX_BACKOFF_SECONDS = 30;

    private bool $stopping = false;

    public function handle(RegisterManualBoxScan $scan, TraceLogger $trace): int
    {
        $code = mb_strtoupper(trim((string) $this->argument('device')));
        $modes = [];
        foreach (['connect', 'listen', 'serial'] as $name) {
            $value = $this->option($name);
            if (is_string($value) && $value !== '') {
                $modes[$name] = $value;
            }
        }

        if (count($modes) !== 1) {
            $this->error('Indica una sola forma de conexión: --connect, --listen o --serial.');

            return self::FAILURE;
        }

        if (defined('SIGTERM')) {
            $this->trap([SIGTERM, SIGINT], function (): void {
                $this->stopping = true;
            });
        }

        $mode = (string) array_key_first($modes);
        $target = $modes[$mode] ?? '';
        $server = null;
        $backoff = 1;

        while (! $this->stopping) {
            $reader = Device::query()->where('code', $code)->first();
            if ($reader === null || ! $reader->active || $reader->kind !== 'LECTOR' || $reader->code === RegisterManualBoxScan::DEVICE_CODE) {
                $this->error("No hay un lector de cajas activo con el código {$code}. Regístralo con devices:register {$code} \"Nombre\" --kind=LECTOR.");

                return self::FAILURE;
            }

            try {
                $stream = match ($mode) {
                    'connect' => $this->connect($target),
                    'listen' => $this->accept($server ??= $this->server($target)),
                    default => $this->serial($target),
                };
            } catch (\RuntimeException $e) {
                $stream = null;
                $this->warn($e->getMessage());
                Log::warning('Lector de cajas sin conexión', ['device' => $code, 'reason' => $e->getMessage()]);
            }

            if ($stream !== null) {
                $this->info("Lector {$code} conectado.");
                $backoff = 1;
                $this->pump($stream, new BoxReaderSession($reader, $scan, $trace), $code, $target, $mode === 'serial' ? 0 : max(0, (int) $this->option('idle-minutes')));
                fclose($stream);
                $this->warn("Lector {$code} desconectado.");
            } elseif ($mode === 'listen' && ! $this->option('once')) {
                // Nobody connected yet: keep waiting, no backoff needed.
                continue;
            }

            if ($this->option('once')) {
                return $stream === null ? self::FAILURE : self::SUCCESS;
            }

            $this->pause($backoff);
            $backoff = min($backoff * 2, self::MAX_BACKOFF_SECONDS);
        }

        return self::SUCCESS;
    }

    /**
     * @param  resource  $stream
     */
    private function pump($stream, BoxReaderSession $session, string $code, string $remote, int $idleMinutes): void
    {
        $debug = app(DatalogicDebugService::class);
        Heartbeat::record(Heartbeat::reader($code));
        $lastData = time();

        while (! $this->stopping) {
            $read = [$stream];
            $write = $except = null;
            $ready = @stream_select($read, $write, $except, self::WAIT_SECONDS);
            Heartbeat::record(Heartbeat::reader($code));

            if ($ready === false || $ready === 0) {
                // A TCP connection can die without notice (cable, switch): reconnecting is harmless.
                if ($idleMinutes > 0 && time() - $lastData >= $idleMinutes * 60) {
                    $this->warn("Lector {$code} sin datos en {$idleMinutes} min; se reconecta.");

                    return;
                }

                continue;
            }

            $lastData = time();

            $chunk = fread($stream, 4096);
            if ($chunk === false || ($chunk === '' && feof($stream))) {
                return;
            }

            $debug->recordTcpChunk($code, $remote, $chunk);

            foreach ($session->feed($chunk) as $box) {
                $time = date('H:i:s');
                if ($box->outcome === BoxReadOutcome::Rejected) {
                    $this->warn("[{$time}] No contada: {$box->message}");
                } else {
                    $this->line("[{$time}] ".($box->outcome === BoxReadOutcome::Counted ? 'Contada: ' : 'Repetida: ').$box->message);
                }
            }
        }
    }

    /**
     * @return resource
     */
    private function connect(string $target)
    {
        $stream = @stream_socket_client('tcp://'.$target, $errno, $error, self::WAIT_SECONDS);

        return $stream !== false ? $stream : throw new \RuntimeException("No se pudo conectar al lector en {$target}: {$error}");
    }

    /**
     * @return resource
     */
    private function server(string $target)
    {
        $server = @stream_socket_server('tcp://'.$target, $errno, $error);

        return $server !== false ? $server : throw new \RuntimeException("No se pudo escuchar en {$target}: {$error}");
    }

    /**
     * @param  resource  $server
     * @return resource|null
     */
    private function accept($server)
    {
        $stream = @stream_socket_accept($server, self::WAIT_SECONDS);

        return $stream !== false ? $stream : null;
    }

    /**
     * @return resource
     */
    private function serial(string $port)
    {
        if (! preg_match('#^/dev/[A-Za-z0-9._/-]+$#', $port)) {
            throw new \RuntimeException("Puerto serial no válido: {$port}");
        }

        $baud = (int) $this->option('baud');
        exec('stty -F '.escapeshellarg($port).' '.$baud.' raw -echo 2>&1', $output, $status);
        if ($status !== 0) {
            throw new \RuntimeException("No se pudo configurar {$port}: ".implode(' ', $output));
        }

        $stream = @fopen($port, 'r');

        return $stream !== false ? $stream : throw new \RuntimeException("No se pudo abrir {$port}.");
    }

    private function pause(int $seconds): void
    {
        for ($i = 0; $i < $seconds * 10 && ! $this->stopping; $i++) {
            usleep(100_000);
        }
    }
}
