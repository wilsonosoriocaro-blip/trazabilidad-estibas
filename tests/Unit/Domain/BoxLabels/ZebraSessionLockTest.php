<?php

namespace Tests\Unit\Domain\BoxLabels;

use App\Domain\BoxLabels\Services\ZebraSessionLock;
use App\Models\BoxLabelPrintJob;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ZebraSessionLockTest extends TestCase
{
    public function test_acquire_and_release_lock(): void
    {
        Cache::flush();

        $service = new ZebraSessionLock;
        $user = new User(['name' => 'Operador 1']);
        $user->id = 10;

        $job = new BoxLabelPrintJob([
            'box_label_batch_id' => 50,
            'lot_code' => '35061309',
            'total_labels' => 800,
        ]);
        $job->id = 100;

        // Adquirir bloqueo
        $result = $service->acquireOrRenew('192.168.1.155', 9100, $job, $user, 'session-abc');
        $this->assertTrue($result['acquired']);
        $this->assertNotNull($result['lock']);

        // Bloqueo activo para otra sesión
        $otherLock = $service->getActiveLockForOther('192.168.1.155', 9100, 101, 'session-xyz');
        $this->assertNotNull($otherLock);
        $this->assertSame(100, $otherLock['job_id']);
        $this->assertSame('Operador 1', $otherLock['user_name']);

        // Misma sesión no se bloquea a sí misma
        $sameLock = $service->getActiveLockForOther('192.168.1.155', 9100, 100, 'session-abc');
        $this->assertNull($sameLock);

        // Liberar bloqueo
        $service->release('192.168.1.155', 9100, 100);

        // Ahora está libre
        $afterRelease = $service->getActiveLockForOther('192.168.1.155', 9100, 101, 'session-xyz');
        $this->assertNull($afterRelease);
    }

    public function test_lock_rejection_for_concurrent_session(): void
    {
        Cache::flush();

        $service = new ZebraSessionLock;
        $user1 = new User(['name' => 'Operador 1']);
        $user1->id = 10;
        $job1 = new BoxLabelPrintJob(['box_label_batch_id' => 1, 'lot_code' => 'LOT1', 'total_labels' => 500]);
        $job1->id = 1;

        $user2 = new User(['name' => 'Operador 2']);
        $user2->id = 20;
        $job2 = new BoxLabelPrintJob(['box_label_batch_id' => 2, 'lot_code' => 'LOT2', 'total_labels' => 200]);
        $job2->id = 2;

        // Usuario 1 adquiere bloqueo
        $res1 = $service->acquireOrRenew('192.168.1.155', 9100, $job1, $user1, 'session-1');
        $this->assertTrue($res1['acquired']);

        // Usuario 2 intenta adquirir para la misma impresora
        $res2 = $service->acquireOrRenew('192.168.1.155', 9100, $job2, $user2, 'session-2');
        $this->assertFalse($res2['acquired']);
        $this->assertStringContainsString('en uso exclusivo por Operador 1', $res2['message']);
        $this->assertStringContainsString('LOT1', $res2['message']);
    }
}
