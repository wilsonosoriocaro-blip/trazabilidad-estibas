<?php

namespace App\Domain\BoxLabels;

use Illuminate\Support\Facades\File;

/**
 * Gestiona la configuración y ajustes visuales de las etiquetas ZPL
 * para cajas y estibas en impresoras Zebra ZT411 (100 × 80 mm a 203 DPI).
 *
 * Permite agrandar, reducir y reubicar elementos sin alterar la lógica de negocio.
 * Persiste los cambios en storage/app/label_format_config.json sin requerir migraciones.
 */
final class LabelFormatConfig
{
    private const FILE_PATH = 'app/label_format_config.json';

    /**
     * Obtiene la velocidad de impresión parametrizada (en pulgadas por segundo, 2 a 14 IPS).
     */
    public static function printSpeed(): int
    {
        $all = self::load();

        if (isset($all['print_speed']) && is_numeric($all['print_speed'])) {
            return (int) $all['print_speed'];
        }

        if (isset($all['box']['print_speed']) && is_numeric($all['box']['print_speed'])) {
            return (int) $all['box']['print_speed'];
        }

        if (function_exists('config') && app()->bound('config')) {
            return (int) config('services.zebra.speed', 6);
        }

        return 6;
    }

    /**
     * Guarda la velocidad de impresión parametrizada (persiste en JSON sin requerir migraciones).
     */
    public static function setPrintSpeed(int $speed): void
    {
        $speed = max(2, min(14, $speed));
        $all = self::load();
        $all['print_speed'] = $speed;
        self::save($all);
    }

    /**
     * Valores por defecto para la etiqueta de caja (100 × 80 mm a 203 DPI = 800 × 640 puntos).
     *
     * @return array<string, mixed>
     */
    public static function defaultBox(): array
    {
        return [
            'width_dots' => 800,
            'height_dots' => 640,
            'dpmm' => 8, // 8 dpmm = 203 DPI
            'scale' => 100, // Escala general en %
            'print_speed' => self::printSpeed(),
            // Código PT
            'pt_font_h' => 54,
            'pt_font_w' => 56,
            'pt_x' => 30,
            'pt_y' => 18,
            'pt_w' => 740,
            // Descripción del producto
            'desc_font_h' => 38,
            'desc_font_w' => 40,
            'desc_font_h_long' => 30,
            'desc_font_w_long' => 32,
            'desc_x' => 30,
            'desc_y' => 76,
            'desc_w' => 740,
            'desc_long_threshold' => 50,
            // Bloque Lote / Vence / Caja Unidades
            'details_font_size' => 40,
            'details_x' => 35,
            'lot_y' => 190,
            'vence_y' => 248,
            'caja_units_y' => 306,
            // Código QR
            'qr_mag' => 5,
            'qr_x' => 550,
            'qr_y' => 140,
            // Logo Tecnoal
            'logo_show' => true,
            'logo_x' => 535,
            'logo_y' => 430,
            // Código Caja (debajo de Tecnoal)
            'box_code_font_h' => 28,
            'box_code_font_w' => 22,
            'box_code_x' => 530,
            'box_code_y' => 560,
            // Código de barras EAN-14 (ITF-14 / ^B2)
            'barcode_height' => 90,
            'barcode_by_w' => 3,
            'barcode_x' => 40,
            'barcode_y' => 440,
            'barcode_text_font' => 28,
            'barcode_text_x' => 40,
            'barcode_text_y' => 560,
            'barcode_text_w' => 460,
        ];
    }

    /**
     * Valores por defecto para la etiqueta de estiba (Pallet 100 × 80 mm en orientación vertical ^FWR).
     *
     * @return array<string, mixed>
     */
    public static function defaultPallet(): array
    {
        return [
            'width_dots' => 800,
            'height_dots' => 640,
            'dpmm' => 8,
            'scale' => 100,
            'print_speed' => self::printSpeed(),
            // Código de estiba (EST-XXXXXXXX)
            'pallet_code_font_h' => 54,
            'pallet_code_font_w' => 52,
            'pallet_code_x' => 730,
            'pallet_code_y' => 30,
            'pallet_code_w' => 580,
            // Código QR
            'qr_mag' => 7,
            'qr_x' => 475,
            'qr_y' => 200,
            // Divisores y bloques verticales
            'div1_x' => 450,
            'div2_x' => 140,
            'order_x' => 400,
            'item_x' => 350,
            'desc_x' => 300,
            'lot_x' => 235,
            'vence_x' => 180,
            'text_y' => 40,
            'details_font' => 36,
            'desc_font' => 28,
            'desc_font_long' => 26,
            'footer_font' => 24,
            'footer_x' => 105,
        ];
    }

