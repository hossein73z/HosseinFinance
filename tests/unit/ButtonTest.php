<?php

use PHPUnit\Framework\TestCase;

class ButtonTest extends TestCase
{
    public function testFromDbRow(): void
    {
        $btn = Button::fromDbRow([
            'id' => 'main',
            'attrs' => json_encode(['text' => 'منوی اصلی'], JSON_UNESCAPED_UNICODE),
            'admin_key' => 0,
            'messages' => 'hello',
            'belong_to' => null,
            'keyboard' => json_encode([['a', 'b']]),
        ]);

        $this->assertSame('main', $btn->getId());
        $this->assertSame('منوی اصلی', $btn->getText());
        $this->assertFalse($btn->isAdminKey());
        $this->assertSame('hello', $btn->getMessages());
        $this->assertTrue($btn->hasKeyboard());
    }

    public function testGetTextFallback(): void
    {
        $btn = new Button('x', [], false, null, null, null);
        $this->assertSame('Unknown Button', $btn->getText());
    }

    public function testHasKeyboardsEmpty(): void
    {
        $btn = new Button('x', ['text' => 'A'], false, null, null, []);
        $this->assertFalse($btn->hasKeyboard());
    }

    public function testToDbArrayEncodesJson(): void
    {
        $btn = new Button(
            'x',
            ['text' => 'تست'],
            true,
            null,
            'parent',
            [['1']]
        );
        $row = $btn->toDbArray();

        $this->assertSame(1, $row['admin_key']);
        $this->assertSame('parent', $row['belong_to']);
        $this->assertStringContainsString('تست', $row['attrs']);
    }
}