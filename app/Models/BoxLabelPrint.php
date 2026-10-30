<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A batch of box labels sent to the Zebra (append-only).
 *
 * @property int $id
 * @property int $box_label_batch_id
 * @property int $production_order_id
 * @property string $lot_code
 * @property int $labels
 * @property string $printer
 * @property int $printed_by
 * @property Carbon $printed_at
 */
class BoxLabelPrint extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'labels' => 'integer',
            'printed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BoxLabelBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(BoxLabelBatch::class, 'box_label_batch_id');
    }

    /**
     * Whether labels of this lot of the order were already printed on the Zebra.
     */
    public static function lotPrinted(int $productionOrderId, string $lotCode): bool
    {
        return self::query()->where('production_order_id', $productionOrderId)->where('lot_code', $lotCode)->exists();
    }
}
