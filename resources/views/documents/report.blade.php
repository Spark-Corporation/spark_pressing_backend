<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $report }}</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #111; }
        table { width: 100%; border-collapse: collapse; }
        td { border-bottom: 1px solid #ddd; padding: 4px; }
        tr:first-child td { font-weight: bold; font-size: 14px; }
    </style>
</head>
<body>
    <table>
        @foreach($rows as $row)
            <tr>
                @foreach($row as $cell)
                    <td>{{ is_int($cell) ? number_format($cell, 0, ',', ' ') : $cell }}</td>
                @endforeach
            </tr>
        @endforeach
    </table>
</body>
</html>
