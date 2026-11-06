<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProductionOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Production order (local read cache of SAP).
 *
 * @property CarbonImmutable|null $order_date
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $ready_to_close_notified_at
 * @property CarbonImmutable|null $sap_closed_at
 */
class ProductionOrder extends Model
{
    /** @use HasFactory<ProductionOrderFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
            'synced_at' => 'datetime',
            'order_date' => 'immutable_date',
            'start_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'sap_header' => 'array',
            'consulted_at' => 'datetime',
            'ready_to_close_notified_at' => 'datetime',
            'sap_closed_at' => 'datetime',
            'last_box_sequence' => 'integer',
            'current_box_run' => 'integer',
            'last_box_labels_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return HasMany<BoxLabelBatch, $this>
     */
    public function boxLabelBatches(): HasMany
    {
        return $this->hasMany(BoxLabelBatch::class);
    }

    /**
     * @return HasMany<Pallet, $this>
     */
    public function pallets(): HasMany
    {
        return $this->hasMany(Pallet::class);
    }
}
