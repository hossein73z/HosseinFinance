<?php

class Person implements JsonSerializable
{
    public function __construct(
        private ?int    $id,
        private int     $chatId,
        private string  $firstName,
        private ?string $lastName,
        private ?string $username,
        private ?string $progress,
        private bool    $isAdmin = false,
        private string  $lastBtn = '0'
    )
    {
    }

    /**
     * Factory method to easily create a Person instance from a database row
     */
    public static function fromDbRow(array $row): self
    {
        return new self(
            isset($row['id']) ? (int)$row['id'] : null,
            (int)$row['chat_id'],
            $row['first_name'],
            $row['last_name'] ?? null,
            $row['username'] ?? null,
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

    // --- Helper Methods ---

    /**
     * Gets the full name by safely combining first and last name
     */
    public function getFullName(): string
    {
        return trim($this->firstName . ' ' . ($this->lastName ?? ''));
    }

    /**
     * Formats an @ username if one exists
     */
    public function getMention(): string
    {
        return $this->username ? '@' . $this->username : $this->firstName;
    }

    /**
     * Prepares data for inserting/updating the database
     */
    public function toDbArray(): array
    {
        return [
            'id' => $this->id,
            'chat_id' => $this->chatId,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'username' => $this->username,
            'progress' => $this->progress,
            'is_admin' => (int)$this->isAdmin,
            'last_btn' => $this->lastBtn,
        ];
    }

    // --- JsonSerializable Implementation ---

    /**
     * Specify data which should be serialized to JSON
     */
    public function jsonSerialize(): mixed
    {
        return [
            'id' => $this->id,
            'chat_id' => $this->chatId,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'full_name' => $this->getFullName(), // Automatically exposed in JSON for convenience!
            'username' => $this->username,
            'progress' => $this->progress,
            'is_admin' => $this->isAdmin,
            'last_btn' => $this->lastBtn,
        ];
    }
}