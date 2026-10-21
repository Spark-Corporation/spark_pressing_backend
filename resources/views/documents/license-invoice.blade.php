<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Facture {{ $payment['invoice_number'] }}</title>
    <style>
        body { font-family: sans-serif; font-size: 13px; color: #111; }
        h1 { font-size: 16px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { border-bottom: 1px solid #ddd; padding: 4px 0; text-align: left; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <h1>SPARK PRESSING — Facture {{ $payment['invoice_number'] }}</h1>
    <p>Client : {{ $license->pressing->name ?? '-' }}<br>
    Licence : {{ $license->reference }}<br>
    Date : {{ \Illuminate\Support\Carbon::parse($payment['date'])->format('d/m/Y') }}<br>
    Statut : {{ $payment['status']['label'] }}</p>
    <table>
        <thead>
        <tr><th>Désignation</th><th>Période</th><th class="right">Montant</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>Plan {{ $payment['plan']['label'] }} · {{ $payment['period']['periodicity']['label'] }}</td>
                <td>{{ \Illuminate\Support\Carbon::parse($payment['period']['start'])->format('d/m/Y') }} → {{ \Illuminate\Support\Carbon::parse($payment['period']['end'])->format('d/m/Y') }}</td>
                <td class="right">{{ number_format($payment['amount'], 0, ',', ' ') }} {{ $payment['currency'] }}</td>
            </tr>
        </tbody>
    </table>
    <p class="right">
        Taxes : {{ number_format($payment['taxes'], 0, ',', ' ') }} {{ $payment['currency'] }}<br>
        <strong>Montant total : {{ number_format($payment['total'], 0, ',', ' ') }} {{ $payment['currency'] }}</strong>
    </p>
    <p>Moyen de paiement : {{ $payment['payment_method']['label'] }}@if($payment['reference']) — Réf. {{ $payment['reference'] }}@endif</p>
</body>
</html>
