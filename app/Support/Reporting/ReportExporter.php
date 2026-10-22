<?php

namespace App\Support\Reporting;

use Symfony\Component\HttpFoundation\Response;

/**
 * « Exporter CSV / PDF » des rapports. Le CSV (séparateur `;`, BOM UTF-8) s’ouvre dans Excel.
 */
class ReportExporter
{
    public function download(string $report, array $payload, string $format): Response
    {
        $filename = sprintf('rapport-%s-%s.%s', $report, now()->format('Ymd'), $format);

        if ($format === 'pdf') {
            $html = view('documents.report', ['report' => $report, 'rows' => $this->rows($report, $payload)])->render();

            return class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)
                ? \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->download($filename)
                : response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        return response($this->csv($report, $payload), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function csv(string $report, array $payload): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($this->rows($report, $payload) as $row) {
            fputcsv($out, $row, ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** @return list<list<string|int|float|null>> */
    private function rows(string $report, array $p): array
    {
        if ($report === 'daily') {
            $rows = [['Bilan journalier', $p['agency'], $p['date']], ['Indicateur', 'Valeur']];
            $rows[] = ['Recettes encaissées', $p['kpis']['receipts']['value']];
            $rows[] = ['Ventes soldées', $p['kpis']['settled_sales']['value']];
            $rows[] = ['Impayés ouverts', $p['kpis']['open_unpaid']['value']];
            $rows[] = ['Écart de caisse', $p['kpis']['cash_gap']['value']];
            $rows[] = [];
            $rows[] = ['Caissier', 'Recettes', 'Ventes', 'Panier'];
            foreach ($p['cashiers'] as $c) {
                $rows[] = [$c['fullname'], $c['receipts'], $c['sales'], $c['average_basket']];
            }

            return $rows;
        }

        $rows = [['Rapports et bilans', $p['period']['from'], $p['period']['to']], ['Indicateur', 'Valeur', 'Évolution %']];
        $rows[] = ['Chiffre d’affaires', $p['kpis']['revenue']['value'], $p['kpis']['revenue']['delta_percent']];
        $rows[] = ['Ventes', $p['kpis']['sales']['value'], $p['kpis']['sales']['delta_percent']];
        $rows[] = ['Remises', $p['kpis']['discounts']['value'], null];
        $rows[] = ['Impayés', $p['kpis']['unpaid']['value'], $p['kpis']['unpaid']['delta_percent']];
        $rows[] = [];
        $rows[] = ['Agence', 'CA', 'Ventes', 'Panier', 'Évolution %'];
        foreach ($p['agencies'] as $a) {
            $rows[] = [$a['name'], $a['revenue'], $a['sales'], $a['average_basket'], $a['delta_percent']];
        }

        return $rows;
    }
}
