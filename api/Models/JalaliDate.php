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
     * Static constructor from Gregorian date.
     */
    public static function fromGregorian(int|string|null $gy = null, int|string|null $gm = null, int|string|null $gd = null): self
    {
        $jalali = self::gregorianToJalali($gy, $gm, $gd);
        return new self($jalali['jy'], $jalali['jm'], $jalali['jd']);
    }

    /**
     * Static constructor from string.
     * Supported format: year/month/day
     */
    public static function fromString(string $date, string $delimiter = '/'): self
    {
        $date = explode("$delimiter", $date);
        $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
        $date[1] = str_replace($months, ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'], $date[1]);
        return new self(intval($date[0]), intval($date[1]), intval($date[2]));
    }

    /**
     * Converts Jalali to Gregorian (returns [y,m,d]).
     */
    public function toGregorian(): array
    {
        [$gy, $gm, $gd] = self::jalaliToGregorian($this->jy, $this->jm, $this->jd);
        return [$gy, $gm, $gd];
    }

    /**
     * Format Jalali date.
     */
    public function format(string $delimiter = '/'): string
    {
        return sprintf('%04d%s%02d%s%02d', $this->jy, $delimiter, $this->jm, $delimiter, $this->jd);
    }

    /**
     * Add days (returns new JalaliDate object).
     */
    public function addDays(int $days): self
    {
        [$gy, $gm, $gd] = $this->toGregorian();
        $newTs = mktime(0, 0, 0, $gm, $gd + $days, $gy);
        return self::fromGregorian(date('Y', $newTs), date('m', $newTs), date('d', $newTs));
    }

    /**
     * Subtract days (returns new JalaliDate object).
     */
    public function subDays(int $days): self
    {
        return $this->addDays(-$days);
    }

    /**
     * Days difference between two JalaliDate objects.
     */
    public function diffInDays(JalaliDate $other): int
    {
        [$gy1, $gm1, $gd1] = $this->toGregorian();
        [$gy2, $gm2, $gd2] = $other->toGregorian();

        $ts1 = mktime(0, 0, 0, $gm1, $gd1, $gy1);
        $ts2 = mktime(0, 0, 0, $gm2, $gd2, $gy2);

        return (int)round(($ts1 - $ts2) / 86400);
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
