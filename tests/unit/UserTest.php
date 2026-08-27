<?php

use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testFromDbRowMapsFields(): void
    {
        $user = User::fromDbRow([
            'id' => '7',
            'first_name' => 'حسین',
            'last_name' => 'زلغی',
            'username' => 'hossein',
            'settings' => null,
            'progress' => null,
            'is_admin' => 1,
            'last_btn' => 'main',
        ]);

        $this->assertSame(7, $user->getId());
        $this->assertSame('حسین', $user->getFirstName());
        $this->assertSame('زلغی', $user->getLastName());
        $this->assertSame('hossein', $user->getUsername());
        $this->assertTrue($user->isAdmin());
        $this->assertSame('main', $user->getLastBtn());
    }

    public function testDefaultBaseCurrency(): void
    {
        $user = new User(1, 'Ali', null, null, null, null);
        $this->assertSame('ریال', $user->getBaseCurrency());
    }

    public function testSetAndGetBaseCurrency(): void
    {
        $user = new User(1, 'Ali', null, null, null, null);
        $user->setBaseCurrency('دلار');
        $this->assertSame('دلار', $user->getBaseCurrency());
    }

    public function testSettingsRoundTrip(): void
    {
        $user = new User(1, 'Ali', null, null, null, null);
        $user->setSettings(['base_currency' => 'یورو', 'theme' => 'dark']);
        $settings = $user->getSettings();

        $this->assertSame('یورو', $settings['base_currency']);
        $this->assertSame('dark', $settings['theme']);
    }

    public function testProgressRoundTrip(): void
    {
        $user = new User(1, 'Ali', null, null, null, null);
        $user->setProgress(['step' => 2, 'data' => ['x' => 1]]);
        $progress = $user->getProgress();

        $this->assertSame(2, $progress['step']);
        $this->assertSame(1, $progress['data']['x']);
    }

    public function testFullNameWithAndWithoutLastName(): void
    {
        $with = new User(1, 'Ali', 'Rezaei', null, null, null);
        $without = new User(2, 'Ali', null, null, null, null);

        $this->assertSame('Ali Rezaei', $with->getFullName());
        $this->assertSame('Ali', $without->getFullName());
    }

    public function testGetMention(): void
    {
        $withUser = new User(1, 'Ali', null, 'ali_r', null, null);
        $withoutUser = new User(2, 'Ali', null, null, null, null);

        $this->assertSame('@ali_r', $withUser->getMention());
        $this->assertSame('Ali', $withoutUser->getMention());
    }

    public function testDetailedLoanDefaultAndSet(): void
    {
        $user = new User(1, 'Ali', null, null, null, null);
        $this->assertFalse($user->getDetailedLoan());

        $user->setDetailedLoan(true);
        $this->assertTrue($user->getDetailedLoan());
    }

    public function testToDbArrayIsAdminAsInt(): void
    {
        $user = new User(1, 'Ali', null, null, null, null, null, true);
        $row = $user->toDbArray();
        $this->assertSame(1, $row['is_admin']);
    }
}