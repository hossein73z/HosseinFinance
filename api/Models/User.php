<?php

class User implements JsonSerializable
{
    public function __construct(
        private ?int    $id,
        private int     $chatId,
        private string  $firstName,
        private ?string $lastName,
        private ?string $username,
        private ?string $settings,
        private ?string $progress,
        private bool    $isAdmin = false,
        private string  $lastBtn = '0'
    )
    {
    }

    /**
     * Factory method to easily create a User instance from a database row
     */
    public static function fromDbRow(array $row): self
    {
        return new self(
            isset($row['id']) ? (int)$row['id'] : null,
            (int)$row['chat_id'],
            $row['first_name'],
            $row['last_name'] ?? null,
            $row['username'] ?? null,
            $row['settings'] ?? null,
            $row['progress'] ?? null,
            (bool)($row['is_admin'] ?? false),
            $row['last_btn'] ?? '0'
        );
    }

    // --- Getters ---

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChatId(): int
    {
        return $this->chatId;
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

    public function getSettings(): ?string
    {
        return $this->settings;
    }

    public function getProgress(): ?string
    {
        return $this->progress;
    }

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function getLastBtn(): string
    {
        return $this->lastBtn;
    }

    // --- Setters ---

    public function setId(?int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function setChatId(int $chatId): self
    {
        $this->chatId = $chatId;
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

    public function setSettings(?string $settings): self
    {
        $this->$settings = $settings;
        return $this;
    }

    public function setProgress(?string $progress): self
    {
        $this->progress = $progress;
        return $this;
    }

    public function setIsAdmin(bool $isAdmin): self
    {
        $this->isAdmin = $isAdmin;
        return $this;
    }

    public function setLastBtn(string $lastBtn): self
    {
        $this->lastBtn = $lastBtn;
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
            'chat_id' => $this->chatId,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'username' => $this->username,
            'settings' => $this->settings,
            'progress' => $this->progress,
            'is_admin' => (int)$this->isAdmin,
            'last_btn' => $this->lastBtn,
        ];
    }

    // --- JsonSerializable Implementation ---

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'chat_id' => $this->chatId,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'full_name' => $this->getFullName(),
            'username' => $this->username,
            'settings' => $this->settings,
            'progress' => $this->progress,
            'is_admin' => $this->isAdmin,
            'last_btn' => $this->lastBtn,
        ];
    }
}