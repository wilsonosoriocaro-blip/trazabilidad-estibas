<?php

namespace Tests\Unit\Domain\BoxLabels;

use App\Domain\BoxLabels\Actions\GenerateBoxLabels;
use App\Domain\BoxLabels\Exceptions\BoxLabelRuleViolation;
use App\Models\BoxLabelPrintJob;
use App\Services\ZebraPrintService;
use PHPUnit\Framework\TestCase;

class BoxLabelPrintJobTest extends TestCase
{
    public function test_max_labels_constant_is_3000(): void
    {
        $this->assertSame(3000, GenerateBoxLabels::MAX_LABELS);
    }

    public function test_quantity_exceeds_maximum_exception(): void
    {
        $e = BoxLabelRuleViolation::quantityExceedsMaximum(3000);
        $this->assertStringContainsString('3000', $e->getMessage());
    }

    public function test_split_zpl_into_labels(): void
    {
        $service = new ZebraPrintService;
        $zpl = "^XA\n^FO50,50^A0N,30,30^FDLabel 1^FS\n^XZ\n^XA\n^FO50,50^A0N,30,30^FDLabel 2^FS\n^XZ";

        $labels = $service->splitZplIntoLabels($zpl);

        $this->assertCount(2, $labels);
        $this->assertStringContainsString('Label 1', $labels[0]);
        $this->assertStringContainsString('Label 2', $labels[1]);
    }

    public function test_print_job_progress_percentage(): void
    {
        $job = new BoxLabelPrintJob([
            'total_labels' => 100,
            'printed_labels' => 45,
            'status' => 'printing',
        ]);

        $this->assertSame(45, $job->progressPercent());
        $this->assertTrue($job->isPendingOrActive());

        $job->status = 'paused_error';
        $this->assertTrue($job->isPendingOrActive());

        $job->status = 'completed';
        $this->assertFalse($job->isPendingOrActive());
    }
}
