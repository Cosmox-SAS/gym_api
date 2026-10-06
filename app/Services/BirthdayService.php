<?php

namespace App\Services;

use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BirthdayService
{
    /**
     * Clientes del gimnasio que cumplen años entre hoy y los próximos $days días,
     * ordenados por cercanía. Los nacidos el 29 de febrero cumplen el 28 en años no bisiestos.
     */
    public function upcoming(int $gimnasioId, int $days = 7, ?Carbon $today = null): Collection
    {
        $today = ($today ?? Carbon::now())->copy()->startOfDay();
        $limit = $today->copy()->addDays($days);

        return Member::where('gimnasio_id', $gimnasioId)
            ->whereNotNull('birth_date')
            ->get(['id', 'name', 'phone', 'birth_date'])
            ->map(function (Member $member) use ($today) {
                $birthDate = Carbon::parse($member->birth_date)->startOfDay();
                $next = $this->birthdayInYear($birthDate, $today->year);
                if ($next->lt($today)) {
                    $next = $this->birthdayInYear($birthDate, $today->year + 1);
                }

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'phone' => $member->phone,
                    'birth_date' => $birthDate->toDateString(),
                    'birthday' => $next->toDateString(),
                    'days_until' => (int) $today->diffInDays($next),
                    'turning_age' => $next->year - $birthDate->year,
                ];
            })
            ->filter(fn (array $b) => Carbon::parse($b['birthday'])->lte($limit))
            ->sortBy([['days_until', 'asc'], ['name', 'asc']])
            ->values();
    }

    private function birthdayInYear(Carbon $birthDate, int $year): Carbon
    {
        $day = $birthDate->day;
        if ($birthDate->month === 2 && $day === 29 && !Carbon::create($year)->isLeapYear()) {
            $day = 28;
        }

        return Carbon::create($year, $birthDate->month, $day)->startOfDay();
    }
}
