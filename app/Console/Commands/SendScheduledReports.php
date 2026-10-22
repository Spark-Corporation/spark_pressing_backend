<?php

namespace App\Console\Commands;

use App\Models\ReportSchedule;
use App\Support\Cash\CashPresenter;
use App\Support\Reporting\ReportExporter;
use App\Support\Reporting\ReportsPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/** Envoie par e-mail les rapports programmés arrivés à échéance (CSV en pièce jointe). */
class SendScheduledReports extends Command
{
    protected $signature = 'reports:send-scheduled';

    protected $description = 'Envoie les rapports programmés (bouton « Programmer »)';

    public function handle(ReportsPresenter $reports, ReportExporter $exporter, CashPresenter $cash): int
    {
        $due = ReportSchedule::withoutGlobalScopes()->where('active', true)->where('next_run_at', '<=', now())->get();

        foreach ($due as $schedule) {
            $end = CarbonImmutable::now()->subDay()->endOfDay();
            $start = match ($schedule->frequency) {
                'weekly' => $end->subDays(6)->startOfDay(),
                'monthly' => $end->startOfMonth(),
                default => $end->startOfDay(),
            };

            $payload = $schedule->report === 'daily' && $schedule->agency_id
                ? $reports->daily($end, $schedule->agency_id, $schedule->pressing_id, $cash)
                : $reports->overview($start, $end, $schedule->agency_id, $schedule->pressing_id);
            $csv = $exporter->csv($schedule->report === 'daily' && $schedule->agency_id ? 'daily' : 'overview', $payload);
            $filename = sprintf('rapport-%s-%s.csv', $schedule->report, $end->format('Ymd'));

            Mail::raw('Veuillez trouver ci-joint votre rapport SPARK PRESSING.', function ($m) use ($schedule, $csv, $filename) {
                $m->to($schedule->recipients)->subject('Rapport SPARK PRESSING')->attachData($csv, $filename, ['mime' => 'text/csv']);
            });

            $schedule->update([
                'last_sent_at' => now(),
                'next_run_at' => ReportSchedule::nextRun($schedule->frequency, now()),
            ]);
        }

        $this->info($due->count().' rapport(s) envoyé(s).');

        return self::SUCCESS;
    }
}
