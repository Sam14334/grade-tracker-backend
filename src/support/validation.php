<?php

function normalizedEmail(mixed $value): string {
    return strtolower(trim(is_string($value) ? $value : ''));
}

function normalizedRole(mixed $value): string {
    return strtolower(trim(is_string($value) ? $value : 'student'));
}

function emailDomain(string $email): string {
    $parts = explode('@', $email);
    return (string)end($parts);
}
