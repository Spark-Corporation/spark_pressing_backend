<?php

namespace App\Http\Controllers\Api\V1\Reporting;

use App\Http\Controllers\Controller;
use App\Models\ReportSchedule;
use App\Support\Cash\CashPresenter;
use App\Support\Reporting\ReportExporter;
use App\Support\Reporting\ReportsPresenter;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

#[Group('Rapports')]
class ReportScreenController extends Controller
{
    public function __construct(private readonly ReportsPresenter $presenter, private readonly ReportExporter $exporter) {}

    /**
     * « Rapports et bilans » : période (`date_from`/`date_to` ou `preset=today|7d|30d`), `agency_id`,
     * comparaison avec la période précédente ; `format=csv|pdf` pour « Exporter ».
     */
    public function overview(Request $request): JsonResponse|Response
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'preset' => ['nullable', 'in:today,7d,30d,month'],
            'agency_id' => ['nullable', 'integer'],
            'format' => ['nullable', 'in:json,csv,pdf'],
        ]);
        [$from, $to] = $this->range($data);
        $payload = $this->presenter->overview($from, $to, $data['agency_id'] ?? null, $request->user()?->pressing_id);

        if (in_array($data['format'] ?? 'json', ['csv', 'pdf'], true)) {
            return $this->exporter->download('overview', $payload, $data['format']);
        }

        return $this->ok($payload);
    }

    /** « Bilan journalier et performance caissiers » (`date`, agence d’opération ; `format=csv|pdf`). */
    public function daily(Request $request, CashPresenter $cash): JsonResponse|Response
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'agency_id' => ['nullable', 'integer'],
            'format' => ['nullable', 'in:json,csv,pdf'],
        ]);
        $agencyId = (int) ($data['agency_id'] ?? app(TenantContext::class)->agencyId ?? $request->user()->agency_id);
        $payload = $this->presenter->daily(CarbonImmutable::parse($data['date'] ?? now()), $agencyId, $request->user()?->pressing_id, $cash);

        if (in_array($data['format'] ?? 'json', ['csv', 'pdf'], true)) {
            return $this->exporter->download('daily', $payload, $data['format']);
        }

        return $this->ok($payload);
    }

    /** « Programmer » : envoi récurrent d’un rapport par e-mail. */
    public function schedules(Request $request): JsonResponse
    {
        return $this->ok(ReportSchedule::query()->latest()->get()->map(fn ($s) => $this->schedulePayload($s))->all());
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'report' => ['required', 'in:overview,daily'],
            'frequency' => ['required', 'in:daily,weekly,monthly'],
            'format' => ['nullable', 'in:csv,pdf'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['email'],
            'agency_id' => ['nullable', 'integer', 'exists:agencies,id'],
        ]);

        $schedule = ReportSchedule::query()->create($data + [
            'pressing_id' => $request->user()->pressing_id,
            'user_id' => $request->user()->id,
            'format' => $data['format'] ?? 'csv',
            'next_run_at' => ReportSchedule::nextRun($data['frequency'], now()),
        ]);

        return $this->created($this->schedulePayload($schedule));
    }

    public function destroySchedule(ReportSchedule $reportSchedule): JsonResponse
    {
        $reportSchedule->delete();

        return $this->ok(['deleted' => true]);
    }

    private function schedulePayload(ReportSchedule $s): array
    {
        return [
            'id' => $s->id,
            'report' => $s->report,
            'frequency' => $s->frequency,
            'format' => $s->format,
            'recipients' => $s->recipients,
            'agency_id' => $s->agency_id,
            'last_sent_at' => $s->last_sent_at?->toIso8601String(),
            'next_run_at' => $s->next_run_at?->toIso8601String(),
            'active' => (bool) $s->active,
        ];
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function range(array $data): array
    {
        $now = CarbonImmutable::now();

        return match ($data['preset'] ?? null) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            '7d' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            '30d' => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            default => [
                CarbonImmutable::parse($data['date_from'] ?? $now->startOfMonth())->startOfDay(),
                CarbonImmutable::parse($data['date_to'] ?? $now)->endOfDay(),
            ],
        };
    }
}
