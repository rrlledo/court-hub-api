<?php

namespace App\Services;

use App\Models\BranchHoliday;
use App\Models\Court;
use App\Models\CourtMaintenance;
use App\Models\OperatingHour;
use App\Models\PricingRule;
use Carbon\Carbon;

class BookingRules
{
    public function validate(Court $court, Carbon $start, Carbon $end): void
    {
        abort_unless($court->status === 'active', 422, 'The court is not active.');
        abort_unless($start->isSameDay($end), 422, 'Choose a booking within one day.');
        abort_if(BranchHoliday::where('branch_id', $court->branch_id)->whereDate('holiday_date', $start)->where('is_closed', true)->exists(), 422, 'The branch is closed for this holiday.');
        $hours = OperatingHour::where('branch_id', $court->branch_id)->where('day_of_week', $start->dayOfWeek)->first();
        if ($hours) {
            abort_if($hours->is_closed || $start->format('H:i:s') < $hours->opens_at || $end->format('H:i:s') > $hours->closes_at, 422, 'The requested time is outside branch operating hours.');
        }
        abort_if(CourtMaintenance::where('court_id', $court->id)->whereIn('status', ['scheduled', 'in_progress'])->where('starts_at', '<', $end)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $start))->exists(), 409, 'The court is unavailable due to maintenance.');
    }

    public function amount(Court $court, Carbon $start, Carbon $end): float
    {
        $time = $start->format('H:i:s');
        $rule = PricingRule::where('court_id', $court->id)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('day_of_week')->orWhere('day_of_week', $start->dayOfWeek))
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $time))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $time))
            ->orderByDesc('day_of_week')->orderByDesc('starts_at')->first();

        return round((float) ($rule?->price ?? $court->base_price) * ($start->diffInMinutes($end) / 60), 2);
    }
}
