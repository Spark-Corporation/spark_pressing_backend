<?php

namespace App\Http\Controllers\Api\V1\Deposits;

use App\Actions\Deposit\AddPayment;
use App\Actions\Deposit\CreateDeposit;
use App\Actions\Deposit\RetrieveDeposit;
use App\Actions\Deposit\RetrievePartial;
use App\Actions\Deposit\TransitionWorkshop;
use App\Http\Controllers\Controller;
use App\Http\Resources\DepositResource;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Support\Deposits\DepositPresenter;
use App\Support\Deposits\WorkshopBoard;
use App\Support\Documents\TicketDocument;
use App\Support\Sms\SmsSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Dépôts', 'Cycle dépôt → paiement → atelier → retrait.')]
class DepositController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $deposits = DepositQuery::filtered($request)
            ->latest('deposit_date')
            ->paginate(min(100, (int) $request->get('per_page', 20)));

        return $this->page($deposits, DepositResource::collection($deposits)->resolve(), [
            'kpis' => app(DepositPresenter::class)->kpis(),
        ]);
    }

    /** « Exporter » — CSV des dépôts filtrés (mêmes filtres que la liste). */
    public function export(Request $request): StreamedResponse
    {
        $rows = DepositResource::collection(DepositQuery::filtered($request)->latest('deposit_date')->limit(5000)->get())->resolve();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Code dépôt', 'Date', 'Client', 'Téléphone', 'Prestation', 'Articles / poids', 'Total', 'Payé', 'Reste', 'Retrait prévu', 'État atelier', 'Paiement'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['code'], $r['deposit_date'], $r['client_name'] ?? null, $r['client_phone'] ?? null,
                    $r['service']['label'], $r['quantity_label'], $r['total'], $r['advanced'], $r['left_to_pay'],
                    $r['retrieve_date'], $r['workshop_status']['label'], $r['payment_status']['label'],
                ], ';');
            }
            fclose($out);
        }, 'depots-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function due(Request $request): JsonResponse
    {
        $deposits = DepositQuery::filtered($request->merge(['due' => 1]))
            ->latest('retrieve_date')
            ->paginate(min(100, (int) $request->get('per_page', 20)));

        return $this->page($deposits, DepositResource::collection($deposits)->resolve());
    }

    public function retrieved(Request $request): JsonResponse
    {
        $deposits = DepositQuery::retrieved($request)
            ->latest('retrieved_at')
            ->paginate(min(100, (int) $request->get('per_page', 20)));

        return $this->page($deposits, DepositResource::collection($deposits)->resolve());
    }

    public function store(Request $request, CreateDeposit $action): JsonResponse
    {
        $payload = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'client_id' => ['required', 'exists:clients,id'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'promo_code' => ['nullable', 'string'],
            'promo_special_code' => ['nullable', 'string'],
            'points_to_redeem' => ['nullable', 'integer', 'min:0'],
            'with_collection' => ['sometimes', 'boolean'],
            'with_delivery' => ['sometimes', 'boolean'],
            'collection_fee' => ['nullable', 'integer', 'min:0'],
            'delivery_fee' => ['nullable', 'integer', 'min:0'],
            'delivery_zone_id' => ['nullable', 'exists:delivery_zones,id'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'advanced' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['nullable', 'in:cash,card,mobile_money,wallet,other'],
            'deposit_date' => ['nullable', 'date'],
            'retrieve_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.article_id' => ['required', 'exists:articles,id'],
            'lines.*.service_id' => ['nullable', 'exists:services,id'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1'],
            'lines.*.type_action' => ['nullable', 'integer', 'in:0,1,2'],
            'lines.*.pricing_type' => ['nullable', 'in:piece,kilo'],
            'lines.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['nullable', 'integer', 'min:0'],
            'lines.*.designation' => ['nullable', 'string'],
            'lines.*.state' => ['nullable', 'string'],
            'lines.*.render_id' => ['nullable', 'integer'],
            'lines.*.express' => ['sometimes', 'boolean'],
            'lines.*.color' => ['nullable', 'string', 'max:64'],
            'lines.*.defects' => ['nullable', 'array'],
            'lines.*.defects.*' => ['string', 'in:'.implode(',', array_keys(Deposit::DEFECTS))],
            'lines.*.internal_notes' => ['nullable', 'string', 'max:1000'],
            'terminal' => ['nullable', 'string', 'max:32'],
            'local_ref' => ['nullable', 'string', 'max:40'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $deposit = $action->handle($request->user(), $payload);

        return $this->created((new DepositResource($deposit))->resolve());
    }

    public function show(Deposit $deposit, DepositPresenter $presenter): JsonResponse
    {
        return $this->ok($presenter->detail($deposit->load([
            'client:id,fullname,phone_number,email,code,wallet_balance,sponsor_code,loyalty_points,created_at',
            'units',
            'transactions',
            'cashier:id,fullname',
        ])));
    }

    public function pay(Request $request, Deposit $deposit, AddPayment $action): JsonResponse
    {
        $payload = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'amount' => ['required', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'in:cash,card,mobile_money,wallet,other'],
            'transaction_date' => ['nullable', 'date'],
        ]);

        $deposit = $action->handle($request->user(), $deposit, $payload);

        return $this->ok((new DepositResource($deposit))->resolve());
    }

    public function retrieve(Request $request, Deposit $deposit, RetrieveDeposit $action): JsonResponse
    {
        $payload = $request->validate([
            'receiver_name' => ['nullable', 'string', 'max:191'],
        ]);

        $deposit = $action->handle($request->user(), $deposit, $payload);

        return $this->ok((new DepositResource($deposit))->resolve());
    }

    public function transition(Request $request, Deposit $deposit, TransitionWorkshop $action): JsonResponse
    {
        $payload = $request->validate([
            'etat' => ['required', 'in:waiting,in_progress,treated,classed'],
            'qr_token' => ['nullable', 'string'],
            'storage_location' => ['nullable', 'string', 'max:64'],
        ]);

        if (! empty($payload['storage_location'])) {
            $deposit->update(['storage_location' => $payload['storage_location']]);
        }

        if (! empty($payload['qr_token']) && $deposit->qr_token && $payload['qr_token'] !== $deposit->qr_token) {
            abort(422, 'QR token invalide pour ce dépôt.');
        }

        $deposit = $action->handle($request->user(), $deposit, $payload['etat']);

        return $this->ok((new DepositResource($deposit))->resolve());
    }

    public function retrievePartial(Request $request, Deposit $deposit, RetrievePartial $action): JsonResponse
    {
        $payload = $request->validate([
            'units' => ['required', 'array', 'min:1'],
            'units.*.id' => ['required', 'integer'],
            'units.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $deposit = $action->handle($request->user(), $deposit, $payload['units']);

        return $this->ok((new DepositResource($deposit))->resolve());
    }

    /** « Annuler le dépôt » — journalisé avec le motif. */
    public function destroy(Request $request, Deposit $deposit): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $deposit->update(['cancel_reason' => $data['reason'] ?? null]);
        $deposit->delete();
        AuditLog::record('deposit.deleted', $deposit, ['reason' => $data['reason'] ?? null]);

        return $this->ok(['deleted' => true]);
    }

    public function ready(): JsonResponse
    {
        $items = Deposit::query()
            ->with(['client:id,fullname,phone_number,code', 'agency:id,name'])
            ->withSum('units as items_count', 'quantity')
            ->where('status', true)
            ->whereIn('etat', ['treated', 'classed'])
            ->latest('retrieve_date')
            ->paginate(20);

        return $this->page($items, DepositResource::collection($items)->resolve());
    }

    /** « Atelier Kanban » : colonnes, cartes, capacité, filtres (q, agency_id, service, responsable_id, due_date, period). */
    public function workshopBoard(Request $request, WorkshopBoard $board): JsonResponse
    {
        return $this->ok($board->build($request, $request->user()?->pressing_id));
    }

    /** Panneau « Aperçu du dépôt » de l’atelier. */
    public function workshopPreview(Deposit $deposit, WorkshopBoard $board, DepositPresenter $presenter): JsonResponse
    {
        return $this->ok($board->preview($deposit, $presenter));
    }

    /**
     * Ticket (80 mm) ou facture A4 : `type=ticket|facture|bon`, `format=pdf` pour le binaire,
     * `layout=ticket|a4` pour le document structuré.
     */
    public function document(Request $request, Deposit $deposit, TicketDocument $tickets, DepositPresenter $presenter): JsonResponse|Response
    {
        $type = $request->query('type', 'ticket');
        $payload = $tickets->payload($request, $deposit, $type);

        if ($request->query('format') === 'pdf') {
            return $tickets->pdfResponse($deposit, $type);
        }

        $layout = $request->query('layout', $type === 'facture' ? 'a4' : 'ticket');
        $payload['deposit'] = (new DepositResource($deposit))->resolve();
        $payload['document'] = $presenter->document($deposit, $layout === 'a4' ? 'a4' : 'ticket');
        $payload['share_url'] = URL::temporarySignedRoute('v1.public.deposits.document', now()->addDays(7), [
            'deposit' => $deposit->id,
            'type' => $type,
        ]);

        return $this->ok($payload);
    }

    /** « Envoyer au client » — lien signé (7 j) vers le PDF par WhatsApp, SMS ou e-mail. */
    public function sendDocument(Request $request, Deposit $deposit, SmsSender $sms): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'in:whatsapp,sms,email'],
            'type' => ['nullable', 'in:ticket,facture'],
        ]);
        $deposit->loadMissing('client', 'pressing');
        $type = $data['type'] ?? 'ticket';
        $url = URL::temporarySignedRoute('v1.public.deposits.document', now()->addDays(7), ['deposit' => $deposit->id, 'type' => $type]);
        $message = sprintf(
            '%s : votre %s %s est disponible : %s',
            $deposit->pressing?->name ?? 'SPARK PRESSING',
            $type === 'facture' ? 'facture' : 'ticket',
            $deposit->code,
            $url
        );
        $phone = $deposit->client?->phone_number;
        $email = $deposit->client?->email;

        if (in_array($data['channel'], ['whatsapp', 'sms'], true) && ! $phone) {
            throw ValidationException::withMessages(['channel' => 'Le client n\'a pas de téléphone.']);
        }
        if ($data['channel'] === 'email' && ! $email) {
            throw ValidationException::withMessages(['channel' => 'Le client n\'a pas d\'e-mail.']);
        }

        $openUrl = null;
        if ($data['channel'] === 'sms') {
            $sms->send($phone, $message);
        } elseif ($data['channel'] === 'email') {
            Mail::raw($message, fn ($m) => $m->to($email)->subject("Votre document {$deposit->code}"));
        } else {
            $openUrl = 'https://wa.me/'.preg_replace('/\D/', '', $phone).'?text='.rawurlencode($message);
        }

        AuditLog::record('deposit.document_sent', $deposit, ['channel' => $data['channel'], 'type' => $type]);

        return $this->ok([
            'sent' => $data['channel'] !== 'whatsapp',
            'channel' => $data['channel'],
            'destination' => $data['channel'] === 'email' ? $email : $phone,
            'share_url' => $url,
            'open_url' => $openUrl,
        ]);
    }

    /** PDF public via lien signé (envoyé au client). */
    public function publicDocument(Request $request, int $deposit, TicketDocument $tickets): Response
    {
        $model = Deposit::withoutGlobalScopes()->findOrFail($deposit);

        return $tickets->pdfResponse($model, $request->query('type', 'ticket'));
    }
}
