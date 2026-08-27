<?php

use PHPUnit\Framework\TestCase;

class JalaliDateTest extends TestCase
{
    public function testFormatDefault(): void
    {
        $date = new JalaliDate(1403, 5, 15);
        $this->assertSame('1403/05/15', $date->format());
    }

    public function testFormatCustomDelimiter(): void
    {
        $date = new JalaliDate(1403, 1, 1);
        $this->assertSame('1403-01-01', $date->format('-'));
    }

    public function testFromStringWithSlash(): void
    {
        $date = JalaliDate::fromString('1403/5/15');
        $this->assertSame(1403, $date->jy);
        $this->assertSame(5, $date->jm);
        $this->assertSame(15, $date->jd);
    }

    public function testFromStringWithDash(): void
    {
        $date = JalaliDate::fromString('1403-05-15');
        $this->assertSame('1403/05/15', $date->format());
    }

    public function testFromGregorianStringKnownDate(): void
    {
        // 2025-03-21 is Nowruz 1404
        $date = JalaliDate::fromGregorianString('2025-03-21');
        $this->assertSame(1404, $date->jy);
        $this->assertSame(1, $date->jm);
        $this->assertSame(1, $date->jd);
    }

    public function testRoundTripJalaliToGregorianToJalali(): void
    {
        $original = new JalaliDate(1403, 8, 20);
        $gregorian = $original->toGregorian();
        $back = JalaliDate::fromGregorianObject($gregorian);

        $this->assertSame($original->format(), $back->format());
    }

    public function testAddDaysWithinMonth(): void
    {
        $date = new JalaliDate(1403, 1, 1);
        $new = $date->addDays(10);
        $this->assertSame('1403/01/11', $new->format());
    }

    public function testSubDays(): void
    {
        $date = new JalaliDate(1403, 1, 11);
        $new = $date->subDays(10);
        $this->assertSame('1403/01/01', $new->format());
    }

    public function testAddMonths(): void
    {
        $date = new JalaliDate(1403, 11, 15);
        $new = $date->addMonths(2);
        $this->assertSame(1404, $new->jy);
        $this->assertSame(1, $new->jm);
        $this->assertSame(15, $new->jd);
    }

    public function testAddYears(): void
    {
        $date = new JalaliDate(1400, 6, 10);
        $new = $date->addYears(3);
        $this->assertSame('1403/06/10', $new->format());
    }

    public function testToPersianMonths(): void
    {
        $date = new JalaliDate(1403, 1, 5);
        $parts = $date->toPersianMonths();
        $this->assertSame(1403, $parts['year']);
        $this->assertSame('فروردین', $parts['month']);
        $this->assertSame(5, $parts['day']);
    }

    public function testDiffInDaysSameDay(): void
    {
        $a = new JalaliDate(1403, 1, 1);
        $b = new JalaliDate(1403, 1, 1);
        $this->assertSame(0, $a->diffInDays($b));
    }
}