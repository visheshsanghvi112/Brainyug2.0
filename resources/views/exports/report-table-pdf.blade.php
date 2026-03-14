<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 11px; }
        .header { margin-bottom: 10px; }
        .title { font-size: 16px; font-weight: 700; }
        .meta { margin-top: 6px; font-size: 10px; color: #4b5563; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #d1d5db; padding: 6px; }
        th { background: #1f4e79; color: #ffffff; text-align: left; font-weight: 700; }
        tr:nth-child(even) td { background: #f9fafb; }
        .kv { margin-top: 10px; }
        .kv-row { margin: 2px 0; }
        .kv-key { font-weight: 700; display: inline-block; min-width: 160px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">{{ $title }}</div>
        <div class="meta">Generated: {{ $generatedAt }}</div>
    </div>

    @if(!empty($meta))
        <div class="kv">
            @foreach($meta as $key => $value)
                <div class="kv-row"><span class="kv-key">{{ $key }}</span><span>{{ $value }}</span></div>
            @endforeach
        </div>
    @endif

    <table>
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
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headers) }}">No records found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
