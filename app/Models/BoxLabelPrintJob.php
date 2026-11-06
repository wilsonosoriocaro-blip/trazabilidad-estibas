<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trabajo de impresión gestionado para lotes de etiquetas de caja.
 * Permite la transmisión en sub-lotes (chunks) para no desbordar el búfer de la Zebra
 * y detecta exactamente cuántas etiquetas se imprimieron físicamente en caso de
 * falta de papel, atascamiento, cabezal abierto o cinta agotada.
 *
 * @property int $id
 * @property int $box_label_batch_id
 * @property int $production_order_id
 * @property string $lot_code
 * @property int $total_labels
 * @property int $printed_labels
 * @property int $pending_labels
 * @property int $start_sequence
 * @property int $end_sequence
 * @property int $last_printed_sequence
 * @property int $next_sequence
 * @property string $status
 * @property string|null $error_reason
 * @property string $printer_ip
 * @property int $printer_port
 * @property int $chunk_size
 * @property int|null $start_odometer
 * @property int|null $last_odometer
 * @property int $created_by
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class BoxLabelPrintJob extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_labels' => 'integer',
            'printed_labels' => 'integer',
            'pending_labels' => 'integer',
            'start_sequence' => 'integer',
            'end_sequence' => 'integer',
            'last_printed_sequence' => 'integer',
            'next_sequence' => 'integer',
            'printer_port' => 'integer',
            'chunk_size' => 'integer',
            'start_odometer' => 'integer',
            'last_odometer' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
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
     * @return BelongsTo<ProductionOrder, $this>
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPendingOrActive(): bool
    {
        return in_array($this->status, ['pending', 'printing', 'paused_error'], true);
    }

    public function progressPercent(): int
    {
        if ($this->total_labels <= 0) {
            return 0;
        }

        return min(100, (int) round(($this->printed_labels / $this->total_labels) * 100));
    }
}
