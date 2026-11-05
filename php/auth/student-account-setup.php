<?php
require_once __DIR__ . '/password_reset_common.php';
require_once __DIR__ . '/student-password-policy.php';
require_once __DIR__ . '/../student-term-enrollments.php';

function knsTemporaryPasswordMessage(array $student, string $password, string $channel): string
{
    $number = $student['student_number'];
    if ($channel === 'sms') {
        return "KNS Portal student $number: temporary password: $password. Expires in 7 days. Change it after first login. Do not share it.";
    }
    $name = implode(' ', array_filter([
        $student['first_name'], $student['middle_name'] ?? '', $student['last_name'], $student['suffix'] ?? '',
    ], static fn ($value) => $value !== null && $value !== ''));
    return "Hello $name,\n\nYour KNS Portal account is ready.\n"
        . "Student number: $number\nTemporary password: $password\n\n"
        . "The temporary password expires after 7 days if it has not been used.\n"
        . "After your first login, you must create a new password before accessing the portal.\n"
        . "Once replaced, the temporary password can no longer be used.\n"
        . "Do not share your temporary password.\n\n"
        . "Sign in through your school's KNS Portal. If the password expires, use Forgot Password on the login page.\n";
}

final class KnsStudentAccountSetup
{
    private Closure $deliver;
    private const FIELDS = ['student_number', 'first_name', 'middle_name', 'last_name', 'suffix',
        'course', 'year_level', 'section', 'status', 'email', 'contact_number', 'address',
        'birth_date', 'guardian_name', 'guardian_contact', 'academic_year', 'semester'];

    public function __construct(private PDO $pdo, ?callable $deliver = null)
    {
        $this->deliver = Closure::fromCallable($deliver ?? 'knsDeliverMessage');
    }

    private function student(int $id, bool $lock = false): array
    {
        $statement = $this->pdo->prepare('SELECT *, temporary_password_expires_at > UTC_TIMESTAMP() AS temporary_password_live
            FROM students WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$id]);
        $student = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$student) throw new InvalidArgumentException('Student account was not found.');
        return $student;
    }

    private function requireContact(array $student): void
    {
        $contacts = knsResetContacts($student);
        if ($contacts['email'] === '' && $contacts['sms'] === '') {
            throw new InvalidArgumentException('An Active account needs a valid email or phone number to receive its temporary password.');
        }
    }

    private function credentials(): array
    {
        // Eight readable random characters; plaintext exists only in memory until delivery finishes.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';
        for ($index = 0; $index < 8; $index++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return [$password, password_hash($password, PASSWORD_DEFAULT), gmdate('Y-m-d H:i:s', time() + 7 * 86400)];
    }

    private function deliver(array $student, string $password): array
    {
        foreach (knsResetContacts($student) as $channel => $destination) {
            if ($destination === '') continue;
            try {
                ($this->deliver)($channel, $destination, 'KNS Portal account setup',
                    knsTemporaryPasswordMessage($student, $password, $channel));
                return ['ok' => true, 'channel' => $channel];
            } catch (Throwable $error) {
                // Provider diagnostics can contain message bodies. Never log them.
                error_log('Student temporary-password delivery failed (' . $channel . ').');
            }
        }
        return ['ok' => false];
    }

    public function saveImportedStudent(array $values): array
    {
        // Workbook passwords and profile photos cannot reach SQL.
        $values = array_intersect_key($values, array_flip(self::FIELDS));
        $password = null;
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('SELECT * FROM students WHERE student_number = ? LIMIT 1 FOR UPDATE');
            $statement->execute([$values['student_number']]);
            $existing = $statement->fetch(PDO::FETCH_ASSOC);
            $created = $existing === false;
            $saved = array_replace($existing ?: ['status' => 'Active'], $values);
            $hasPassword = !empty($existing['password_hash'])
                && (password_get_info($existing['password_hash'])['algo'] ?? null) !== null;
            if ($saved['status'] === 'Active' && !$hasPassword) {
                $this->requireContact($saved);
                [$password, $hash, $expires] = $this->credentials();
                $values['password_hash'] = $hash;
                $values['requires_password_change'] = 1;
                $values['temporary_password_expires_at'] = $expires;
            } elseif ($created) {
                // Inactive/Graduated imports get no usable password; activation can provision it later.
                $values['requires_password_change'] = 1;
            }
            if ($created) {
                $columns = array_map(static fn ($field) => "`$field`", array_keys($values));
                $statement = $this->pdo->prepare('INSERT INTO students (' . implode(', ', $columns) . ') VALUES ('
                    . implode(', ', array_fill(0, count($values), '?')) . ')');
                $statement->execute(array_values($values));
                $id = (int) $this->pdo->lastInsertId();
            } else {
                $id = (int) $existing['id'];
                unset($values['student_number']);
                $assignments = array_map(static fn ($field) => "`$field` = ?", array_keys($values));
                if ($assignments) {
                    $statement = $this->pdo->prepare('UPDATE students SET ' . implode(', ', $assignments) . ' WHERE id = ?');
                    $statement->execute([...array_values($values), $id]);
                }
            }
            knsCaptureImportedEnrollment($this->pdo, $id, $existing ?: null, $saved, array_key_exists('status', $values));
            $student = $password !== null ? $this->student($id) : null;
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            unset($password);
            throw $error;
        }
        try {
            // Commit before network calls. Failure leaves a pending account eligible for a fresh send.
            return ['created' => $created, 'delivery' => $password === null ? null : $this->deliver($student, $password)];
        } finally {
            unset($password);
        }
    }

    public function resend(int $id): array
    {
        $this->pdo->beginTransaction();
        try {
            $student = $this->student($id, true);
            if ($student['status'] !== 'Active' || !(int) $student['requires_password_change']) {
                throw new InvalidArgumentException('Only an Active account still awaiting a password change can receive a fresh temporary password.');
            }
            $this->requireContact($student);
            [$password, $hash, $expires] = $this->credentials();
            $statement = $this->pdo->prepare('UPDATE students SET password_hash = ?, temporary_password_expires_at = ? WHERE id = ?');
            $statement->execute([$hash, $expires, $id]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            unset($password);
            throw $error;
        }
        try {
            return $this->deliver($student, $password);
        } finally {
            unset($password);
        }
    }

    public function complete(int $id, string $proof, string $password, string $confirmation): void
    {
        knsValidateStudentPassword($password, $confirmation);
        $this->pdo->beginTransaction();
        try {
            $student = $this->student($id, true);
            if ($student['status'] !== 'Active' || !(int) $student['requires_password_change']
                || !(int) $student['temporary_password_live']
                || !hash_equals(hash('sha256', (string) $student['password_hash']), $proof)) {
                throw new InvalidArgumentException('This setup session expired or was replaced. Sign in again or use Forgot Password.');
            }
            if (password_verify($password, $student['password_hash'])) {
                throw new InvalidArgumentException('Choose a new password different from your temporary password.');
            }
            $statement = $this->pdo->prepare('UPDATE students SET password_hash = ?, requires_password_change = 0,
                temporary_password_expires_at = NULL WHERE id = ?');
            $statement->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            $statement = $this->pdo->prepare('UPDATE password_reset_tokens SET used_at = COALESCE(used_at, UTC_TIMESTAMP()),
                expires_at = LEAST(expires_at, UTC_TIMESTAMP()) WHERE student_id = ?');
            $statement->execute([$id]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}
