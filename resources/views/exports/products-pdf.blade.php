<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Product Catalog</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #333; }
        .header { text-align: center; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #1F4E79; }
        .header h1 { font-size: 18px; color: #1F4E79; margin-bottom: 2px; }
        .header .meta { font-size: 8px; color: #666; }
        table { width: 100%; border-collapse: collapse; }
        th {
            background-color: #1F4E79;
            color: #fff;
            font-weight: bold;
            font-size: 8px;
            text-transform: uppercase;
            padding: 6px 4px;
            text-align: left;
            white-space: nowrap;
        }
        th.right, td.right { text-align: right; }
        th.center, td.center { text-align: center; }
        td {
            padding: 4px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 8px;
            vertical-align: top;
        }
        tr:nth-child(even) td { background-color: #f7fafc; }
        tr:hover td { background-color: #eef3f9; }
        .product-name { font-weight: bold; max-width: 150px; word-wrap: break-word; }
        .salt-name { max-width: 120px; word-wrap: break-word; color: #555; }
        .badge-active { color: #16a34a; font-weight: bold; }
        .badge-inactive { color: #dc2626; font-weight: bold; }
        .footer { margin-top: 10px; text-align: center; font-size: 7px; color: #999; border-top: 1px solid #ddd; padding-top: 6px; }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Product Catalog</h1>
        <div class="meta">Generated: {{ $generatedAt }} &bull; Total Products: {{ $totalCount }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="center">SR</th>
                <th>PRODUCT NAME</th>
                <th>CONTENT / SALT</th>
                <th>COMPANY</th>
                <th>CATEGORY</th>
                <th>HSN</th>
                <th>PACKING</th>
                <th>BOX SIZE</th>
                <th class="center">CONV.</th>
                <th class="right">MRP</th>
                <th class="right">PTR</th>
                <th class="right">PTS</th>
                <th class="right">RATE A</th>
                <th class="right">CSR</th>
                <th class="center">STATUS</th>
            </tr>
        </thead>
        <tbody>
            @foreach($products as $idx => $product)
            <tr>
                <td class="center">{{ $idx + 1 }}</td>
                <td class="product-name">{{ $product->product_name }}</td>
                <td class="salt-name">{{ $product->salt?->name ?? '—' }}</td>
                <td>{{ $product->company?->name ?? '—' }}</td>
                <td>{{ $product->category?->name ?? '—' }}</td>
                <td>{{ $product->hsn?->hsn_code ?? '—' }}</td>
                <td>{{ $product->packing_desc ?? '—' }}</td>
                <td>{{ $product->boxSize?->size_name ?? '—' }}</td>
                <td class="center">{{ $product->conversion_factor }}</td>
                <td class="right">{{ number_format($product->mrp, 2) }}</td>
                <td class="right">{{ number_format($product->ptr, 2) }}</td>
                <td class="right">{{ number_format($product->pts, 2) }}</td>
                <td class="right">{{ number_format($product->rate_a ?? 0, 2) }}</td>
                <td class="right">{{ number_format($product->csr ?? 0, 2) }}</td>
                <td class="center">
                    <span class="{{ $product->is_active ? 'badge-active' : 'badge-inactive' }}">
                        {{ $product->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        BrainyUG ERP &mdash; Product Catalog Export &mdash; Confidential
    </div>
</body>
</html>
