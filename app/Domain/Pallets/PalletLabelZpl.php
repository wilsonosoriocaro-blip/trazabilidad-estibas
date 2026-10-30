<?php

namespace App\Domain\Pallets;

use App\Domain\BoxLabels\LotDates;
use App\Models\Pallet;
use App\Models\PalletLabelPrint;
use Carbon\CarbonImmutable;

/**
 * ZPL II para la etiqueta térmica de estiba (pallet) en Zebra ZT411 (203 dpi, 8 dpmm).
 * Formato vertical (portrait) ocupando los 100 × 80 mm (800 × 640 puntos) mediante
 * rotación de 90° (^FWR), permitiendo que la etiqueta se lea verticalmente con:
 *   - Código de estiba destacado arriba (EST-XXXXXXXX)
 *   - Código QR de alta visibilidad en el centro
 *   - Datos completos de Orden, Producto (PT y descripción), Lote, Vencimiento
 *   - Cantidad de cajas en formato grande y legible
 *   - Metadatos de impresión y motivo de reimpresión si aplica
 */
final class PalletLabelZpl
{
    public const WIDTH_DOTS = 800;

    public const HEIGHT_DOTS = 640;

    public static function render(Pallet $pallet, ?PalletLabelPrint $print = null, ?CarbonImmutable $expiration = null): string
    {
        if ($pallet->exists && ! $pallet->relationLoaded('item')) {
            $pallet->loadMissing('item:id,description');
        }

        $palletCode = self::field($pallet->pallet_code);
        $qrPayload = PalletQr::payload($pallet);
        $orderRef = self::field($pallet->production_order_ref);
        $itemCode = self::field($pallet->item_code);
        $itemDesc = self::field($pallet->relationLoaded('item') ? ($pallet->item?->description ?? '') : ($pallet->exists ? ($pallet->item?->description ?? '') : ''));
        $lotCode = self::field($pallet->lot_code);

        $expiration = $expiration ?? ($pallet->exists ? LotDates::expiration($pallet->production_order_id, $pallet->lot_code) : null);
        $expirationFormatted = $expiration ? $expiration->format('m/Y') : '—';

        $printNumber = $print?->print_number ?? max(1, $pallet->label_print_count);
        $isReprint = $printNumber > 1;
        $tz = function_exists('config') && app()->bound('config') ? config('app.timezone', 'America/Bogota') : 'America/Bogota';
        $printDate = ($print?->printed_at ?? now())->timezone($tz)->format('d/m/Y H:i');
        $reason = $print?->reason ? self::field($print->reason) : null;

        $lines = [
            '^XA',
            '^CI28',
            '^PW'.self::WIDTH_DOTS,
            '^LL'.self::HEIGHT_DOTS,
            '^LS0',
            '^FWR', // Rotación a 90° para orientación vertical
            // 1. Cabecera vertical: Código de la estiba centrado
            '^FO730,30^FB580,1,0,C^A0R,54,52^FD'.$palletCode.'^FS',
            // 2. Código QR centrado horizontalmente
            '^FO475,200^BQR,2,7^FH_^FDMA,'.self::hexField($qrPayload).'^FS',
            // 3. Línea divisoria superior
            '^FO450,35^GB2,570,2^FS',
            // 4. Datos de la orden y producto
            '^FO400,40^A0R,36,36^FDORDEN: '.$orderRef.'^FS',
            '^FO350,40^A0R,36,36^FDPRODUCTO: '.$itemCode.'^FS',
        ];

        // Descripción dinámica (1 o 2 líneas según longitud)
        $descLen = mb_strlen($itemDesc);
        if ($descLen > 50) {
            $lines[] = '^FO300,40^FB560,2,2,L^A0R,26,26^FD'.$itemDesc.'^FS';
            $currentX = 235;
        } elseif ($descLen > 30) {
            $lines[] = '^FO300,40^FB560,2,2,L^A0R,28,28^FD'.$itemDesc.'^FS';
            $currentX = 235;
        } else {
            $lines[] = '^FO300,40^FB560,1,0,L^A0R,30,30^FD'.$itemDesc.'^FS';
            $currentX = 245;
        }

        $lines[] = '^FO'.$currentX.',40^A0R,36,36^FDLOTE: '.$lotCode.'^FS';
        $currentX -= 55;
        $lines[] = '^FO'.$currentX.',40^A0R,36,36^FDVENCE: '.$expirationFormatted.'^FS';

        // 5. Línea divisoria inferior
        $currentX -= 40;
        $lines[] = '^FO'.$currentX.',35^GB2,570,2^FS';

        // 6. Pie de página: Metadatos de impresión y motivo de reimpresión si aplica
        $currentX -= 35;
        $metaText = 'Impresion #'.$printNumber.' - '.$printDate;
        if ($isReprint) {
            $lines[] = '^FO'.$currentX.',40^A0R,24,24^FD'.$metaText.'^FS';
            $currentX -= 30;
            $reasonText = $reason ? 'REIMPRESION: '.mb_substr($reason, 0, 45) : '*** REIMPRESION ***';
            $lines[] = '^FO'.$currentX.',40^A0R,26,26^FD'.$reasonText.'^FS';
        } else {
            $lines[] = '^FO'.$currentX.',40^A0R,24,24^FD'.$metaText.'^FS';
        }

        $lines[] = '^PQ1,0,1,Y';
        $lines[] = '^XZ';

        return implode("\n", $lines)."\n";
    }

    private static function field(string $text): string
    {
        return trim(str_replace(['^', '~', "\r", "\n"], ' ', $text));
    }

    private static function hexField(string $text): string
    {
        return str_replace(['_', "\n"], ['_5F', '_0A'], str_replace(['^', '~', "\r"], ' ', $text));
    }
}
