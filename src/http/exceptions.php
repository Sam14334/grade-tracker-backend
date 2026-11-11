<?php

final class ApiException extends RuntimeException {
    public function __construct(
        string $message,
        private int $status = 400,
        private array $extra = []
    ) {
        parent::__construct($message);
    }

    public function status(): int {
        return $this->status;
    }

    public function extra(): array {
        return $this->extra;
    }
}
