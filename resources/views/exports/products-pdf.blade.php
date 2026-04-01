<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Product Catalog</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #0f172a; margin: 0; padding: 14px; }
        .header { border-bottom: 2px solid #1d4ed8; padding-bottom: 10px; margin-bottom: 12px; }
        .header h1 { margin: 0; font-size: 20px; color: #1e3a8a; }
        .header p { margin: 4px 0 0; font-size: 9px; color: #475569; }
        .summary { width: 100%; margin-bottom: 12px; border-collapse: separate; border-spacing: 8px 0; }
        .summary td { width: 25%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px; background: #f8fafc; }
        .summary .label { font-size: 8px; text-transform: uppercase; color: #64748b; margin-bottom: 3px; }
        .summary .value { font-size: 13px; font-weight: bold; color: #0f172a; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .meta td { border: 1px solid #e2e8f0; padding: 5px 6px; vertical-align: top; }
        .meta .label { width: 24%; background: #f8fafc; font-weight: bold; color: #334155; }
        table.catalog { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.catalog th { background: #0f766e; color: #ffffff; font-size: 8px; text-transform: uppercase; padding: 6px 4px; border: 1px solid #cbd5e1; }
        table.catalog td { border: 1px solid #e2e8f0; padding: 5px 4px; font-size: 8px; vertical-align: top; word-wrap: break-word; }
        table.catalog tbody tr:nth-child(even) td { background: #f8fafc; }
        .right { text-align: right; }
        .center { text-align: center; }
        .footer { margin-top: 10px; text-align: center; font-size: 7px; color: #64748b; border-top: 1px solid #cbd5e1; padding-top: 6px; }
        @page { size: A4 landscape; margin: 10mm; }
    </style>
    @if(!empty($autoPrint))
    <script>
        window.addEventListener('load', function () {
            window.print();
        });
    </script>
    @endif
</head>
<body>
    <div class="header">
        <h1>Product Catalog</h1>
        <p>{{ $subtitle }}</p>
        <p>Generated: {{ $generatedAt }} | Total Products: {{ $totalCount }}</p>
    </div>

    <table class="summary">
        <tr>
            <td>
                <div class="label">Total Products</div>
                <div class="value">{{ $meta['Total Products'] ?? $totalCount }}</div>
            </td>
            <td>
                <div class="label">Active Products</div>
                <div class="value">{{ $meta['Active Products'] ?? 0 }}</div>
            </td>
            <td>
                <div class="label">Inactive Products</div>
                <div class="value">{{ $meta['Inactive Products'] ?? 0 }}</div>
            </td>
            <td>
                <div class="label">Layout</div>
                <div class="value">{{ $meta['Export Layout'] ?? 'Detailed' }}</div>
            </td>
        </tr>
    </table>

    <table class="meta">
        @foreach($meta as $label => $value)
        <tr>
            <td class="label">{{ $label }}</td>
            <td>{{ $value }}</td>
        </tr>
        @endforeach
    </table>

    <table class="catalog">
        <thead>
            <tr>
                @foreach($headers as $header)
                <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
            <tr>
                @foreach($row as $cell)
                <td class="{{ is_numeric($cell) ? 'right' : '' }}">{{ $cell }}</td>
                @endforeach
            </tr>
            @empty
            <tr>
                <td colspan="{{ count($headers) }}" class="center">No products matched the selected filters.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        BrainyUG ERP - Product Catalog Export - Confidential
    </div>
</body>
</html>
