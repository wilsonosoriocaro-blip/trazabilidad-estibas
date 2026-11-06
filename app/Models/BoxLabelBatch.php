<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BoxLabelBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One generated set of box labels: all identical, for one production
 * order, lot and production date. Append-only (database trigger).
 *
 * @property int $id
 * @property int $production_order_id
 * @property int $item_id
 * @property int $box_conversion_factor_id
 * @property string $production_order_ref
 * @property string $item_code
 * @property string $item_description
 * @property string $lot_code
 * @property CarbonImmutable $production_date
 * @property CarbonImmutable $expiration_date
 * @property string $units_per_box
 * @property string|null $uom_name
 * @property string $barcode
 * @property int $labels
 * @property string $qr_payload
 * @property string $zpl
 * @property int $generated_by
 * @property CarbonImmutable $generated_at
 */
class BoxLabelBatch extends Model
{
    /** @use HasFactory<BoxLabelBatchFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'production_date' => 'immutable_date',
            'expiration_date' => 'immutable_date',
            'generated_at' => 'datetime',
            'labels' => 'integer',
            'first_sequence' => 'integer',
            'last_sequence' => 'integer',
            'run_number' => 'integer',
            'is_reset' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ProductionOrder, $this>
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * @return HasMany<BoxLabel, $this>
     */
    public function boxLabels(): HasMany
    {
        return $this->hasMany(BoxLabel::class, 'box_label_batch_id');
    }

    /**
     * @return HasMany<BoxLabelPrint, $this>
     */
    public function prints(): HasMany
    {
        return $this->hasMany(BoxLabelPrint::class, 'box_label_batch_id');
    }

    /**
     * @return HasMany<BoxLabelPrintJob, $this>
     */
    public function printJobs(): HasMany
    {
        return $this->hasMany(BoxLabelPrintJob::class, 'box_label_batch_id');
    }

    /**
     * @return HasOne<BoxLabelPrintJob, $this>
     */
    public function latestPrintJob(): HasOne
    {
        return $this->hasOne(BoxLabelPrintJob::class, 'box_label_batch_id')->latestOfMany();
    }
}
