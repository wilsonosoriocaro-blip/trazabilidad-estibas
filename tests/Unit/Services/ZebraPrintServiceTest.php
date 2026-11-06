<?php

namespace Tests\Unit\Services;

use App\Services\ZebraPrintService;
use PHPUnit\Framework\TestCase;

class ZebraPrintServiceTest extends TestCase
{
    public function test_interpolates_variables(): void
    {
        $service = new ZebraPrintService;
        $template = '^XA^FO50,50^FD{name}^FS^FO50,100^FD{{ code }}^FS^XZ';
        $result = $service->interpolate($template, ['name' => 'GELATINA', 'code' => 'PT1350']);

        $this->assertSame('^XA^FO50,50^FDGELATINA^FS^FO50,100^FDPT1350^FS^XZ', $result);
    }

    public function test_extracts_variables(): void
    {
        $service = new ZebraPrintService;
        $template = '^XA^FD{item}^FS^FD{{ lot }}^FS^FD{ item }^FS^XZ';
        $variables = $service->extractVariables($template);

        $this->assertSame(['item', 'lot'], $variables);
    }

    public function test_returns_error_on_empty_ip(): void
    {
        $service = new ZebraPrintService;
        $result = $service->printToSocket('', 9100, '^XA^XZ');

        $this->assertFalse($result['success']);
        $this->assertSame('INVALID_IP', $result['status']);
    }

    public function test_handles_connection_failure_cleanly(): void
    {
        $service = new ZebraPrintService;
        // 127.0.0.1 on a non-listening port with 1ms timeout should fail cleanly without unhandled exception
        $result = $service->printToSocket('127.0.0.1', 59999, '^XA^XZ', 1);

        $this->assertFalse($result['success']);
        $this->assertSame('CONNECTION_FAILED', $result['status']);
    }

    public function test_ensure_print_speed_injects_pr_command(): void
    {
        $service = new ZebraPrintService;
        $zpl = "^XA\n^FO50,50^FDTEST^FS\n^XZ";
        $result = $service->ensurePrintSpeed($zpl, 4);

        $this->assertStringContainsString('^PR4,4,4', $result);
    }

    public function test_ensure_print_speed_updates_existing_pr(): void
    {
        $service = new ZebraPrintService;
        $zpl = "^XA\n^PR3,3,3\n^FO50,50^FDTEST^FS\n^XZ";
        $result = $service->ensurePrintSpeed($zpl, 6);

        $this->assertStringContainsString('^PR6,6,6', $result);
    }
}
