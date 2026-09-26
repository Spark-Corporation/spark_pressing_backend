<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $type }} {{ $deposit->code }}</title>
    <style>
        body { font-family: sans-serif; font-size: 13px; color: #111; }
        h1 { font-size: 16px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { border-bottom: 1px solid #ddd; padding: 4px 0; text-align: left; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <h1>{{ $deposit->pressing->name ?? 'Spark Pressing' }} — {{ strtoupper($type) }}</h1>
    <p>Agence : {{ $deposit->agency->name ?? '-' }}<br>
    Code : {{ $deposit->code }}<br>
    Client : {{ $deposit->client->fullname ?? '-' }} — {{ $deposit->client->phone_number ?? '' }}<br>
    Date : {{ optional($deposit->deposit_date)->format('d/m/Y H:i') }}</p>
    <table>
        <thead>
        <tr><th>Article</th><th>Qté / kg</th><th class="right">Montant</th></tr>
        </thead>
        <tbody>
        @foreach($deposit->units as $unit)
            <tr>
                <td>{{ $unit->designation }}</td>
                <td>{{ $unit->pricing_type === 'kilo' ? $unit->weight_kg.' kg' : $unit->quantity }}</td>
                <td class="right">{{ number_format($unit->line_total, 0, ',', ' ') }} XAF</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <p class="right">
        Sous-total : {{ number_format($deposit->subtotal, 0, ',', ' ') }} XAF<br>
        Collecte / livraison : {{ number_format(($deposit->collection_fee ?? 0) + ($deposit->delivery_fee ?? 0), 0, ',', ' ') }} XAF<br>
        Remise : {{ number_format($deposit->discount, 0, ',', ' ') }} XAF<br>
        <strong>Total : {{ number_format($deposit->total, 0, ',', ' ') }} XAF</strong><br>
        Acompte : {{ number_format($deposit->advanced, 0, ',', ' ') }} XAF<br>
        Reste : {{ number_format($deposit->left_to_pay, 0, ',', ' ') }} XAF
    </p>
</body>
</html>
