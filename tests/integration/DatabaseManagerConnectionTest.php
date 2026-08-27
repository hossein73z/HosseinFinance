<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for DatabaseManager credential handling and connection behaviour.
 * These tests require a real database connection and therefore belong to integration suite.
 */
class DatabaseManagerConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        DatabaseManager::closeConnection();
    }

    protected function tearDown(): void
    {
        DatabaseManager::closeConnection();
    }

    public function testSingletonReturnsSameInstance(): void
    {
        if (!$this->hasUsableDbCredentials()) {
            $this->markTestSkipped('DB_* environment variables not set for a live connection.');
        }

        $a = DatabaseManager::getInstance(DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT ?: 3306);
        $b = DatabaseManager::getInstance();

        $this->assertSame($a, $b);
    }

    public function testCloseConnectionAllowsReinitialization(): void
    {
        if (!$this->hasUsableDbCredentials()) {
            $this->markTestSkipped('DB_* environment variables not set for a live connection.');
        }

        $first = DatabaseManager::getInstance(DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT ?: 3306);
        DatabaseManager::closeConnection();
        $second = DatabaseManager::getInstance(DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT ?: 3306);

        $this->assertNotSame($first, $second);
    }

    public function testSuccessfulConnectionWithEnvCredentials(): void
    {
        if (!$this->hasUsableDbCredentials()) {
            $this->markTestSkipped('DB_* environment variables not set for a live connection.');
        }

        $db = DatabaseManager::getInstance(DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT ?: 3306);

        $this->assertInstanceOf(PDO::class, $db->getConnection());
        $row = $db->query('SELECT 1 AS ok')->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(1, (int)$row['ok']);
    }

    public function testConnectionUsesUtf8mb4Charset(): void
    {
        if (!$this->hasUsableDbCredentials()) {
            $this->markTestSkipped('DB_* environment variables not set for a live connection.');
        }

        $db = DatabaseManager::getInstance(DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT ?: 3306);
        $row = $db->query("SHOW VARIABLES LIKE 'character_set_connection'")->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertStringContainsString('utf8mb4', strtolower($row['Value'] ?? ''));
    }

    public function testInvalidCredentialsProduceGenericErrorMessage(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Database connection failed. Check server logs.');

        DatabaseManager::getInstance('127.0.0.1', 'definitely_not_a_real_db_' . bin2hex(random_bytes(4)), 'invalid', 'wrong', 3306);
    }

    public function testExceptionMessageDoesNotContainPassword(): void
    {
        $secretPass = 'super_secret_pass_' . bin2hex(random_bytes(8));

        try {
            DatabaseManager::getInstance('127.0.0.1', 'no_such_db', 'no_such_user', $secretPass, 59999);
            $this->fail('Expected Exception was not thrown');
        } catch (Exception $e) {
            $this->assertStringNotContainsString($secretPass, $e->getMessage());
            $this->assertStringNotContainsString('super_secret_pass_', $e->getMessage());
        }
    }

    private function hasUsableDbCredentials(): bool
    {
        return is_string(DB_HOST) && DB_HOST !== ''
            && is_string(DB_NAME) && DB_NAME !== ''
            && is_string(DB_USER) && DB_USER !== '';
    }
}
