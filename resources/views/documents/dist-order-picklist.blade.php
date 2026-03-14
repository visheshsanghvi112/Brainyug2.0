<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Picklist - {{ $order->order_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2937; }
        .header { margin-bottom: 14px; }
        .title { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
        .meta { font-size: 11px; color: #4b5563; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #d1d5db; padding: 6px 8px; }
        th { background: #f3f4f6; text-align: left; font-size: 11px; text-transform: uppercase; }
        td.num { text-align: right; }
        .footer { margin-top: 12px; font-size: 11px; color: #4b5563; }
        .summary { margin-top: 10px; width: 55%; }
        .summary td { border: none; padding: 3px 0; }
        .summary .label { color: #4b5563; }
        .summary .value { text-align: right; font-weight: 700; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">Dispatch Picklist</div>
        <div class="meta">Order: {{ $order->order_number }} | Status: {{ strtoupper($order->status) }}</div>
        <div class="meta">Franchisee: {{ optional($order->franchisee)->shop_name ?? '-' }} | Shop Code: {{ optional($order->franchisee)->shop_code ?? '-' }}</div>
        <div class="meta">Generated: {{ now()->format('d M Y h:i A') }} | Dispatch Date: {{ optional($order->dispatch_date)->format('d M Y') ?? '-' }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th style="width: 24%;">Product</th>
                <th style="width: 11%;">SKU</th>
                <th style="width: 12%;">Batch</th>
                <th style="width: 10%;">Req Qty</th>
                <th style="width: 10%;">Approved</th>
                <th style="width: 8%;">Free</th>
                <th style="width: 11%;">Pick Qty</th>
                <th style="width: 10%;">MRP</th>
            </tr>
        </thead>
        <tbody>
            @forelse($order->items as $idx => $item)
                @php
                    $approved = (float) ($item->approved_qty ?? 0);
                    $free = (float) ($item->free_qty ?? 0);
                @endphp
                <tr>
                    <td>{{ $idx + 1 }}</td>
                    <td>{{ optional($item->product)->product_name ?? '-' }}</td>
                    <td>{{ optional($item->product)->sku ?? '-' }}</td>
                    <td>{{ $item->batch_no ?: '-' }}</td>
                    <td class="num">{{ number_format((float) $item->request_qty, 2) }}</td>
                    <td class="num">{{ number_format($approved, 2) }}</td>
                    <td class="num">{{ number_format($free, 2) }}</td>
                    <td class="num">{{ number_format($approved + $free, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->mrp, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" style="text-align: center;">No order lines found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="summary">
        <tr>
            <td class="label">Total Lines</td>
            <td class="value">{{ $totals['line_count'] }}</td>
        </tr>
        <tr>
            <td class="label">Requested Qty</td>
            <td class="value">{{ number_format((float) $totals['requested_qty'], 2) }}</td>
        </tr>
        <tr>
            <td class="label">Approved Qty</td>
            <td class="value">{{ number_format((float) $totals['approved_qty'], 2) }}</td>
        </tr>
        <tr>
            <td class="label">Free Qty</td>
            <td class="value">{{ number_format((float) $totals['free_qty'], 2) }}</td>
        </tr>
        <tr>
            <td class="label">Total Pick Qty</td>
            <td class="value">{{ number_format((float) $totals['approved_qty'] + (float) $totals['free_qty'], 2) }}</td>
        </tr>
    </table>

    <div class="footer">
        Packed By: ____________________ &nbsp;&nbsp; Checked By: ____________________ &nbsp;&nbsp; Dispatch Approved By: ____________________
    </div>
</body>
</html>
