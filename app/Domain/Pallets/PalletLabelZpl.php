<?php

namespace App\Domain\Pallets;

use App\Domain\BoxLabels\LabelFormatConfig;
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
 *   - Metadatos de impresión y motivo de reimpresión si aplica
 */
final class PalletLabelZpl
{
    public const WIDTH_DOTS = 800;

    public const HEIGHT_DOTS = 640;

    /**
     * Renderiza la etiqueta ZPL para la estiba.
     *
     * @param  array<string, mixed>|null  $overrideConfig
     */
    public static function render(Pallet $pallet, ?PalletLabelPrint $print = null, ?CarbonImmutable $expiration = null, ?array $overrideConfig = null): string
    {
        $cfg = $overrideConfig ?? LabelFormatConfig::pallet();

        if ($pallet->exists && ! $pallet->relationLoaded('item')) {
            $pallet->loadMissing('item:id,description');
        }

        $palletCode = self::field($pallet->pallet_code);
        $qrPayload = PalletQr::payload($pallet);
        $orderRef = self::field($pallet->production_order_ref);
        $itemCode = self::field($pallet->item_code);
        $itemDesc = self::field($pallet->item->description ?? '');
        $lotCode = self::field($pallet->lot_code);

        $expiration = $expiration ?? ($pallet->exists ? LotDates::expiration($pallet->production_order_id, $pallet->lot_code) : null);
        $expirationFormatted = $expiration ? $expiration->format('m/Y') : '—';

        $printNumber = $print !== null ? $print->print_number : max(1, $pallet->label_print_count);
        $isReprint = $printNumber > 1;
        $tz = function_exists('config') && app()->bound('config') ? config('app.timezone', 'America/Bogota') : 'America/Bogota';
        $printDate = ($print !== null ? CarbonImmutable::parse($print->printed_at) : now())->timezone($tz)->format('d/m/Y H:i');
        $reason = $print?->reason ? self::field($print->reason) : null;

        $width = (int) ($cfg['width_dots'] ?? self::WIDTH_DOTS);
        $height = (int) ($cfg['height_dots'] ?? self::HEIGHT_DOTS);
        $speed = (int) ($cfg['print_speed'] ?? LabelFormatConfig::printSpeed());
        $speed = max(2, min(14, $speed));
        $detailsFont = (int) ($cfg['details_font'] ?? 36);
        $textY = (int) ($cfg['text_y'] ?? 40);

        $lines = [
            '^XA',
            '^CI28',
            '^PW'.$width,
            '^LL'.$height,
            '^LS0',
            '^PR'.$speed.','.$speed.','.$speed,
            '^FWR', // Rotación a 90° para orientación vertical
            // 1. Cabecera vertical: Código de la estiba centrado
            '^FO'.($cfg['pallet_code_x'] ?? 730).','.($cfg['pallet_code_y'] ?? 30).'^FB'.($cfg['pallet_code_w'] ?? 580).',1,0,C^A0R,'.($cfg['pallet_code_font_h'] ?? 54).','.($cfg['pallet_code_font_w'] ?? 52).'^FD'.$palletCode.'^FS',
            // 2. Código QR centrado horizontalmente
            '^FO'.($cfg['qr_x'] ?? 475).','.($cfg['qr_y'] ?? 200).'^BQR,2,'.($cfg['qr_mag'] ?? 7).'^FH_^FDMA,'.self::hexField($qrPayload).'^FS',
            // 3. Línea divisoria superior
            '^FO'.($cfg['div1_x'] ?? 450).',35^GB2,570,2^FS',
            // 4. Datos de la orden y producto
            '^FO'.($cfg['order_x'] ?? 400).','.$textY.'^A0R,'.$detailsFont.','.$detailsFont.'^FDORDEN: '.$orderRef.'^FS',
            '^FO'.($cfg['item_x'] ?? 350).','.$textY.'^A0R,'.$detailsFont.','.$detailsFont.'^FDPRODUCTO: '.$itemCode.'^FS',
        ];

        // Descripción dinámica (1 o 2 líneas según longitud)
        $descLen = mb_strlen($itemDesc);
        if ($descLen > 50) {
            $descFont = (int) ($cfg['desc_font_long'] ?? 26);
            $lines[] = '^FO'.($cfg['desc_x'] ?? 300).','.$textY.'^FB560,2,2,L^A0R,'.$descFont.','.$descFont.'^FD'.$itemDesc.'^FS';
            $currentX = (int) ($cfg['lot_x'] ?? 235);
        } elseif ($descLen > 30) {
            $descFont = (int) ($cfg['desc_font'] ?? 28);
            $lines[] = '^FO'.($cfg['desc_x'] ?? 300).','.$textY.'^FB560,2,2,L^A0R,'.$descFont.','.$descFont.'^FD'.$itemDesc.'^FS';
            $currentX = (int) ($cfg['lot_x'] ?? 235);
        } else {
            $descFont = (int) ($cfg['desc_font'] ?? 30);
            $lines[] = '^FO'.($cfg['desc_x'] ?? 300).','.$textY.'^FB560,1,0,L^A0R,'.$descFont.','.$descFont.'^FD'.$itemDesc.'^FS';
            $currentX = (int) ($cfg['lot_x'] ?? 245);
        }

        $lines[] = '^FO'.$currentX.','.$textY.'^A0R,'.$detailsFont.','.$detailsFont.'^FDLOTE: '.$lotCode.'^FS';
        $venceX = (int) ($cfg['vence_x'] ?? ($currentX - 55));
        $lines[] = '^FO'.$venceX.','.$textY.'^A0R,'.$detailsFont.','.$detailsFont.'^FDVENCE: '.$expirationFormatted.'^FS';

        // 5. Línea divisoria inferior
        $div2X = (int) ($cfg['div2_x'] ?? 140);
        $lines[] = '^FO'.$div2X.',35^GB2,570,2^FS';

        // 6. Pie de página: Metadatos de impresión y motivo de reimpresión si aplica
        $footerX = (int) ($cfg['footer_x'] ?? 105);
        $footerFont = (int) ($cfg['footer_font'] ?? 24);
        $metaText = 'Impresion #'.$printNumber.' - '.$printDate;
        if ($isReprint) {
            $lines[] = '^FO'.$footerX.',40^A0R,'.$footerFont.','.$footerFont.'^FD'.$metaText.'^FS';
            $reasonX = $footerX - 30;
            $reasonText = $reason ? 'REIMPRESION: '.mb_substr($reason, 0, 45) : '*** REIMPRESION ***';
            $lines[] = '^FO'.$reasonX.',40^A0R,'.($footerFont + 2).','.($footerFont + 2).'^FD'.$reasonText.'^FS';
        } else {
            $lines[] = '^FO'.$footerX.',40^A0R,'.$footerFont.','.$footerFont.'^FD'.$metaText.'^FS';
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
