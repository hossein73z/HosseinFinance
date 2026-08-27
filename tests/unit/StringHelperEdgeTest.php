<?php

use PHPUnit\Framework\TestCase;

class StringHelperEdgeTest extends TestCase
{
    public function testCleanAndValidateNumberMixedPersianArabic(): void
    {
        $result = cleanAndValidateNumber('۱۲٣٤'); // Persian 1,2 + Arabic 3,4
        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(1234.0, (float)$result, 0.001);
    }

    public function testCleanAndValidateNumberOnlyDotsReturnsNullOrInvalid(): void
    {
        $result = cleanAndValidateNumber('...');
        $this->assertTrue($result === null || $result === false || $result === '');
    }

    public function testBeautifulNumberPreservesDecimals(): void
    {
        $result = beautifulNumber('1234.5', ',', false);
        $this->assertNotNull($result);
        $this->assertStringContainsString('1,234.5', $result);
    }

    public function testBeautifulNumberZero(): void
    {
        $result = beautifulNumber('0', ',', false);
        $this->assertSame('0', $result);
    }

    public function testMarkdownScapeEmptyString(): void
    {
        $this->assertSame('', markdownScape(''));
    }

    public function testMarkdownScapeLeavesNormalText(): void
    {
        $this->assertSame('hello', markdownScape('hello'));
    }

    public function testToEnglishDigitsDoesNotTouchLetters(): void
    {
        $this->assertSame('abcXYZ', toEnglishDigits('abcXYZ'));
    }
}