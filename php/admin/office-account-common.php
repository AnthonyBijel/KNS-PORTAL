<?php
final class KnsOfficeAccountError extends RuntimeException
{
    public function __construct(string $message, public int $status = 422, public array $errors = [])
    {
        parent::__construct($message);
    }
}

final class KnsOfficeAccountsService
{
    public function __construct(private PDO $pdo) {}

    private function administrator(array $session, bool $lock = false): array
    {
        $id = $session['admin_id'] ?? null;
        if (!is_int($id) || $id < 1 || isset($session['student_id']) || isset($session['office_id'])) {
            throw new KnsOfficeAccountError('Only a signed-in Admin can manage office accounts.',
                isset($session['student_id']) || isset($session['office_id']) ? 403 : 401);
        }
        $statement = $this->pdo->prepare('SELECT id, role, is_active, password_hash, school_name
            FROM admin_account WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$id]);
        $account = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$account || $account['role'] !== 'Administrator' || (int) $account['is_active'] !== 1) {
            throw new KnsOfficeAccountError('An active Admin account is required.', 403);
        }
        return $account;
    }

    private function publicAccount(array $row): array
    {
        return ['id' => (int) $row['id'], 'full_name' => $row['admin_name'],
            'username' => $row['username'], 'email' => $row['school_email'],
            'phone_number' => $row['school_contact'], 'role' => $row['role'],
            'is_active' => (int) $row['is_active'] === 1];
    }

    private function account(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT id, admin_name, username, school_email, school_contact, role, is_active
            FROM admin_account WHERE id = ? AND role IN (?, ?) LIMIT 1');
        $statement->execute([$id, 'Registrar', 'OSA']);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new KnsOfficeAccountError('Office account not found.', 404);
        return $this->publicAccount($row);
    }

    public function accounts(array $session): array
    {
        $this->administrator($session);
        $statement = $this->pdo->prepare('SELECT id, admin_name, username, school_email, school_contact, role, is_active
            FROM admin_account WHERE role IN (?, ?) ORDER BY id DESC');
        $statement->execute(['Registrar', 'OSA']);
        return array_map(fn(array $row): array => $this->publicAccount($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function registration(array $input): array
    {
        $fields = [];
        $errors = [];
        foreach (['full_name' => 150, 'username' => 50, 'email' => 254, 'phone_number' => 30, 'role' => 50] as $key => $limit) {
            $value = $input[$key] ?? null;
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')
                || trim($value) === '' || mb_strlen(trim($value), 'UTF-8') > $limit) {
                $errors[$key] = 'Enter a valid ' . str_replace('_', ' ', $key) . ' (at most ' . $limit . ' characters).';
                continue;
            }
            $fields[$key] = trim($value);
        }
        if (isset($fields['username']) && !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{2,49}\z/D', $fields['username'])) {
            $errors['username'] = 'Use 3 to 50 letters, numbers, dots, underscores, or hyphens for the username.';
        }
        if (isset($fields['email']) && filter_var($fields['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (isset($fields['phone_number'])) {
            $digits = preg_replace('/\D/', '', $fields['phone_number']);
            if (!preg_match('/\A\+?[0-9 ()-]+\z/D', $fields['phone_number']) || strlen($digits) < 7 || strlen($digits) > 15) {
                $errors['phone_number'] = 'Enter a valid phone number with 7 to 15 digits.';
            }
        }
        if (isset($fields['role']) && !in_array($fields['role'], ['Registrar', 'OSA'], true)) {
            $errors['role'] = 'Choose Registrar or OSA.';
        }
        $password = $input['password'] ?? null;
        $confirmation = $input['password_confirmation'] ?? null;
        if (!is_string($password) || !mb_check_encoding($password, 'UTF-8') || str_contains($password, "\0")
            || mb_strlen($password, 'UTF-8') < 12 || strlen($password) > 72) {
            $errors['password'] = 'Use a password of at least 12 characters and at most 72 bytes.';
        }
        if (!is_string($confirmation) || !is_string($password) || $confirmation !== $password || $confirmation === '') {
            $errors['password_confirmation'] = 'The passwords must match.';
        }
        if ($errors) throw new KnsOfficeAccountError('Please check the account details.', 422, $errors);
        $fields['password'] = $password;
        return $fields;
    }

    public function create(array $session, array $input): array
    {
        $this->administrator($session);
        $fields = $this->registration($input);
        // Hash outside the transaction; password fields are never returned.
        $hash = password_hash($fields['password'], PASSWORD_DEFAULT);
        unset($fields['password'], $input['password'], $input['password_confirmation']);
        $this->pdo->beginTransaction();
        try {
            $admin = $this->administrator($session, true);
            $duplicate = $this->pdo->prepare('SELECT username, school_email FROM admin_account
                WHERE username = ? OR school_email = ?');
            $duplicate->execute([$fields['username'], $fields['email']]);
            $errors = [];
            foreach ($duplicate->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (strcasecmp($row['username'], $fields['username']) === 0) $errors['username'] = 'This username is already in use.';
                if (strcasecmp((string) $row['school_email'], $fields['email']) === 0) $errors['email'] = 'This email address is already in use.';
            }
            if ($errors) throw new KnsOfficeAccountError('Username or email is already in use.', 409, $errors);
            $statement = $this->pdo->prepare('INSERT INTO admin_account
                (username, password_hash, admin_name, role, school_name, school_email, school_contact, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
            $statement->execute([$fields['username'], $hash, $fields['full_name'], $fields['role'],
                $admin['school_name'], $fields['email'], $fields['phone_number']]);
            $account = $this->account((int) $this->pdo->lastInsertId());
            $this->pdo->commit();
            return $account;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new KnsOfficeAccountError('Username or email is already in use.', 409);
            }
            if ($error instanceof PDOException && in_array((int) ($error->errorInfo[1] ?? 0), [1467, 167, 1264], true)) {
                throw new KnsOfficeAccountError('The existing staff account ID range is full. Contact the portal maintainer.', 409);
            }
            throw $error;
        }
    }

    public function deactivate(array $session, array $input): array
    {
        $this->administrator($session);
        $id = filter_var($input['account_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $password = $input['admin_password'] ?? null;
        if (!$id) throw new KnsOfficeAccountError('Choose a valid office account.');
        if (!is_string($password) || $password === '' || strlen($password) > 4096) {
            throw new KnsOfficeAccountError('Enter your own current Admin password.', 422, ['admin_password' => 'Enter your Admin password.']);
        }
        $this->pdo->beginTransaction();
        try {
            // Read and lock the current Admin password; never trust a session role/password.
            $admin = $this->administrator($session, true);
            if (!password_verify($password, $admin['password_hash'])) {
                throw new KnsOfficeAccountError('Your Admin password is incorrect.', 422, ['admin_password' => 'Your Admin password is incorrect.']);
            }
            $account = $this->account($id);
            $statement = $this->pdo->prepare('UPDATE admin_account SET is_active = 0
                WHERE id = ? AND role IN (?, ?)');
            $statement->execute([$id, 'Registrar', 'OSA']);
            $account['is_active'] = false;
            $this->pdo->commit();
            return $account;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}
