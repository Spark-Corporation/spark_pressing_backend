<?php

namespace App\Support\Documents;

use App\Models\Deposit;
use App\Models\PrintLog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TicketDocument
{
    public function payload(Request $request, Deposit $deposit, string $type): array
    {
        $deposit->loadMissing(['client:id,fullname,phone_number,code', 'units', 'transactions', 'agency:id,name', 'pressing:id,name']);

        PrintLog::query()->create([
            'pressing_id' => $deposit->pressing_id,
            'agency_id' => $deposit->agency_id,
            'deposit_id' => $deposit->id,
            'user_id' => $request->user()?->id,
            'document' => $type,
        ]);

        $html = view('documents.ticket', ['deposit' => $deposit, 'type' => $type])->render();

        return [
            'type' => $type,
            'currency' => config('spark.currency'),
            'pressing' => $deposit->pressing?->name,
            'agency' => $deposit->agency?->name,
            'print_html' => $html,
        ];
    }

    public function pdfResponse(Deposit $deposit, string $type): Response
    {
        $html = view('documents.ticket', ['deposit' => $deposit, 'type' => $type])->render();
        $filename = $type.'-'.$deposit->code.'.pdf';

        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
                ->download($filename);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
