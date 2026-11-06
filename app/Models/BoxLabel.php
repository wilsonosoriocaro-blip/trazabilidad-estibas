<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One printed box label with its own code. Append-only (database trigger).
 *
 * @property int $id
 * @property int $box_label_batch_id
 * @property int $production_order_id
 * @property string $lot_code
 * @property int $sequence
 * @property string $code
 * @property string|null $qr_token null on labels printed before the QR carried only the token
 * @property CarbonImmutable $created_at
 */
class BoxLabel extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'sequence' => 'integer', 'run_number' => 'integer'];
    }

    /**
     * @return BelongsTo<BoxLabelBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(BoxLabelBatch::class, 'box_label_batch_id');
    }
}
