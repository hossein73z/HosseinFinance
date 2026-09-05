<?php

class Button implements JsonSerializable
{
    public function __construct(
        private string  $id,
        private array   $attrs,
        private bool    $adminKey,
        private ?string $messages,
        private ?string $belongTo,
        private ?array  $keyboard
    )
    {
    }

    /**
     * Factory method to easily create a Button instance from a database row
     */
    public static function fromDbRow(array $row): self
    {
        return new self(
            $row['id'],
            !isset($row['attrs']) ?
                [] :
                (is_string($row['attrs']) ?
                    json_decode($row['attrs'], true) :
                    $row['attrs']),
            (bool)$row['admin_key'],
            $row['messages'] ?? null,
            $row['belong_to'] ?? null,
            !isset($row['keyboard']) ?
                null :
                (is_string($row['keyboard']) ?
                    json_decode($row['keyboard'], true) :
                    $row['keyboard'])
        );
    }

    // --- Getters ---

    public function getId(): string
    {
        return $this->id;
    }

    public function getAttrs(): array
    {
        return $this->attrs;
    }

    public function isAdminKey(): bool
    {
        return $this->adminKey;
    }

    public function getMessages(): ?string
    {
        return $this->messages;
    }

    public function getBelongTo(): ?string
    {
        return $this->belongTo;
    }

    public function getKeyboard(): ?array
    {
        return $this->keyboard;
    }

    // --- Setters ---

    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function setAttrs(array $attrs): self
    {
        $this->attrs = $attrs;
        return $this;
    }

    public function setAdminKey(bool $adminKey): self
    {
        $this->adminKey = $adminKey;
        return $this;
    }

    public function setMessages(?string $messages): self
    {
        $this->messages = $messages;
        return $this;
    }

    public function setBelongTo(?string $belongTo): self
    {
        $this->belongTo = $belongTo;
        return $this;
    }

    public function setKeyboard(?array $keyboard): self
    {
        $this->keyboard = $keyboard;
        return $this;
    }

    // --- Helper Methods ---

    public function getText(): string
    {
        return $this->attrs['text'] ?? 'Unknown Button';
    }

    public function hasKeyboard(): bool
    {
        return !empty($this->keyboard);
    }

    public function toDbArray(): array
    {
        return [
            'id' => $this->id,
            'attrs' => json_encode($this->attrs, JSON_UNESCAPED_UNICODE),
            'admin_key' => (int)$this->adminKey,
            'messages' => $this->messages,
            'belong_to' => $this->belongTo,
            'keyboard' => $this->keyboard ? json_encode($this->keyboard) : null,
        ];
    }

    // --- JsonSerializable Implementation ---

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'attrs' => $this->attrs,
            'text' => $this->getText(),
            'admin_key' => $this->adminKey,
            'messages' => $this->messages,
            'belong_to' => $this->belongTo,
            'keyboard' => $this->keyboard,
        ];
    }
}