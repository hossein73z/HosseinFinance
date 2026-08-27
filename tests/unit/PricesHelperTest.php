<?php

use PHPUnit\Framework\TestCase;

class PricesHelperTest extends TestCase
{
    private function sampleAssets(): array
    {
        return [
            [
                'name' => 'طلا',
                'price' => '1000',
                'base_currency' => 'دلار',
                'date' => '1403-05-15',
                'time' => '12:30',
            ],
            [
                'name' => 'نقره',
                'price' => '50',
                'base_currency' => 'ریال',
                'date' => '1403-05-15',
                'time' => '12:30',
            ],
        ];
    }

    public function testCreatePricesTextContainsDateAndTime(): void
    {
        $text = createPricesTextForSingleAssetType(
            $this->sampleAssets(),
            ['دلار' => 60000.0, 'ریال' => 1.0],
            'ریال'
        );

        // Month 05 → مرداد (from your mapping)
        $this->assertStringContainsString('مرداد', toEnglishDigits($text));
        $this->assertStringContainsString('12:30', toEnglishDigits($text));
    }

    public function testCreatePricesTextContainsAssetNames(): void
    {
        $text = createPricesTextForSingleAssetType(
            $this->sampleAssets(),
            ['دلار' => 60000.0, 'ریال' => 1.0],
            'ریال'
        );

        $plain = toEnglishDigits($text);
        $this->assertStringContainsString('طلا', $text);
        $this->assertStringContainsString('نقره', $text);
    }

    public function testCreatePricesTextAddsConversionWhenCurrenciesDiffer(): void
    {
        $text = createPricesTextForSingleAssetType(
            [
                [
                    'name' => 'طلا',
                    'price' => '2',
                    'base_currency' => 'دلار',
                    'date' => '1403-01-01',
                    'time' => '10:00',
                ],
            ],
            ['دلار' => 60000.0, 'ریال' => 1.0],
            'ریال'
        );

        // exchange: base_prices[دلار] / base_prices[ریال] = 60000
        // based_price = 2 * 60000 = 120000 → should appear after -->
        $this->assertStringContainsString('-->', $text);
    }

    public function testCreatePricesTextNoConversionWhenSameCurrency(): void
    {
        $text = createPricesTextForSingleAssetType(
            [
                [
                    'name' => 'سکه',
                    'price' => '100',
                    'base_currency' => 'ریال',
                    'date' => '1403-01-01',
                    'time' => '10:00',
                ],
            ],
            ['ریال' => 1.0],
            'ریال'
        );

        $this->assertStringNotContainsString('-->', $text);
    }
}