<?php

namespace App\Services\Reports;

final class JapaneseHolidayCalendar
{
    public static function name(string $date): ?string
    {
        static $calendar = null;
        $calendar ??= json_decode(file_get_contents(resource_path('calendar/japanese-holidays.json')), true, 512, JSON_THROW_ON_ERROR);

        return $calendar['names'][$date] ?? null;
    }
}
