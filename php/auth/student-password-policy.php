<?php
// Shared recovery and first-login password rules.
function knsValidateStudentPassword(string $password, string $confirmation): void
{
    if (!mb_check_encoding($password, 'UTF-8') || mb_strlen($password, 'UTF-8') < 12
        || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new InvalidArgumentException('Use a password of at least 12 characters and at most 72 bytes.');
    }
    if (!hash_equals($password, $confirmation)) {
        throw new InvalidArgumentException('The new passwords do not match.');
    }
}
