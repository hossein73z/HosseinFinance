<?php

use PHPUnit\Framework\TestCase;

class JalaliDateEdgeTest extends TestCase
{
    public function testAddDaysCrossesMonthBoundary(): void
    {
        // Farvardin has 31 days
        $date = new JalaliDate(1403, 1, 31);
        $new = $date->addDays(1);

        $this->assertSame(1403, $new->jy);
        $this->assertSame(2, $new->jm);
        $this->assertSame(1, $new->jd);
    }

    public function testAddDaysCrossesYearBoundary(): void
    {
        $date = new JalaliDate(1403, 12, 29);
        // Esfand length depends on leap year; adding enough days must land in next year
        $new = $date->addDays(5);

        $this->assertSame(1404, $new->jy);
        $this->assertSame(1, $new->jm);
    }

    public function testAddMonthsClampsDayInShortMonth(): void
    {
        // Day 31 in Farvardin → add 6 months → Mehr has 30 days → day becomes 30
        $date = new JalaliDate(1403, 1, 31);
        $new = $date->addMonths(6);

        $this->assertSame(1403, $new->jy);
        $this->assertSame(7, $new->jm);
        $this->assertLessThanOrEqual(30, $new->jd);
    }

    public function testSubMonthsCrossesYear(): void
    {
        $date = new JalaliDate(1403, 1, 10);
        $new = $date->subMonths(2);

        $this->assertSame(1402, $new->jy);
        $this->assertSame(11, $new->jm);
        $this->assertSame(10, $new->jd);
    }

    public function testFromStringWithPersianMonthName(): void
    {
        $date = JalaliDate::fromString('1403 فروردین 15', ' ');

        $this->assertSame(1403, $date->jy);
        $this->assertSame(1, $date->jm);
        $this->assertSame(15, $date->jd);
    }

    public function testFormatPadsMonthAndDay(): void
    {
        $date = new JalaliDate(1403, 1, 5);
        $this->assertSame('1403/01/05', $date->format());
    }

    public function testDiffInDaysOrdered(): void
    {
        $a = new JalaliDate(1403, 1, 1);
        $b = new JalaliDate(1403, 1, 11);

        // Sign depends on your implementation (a.diff(b) vs b.diff(a))
        $diff = $a->diffInDays($b);
        $this->assertSame(10, abs($diff));
    }

    public function testRoundTripSeveralDates(): void
    {
        $samples = [
            [1400, 1, 1],
            [1402, 12, 29],
            [1403, 6, 15],
            [1399, 12, 30], // may be leap-related
        ];

        foreach ($samples as [$y, $m, $d]) {
            $original = new JalaliDate($y, $m, $d);
            $back = JalaliDate::fromGregorianObject($original->toGregorian());
            $this->assertSame(
                $original->format(),
                $back->format(),
                "Round-trip failed for {$original->format()}"
            );
        }
    }
}