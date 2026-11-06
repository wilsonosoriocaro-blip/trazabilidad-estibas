<?php

namespace App\Services;

use App\Domain\BoxLabels\LabelFormatConfig;

class ZebraPrintService
{
    /**
     * Reemplaza variables parametrizables {variable} en una plantilla ZPL
     *
     * @param  array<string, mixed>  $variables
     */
    public function interpolate(string $templateZpl, array $variables): string
    {
        $replace = [];
        foreach ($variables as $key => $val) {
            $cleanVal = trim((string) $val);
            $replace['{'.$key.'}'] = $cleanVal;
            $replace['{{'.$key.'}}'] = $cleanVal;
            $replace['{{ '.$key.' }}'] = $cleanVal;
            $replace['{ '.$key.' }'] = $cleanVal;
        }

        return strtr($templateZpl, $replace);
    }

    /**
     * Extrae todas las variables {variable} encontradas en una plantilla ZPL
     *
     * @return list<string>
     */
    public function extractVariables(string $templateZpl): array
    {
        preg_match_all('/\{{1,2}\s*([a-zA-Z0-9_\-]+)\s*\}{1,2}/', $templateZpl, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Asegura que el flujo ZPL tenga configurada la velocidad de impresión (^PRp,s,b).
     * Si no la tiene, la inyecta después de cada ^XA; si ya la tiene, actualiza la velocidad
     * al valor configurado para garantizar que la Zebra imprima siempre al ritmo deseado.
     */
    public function ensurePrintSpeed(string $zpl, ?int $speed = null): string
    {
        $configured = $speed ?? LabelFormatConfig::printSpeed();
        $speedVal = max(2, min(14, $configured));

        // Si ya contiene algún comando ^PR explícito, actualizarlo a la velocidad parametrizada
        if (preg_match('/\^PR\d+/i', $zpl) === 1) {
            return preg_replace('/\^PR\d+(?:,\d+)?(?:,\d+)?/i', "^PR{$speedVal},{$speedVal},{$speedVal}", $zpl) ?? $zpl;
        }

        // Si no tiene ^PR, inyectar ^PR{speed},{speed},{speed} justo después de cada ^XA
        return preg_replace('/(\^XA)/i', "$1\n^PR{$speedVal},{$speedVal},{$speedVal}", $zpl) ?? $zpl;
    }

    /**
     * Divide una cadena ZPL multietiqueta en un arreglo de etiquetas individuales (^XA ... ^XZ).
     *
     * @return list<string>
     */
    public function splitZplIntoLabels(string $zpl): array
    {
        if (preg_match_all('/\^XA.*?\^XZ/is', $zpl, $matches)) {
            return $matches[0];
        }

        $trimmed = trim($zpl);

        return ! empty($trimmed) ? [$trimmed] : [];
    }

    /**
     * Consulta el odómetro de etiquetas físicas impresas en la Zebra vía SGD con reintentos para alta confiabilidad.
     */
    public function queryOdometer(string $ip, int $port = 9100, float $timeout = 2.5, int $retries = 3): ?int
    {
        $ip = trim($ip);
        if (empty($ip)) {
            return null;
        }

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            $errno = 0;
            $errstr = '';
            $socket = @fsockopen($ip, $port, $errno, $errstr, $timeout);
            if ($socket) {
                stream_set_timeout($socket, (int) ceil($timeout), (int) (($timeout - floor($timeout)) * 1000000));
                @fwrite($socket, "{}{! U1 getvar \"odometer.total_label_count\"}\r\n");
                @fflush($socket);

                usleep(150000); // 150ms

                $response = '';
                while ($chunk = @fread($socket, 512)) {
                    $response .= $chunk;
                    if (strlen($chunk) < 512) {
                        break;
                    }
                }
                @fclose($socket);

                if (preg_match('/"?(\d+)"?/', $response, $matches)) {
                    return (int) $matches[1];
                }
            }

            if ($attempt < $retries) {
                usleep(300000); // 300ms antes del siguiente reintento
            }
        }

        return null;
    }

    /**
     * Escribe datos en un socket TCP de forma segura y fragmentada en bloques,
     * protegiendo contra desbordamientos y el error PHP errno=11 (EAGAIN/EWOULDBLOCK).
     *
     * @param  resource  $socket
     */
    public function writeSocketSafely($socket, string $data, int $timeoutSeconds = 20): bool
    {
        $totalBytes = strlen($data);
        $bytesSent = 0;
        $chunkSize = 8192; // 8 KB
        $startTime = microtime(true);

        stream_set_blocking($socket, false);

        while ($bytesSent < $totalBytes) {
            if ((microtime(true) - $startTime) > $timeoutSeconds) {
                return false;
            }

            $writeStreams = [$socket];
            $readStreams = null;
            $exceptStreams = null;

            $ready = @stream_select($readStreams, $writeStreams, $exceptStreams, 2);
            if ($ready === false || $ready === 0) {
                continue;
            }

            $slice = substr($data, $bytesSent, $chunkSize);
            $written = @fwrite($socket, $slice);

            if ($written === false) {
                return false;
            }

            if ($written > 0) {
                $bytesSent += $written;
            } else {
                usleep(50000); // 50ms
            }
        }

        @fflush($socket);

        return true;
    }

    /**
     * Envía ZPL directamente a la IP y puerto de la Zebra por Socket TCP RAW
     *
     * @return array<string, mixed>
     */
    public function printToSocket(string $ip, int $port = 9100, string $zpl = '', int $timeout = 3): array
    {
        $startTime = microtime(true);
        $errno = 0;
        $errstr = '';

        $ip = trim($ip);
        if (empty($ip)) {
            return [
                'success' => false,
                'status' => 'INVALID_IP',
                'message' => 'La dirección IP de la impresora no puede estar vacía.',
                'bytes_sent' => 0,
                'latency_ms' => 0,
            ];
        }

        // Asegurar velocidad de impresión configurada (evita que la Zebra imprima a máxima velocidad descontrolada)
        $zpl = $this->ensurePrintSpeed($zpl);
        $labels = $this->splitZplIntoLabels($zpl);
        $totalLabels = count($labels);

        $totalBytes = strlen($zpl);
        $effectiveTimeout = max($timeout, min(90, (int) ceil($totalBytes / 8000) + $timeout));

        $socket = @fsockopen($ip, $port, $errno, $errstr, $timeout);

        if (! $socket) {
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'success' => false,
                'status' => 'CONNECTION_FAILED',
                'error_code' => $errno,
                'message' => "Fallo de conexión con la impresora Zebra en {$ip}:{$port}. ({$errstr})",
                'details' => 'Verifique que la impresora esté encendida, con cable de red conectado y en el mismo segmento IP.',
                'bytes_sent' => 0,
                'latency_ms' => $latency,
            ];
        }

        $sent = $this->writeSocketSafely($socket, $zpl, $effectiveTimeout);
        @fclose($socket);

        $latency = round((microtime(true) - $startTime) * 1000, 2);

        if (! $sent) {
            return [
                'success' => false,
                'status' => 'WRITE_INCOMPLETE',
                'message' => "Transmisión incompleta a la Zebra: tiempo de espera superado tras {$latency} ms.",
                'details' => 'El búfer de la impresora no pudo recibir todos los datos a tiempo.',
                'bytes_sent' => 0,
                'ip' => $ip,
                'port' => $port,
                'latency_ms' => $latency,
            ];
        }

        return [
            'success' => true,
            'status' => 'PRINTED',
            'message' => "Trabajo transmitido exitosamente a la impresora Zebra ({$totalLabels} etiquetas en {$latency} ms).",
            'bytes_sent' => $totalBytes,
            'ip' => $ip,
            'port' => $port,
            'latency_ms' => $latency,
        ];
    }

