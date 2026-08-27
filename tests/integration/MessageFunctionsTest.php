<?php

use PHPUnit\Framework\TestCase;

/**
 * Integration tests because these tests call Telegram API.
 */
class MessageFunctionsTest extends TestCase
{
    public function testSendLoadingMessageReturnsTelegramPayload(): void
    {
        $result = sendLoadingMessage(TEST_CHAT_ID, 'در حال پردازش...');

        $this->assertIsArray($result);
        $this->assertEquals(TEST_CHAT_ID, $result['result']['chat']['id']);
        $this->assertSame('در حال پردازش...', $result['result']['text']);
    }

    public function testSendLoadingMessageCreatesDisabledButtonMarkup(): void
    {
        $result = sendLoadingMessage(TEST_CHAT_ID, 'Loading');

        $this->assertArrayHasKey('reply_markup', $result['result']);
        $this->assertArrayHasKey('inline_keyboard', $result['result']['reply_markup']);
        $this->assertSame('...', $result['result']['reply_markup']['inline_keyboard'][0][0]['text']);
        $this->assertArrayHasKey('disabled', $result['result']['reply_markup']['inline_keyboard'][0][0]);
    }

    public function testSendLoadingMessageHandlesDifferentTexts(): void
    {
        $result = sendLoadingMessage(TEST_CHAT_ID, 'Please wait');

        $this->assertEquals(TEST_CHAT_ID, $result['result']['chat']['id']);
        $this->assertSame('Please wait', $result['result']['text']);
    }
}
