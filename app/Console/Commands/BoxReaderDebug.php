<?php

namespace App\Console\Commands;

use App\Services\DatalogicDebugService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('boxes:debug
    {--clear : Limpia el historial de eventos de depuración}
    {--watch : Observa continuamente la llegada de nuevas lecturas en tiempo real}
    {--limit=20 : Cantidad de eventos recientes a mostrar}')]
#[Description('Muestra en vivo la bitácora de depuración del lector Datalogic: qué acciones se ejecutaron y qué se omitió.')]
class BoxReaderDebug extends Command
{
    private bool $running = true;

    public function handle(DatalogicDebugService $debug): int
    {
        if ($this->option('clear')) {
            $debug->clear();
            $this->info('Historial de depuración de Datalogic limpiado exitosamente.');

            return self::SUCCESS;
        }

        $this->output->writeln('');
        $this->output->writeln('<bg=blue;fg=white;options=bold>  ========================================================================  </>');
        $this->output->writeln('<bg=blue;fg=white;options=bold>   🔍 MONITOR DE DEPURACIÓN EN VIVO - DATALOGIC (LEC-L1)                   </>');
        $this->output->writeln('<bg=blue;fg=white;options=bold>  ========================================================================  </>');
        $this->output->writeln('');

        $stats = $debug->getStats();
        $this->output->writeln(sprintf(
            ' Estadísticas: <info>%d</info> recibidos | <fg=green;options=bold>%d contadas</> | <fg=yellow>%d duplicadas</> | <fg=red;options=bold>%d rechazadas</> | <comment>%d bytes TCP</comment>',
            $stats['total_reads'] ?? 0,
            $stats['total_counted'] ?? 0,
            $stats['total_duplicate'] ?? 0,
            $stats['total_rejected'] ?? 0,
            $stats['total_tcp_bytes'] ?? 0
        ));
        $this->output->writeln('');

        $events = $debug->getRecent((int) $this->option('limit'));

        if (empty($events)) {
            $this->line('  <comment>No hay eventos registrados recientemente en la bitácora.</comment>');
            $this->line('  Los eventos aparecerán automáticamente cada vez que el Datalogic lea un código.');
        } else {
            foreach (array_reverse($events) as $event) {
                $this->renderEvent($event);
            }
        }

        if ($this->option('watch')) {
            $this->info('Modo observación activo (Ctrl+C para salir)...');
            $seenIds = array_column($events, 'id');

            if (defined('SIGINT')) {
                $this->trap([SIGINT, SIGTERM], function (): void {
                    $this->running = false;
                });
            }

            while ($this->running) {
                sleep(1);
                $latest = $debug->getRecent(10);
                foreach (array_reverse($latest) as $event) {
                    if (! in_array($event['id'], $seenIds, true)) {
                        $this->renderEvent($event);
                        $seenIds[] = $event['id'];
                    }
                }
                if (count($seenIds) > 100) {
                    $seenIds = array_slice($seenIds, -50);
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function renderEvent(array $event): void
    {
        $time = $event['time'] ?? '';
        $outcome = $event['outcome'] ?? 'rejected';
        $summary = $event['summary'] ?? '';
        $raw = $event['raw_printable'] ?? ($event['raw_input'] ?? '');
        $bytes = $event['raw_bytes_len'] ?? 0;

        $badge = match ($outcome) {
            'counted' => '<bg=green;fg=white;options=bold>  ✓ CONTADA  </>',
            'duplicate' => '<bg=yellow;fg=black;options=bold>  ⚠ DUPLICADA  </>',
            default => '<bg=red;fg=white;options=bold>  ✗ RECHAZADA  </>',
        };

        $this->output->writeln('--------------------------------------------------------------------------------');
        $this->output->writeln(" [{$time}] {$badge}  <options=bold>{$summary}</>");
        $this->output->writeln("   <fg=cyan;options=bold>📥 TCP IN ({$bytes} bytes):</> \"{$raw}\"");

        if (! empty($event['actions_done'])) {
            $this->output->writeln('   <fg=green;options=bold>✅ Acciones Realizadas:</>');
            foreach ($event['actions_done'] as $action) {
                $this->output->writeln("      • {$action}");
            }
        }

        if (! empty($event['actions_not_done'])) {
            $this->output->writeln('   <fg=yellow;options=bold>⚠️ Acciones NO Realizadas (y motivo):</>');
            foreach ($event['actions_not_done'] as $action) {
                $this->output->writeln("      • {$action}");
            }
        }
    }
}
