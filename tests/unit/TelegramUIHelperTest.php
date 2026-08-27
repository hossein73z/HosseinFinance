<?php

use PHPUnit\Framework\TestCase;

class TelegramUIHelperTest extends TestCase
{
    public function testCreateWebAppBtnBasic(): void
    {
        $btn = createWebAppBtn('باز کردن', '/assets/loan.html', ['id' => '5']);

        $this->assertSame('باز کردن', $btn['text']);
        $this->assertArrayHasKey('web_app', $btn);
        $this->assertStringStartsWith('https://example.com/assets/loan.html?', $btn['web_app']['url']);
        $this->assertStringContainsString('id=5', $btn['web_app']['url']);
        $this->assertStringNotContainsString('api_key', $btn['web_app']['url']);
    }

    public function testCreateWebAppBtnWithApi(): void
    {
        $btn = createWebAppBtn('وب‌اپ', '/assets/holding.html', [], true);

        $this->assertStringContainsString('api_url=', $btn['web_app']['url']);
        $this->assertStringContainsString('api_key=test-secret', $btn['web_app']['url']);
    }
}