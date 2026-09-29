<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class DateRange
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    public static function fromRequest(Request $request, bool $defaultToday = true): self
    {
        $from = $request->date('date_from');
        $to = $request->date('date_to');

        if (! $from && ! $to && ! $defaultToday) {
            $from = now()->subYears(10);
            $to = now();
        }

        return new self(
            ($from ? CarbonImmutable::parse($from) : CarbonImmutable::now())->startOfDay(),
            ($to ? CarbonImmutable::parse($to) : CarbonImmutable::now())->endOfDay(),
        );
    }

    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
        ];
    }
}
