<?php

use PHPUnit\Framework\TestCase;

class FavoritesHelperTest extends TestCase
{
    public function testCreateFavoritesInlineMarkupWithFavoritesAndLiveState(): void
    {
        $markup = createFavoritesInlineMarkup(true, true);

        $this->assertArrayHasKey('inline_keyboard', $markup);
        $this->assertCount(3, $markup['inline_keyboard']);
        $this->assertStringContainsString('توقف نمایش زنده', $markup['inline_keyboard'][0][0]['text']);
    }

    public function testCreateFavoritesInlineMarkupWithoutFavoritesOnlyHasEditButton(): void
    {
        $markup = createFavoritesInlineMarkup(false, false);

        $this->assertCount(1, $markup['inline_keyboard']);
        $this->assertSame('حذف / اضافه', $markup['inline_keyboard'][0][0]['text']);
    }

    public function testCreateFavoritesRichMessageGroupsAssetsByType(): void
    {
        $message = createFavoritesRichMessage([
            [
                'id' => 12,
                'asset_type' => 'ارز',
                'name' => 'دلار',
                'price' => 60000,
                'base_currency' => 'ریال',
                'exchange_rate' => 1,
                'date' => '1403-05-15',
                'time' => '12:30',
                'alerts' => '[{"id":null}]',
            ],
            [
                'id' => 470,
                'asset_type' => 'ارز',
                'name' => 'یورو',
                'price' => 65000,
                'base_currency' => 'ریال',
                'exchange_rate' => 1,
                'date' => '1403-05-15',
                'time' => '12:30',
                'alerts' => '[{"id":null}]',
            ],
        ], 'ریال');

        $this->assertTrue($message['is_rtl']);
        $this->assertStringContainsString('دلار', $message['html']);
        $this->assertStringContainsString('یورو', $message['html']);
        $this->assertStringContainsString('مرداد', $message['html']);
    }

    public function testCreateFavoritesRichMessageReturnsEmptyMessageForNoFavorites(): void
    {
        $message = createFavoritesRichMessage([], 'ریال');

        $this->assertSame('<p>لیست علاقه‌مندی‌های شما خالیست!</p>', $message['html']);
    }
}
