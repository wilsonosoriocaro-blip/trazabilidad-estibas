<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Etiqueta {{ $pallet->pallet_code }}</title>
    {{-- Self-contained on purpose: prints the same without the app's assets. Final layout is DP-07. --}}
    <style>
        @page { size: {{ $widthMm }}mm {{ $heightMm }}mm; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; }
        .label { width: {{ $widthMm }}mm; height: {{ $heightMm }}mm; padding: 6mm; display: flex; flex-direction: column; gap: 3mm; border: 1px dashed #999; }
        .code { font-family: "Courier New", monospace; font-size: 9mm; font-weight: bold; text-align: center; letter-spacing: .5mm; }
        .qr { display: flex; justify-content: center; }
        .qr svg { width: 55mm; height: 55mm; }
        table { width: 100%; border-collapse: collapse; font-size: 4mm; }
        th { text-align: left; font-weight: normal; color: #333; width: 32%; padding: 1mm 0; vertical-align: top; }
        td { font-weight: bold; padding: 1mm 0; }
        .boxes { font-size: 8mm; }
        .meta { margin-top: auto; font-size: 3mm; color: #333; display: flex; justify-content: space-between; }
        .reprint { border: .6mm solid #000; padding: .5mm 2mm; font-weight: bold; }
        .actions { padding: 8px 16px; font-family: system-ui, sans-serif; display: flex; gap: 8px; align-items: center; background: #f4f4f5; border-bottom: 1px solid #e4e4e7; }
        .actions button, .actions a { font-size: 13px; font-weight: 500; padding: 6px 12px; border-radius: 6px; cursor: pointer; text-decoration: none; border: 1px solid #d4d4d8; background: #fff; color: #18181b; }
        .actions button.primary { background: #18181b; color: #fff; border-color: #18181b; }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 8px 16px; font-size: 13px; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; padding: 8px 16px; font-size: 13px; }
        @media print { .actions, .alert-success, .alert-error { display: none !important; } .label { border: 0; } }
    </style>
</head>
<body>
    @if (session('status'))
        <div class="alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->has('zebra'))
        <div class="alert-error">{{ $errors->first('zebra') }}</div>
    @endif

    <div class="actions">
        <form method="POST" action="{{ route('pallets.label.print-direct', ['pallet' => $pallet->id, 'print' => $print->print_number]) }}">
            @csrf
            <button type="submit" class="primary">Imprimir en Zebra ({{ $printerIp }})</button>
        </form>
        <a href="{{ route('pallets.label.zpl', ['pallet' => $pallet->id, 'print' => $print->print_number]) }}">Descargar ZPL</a>
        <button type="button" onclick="window.print()">Impresión navegador</button>
        <a href="{{ url()->previous() }}">Volver</a>
    </div>

    <main class="label">
        <div class="code">{{ $pallet->pallet_code }}</div>
        <div class="qr">{!! $qrSvg !!}</div>
        <table>
            <tr><th>Orden</th><td>{{ $pallet->production_order_ref }}</td></tr>
            <tr><th>Producto</th><td>{{ $pallet->item_code }}<br><span style="font-weight: normal">{{ $pallet->item->description }}</span></td></tr>
            <tr><th>Lote</th><td>{{ $pallet->lot_code }}</td></tr>
            <tr><th>Vence</th><td>{{ $expiration?->format('d/m/Y') ?? '—' }}</td></tr>
        </table>
        <div class="meta">
            <span>Impresión #{{ $print->print_number }} · {{ $print->printed_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span>
            @if ($print->print_number > 1)<span class="reprint">REIMPRESIÓN</span>@endif
        </div>
    </main>
</body>
</html>
