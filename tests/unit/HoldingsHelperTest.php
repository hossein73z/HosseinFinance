<?php

use PHPUnit\Framework\TestCase;

class HoldingsHelperTest extends TestCase
{
    public function testCalculateProLosDefaultAmountAndRate(): void
    {
        $this->assertSame(25.0, calculateProLos(100, 125));
    }

    // ---------- calculateProLos (from before) ----------

    public function testCalculateProLosProfit(): void
    {
        $this->assertSame(100.0, calculateProLos(100, 150, 2));
    }

    public function testCalculateProLosLoss(): void
    {
        $this->assertSame(-50.0, calculateProLos(100, 50));
    }

    public function testCalculateProLosZero(): void
    {
        $this->assertSame(0.0, calculateProLos(100, 100, 5));
    }

    public function testCalculateProLosWithExchangeRate(): void
    {
        $this->assertSame(600.0, calculateProLos(100, 200, 3, 2));
    }

    // ---------- createHoldingDetailText ----------

    private function sampleHolding(): array
    {
        return [
            'id' => 42,
            'asset_name' => 'طلای ۱۸',
            'date' => '1403/05/15',
            'amount' => 2,
            'avg_price' => 1000,
            'current_price' => 1200,
            'base_currency' => 'ریال',
            'exchange_rate' => 1,
        ];
    }

    public function testCreateHoldingDetailTextContainsAssetName(): void
    {
        $text = createHoldingDetailText($this->sampleHolding());
        $this->assertStringContainsString('طلای ۱۸', $text);
    }

    public function testCreateHoldingDetailTextContainsTreeParts(): void
    {
        $text = createHoldingDetailText($this->sampleHolding());

        $this->assertStringContainsString('تاریخ خرید', $text);
        $this->assertStringContainsString('مرداد', $text); // month name from toPersianMonths
        $this->assertStringContainsString('مقدار', $text);
        $this->assertStringContainsString('قیمت خرید', $text);
        $this->assertStringContainsString('قیمت لحظه‌ای', $text);
    }

    public function testCreateHoldingDetailTextShowsProfit(): void
    {
        // (1200 - 1000) * 2 * 1 = 400 profit → 🟢
        $text = createHoldingDetailText($this->sampleHolding());
        $this->assertStringContainsString('🟢', $text);
        $this->assertStringContainsString('سود', $text);
    }

    public function testCreateHoldingDetailTextShowsLoss(): void
    {
        $holding = $this->sampleHolding();
        $holding['current_price'] = 800; // loss

        $text = createHoldingDetailText($holding);
        $this->assertStringContainsString('🔴', $text);
        $this->assertStringContainsString('ضرر', $text);
    }

    public function testCreateHoldingDetailTextZeroProfit(): void
    {
        $holding = $this->sampleHolding();
        $holding['current_price'] = 1000;

        $text = createHoldingDetailText($holding);
        $this->assertStringContainsString('🟤', $text);
    }

    public function testCreateHoldingDetailTextMarkdownDeepLink(): void
    {
        $text = createHoldingDetailText(
            $this->sampleHolding(),
            'MarkdownV2',
            'ریال',
            ['space', 'profit'],
            '77',
            '88'
        );

        $this->assertStringContainsString('viewHolding_holdingId42', $text);
        $this->assertStringContainsString('holdingsMssgId77', $text);
        $this->assertStringContainsString('initMssgId88', $text);
        $this->assertStringContainsString('t.me/TestBot', $text);
    }

    public function testCreateHoldingDetailTextCustomAttributesOnlyAmount(): void
    {
        $text = createHoldingDetailText(
            $this->sampleHolding(),
            null,
            'ریال',
            ['org_amount']
        );

        $this->assertStringContainsString('مقدار', $text);
        $this->assertStringNotContainsString('تاریخ خرید', $text);
        $this->assertStringNotContainsString('سود', $text);
        $this->assertStringNotContainsString('ضرر', $text);
    }
}