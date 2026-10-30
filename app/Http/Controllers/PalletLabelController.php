<?php

namespace App\Http\Controllers;

use App\Domain\BoxLabels\LotDates;
use App\Domain\Pallets\PalletLabelZpl;
use App\Domain\Pallets\PalletQr;
use App\Models\Pallet;
use App\Models\PalletLabelPrint;
use App\Services\ZebraPrintService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * Printable label for a print already recorded by PrintLabel. Viewing it
 * changes nothing; opening it again shows the same print number, so a
 * reprint always goes through PrintLabel and its reason.
 */
class PalletLabelController extends Controller
{
    public function show(Pallet $pallet, int $print): View
    {
        $record = PalletLabelPrint::query()->where('pallet_id', $pallet->id)->where('print_number', $print)->firstOrFail();
        $pallet->loadMissing('item:id,description');
        $expiration = LotDates::expiration($pallet->production_order_id, $pallet->lot_code);
        $zpl = PalletLabelZpl::render($pallet, $record, $expiration);

        return view('labels.pallet', [
            'pallet' => $pallet,
            'print' => $record,
            'qrSvg' => PalletQr::svg($pallet),
            'expiration' => $expiration,
            'widthMm' => config()->integer('traceability.label.width_mm'),
            'heightMm' => config()->integer('traceability.label.height_mm'),
            'zpl' => $zpl,
            'printerIp' => config('services.zebra.ip', env('ZEBRA_PRINTER_IP', '192.168.1.154')),
            'printerPort' => (int) config('services.zebra.port', env('ZEBRA_PRINTER_PORT', 9100)),
        ]);
    }

    public function zpl(Pallet $pallet, int $print): Response
    {
        $record = PalletLabelPrint::query()->where('pallet_id', $pallet->id)->where('print_number', $print)->firstOrFail();
        $pallet->loadMissing('item:id,description');
        $zpl = PalletLabelZpl::render($pallet, $record);

        return response($zpl, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$pallet->pallet_code.'-'.$print.'.zpl"',
        ]);
    }

    public function printDirect(Pallet $pallet, int $print, ZebraPrintService $zebra): RedirectResponse
    {
        $record = PalletLabelPrint::query()->where('pallet_id', $pallet->id)->where('print_number', $print)->firstOrFail();
        $pallet->loadMissing('item:id,description');
        $zpl = PalletLabelZpl::render($pallet, $record);

        $ip = (string) config('services.zebra.ip', env('ZEBRA_PRINTER_IP', '192.168.1.154'));
        $port = (int) config('services.zebra.port', env('ZEBRA_PRINTER_PORT', 9100));

        $res = $zebra->printToSocket($ip, $port, $zpl);

        if ($res['success']) {
            return back()->with('status', "Etiqueta {$pallet->pallet_code} enviada a Zebra ({$ip}).");
        }

        return back()->withErrors(['zebra' => $res['message']]);
    }
}
