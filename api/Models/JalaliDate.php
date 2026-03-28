<?php

class JalaliDate
{
    public int $jy;
    public int $jm;
    public int $jd;

    /**
     * Construct from Jalali components.
     */
    public function __construct(int $jy, int $jm, int $jd)
    {
        $this->jy = $jy;
        $this->jm = $jm;
        $this->jd = $jd;
    }

    /**
     * Static constructor from Gregorian date array.
     * Format: [Y, m, d]
     */
    public static function fromGregorian(array|null $Ymd = null): self
    {
        $jalali = self::gregorianToJalali($Ymd ? $Ymd[0] : null, $Ymd ? $Ymd[1] : null, $Ymd ? $Ymd[2] : null);
        return new self($jalali['jy'], $jalali['jm'], $jalali['jd']);
    }

    /**
     * Static constructor from Gregorian date as DateTime object.
     */
    public static function fromGregorianObject(DateTime $Ymd): self
    {
        $jalali = self::gregorianToJalali($Ymd->format('Y'), $Ymd->format('m'), $Ymd->format('d'));
        return new self($jalali['jy'], $jalali['jm'], $jalali['jd']);
    }

    /**
     * Static constructor from Gregorian string.
     */
    public static function fromGregorianString(string $date_string, string $format = 'Y-m-d'): self
    {
        $g_date = DateTime::createFromFormat($format, $date_string);
        return self::fromGregorianObject($g_date);
    }

    /**
     * Static constructor from string.
     * Supported format: year/month/day
     */
    public static function fromString(string $date_string, ?string $delimiter = null): self
    {
        if ($delimiter) $date_array = explode("$delimiter", $date_string);
        else {
            preg_match_all("/(\d{4}).+?(\d\d?).+?(\d\d?)/u", $date_string, $matches);
            $date_array[0] = $matches[1][0]; // Year
            $date_array[1] = $matches[2][0]; // Month
            $date_array[2] = $matches[3][0]; // Day
            // 1404-03-06
        }

        $date_array[1] = str_replace(
            ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'],
            ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'], $date_array[1]
        );
        return new self(intval($date_array[0]), intval($date_array[1]), intval($date_array[2]));
    }

    /**
     * Convert Jalali date to Gregorian DateTime object.
     */
    public function toGregorian(): DateTime
    {
        [$gy, $gm, $gd] = self::jalaliToGregorian($this->jy, $this->jm, $this->jd);
        return new DateTime("$gy-$gm-$gd");
    }

    /**
     * Format Jalali date.
     */
    public function format(string $delimiter = '/'): string
    {
        return sprintf('%04d%s%02d%s%02d', $this->jy, $delimiter, $this->jm, $delimiter, $this->jd);
    }

    /**
     * Change month number, to Persian name.
     * Returns array instead of JalaliDate object.
     */
    public function toPersianMonths(): array
    {
        $month = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'][$this->jm + 1];
        return ['year' => $this->jy, 'month' => $month, 'day' => $this->jd];

    }

    /**
     * Add days (returns new JalaliDate object).
     */
    public function addDays(int $days): self
    {
        $gregorian = $this->toGregorian();
        $newDate = clone $gregorian;
        $newDate->modify("$days days");
        return self::fromGregorian([
            $newDate->format('Y'),
            $newDate->format('m'),
            $newDate->format('d')
        ]);
    }

    /**
     * Subtract days (returns new JalaliDate object).
     */
    public function subDays(int $days): self
    {
        return $this->addDays(-$days);
    }

    /**
     * Add months (returns new JalaliDate object).
     */
    public function addMonths(int $months): self
    {
        $newYear = $this->jy + intdiv($months, 12);
        $newMonth = $this->jm + ($months % 12);

        if ($newMonth > 12) {
            $newMonth -= 12;
            $newYear++;
        } elseif ($newMonth < 1) {
            $newMonth += 12;
            $newYear--;
        }

        // Number of days in this month
        $daysInMonth = $this->getJalaliMonthLength($newYear, $newMonth);
        $newDay = min($this->jd, $daysInMonth);

        return new self($newYear, $newMonth, $newDay);
    }

    /**
     * Subtract months (returns new JalaliDate object).
     */
    public function subMonths(int $months): self
    {
        return $this->addMonths(-$months);
    }

    /**
     * Add years (returns new JalaliDate object).
     */
    public function addYears(int $years): self
    {
        $newYear = $this->jy + $years;
        $daysInMonth = $this->getJalaliMonthLength($newYear, $this->jm);
        $newDay = min($this->jd, $daysInMonth);

        return new self($newYear, $this->jm, $newDay);
    }

