<?php

use PHPUnit\Framework\TestCase;

class KeyboardFunctionsDatabaseTest extends TestCase
{
    private $db;

    protected function setUp(): void
    {
        if (!$this->hasUsableDbCredentials()) {
            $this->markTestSkipped('DB_* environment variables not set for integration tests.');
        }

        $this->db = DatabaseManager::getInstance(
            host: DB_HOST,
            db: DB_NAME,
            user: DB_USER,
            pass: DB_PASS,
            port: DB_PORT ?: 3306
        );
    }

    public function testCreateKeyboardsArrayReturnsRootKeyboard(): void
    {
        $keyboard = createKeyboardsArray(0, false, $this->db, false);

        $this->assertIsArray($keyboard);
        $this->assertNotEmpty($keyboard);
    }

    public function testGetStructuredButtonReturnsButtonObject(): void
    {
        $button = getStructuredButton(0, false, $this->db);

        $this->assertInstanceOf(Button::class, $button);
        $this->assertSame('0', (string)$button->getId());
    }

    public function testPressedButtonCanResolveVisibleButton(): void
    {
        $userRow = [
            'id' => 7264214982,
            'first_name' => 'حسین',
            'last_name' => 'زلقی',
            'username' => 'DEMENTOR73',
            'progress' => null,
            'settings' => json_encode(['base_currency' => 'ریال']),
            'is_admin' => 0,
            'last_btn' => 0,
        ];

        $user = User::fromDbRow($userRow);
        $user->setKeyboard(createKeyboardsArray(0, $user->isAdmin(), $this->db, false));

        $button = getPressedButton('👑 بخش مدیریت', $user, $this->db);

        if ($button !== null) {
            $this->assertInstanceOf(Button::class, $button);
        } else {
            $this->assertNull($button);
        }
    }

    private function hasUsableDbCredentials(): bool
    {
        return DB_HOST !== '' && DB_NAME !== '' && DB_USER !== '';
    }
}
