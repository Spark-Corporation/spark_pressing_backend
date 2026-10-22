<?php

namespace App\Http\Controllers\Api\V1\Deposits;

use App\Actions\Deposit\AddPayment;
use App\Actions\Deposit\RetrieveDeposit;
use App\Actions\Deposit\RetrievePartial;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\NotificationLog;
use App\Models\Pressing;
use App\Support\Deposits\DepositPresenter;
use App\Support\Deposits\RetrievalPresenter;
use App\Support\Notifications\Notifier;
use App\Support\Sms\SmsSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Group;

#[Group('Dépôts', 'Centre de retrait et traitement du retrait.')]
class RetrievalController extends Controller
{
    public function __construct(private readonly RetrievalPresenter $presenter) {}

    /** « Centre de retrait » : KPI, retraits du jour, dépôts prêts (filtres q, date, agency_id, payment_status, etat). */
    public function center(Request $request): JsonResponse
    {
        $items = $this->presenter->readyList($request)->paginate(min(100, (int) $request->get('per_page', 20)));

        return $this->page(
            $items,
            collect($items->items())->map(fn (Deposit $d) => $this->presenter->row($d))->all(),
            ['kpis' => $this->presenter->kpis(), 'today_schedule' => $this->presenter->schedule()]
        );
    }

    /** « Vêtements classés depuis 30+ jours ». */
    public function aged(): JsonResponse
    {
        return $this->ok($this->presenter->aged());
    }

