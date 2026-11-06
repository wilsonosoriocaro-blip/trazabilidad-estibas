<?php

namespace Tests\Unit\Domain;

use App\Domain\BoxLabels\Actions\GenerateBoxLabels;
use App\Domain\BoxLabels\BoxLot;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class BoxLotTest extends TestCase
{
    public function test_box_lot_code_formats_with_julian_day(): void
    {
        $date = CarbonImmutable::parse('2026-10-20');
        $code = BoxLot::code('33128', $date);

        $this->assertSame('33128293', $code);
    }

    public function test_box_code_pads_sequence_to_four_digits(): void
    {
        $this->assertSame('33128293-0001', BoxLot::boxCode('33128293', 1));
        $this->assertSame('33128293-0056', BoxLot::boxCode('33128293', 56));
        $this->assertSame('33128293-0057', BoxLot::boxCode('33128293', 57));
        $this->assertSame('33128293-0156', BoxLot::boxCode('33128293', 156));
        $this->assertSame('33128293-1000', BoxLot::boxCode('33128293', 1000));
    }

    public function test_max_labels_calculation(): void
    {
        // 100 planned, 24 per box -> 5 boxes
        $this->assertSame(5, GenerateBoxLabels::maxLabels('100', '24'));
        // 96 planned, 24 per box -> 4 boxes
        $this->assertSame(4, GenerateBoxLabels::maxLabels('96', '24'));
        // 1 planned, 24 per box -> 1 box
        $this->assertSame(1, GenerateBoxLabels::maxLabels('1', '24'));
    }
}