    /**
     * Comprueba si la IP de la impresora responde a un ping ICMP rápido (timeout 1s).
     */
    public function pingHost(string $ip): bool
    {
        $ip = trim($ip);
        if (empty($ip) || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $cmd = sprintf('ping -c 1 -W 1 %s 2>&1', escapeshellarg($ip));
        exec($cmd, $output, $resultCode);

        return $resultCode === 0;
    }

    /**
     * Cancela y purga los formatos no impresos que quedaron en el búfer de recepción de la Zebra (~JA).
     */
    public function cancelAllInReceiveBuffer(string $ip, int $port = 9100): bool
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($ip, $port, $errno, $errstr, 1.0);
        if (! $socket) {
            return false;
        }

        @fwrite($socket, "~JA\r\n");
        @fflush($socket);
        @fclose($socket);

        return true;
    }

    /**
     * Envía comando ~PS (Print Start) para despausar la Zebra remotamente.
     */
    public function unpausePrinter(string $ip, int $port = 9100): bool
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($ip, $port, $errno, $errstr, 1.0);
        if (! $socket) {
            return false;
        }

        @fwrite($socket, "~PS\r\n");
        @fflush($socket);
        @fclose($socket);

        return true;
    }

    /**
     * Pausa inmediatamente el motor físico de la Zebra (~PP) y purga los formatos
     * pendientes en su búfer de recepción (~JA) para que no siga imprimiendo.
     */
    public function pausePrinter(string $ip, int $port = 9100): bool
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($ip, $port, $errno, $errstr, 1.0);
        if (! $socket) {
            return false;
        }

        @fwrite($socket, "~PP\r\n~JA\r\n");
        @fflush($socket);
        @fclose($socket);

        return true;
    }

    /**
     * Imprime un sub-lote (chunk) de etiquetas ZPL verificando activamente el estado físico
     * de la Zebra (falta de papel, cabezal abierto, ribbon agotado, o pausa física) antes de transmitir.
     *
     * @return array{
     *     success: bool,
     *     status: string,
     *     message: string,
     *     printed_count: int,
     *     remaining_in_chunk: int,
     *     hardware_status?: string,
     *     error_reason?: string,
     *     unprinted_in_buffer?: int
     * }
     */
    public function printChunk(string $ip, int $port, string $chunkZpl, int $expectedLabels): array
    {
        // 1. Verificar estado físico de la impresora antes de enviar (modo rápido ~45ms)
        $status = $this->getPrinterStatus($ip, $port, 2.0, fast: true);

        if ($status['is_paused'] ?? false) {
            // Intentar despausar con ~PS automáticamente
            $this->unpausePrinter($ip, $port);
            usleep(40000);
            $status = $this->getPrinterStatus($ip, $port, 2.0, fast: true);
        }

        if ($status['is_paused'] ?? false) {
            return [
                'success' => false,
                'status' => 'HARDWARE_PAUSED',
                'message' => 'La impresora Zebra está en PAUSA (botón presionado en panel frontal).',
                'printed_count' => 0,
                'remaining_in_chunk' => $expectedLabels,
                'error_reason' => 'Impresora en pausa física en panel',
            ];
        }

        if (! ($status['online'] ?? false)) {
            return [
                'success' => false,
                'status' => 'OFFLINE',
                'message' => "La impresora Zebra no responde en {$ip}:{$port}.",
                'printed_count' => 0,
                'remaining_in_chunk' => $expectedLabels,
                'error_reason' => $status['error_explanation'] ?? 'Impresora fuera de línea',
            ];
        }

        if ($status['head_open'] ?? false) {
            return [
                'success' => false,
                'status' => 'HEAD_OPEN',
                'message' => 'Cabezal de la impresora abierto. Por favor ciérrelo para continuar.',
                'printed_count' => 0,
                'remaining_in_chunk' => $expectedLabels,
                'error_reason' => 'Cabezal abierto',
            ];
        }

        if ($status['paper_out'] ?? false) {
            return [
                'success' => false,
                'status' => 'PAPER_OUT',
                'message' => 'Falta de rollo de etiquetas o papel atascado en la impresora.',
                'printed_count' => 0,
                'remaining_in_chunk' => $expectedLabels,
                'error_reason' => 'Sin papel / rollo de etiquetas',
            ];
        }

        if ($status['ribbon_out'] ?? false) {
            return [
                'success' => false,
                'status' => 'RIBBON_OUT',
                'message' => 'Cinta de impresión (Ribbon) agotada en la impresora.',
                'printed_count' => 0,
                'remaining_in_chunk' => $expectedLabels,
                'error_reason' => 'Sin cinta ribbon',
            ];
        }

        // 2. Asegurar velocidad configurada e inyectar
        $chunkZpl = $this->ensurePrintSpeed($chunkZpl);

        // 3. Conectar y transmitir el chunk de forma segura
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($ip, $port, $errno, $errstr, 3.5);
        if (! $socket) {
            usleep(200000);
            $socket = @fsockopen($ip, $port, $errno, $errstr, 3.5);
        }
        if (! $socket) {
            $isPingable = $this->pingHost($ip);
            if ($isPingable && ($errno === 111 || $errno === 61 || str_contains(strtolower($errstr), 'refused'))) {
                return [
                    'success' => false,
                    'status' => 'HARDWARE_PAUSED',
                    'message' => 'La impresora Zebra entró en PAUSA física (panel frontal).',
                    'printed_count' => 0,
                    'remaining_in_chunk' => $expectedLabels,
                    'error_reason' => 'Impresora en pausa física en panel',
                ];
            }

            return [
                'success' => false,
                'status' => 'CONNECTION_FAILED',
                'message' => "No se pudo conectar a la impresora Zebra ({$errstr}).",
                'printed_count' => 0,
                'remaining_in_chunk' => $expectedLabels,
                'error_reason' => 'Fallo de conexión socket',
            ];
        }

        $sent = $this->writeSocketSafely($socket, $chunkZpl, 10);
        @fflush($socket);
        @fclose($socket);

        if (! $sent) {
            return [
                'success' => false,
                'status' => 'TRANSMISSION_ERROR',
                'message' => 'Error durante el envío de datos a la impresora.',
                'printed_count' => 0,
                'remaining_in_chunk' => $expectedLabels,
                'error_reason' => 'Error de transmisión socket',
            ];
        }

        // Pacing físico optimizado: 80ms es suficiente para que el socket descargue
        // el paquete en la memoria de la impresora sin frenar artificialmente el software.
        $sleepMicroseconds = min(500000, max(80000, $expectedLabels * 80000));
        usleep($sleepMicroseconds);

        return [
            'success' => true,
            'status' => 'CHUNK_COMPLETED',
            'message' => "Sub-lote de {$expectedLabels} etiquetas transmitido exitosamente.",
            'printed_count' => $expectedLabels,
            'remaining_in_chunk' => 0,
        ];
    }

    /**
     * Consulta el estado en tiempo real de la Zebra mediante comandos nativos ~HS y SGD (Link-OS)
     *
     * @return array<string, mixed>
     */
    public function getPrinterStatus(string $ip, int $port = 9100, float $timeout = 1.0, bool $fast = false): array
    {
        $startTime = microtime(true);
        $errno = 0;
        $errstr = '';

        $ip = trim($ip);
        if (empty($ip)) {
            return [
                'online' => false,
                'status' => 'INVALID_IP',
                'status_label' => 'IP de impresora no definida',
                'badge_color' => 'slate',
                'message' => 'La dirección IP de la impresora no puede estar vacía.',
                'latency_ms' => 0,
            ];
        }

        $effectiveTimeout = $fast ? min(0.8, $timeout) : $timeout;
        $socket = @fsockopen($ip, $port, $errno, $errstr, $effectiveTimeout);

        if (! $socket) {
            $latency = round((microtime(true) - $startTime) * 1000, 2);
            $isPingable = $this->pingHost($ip);

            // Si el host responde a ping pero el puerto 9100 rechaza la conexión (errno 111 en Linux, 61 en macOS),
            // la Zebra está encendida en red pero en estado PAUSA / Detenida en su panel físico
            if ($isPingable && ($errno === 111 || $errno === 61 || str_contains(strtolower($errstr), 'refused'))) {
                return [
                    'online' => true,
                    'ip' => $ip,
                    'port' => $port,
                    'device_name' => 'Zebra ZT411',
                    'status' => 'PAUSED',
                    'status_label' => 'En pausa en panel físico',
                    'badge_color' => 'amber',
                    'error_code' => $errno,
                    'error_explanation' => 'La impresora está encendida en red pero en PAUSA en su panel frontal.',
                    'message' => 'La impresora Zebra está en PAUSA en su panel frontal.',
                    'troubleshooting' => 'Presione el botón de Pausa en el panel de la Zebra para reanudar.',
                    'is_printing' => false,
                    'is_paused' => true,
                    'paper_out' => false,
                    'head_open' => false,
                    'ribbon_out' => false,
                    'buffer_full' => false,
                    'formats_in_buffer' => 0,
                    'labels_remaining' => 0,
                    'sgd_status' => 'paused',
                    'latency_ms' => $latency,
                    'timestamp' => date('H:i:s'),
                ];
            }

            $explanation = ($errno === 64 || $errno === 65 || str_contains(strtolower($errstr), 'route') || str_contains(strtolower($errstr), 'down'))
                ? "El dispositivo en {$ip} no responde en la red (ARP/Ruta no alcanzable)."
                : "No se pudo conectar a {$ip}:{$port}. ({$errstr})";

            return [
                'online' => false,
                'ip' => $ip,
                'port' => $port,
                'status' => 'OFFLINE',
                'status_label' => 'Sin enlace ('.$ip.')',
                'badge_color' => 'rose',
                'error_code' => $errno,
                'error_explanation' => $explanation,
                'message' => "La impresora física no responde en {$ip}:{$port}.",
                'troubleshooting' => 'La impresora física está apagada o no responde en esa dirección IP.',
                'latency_ms' => $latency,
                'timestamp' => date('H:i:s'),
            ];
        }

        stream_set_timeout($socket, (int) ceil($effectiveTimeout), (int) (($effectiveTimeout - floor($effectiveTimeout)) * 1000000));

        if ($fast) {
            $cmd = "~HS\r\n";
            fwrite($socket, $cmd);
            fflush($socket);
            usleep(45000); // 45ms para respuesta rápida ~HS
        } else {
            $cmd = "~HS\r\n! U1 getvar \"device.friendly_name\"\r\n! U1 getvar \"device.status\"\r\n";
            fwrite($socket, $cmd);
            fflush($socket);
            usleep(120000); // 120ms para comandos SGD completos
        }

        $buffer = '';
        while ($chunk = fread($socket, 1024)) {
            $buffer .= $chunk;
            if (strlen($chunk) < 1024) {
                break;
            }
        }
        fclose($socket);

        $latency = round((microtime(true) - $startTime) * 1000, 2);

        preg_match_all("/\x02([^\x03]+)\x03/", $buffer, $matches);
        $lines = $matches[1];

        $paperOut = false;
        $paused = false;
        $headOpen = false;
        $ribbonOut = false;
        $bufferFull = false;
        $formatsInBuffer = 0;
        $labelsRemaining = 0;

        if (count($lines) >= 2) {
            $l1 = explode(',', $lines[0]);
            $l2 = explode(',', $lines[1]);

            $paperOut = ($l1[1] ?? '0') === '1';
            $paused = ($l1[2] ?? '0') === '1';
            $formatsInBuffer = (int) ($l1[4] ?? 0);
            $bufferFull = ($l1[5] ?? '0') === '1';

            $headOpen = ($l2[2] ?? '0') === '1';
            $ribbonOut = ($l2[3] ?? '0') === '1';
            $labelsRemaining = (int) ($l2[8] ?? 0);
        }

        $deviceName = 'Zebra ZT411';
        $sgdStatus = 'ready';

        if (! $fast && preg_match_all('/"([^"]+)"/', $buffer, $sgdMatches)) {
            $deviceName = $sgdMatches[1][0];
            $sgdStatus = strtolower(trim($sgdMatches[1][1] ?? 'ready'));
        }

        $statusKey = 'READY';
        $statusLabel = 'Lista para imprimir';
        $badgeColor = 'emerald';
        $isPrinting = false;

        if ($headOpen) {
            $statusKey = 'HEAD_OPEN';
            $statusLabel = 'Cabezal Abierto';
            $badgeColor = 'rose';
        } elseif ($paperOut || str_contains($sgdStatus, 'paper out') || str_contains($sgdStatus, 'media')) {
            $statusKey = 'PAPER_OUT';
            $statusLabel = 'Sin papel / Atascada';
            $badgeColor = 'rose';
        } elseif ($ribbonOut || str_contains($sgdStatus, 'ribbon')) {
            $statusKey = 'RIBBON_OUT';
            $statusLabel = 'Sin cinta Ribbon';
            $badgeColor = 'amber';
        } elseif ($labelsRemaining > 0 || str_contains($sgdStatus, 'printing')) {
            $statusKey = 'PRINTING';
            $statusLabel = "Imprimiendo (quedan {$labelsRemaining})";
            $badgeColor = 'blue';
            $isPrinting = true;
        } elseif ($paused || str_contains($sgdStatus, 'pause')) {
            $statusKey = 'PAUSED';
            $statusLabel = 'Pausada';
            $badgeColor = 'amber';
        } elseif ($formatsInBuffer > 0) {
            $statusKey = 'BUFFERED';
            $statusLabel = "Procesando ({$formatsInBuffer} en cola)";
            $badgeColor = 'indigo';
        }

        return [
            'online' => true,
            'ip' => $ip,
            'port' => $port,
            'device_name' => $deviceName,
            'status' => $statusKey,
            'status_label' => $statusLabel,
            'badge_color' => $badgeColor,
            'is_printing' => $isPrinting,
            'is_paused' => ($paused || str_contains($sgdStatus, 'pause')),
            'paper_out' => $paperOut,
            'head_open' => $headOpen,
            'ribbon_out' => $ribbonOut,
            'buffer_full' => $bufferFull,
            'formats_in_buffer' => $formatsInBuffer,
            'labels_remaining' => $labelsRemaining,
            'unprinted_in_buffer' => max($formatsInBuffer, $labelsRemaining),
            'sgd_status' => $sgdStatus,
            'latency_ms' => $latency,
            'timestamp' => date('H:i:s'),
        ];
    }
}
