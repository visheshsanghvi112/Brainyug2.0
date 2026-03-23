<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Estimate</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 18px;
            color: #111;
            font-size: 12px;
        }

        h2 {
            margin: 0 0 8px;
        }

        .meta {
            margin-bottom: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            border: 1px solid #cfcfcf;
            padding: 6px;
        }

        th {
            background: #f5f5f5;
            text-align: left;
        }

        .text-right {
            text-align: right;
        }

        .totals {
            margin-top: 10px;
            max-width: 360px;
            margin-left: auto;
            font-size: 12px;
        }

        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
        }

        .totals-row.grand {
            border-top: 1px solid #cfcfcf;
            margin-top: 3px;
            padding-top: 5px;
            font-weight: 700;
        }

        @media print {
            @page {
                margin: 8mm;
            }
        }
    </style>
</head>
<body>
    <h2>{{ $shop_name }} - Estimate</h2>
    <div class="meta">
        <div>Tab: {{ $estimate['tab_code'] ?? 'POS' }}</div>
        <div>Customer: {{ $estimate['customer_name'] ?? 'Walk-in' }}</div>
        <div>Mobile: {{ $estimate['customer_mobile'] ?? '-' }}</div>
        <div>Generated: {{ now()->format('d-m-Y H:i:s') }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Product</th>
                <th>Batch</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Rate</th>
                <th class="text-right">GST%</th>
                <th class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($estimate['items'] as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item['product_name'] }}</td>
                    <td>{{ $item['batch_no'] ?? '' }}</td>
                    <td class="text-right">{{ number_format((float) $item['qty'], 2) }}</td>
                    <td class="text-right">{{ number_format((float) $item['rate'], 2) }}</td>
                    <td class="text-right">{{ number_format((float) ($item['gst_percent'] ?? 0), 2) }}</td>
                    <td class="text-right">{{ number_format((float) $item['total_amount'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div class="totals-row"><span>Subtotal</span><span>{{ number_format((float) $estimate['totals']['sub_total'], 2) }}</span></div>
        <div class="totals-row"><span>Discount</span><span>{{ number_format((float) $estimate['totals']['discount_total'], 2) }}</span></div>
        <div class="totals-row"><span>Tax</span><span>{{ number_format((float) $estimate['totals']['tax_amount'], 2) }}</span></div>
        <div class="totals-row"><span>Other Charges</span><span>{{ number_format((float) ($estimate['totals']['other_charges'] ?? 0), 2) }}</span></div>
        <div class="totals-row"><span>Round Off</span><span>{{ number_format((float) ($estimate['totals']['round_off'] ?? 0), 2) }}</span></div>
        <div class="totals-row grand"><span>Grand Total</span><span>{{ number_format((float) $estimate['totals']['total'], 2) }}</span></div>
    </div>

    <script>
        window.setTimeout(function () {
            window.print();
        }, 300);
    </script>
</body>
</html>
