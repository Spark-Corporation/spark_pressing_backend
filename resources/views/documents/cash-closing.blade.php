<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $closing['report']['filename'] }}</title>
    <style>
        body { font-family: sans-serif; font-size: 13px; color: #111; }
        h1 { font-size: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        td, th { border-bottom: 1px solid #ddd; padding: 4px 0; text-align: left; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <h1>Clôture de caisse — {{ $agency->name ?? '' }} — {{ $closing['session']['business_date'] }}</h1>
    <p>Caisse {{ $closing['session']['code'] }} · {{ $closing['status']['label'] }}</p>
    <table>
        <tr><td>Solde d’ouverture</td><td class="right">{{ number_format($closing['summary']['opening'], 0, ',', ' ') }}</td></tr>
        <tr><td>Recettes liées aux dépôts ({{ $closing['summary']['deposit_receipts']['count'] }})</td><td class="right">{{ number_format($closing['summary']['deposit_receipts']['amount'], 0, ',', ' ') }}</td></tr>
        <tr><td>Paiements de soldes ({{ $closing['summary']['balance_payments']['count'] }})</td><td class="right">{{ number_format($closing['summary']['balance_payments']['amount'], 0, ',', ' ') }}</td></tr>
        <tr><td>Entrées hors dépôt ({{ $closing['summary']['cash_in']['count'] }})</td><td class="right">{{ number_format($closing['summary']['cash_in']['amount'], 0, ',', ' ') }}</td></tr>
        <tr><td>Sorties hors dépôt ({{ $closing['summary']['cash_out']['count'] }})</td><td class="right">-{{ number_format($closing['summary']['cash_out']['amount'], 0, ',', ' ') }}</td></tr>
        <tr><th>Solde théorique</th><th class="right">{{ number_format($closing['summary']['theoretical'], 0, ',', ' ') }}</th></tr>
    </table>
    <table>
        <tr><th>Moyen</th><th class="right">Théorique</th><th class="right">Réel</th><th>Statut</th></tr>
        @foreach($closing['reconciliation'] as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td class="right">{{ number_format($row['theoretical'], 0, ',', ' ') }}</td>
                <td class="right">{{ $row['counted'] === null ? '—' : number_format($row['counted'], 0, ',', ' ') }}</td>
                <td>{{ $row['status']['label'] }}</td>
            </tr>
        @endforeach
    </table>
    <p>{{ $closing['result']['label'] }} — écart {{ number_format($closing['result']['difference'], 0, ',', ' ') }}@if($closing['result']['reason']) · {{ $closing['result']['reason'] }}@endif</p>
    <p>Compté par {{ $closing['result']['counted_by'] ?? '—' }} · contrôlé par {{ $closing['result']['controlled_by'] ?? '—' }}</p>
</body>
</html>
