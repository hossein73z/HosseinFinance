<?php

use PHPUnit\Framework\TestCase;

class KeyboardFunctionsTest extends TestCase
{
    /**
     * Fake buttons table rows (id => row), like DB would return.
     * keyboards / attrs stored as JSON strings, same as from DB.
     */
    private function sampleButtons(): array
    {
        return [
            0 => [
                'id' => 0,
                'attrs' => json_encode(['text' => 'ریشه'], JSON_UNESCAPED_UNICODE),
                'keyboards' => json_encode([['1', '2'], ['3']], JSON_UNESCAPED_UNICODE),
            ],
            1 => [
                'id' => 1,
                'attrs' => json_encode(['text' => 'قیمت‌ها'], JSON_UNESCAPED_UNICODE),
                'keyboards' => null,
            ],
            2 => [
                'id' => 2,
                'attrs' => json_encode(['text' => 'وام‌ها'], JSON_UNESCAPED_UNICODE),
                'keyboards' => json_encode([['4']], JSON_UNESCAPED_UNICODE),
            ],
            3 => [
                'id' => 3,
                'attrs' => json_encode(['text' => 'تنظیمات'], JSON_UNESCAPED_UNICODE),
                'keyboards' => null,
            ],
            4 => [
                'id' => 4,
                'attrs' => json_encode(['text' => 'افزودن وام'], JSON_UNESCAPED_UNICODE),
                'keyboards' => null,
            ],
        ];
    }

    public function testCreateButtonTextTreeContainsRootAndChildren(): void
    {
        $tree = createButtonTextTree($this->sampleButtons());

        $this->assertStringContainsString('ریشه', $tree);
        $this->assertStringContainsString('قیمت‌ها', $tree);
        $this->assertStringContainsString('وام‌ها', $tree);
        $this->assertStringContainsString('تنظیمات', $tree);
        $this->assertStringContainsString('افزودن وام', $tree); // nested under وام‌ها
    }

    public function testCreateButtonTextTreeHasStructureSymbols(): void
    {
        $tree = createButtonTextTree($this->sampleButtons());

        $this->assertStringContainsString('┤──', $tree);
        $this->assertStringContainsString('┘──', $tree);
    }

    public function testCreateButtonTextTreeMissingRootKeyboards(): void
    {
        $buttons = [
            0 => [
                'id' => 0,
                'attrs' => json_encode(['text' => 'ریشه']),
                // no keyboards
            ],
        ];

        $tree = createButtonTextTree($buttons);
        $this->assertStringContainsString('Error', $tree);
    }
}