    /**
     * Subtract years (returns new JalaliDate object).
     */
    public function subYears(int $years): self
    {
        return $this->addYears(-$years);
    }

    /**
     * Internal helper: get number of days in a Jalali month.
     */
    private function getJalaliMonthLength(int $jy, int $jm): int
    {
        if ($jm <= 6) return 31;
        if ($jm <= 11) return 30;

        // Esfand: 29 or leap year 30
        return $this->isJalaliLeap($jy) ? 30 : 29;
    }

    /**
     * Jalali leap year check.
     * Accurate algorithm based on 33-year cycles.
     */
    private function isJalaliLeap(int $year): bool
    {
        $mod = ($year - 474) % 2820;
        $mod = $mod < 0 ? $mod + 2820 : $mod;
        return ((($mod + 474 + 38) * 682) % 2816) < 682;
    }

    /**
     * Days difference between two JalaliDate objects.
     */
    public function diffInDays(JalaliDate $other): int
    {
        $gregorian1 = $this->toGregorian();
        $gregorian2 = $other->toGregorian();
        $diff = $gregorian1->diff($gregorian2);
        $days = $diff->days;

        return $diff->invert ? $days : -$days;
    }

    /* ----------------------------- Internal Conversion Methods ------------------------------ */

    private static function gregorianToJalali(int|string|null $g_y = null, int|string|null $g_m = null, int|string|null $g_d = null): array
    {
        $g_y = $g_y ?? date('Y');
        $g_m = $g_m ?? date('m');
        $g_d = $g_d ?? date('d');

        $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

        $gy = (int)$g_y - 1600;
        $gm = (int)$g_m - 1;
        $gd = (int)$g_d - 1;

        $g_day_no = 365 * $gy + floor(($gy + 3) / 4)
            - floor(($gy + 99) / 100) + floor(($gy + 399) / 400);

        for ($i = 0; $i < $gm; ++$i) {
            $g_day_no += $g_days_in_month[$i];
        }

        if ($gm > 1 && (($g_y % 4 == 0 && $g_y % 100 != 0) || ($g_y % 400 == 0))) {
            $g_day_no++;
        }

        $g_day_no += $gd;
        $j_day_no = $g_day_no - 79;
        $j_np = floor($j_day_no / 12053);
        $j_day_no %= 12053;

        $jy = 979 + 33 * $j_np + 4 * floor($j_day_no / 1461);
        $j_day_no %= 1461;
        if ($j_day_no >= 366) {
            $jy += floor(($j_day_no - 1) / 365);
            $j_day_no = ($j_day_no - 1) % 365;
        }

        for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; ++$i) {
            $j_day_no -= $j_days_in_month[$i];
        }

        $jm = $i + 1;
        $jd = $j_day_no + 1;

        return ['jy' => $jy, 'jm' => $jm, 'jd' => $jd];
    }

    private static function jalaliToGregorian(int $j_y, int $j_m, int $j_d): array
    {
        $jy = $j_y - 979;
        $jm = $j_m - 1;
        $jd = $j_d - 1;
        $j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

        $j_day_no = 365 * $jy + floor($jy / 33) * 8 + floor(($jy % 33 + 3) / 4);
        for ($i = 0; $i < $jm; ++$i) {
            $j_day_no += $j_days_in_month[$i];
        }
        $j_day_no += $jd;
        $g_day_no = $j_day_no + 79;

        $gy = 1600 + 400 * floor($g_day_no / 146097);
        $g_day_no %= 146097;
        $leap = true;

        if ($g_day_no >= 36525) {
            $g_day_no--;
            $gy += 100 * floor($g_day_no / 36524);
            $g_day_no %= 36524;
            if ($g_day_no >= 365) {
                $g_day_no++;
            } else {
                $leap = false;
            }
        }

        $gy += 4 * floor($g_day_no / 1461);
        $g_day_no %= 1461;
        if ($g_day_no >= 366) {
            $leap = false;
            $g_day_no--;
            $gy += floor($g_day_no / 365);
            $g_day_no %= 365;
        }

        $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        if ($leap) $g_days_in_month[1] = 29;

        $gm = 0;
        while ($g_day_no >= $g_days_in_month[$gm]) {
            $g_day_no -= $g_days_in_month[$gm];
            $gm++;
        }

        $gm++;
        $gd = $g_day_no + 1;
        return [$gy, $gm, $gd];
    }
}