    /**
     * Calcula la configuración de caja escalada armónicamente en porcentaje (ej: 85, 100, 115).
     *
     * @return array<string, mixed>
     */
    public static function getScaledBox(int $percent): array
    {
        $base = self::defaultBox();
        if ($percent === 100) {
            return $base;
        }

        $factor = max(0.5, min(1.8, $percent / 100.0));
        $scaled = $base;
        $scaled['scale'] = $percent;

        $scaled['pt_font_h'] = max(24, (int) round($base['pt_font_h'] * $factor));
        $scaled['pt_font_w'] = max(24, (int) round($base['pt_font_w'] * $factor));
        $scaled['desc_font_h'] = max(20, (int) round($base['desc_font_h'] * $factor));
        $scaled['desc_font_w'] = max(20, (int) round($base['desc_font_w'] * $factor));
        $scaled['desc_font_h_long'] = max(16, (int) round($base['desc_font_h_long'] * $factor));
        $scaled['desc_font_w_long'] = max(18, (int) round($base['desc_font_w_long'] * $factor));
        $scaled['details_font_size'] = max(22, (int) round($base['details_font_size'] * $factor));
        $scaled['box_code_font_h'] = max(16, (int) round($base['box_code_font_h'] * $factor));
        $scaled['box_code_font_w'] = max(14, (int) round($base['box_code_font_w'] * $factor));
        $scaled['barcode_text_font'] = max(16, (int) round($base['barcode_text_font'] * $factor));

        $scaled['barcode_height'] = max(40, min(150, (int) round($base['barcode_height'] * $factor)));

        if ($percent <= 80) {
            $scaled['qr_mag'] = 4;
        } elseif ($percent >= 120) {
            $scaled['qr_mag'] = 6;
        } else {
            $scaled['qr_mag'] = 5;
        }

        return $scaled;
    }

    /**
     * Calcula la configuración de estiba escalada armónicamente en porcentaje.
     *
     * @return array<string, mixed>
     */
    public static function getScaledPallet(int $percent): array
    {
        $base = self::defaultPallet();
        if ($percent === 100) {
            return $base;
        }

        $factor = max(0.5, min(1.8, $percent / 100.0));
        $scaled = $base;
        $scaled['scale'] = $percent;

        $scaled['pallet_code_font_h'] = max(26, (int) round($base['pallet_code_font_h'] * $factor));
        $scaled['pallet_code_font_w'] = max(26, (int) round($base['pallet_code_font_w'] * $factor));
        $scaled['details_font'] = max(20, (int) round($base['details_font'] * $factor));
        $scaled['desc_font'] = max(16, (int) round($base['desc_font'] * $factor));
        $scaled['desc_font_long'] = max(14, (int) round($base['desc_font_long'] * $factor));
        $scaled['footer_font'] = max(16, (int) round($base['footer_font'] * $factor));

        if ($percent <= 80) {
            $scaled['qr_mag'] = 5;
        } elseif ($percent >= 120) {
            $scaled['qr_mag'] = 8;
        } else {
            $scaled['qr_mag'] = 7;
        }

        return $scaled;
    }

    /**
     * Obtiene la configuración activa para etiquetas de caja.
     *
     * @return array<string, mixed>
     */
    public static function box(): array
    {
        $all = self::load();

        return array_merge(self::defaultBox(), $all['box'] ?? []);
    }

    /**
     * Obtiene la configuración activa para etiquetas de estiba.
     *
     * @return array<string, mixed>
     */
    public static function pallet(): array
    {
        $all = self::load();

        return array_merge(self::defaultPallet(), $all['pallet'] ?? []);
    }

    /**
     * Guarda la configuración de etiquetas de caja.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function saveBox(array $settings): void
    {
        $all = self::load();
        $all['box'] = array_merge(self::defaultBox(), $settings);
        self::save($all);
    }

    /**
     * Guarda la configuración de etiquetas de estiba.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function savePallet(array $settings): void
    {
        $all = self::load();
        $all['pallet'] = array_merge(self::defaultPallet(), $settings);
        self::save($all);
    }

    /**
     * Restablece la configuración de caja a los valores por defecto.
     */
    public static function resetBox(): void
    {
        $all = self::load();
        unset($all['box']);
        self::save($all);
    }

    /**
     * Restablece la configuración de estiba a los valores por defecto.
     */
    public static function resetPallet(): void
    {
        $all = self::load();
        unset($all['pallet']);
        self::save($all);
    }

    /**
     * Restablece toda la configuración a los valores originales.
     */
    public static function resetAll(): void
    {
        $path = storage_path(self::FILE_PATH);
        if (File::exists($path)) {
            File::delete($path);
        }
    }

    /**
     * Carga el archivo de configuración.
     *
     * @return array<string, mixed>
     */
    private static function load(): array
    {
        $path = storage_path(self::FILE_PATH);
        if (! File::exists($path)) {
            return [];
        }

        try {
            $json = File::get($path);
            $data = json_decode($json, true);

            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Guarda el archivo de configuración.
     *
     * @param  array<string, mixed>  $data
     */
    private static function save(array $data): void
    {
        $path = storage_path(self::FILE_PATH);
        $dir = dirname($path);

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0775, true, true);
        }

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($encoded)) {
            File::put($path, $encoded);
        }
    }
}
