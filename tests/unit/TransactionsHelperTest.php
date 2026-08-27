<?php

use PHPUnit\Framework\TestCase;

class TransactionsHelperTest extends TestCase
{
    public function testExtractInwardBluSms(): void
    {
        $sms = "بلو" .
            "واریز پول" .
            "حسین عزیز، 10,000,000 ریال به حساب شما نشست." .
            "موجودی: 84,612,522 ریال" .
            "۱۸:۴۰" .
            "۱۴۰۵.۰۵.۲۷";

        $tx = extractTransactionFromText($sms);

        $this->assertNotNull($tx);
        $this->assertSame('بلو', $tx['bank']);
        $this->assertSame('inward', $tx['type']);
        $this->assertNotNull($tx['amount']);
        $this->assertNotNull($tx['balance']);
    }

    public function testExtractOutwardBluSms(): void
    {
        $sms = "بلو" .
            "برداشت پول" .
            "حسین عزیز، 37,840,000 ریال از حساب شما پرید." .
            "موجودی: 44,272,522 ریال" .
            "۱۷:۵۱" .
            "۱۴۰۵.۰۶.۰۳";
        $tx = extractTransactionFromText($sms);

        $this->assertNotNull($tx);
        $this->assertSame('outward', $tx['type']);
        $this->assertNotNull($tx['amount']);
    }

    public function testNonBluReturnsEmptyOrIncomplete(): void
    {
        $tx = extractTransactionFromText('پیام عادی بدون بانک');
        // function only fills $transaction inside the بلو branch
        $this->assertTrue($tx === null || $tx === []);
    }
}