<?php

class User implements JsonSerializable
{
    public function __construct(
        private int                $id,
        private string             $firstName,
        private ?string            $lastName = null,
        private ?string            $username = null,
        private ?string            $settings = null,
        private ?string            $progress = null,
        private Button|string|null $button = null,
        private bool               $isAdmin = false
    )
    {
    }

    /**
     * Factory method to easily create a User instance from a database row
     */
    public static function fromDbRow(array $row): self
    {
        $button = null;
        if (isset($row['button'])) {
            if (is_string($row['button'])) $button = Button::fromDbRow(json_decode($row['button'], true));
            elseif (is_array($row['button'])) $button = Button::fromDbRow($row['button']);
            elseif ($row['button'] instanceof Button) $button = $row['button'];
        }
        return new self(
            id: (int)$row['id'],
            firstName: $row['first_name'],
            lastName: $row['last_name'] ?? null,
            username: $row['username'] ?? null,
            settings: $row['settings'] ?? null,
            progress: $row['progress'] ?? null,
            button: $button,
            isAdmin: (bool)($row['is_admin'] ?? false),
        );
    }

    // --- Getters ---

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    /**
     * Get settings as an associative array (decoded from JSON)
     */
    public function getSettings(): ?array
    {
        return $this->settings ? json_decode($this->settings, true) : null;
    }

    /**
     * Replace the full settings array (stored as JSON internally)
     */
    public function setSettings(?array $settings): self
    {
        $this->settings = $settings ? json_encode($settings, JSON_UNESCAPED_UNICODE) : null;
        return $this;
    }

    public function getProgress(): ?array
    {
        if ($this->progress) return json_decode($this->progress, true);
        else return null;
    }

    public function getButton(): ?Button
    {
        return $this->button;
    }

    public function getButtonId(): ?int
    {
        if (!$this->button) return null;
        else return $this->button->getId();
    }

    public function getKeyboard(): ?array
    {
        if ($this->button) return $this?->button->getKeyboard();
        else return null;
    }

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    // --- Setters ---

    public function setId(?int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function setFirstName(string $firstName): self
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function setLastName(?string $lastName): self
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function setUsername(?string $username): self
    {
        $this->username = $username;
        return $this;
    }

    public function setProgress(?array $progress): self
    {
        $this->progress = ($progress === null) ? null : json_encode($progress);
        return $this;
    }

    public function setButton(?Button $button): self
    {
        $this->button = ($button === null) ? null : $button;
        return $this;
    }

    public function setIsAdmin(bool $isAdmin): self
    {
        $this->isAdmin = $isAdmin;
        return $this;
    }

    public function getBaseCurrency(): ?string
    {
        $settings = $this->getSettings();
        return $settings['base_currency'] ?? 'ریال';
    }

    public function setBaseCurrency(string $currency): self
    {
        $settings = $this->getSettings() ?? [];
        $settings['base_currency'] = $currency;
        $this->setSettings($settings);
        return $this;
    }

    public function getDetailedLoan(): ?bool
    {
        $settings = $this->getSettings();
        return $settings['detailed_loan'] ?? false;
    }

    public function setDetailedLoan(bool $detailed = false): self
    {
        $settings = $this->getSettings() ?? [];
        $settings['detailed_loan'] = $detailed;
        $this->setSettings($settings);
        return $this;
    }

    // --- Helper Methods ---

    public function getFullName(): string
    {
        return trim($this->firstName . ' ' . ($this->lastName ?? ''));
    }

    public function getMention(): string
    {
        return $this->username ? '@' . $this->username : $this->firstName;
    }

    public function toDbArray(): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'username' => $this->username,
            'settings' => $this->settings,
            'progress' => $this->progress,
            'button' => $this->button,
            'is_admin' => (int)$this->isAdmin,
        ];
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'full_name' => $this->getFullName(),
            'username' => $this->username,
            'settings' => $this->getSettings(),
            'progress' => $this->progress,
            'button' => $this->button,
            'is_admin' => $this->isAdmin,
            'base_currency' => $this->getBaseCurrency(),
            'detailed_loan' => $this->getDetailedLoan(),
        ];
    }
}