    /** Relancer / frais de garde / escalade / traité sur un dépôt ancien. */
    public function agedAction(Request $request, Deposit $deposit, SmsSender $sms): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:remind,storage_fee,escalate,handled'],
            'amount' => ['required_if:action,storage_fee', 'nullable', 'integer', 'min:1'],
        ]);

        match ($data['action']) {
            'remind' => $this->sendNotification($deposit, 'sms', $sms),
            'storage_fee' => DB::transaction(function () use ($deposit, $data) {
                $deposit->increment('storage_fee', (int) $data['amount']);
                $deposit->increment('total', (int) $data['amount']);
                $deposit->increment('left_to_pay', (int) $data['amount']);
            }),
            'escalate' => $deposit->update(['escalated_at' => now()]),
            'handled' => $deposit->update(['aged_handled_at' => now()]),
        };

        AuditLog::record('deposit.aged_'.$data['action'], $deposit, $data);

        return $this->ok($this->presenter->row($deposit->fresh()));
    }

    /** « Notifier le client » (SMS ou WhatsApp). */
    public function notify(Request $request, Deposit $deposit, SmsSender $sms): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', 'in:sms,whatsapp']]);
        $openUrl = $this->sendNotification($deposit, $data['channel'], $sms);

        return $this->ok($this->presenter->row($deposit->fresh()) + ['open_url' => $openUrl]);
    }

    /** Marquer la notification comme lue (accusé opérateur ou saisie manuelle). */
    public function notificationRead(Deposit $deposit): JsonResponse
    {
        $deposit->update(['notification_read_at' => now(), 'notification_status' => 'read']);

        return $this->ok($this->presenter->row($deposit->fresh()));
    }

    /** « Traiter le retrait » — données de l’écran. */
    public function showCheckout(Request $request, Deposit $deposit, DepositPresenter $presenter): JsonResponse
    {
        return $this->ok($this->presenter->checkout($deposit, $request->user(), $presenter));
    }

    /**
     * « Confirmer le retrait » : encaissement, remise (totale ou partielle), état des pièces,
     * réceptionnaire et journal enregistrés ensemble.
     */
    public function checkout(
        Request $request,
        Deposit $deposit,
        AddPayment $pay,
        RetrieveDeposit $retrieve,
        RetrievePartial $partial,
        SmsSender $sms,
        DepositPresenter $presenter,
    ): JsonResponse {
        $data = $request->validate([
            'units' => ['nullable', 'array'],
            'units.*.id' => ['required', 'integer'],
            'units.*.quantity' => ['required', 'integer', 'min:0'],
            'units.*.verification' => ['nullable', 'in:compliant,control_required'],
            'condition' => ['nullable', 'in:compliant,client_reserve,anomaly'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'receiver_type' => ['nullable', 'in:client,third_party'],
            'receiver_name' => ['nullable', 'string', 'max:191'],
            'identity_confirmed' => ['sometimes', 'boolean'],
            'payment.amount' => ['nullable', 'integer', 'min:0'],
            'payment.method' => ['nullable', 'in:cash,card,mobile_money,wallet,other'],
            'payment.received' => ['nullable', 'integer', 'min:0'],
            'override_reason' => ['nullable', 'string', 'max:255'],
            'notify_sms' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();

        $result = DB::transaction(function () use ($data, $deposit, $user, $pay, $retrieve, $partial) {
            $amount = (int) ($data['payment']['amount'] ?? 0);
            if ($amount > 0) {
                $deposit = $pay->handle($user, $deposit, [
                    'amount' => $amount,
                    'payment_method' => $data['payment']['method'] ?? 'cash',
                ]);
            }

            $override = false;
            if ((int) $deposit->left_to_pay > 0 && Pressing::query()->whereKey($deposit->pressing_id)->value('block_retrieve_if_unpaid')) {
                if (empty($data['override_reason']) || ! $user->can('deposits.override')) {
                    throw ValidationException::withMessages(['deposit' => 'Retrait bloqué : solde impayé. Une dérogation manager est requise.']);
                }
                $override = true;
                AuditLog::record('deposit.retrieve_override', $deposit, ['reason' => $data['override_reason'], 'left_to_pay' => (int) $deposit->left_to_pay]);
            }

            foreach ($data['units'] ?? [] as $row) {
                if (! empty($row['verification'])) {
                    $deposit->units()->whereKey($row['id'])->update(['verification' => $row['verification']]);
                }
            }

            $selected = collect($data['units'] ?? [])->filter(fn ($r) => (int) $r['quantity'] > 0)->values();
            $deposit->load('units');
            $remaining = $deposit->units->sum(fn ($u) => max(0, $u->quantity - $u->retrieve_quantity));
            $isPartial = $selected->isNotEmpty() && $selected->sum('quantity') < $remaining;

            $deposit = $isPartial
                ? $partial->handle($user, $deposit, $selected->map(fn ($r) => ['id' => $r['id'], 'quantity' => $r['quantity']])->all(), $override)
                : $retrieve->handle($user, $deposit, ['receiver_name' => $data['receiver_name'] ?? null], $override);

            $deposit->update([
                'retrieve_condition' => $data['condition'] ?? 'compliant',
                'retrieve_remarks' => $data['remarks'] ?? null,
                'receiver_type' => $data['receiver_type'] ?? 'client',
                'receiver_name' => $data['receiver_name'] ?? $deposit->receiver_name ?? $deposit->client?->fullname,
                'identity_confirmed' => (bool) ($data['identity_confirmed'] ?? false),
                'retrieve_override_reason' => $override ? $data['override_reason'] : null,
            ]);

            return [$deposit->fresh(['units', 'client', 'transactions']), $isPartial];
        });

        [$deposit, $isPartial] = $result;

        app(Notifier::class)->dispatch('retrieved', $deposit, ! empty($data['notify_sms']) ? ['sms'] : []);

        $received = $data['payment']['received'] ?? null;
        $amount = (int) ($data['payment']['amount'] ?? 0);

        return $this->ok([
            'partial' => $isPartial,
            'change_due' => $received !== null ? max(0, (int) $received - $amount) : 0,
            'deposit' => $presenter->detail($deposit),
            'slip_url' => route('v1.deposits.document', ['deposit' => $deposit->id, 'type' => 'bon', 'format' => 'pdf']),
        ]);
    }

    private function sendNotification(Deposit $deposit, string $channel, SmsSender $sms): ?string
    {
        $deposit->loadMissing('client');
        $phone = $deposit->client?->phone_number;
        if (! $phone) {
            throw ValidationException::withMessages(['channel' => 'Le client n\'a pas de téléphone.']);
        }

        $message = RetrievalPresenter::notificationMessage($deposit);
        $openUrl = null;
        if ($channel === 'sms') {
            $sms->send($phone, $message);
        } else {
            $openUrl = 'https://wa.me/'.preg_replace('/\D/', '', $phone).'?text='.rawurlencode($message);
        }

        $deposit->update([
            'notification_channel' => $channel,
            'notification_status' => 'sent',
            'notified_at' => now(),
            'notification_read_at' => null,
        ]);
        AuditLog::record('deposit.notified', $deposit, ['channel' => $channel]);
        NotificationLog::query()->create([
            'pressing_id' => $deposit->pressing_id, 'agency_id' => $deposit->agency_id, 'client_id' => $deposit->client_id, 'deposit_id' => $deposit->id,
            'event' => 'ready', 'channel' => $channel, 'recipient' => $phone, 'message' => $message, 'status' => 'sent',
        ]);

        return $openUrl;
    }
}
