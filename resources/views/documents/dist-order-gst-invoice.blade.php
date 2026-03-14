<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GST Invoice - {{ $order->order_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }
        h1 { margin: 0; font-size: 20px; }
        .muted { color: #4b5563; font-size: 11px; }
        .header, .block { margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 6px 8px; }
        th { background: #f3f4f6; text-transform: uppercase; font-size: 11px; text-align: left; }
        td.right { text-align: right; }
        .summary { width: 45%; margin-left: auto; margin-top: 10px; }
        .summary td { border: 1px solid #d1d5db; }
        .summary .label { background: #f9fafb; }
        .summary .strong { font-weight: 700; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Tax Invoice</h1>
        <div class="muted">Order Ref: {{ $order->order_number }} | Dispatch Status: {{ strtoupper($order->status) }}</div>
        <div class="muted">Invoice No: {{ $order->invoice_number ?: 'NA' }} | E-Bill: {{ $order->ebill_number ?: 'NA' }}</div>
        <div class="muted">Dispatch Date: {{ optional($order->dispatch_date)->format('d M Y') ?? '-' }} | Generated: {{ now()->format('d M Y h:i A') }}</div>
    </div>

    <div class="block">
        <table>
            <tr>
                <td style="width: 50%; vertical-align: top;">
                    <strong>Bill To</strong><br>
                    {{ optional($order->franchisee)->shop_name ?? '-' }}<br>
                    {{ optional($order->franchisee)->shop_code ?? '-' }}<br>
                    {{ optional($order->franchisee)->address ?? '-' }}
                </td>
                <td style="width: 50%; vertical-align: top;">
                    <strong>Dispatch Details</strong><br>
                    Courier: {{ $order->courier_name ?: '-' }}<br>
                    Tracking: {{ $order->tracking_number ?: '-' }}<br>
                    Sales User: {{ optional($order->user)->name ?? '-' }}
                </td>
            </tr>
        </table>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th style="width: 28%;">Product</th>
                <th style="width: 12%;">Batch</th>
                <th style="width: 9%;">Qty</th>
                <th style="width: 10%;">Rate</th>
                <th style="width: 8%;">Disc%</th>
                <th style="width: 8%;">GST%</th>
                <th style="width: 11%;">Taxable</th>
                <th style="width: 10%;">GST</th>
                <th style="width: 10%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($order->items as $idx => $item)
                <tr>
                    <td>{{ $idx + 1 }}</td>
                    <td>{{ optional($item->product)->product_name ?? '-' }}</td>
                    <td>{{ $item->batch_no ?: '-' }}</td>
                    <td class="right">{{ number_format((float) $item->approved_qty, 2) }}</td>
                    <td class="right">{{ number_format((float) $item->rate, 2) }}</td>
                    <td class="right">{{ number_format((float) $item->discount_percent, 2) }}</td>
                    <td class="right">{{ number_format((float) $item->gst_percent, 2) }}</td>
                    <td class="right">{{ number_format((float) $item->taxable_amount, 2) }}</td>
                    <td class="right">{{ number_format((float) $item->gst_amount, 2) }}</td>
                    <td class="right">{{ number_format((float) $item->total_amount, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" style="text-align: center;">No order lines found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="summary">
        <tr>
            <td class="label">Taxable Total</td>
            <td class="right">{{ number_format((float) $summary['taxable_total'], 2) }}</td>
        </tr>
        <tr>
            <td class="label">CGST</td>
            <td class="right">{{ number_format((float) $summary['cgst_total'], 2) }}</td>
        </tr>
        <tr>
            <td class="label">SGST</td>
            <td class="right">{{ number_format((float) $summary['sgst_total'], 2) }}</td>
        </tr>
        <tr>
            <td class="label">IGST</td>
            <td class="right">{{ number_format((float) $summary['igst_total'], 2) }}</td>
        </tr>
        <tr>
            <td class="label">Round Off</td>
            <td class="right">{{ number_format((float) $summary['round_off'], 2) }}</td>
        </tr>
        <tr>
            <td class="label strong">Net Invoice Value</td>
            <td class="right strong">{{ number_format((float) $summary['net_total'], 2) }}</td>
        </tr>
    </table>
</body>
</html>
