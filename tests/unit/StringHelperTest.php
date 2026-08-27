<?php

use PHPUnit\Framework\TestCase;

class StringHelperTest extends TestCase
{
    // ---------- toEnglishDigits ----------

    public function testToEnglishDigitsConvertsPersian(): void
    {
        $this->assertSame('0123456789', toEnglishDigits('۰۱۲۳۴۵۶۷۸۹'));
    }

    public function testToEnglishDigitsConvertsArabic(): void
    {
        $this->assertSame('0123456789', toEnglishDigits('٠١٢٣٤٥٦٧٨٩'));
    }

    public function testToEnglishDigitsLeavesEnglish(): void
    {
        $this->assertSame('0123', toEnglishDigits('0123'));
    }

    public function testToEnglishDigitsMixedText(): void
    {
        $this->assertSame('قیمت 123', toEnglishDigits('قیمت ۱۲۳'));
    }

    public function testToEnglishDigitsEmpty(): void
    {
        $this->assertSame('', toEnglishDigits(''));
    }

    // ---------- cleanAndValidateNumber ----------
    // Note: The function returns float (floatval) or null

    public function testCleanAndValidateNumberSimple(): void
    {
        $this->assertSame(1234.0, cleanAndValidateNumber('1234'));
    }

    public function testCleanAndValidateNumberPersian(): void
    {
        $this->assertSame(1234.56, cleanAndValidateNumber('۱۲۳۴٫۵۶'));
    }

    public function testCleanAndValidateNumberWithComma(): void
    {
        // space and comma become dots, then sanitized
        $result = cleanAndValidateNumber('1,234');
        $this->assertNotNull($result);
        $this->assertTrue(is_numeric($result));
    }

    public function testCleanAndValidateNumberInvalid(): void
    {
        $this->assertNull(cleanAndValidateNumber('abc'));
    }

    // ---------- beautifulNumber ----------

    public function testBeautifulNumberAddsThousandsAndPersianDigits(): void
    {
        $result = beautifulNumber('1125000');
        $this->assertNotNull($result);
        // default: Persian digits + comma thousands
        $this->assertSame('1,125,000', toEnglishDigits($result));
    }

    public function testBeautifulNumberEnglishDigits(): void
    {
        $result = beautifulNumber('1125000', ',', false);
        $this->assertSame('1,125,000', $result);
    }

    public function testBeautifulNumberInvalidReturnsNull(): void
    {
        $this->assertNull(beautifulNumber('not-a-number'));
    }

    // ---------- markdownScape ----------

    public function testMarkdownScapeEscapesSpecialChars(): void
    {
        $this->assertSame('\\(\\-\\.\\!', markdownScape('(-.!'));
    }

    public function testMarkdownScapeNullReturnsEmpty(): void
    {
        $this->assertSame('', markdownScape(null));
    }
}