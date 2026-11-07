<?php
require_once __DIR__ . '/../auth/password_reset_common.php';

final class KnsStudentSettingsError extends RuntimeException
{
    public function __construct(string $message, public int $status = 422) { parent::__construct($message); }
}

function knsStudentSettingsAccount(PDO $pdo, array $session, bool $lock = false): array
{
    $id = $session['student_id'] ?? null;
    if (!is_int($id) || $id < 1 || isset($session['admin_id']) || isset($session['office_id'])) {
        throw new KnsStudentSettingsError('Sign in to your student account to use Settings.', 401);
    }
    $statement = $pdo->prepare('SELECT * FROM students WHERE id = ? AND ' . knsStudentAccessSql()
        . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute([$id]);
    $account = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$account) throw new KnsStudentSettingsError('Your student account is unavailable.', 401);
    if ((int) $account['requires_password_change']) {
        throw new KnsStudentSettingsError('Create your new password before using Settings.', 403);
    }
    return $account;
}

function knsStudentPreferences(PDO $pdo, int $id): array
{
    $defaults = ['notify_announcements'=>1,'notify_requests'=>1,'notify_activity'=>1,'notify_email'=>1,
        'theme'=>'light','language'=>'en','alternate_contact'=>null,'auth_failures'=>0,
        'auth_locked_until'=>null,'installed'=>false];
    try {
        $statement = $pdo->prepare('SELECT * FROM student_account_settings WHERE student_id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->prepare('INSERT IGNORE INTO student_account_settings(student_id) VALUES (?)')->execute([$id]);
            $statement->execute([$id]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        }
        return array_merge($defaults, $row, ['installed'=>true]);
    } catch (PDOException $error) {
        if ($error->getCode() !== '42S02') throw $error;
        return $defaults;
    }
}

function knsStudentSettingsText(array $input, string $key, int $max, bool $required = true, bool $multiline = false): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $max
        || ($required && trim($value) === '')
        || preg_match($multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u' : '/[\x00-\x1f\x7f]/u', $value)) {
        throw new KnsStudentSettingsError('Enter a valid ' . str_replace('_', ' ', $key) . '.');
    }
    return trim($value);
}

function knsStudentActivity(PDO $pdo, int $id, string $description): void
{
    $pdo->prepare('INSERT INTO student_account_activity(student_id, description) VALUES (?, ?)')->execute([$id, $description]);
}

function knsStudentSettingsMailer(): Closure
{
    return static function (string $email, string $subject, string $message): void {
        knsDeliverMessage('email', $email, $subject, $message);
    };
}

function knsStudentSettingsPhoto(?string $path): string
{
    $path = str_replace('\\', '/', $path ?? '');
    return preg_match('~^(asset|uploads)/~', $path) && !str_contains($path, '..') && is_file(__DIR__ . '/../../' . $path)
        ? '../' . $path : '../asset/logo/user (1).png';
}

final class KnsStudentSettings
{
    private Closure $deliver;
    public function __construct(private PDO $pdo, ?callable $deliver = null)
    {
        $this->deliver = $deliver ? Closure::fromCallable($deliver) : knsStudentSettingsMailer();
        $this->pdo->exec("SET time_zone = '+00:00'");
    }

    public function state(array $session): array
    {
        $account = knsStudentSettingsAccount($this->pdo, $session);
        $prefs = knsStudentPreferences($this->pdo, (int) $account['id']);
        if (!$prefs['installed']) throw new KnsStudentSettingsError('Student settings are unavailable. Ask the administrator to install the student settings migration.', 503);
        $statement = $this->pdo->prepare('SELECT new_email, expires_at, created_at FROM student_email_changes
            WHERE student_id = ? AND expires_at > UTC_TIMESTAMP() AND attempts < 5');
        $statement->execute([$account['id']]);
        $preferences = [];
        foreach (['notify_announcements','notify_requests','notify_activity','notify_email'] as $key) $preferences[$key] = (bool) $prefs[$key];
        foreach (['theme','language','alternate_contact'] as $key) $preferences[$key] = $prefs[$key];
        $available = true;
        try { knsResetDeliveryConfig('email'); } catch (Throwable $error) { $available = false; }
        return ['profile'=>[
            'full_name'=>implode(' ', array_filter([$account['first_name'],$account['middle_name'],$account['last_name'],$account['suffix']])),
            'student_number'=>$account['student_number'],'role'=>'Student','school_name'=>'Kolehiyo ng Subic',
            'email'=>$account['email'],'phone_number'=>$account['contact_number'],'address'=>$account['address'],
            'photo_url'=>knsStudentSettingsPhoto($account['profile_photo'])],
            'preferences'=>$preferences,'pending_email'=>$statement->fetch(PDO::FETCH_ASSOC) ?: null,'email_available'=>$available];
    }

    public function save(array $session, string $action, array $input): array
    {
        if (!in_array($action, ['notifications','security','appearance','language','contacts'], true)) {
            throw new KnsStudentSettingsError('Choose an available settings action. Student identity and academic records are read-only.');
        }
        $this->pdo->beginTransaction();
        try {
            $account = knsStudentSettingsAccount($this->pdo, $session, true);
            $id = (int) $account['id'];
            $prefs = knsStudentPreferences($this->pdo, $id);
            if (!$prefs['installed']) throw new KnsStudentSettingsError('Student settings are unavailable.', 503);
            if ($action === 'notifications') {
                $values = [];
                foreach (['notify_announcements','notify_requests','notify_activity','notify_email'] as $key) {
                    if (!in_array($input[$key] ?? null, ['0','1',0,1], true)) throw new KnsStudentSettingsError('Choose valid notification preferences.');
                    $values[] = (int) $input[$key];
                }
                $this->pdo->prepare('UPDATE student_account_settings SET notify_announcements=?, notify_requests=?, notify_activity=?, notify_email=? WHERE student_id=?')->execute([...$values,$id]);
                $description = 'Your notification preferences were updated.';
            } elseif ($action === 'security') {
                $current = $input['current_password'] ?? null;
                $password = $input['new_password'] ?? null;
                $confirmation = $input['password_confirmation'] ?? null;
                if (!is_string($current) || $current === '' || strlen($current) > 4096 || !is_string($password) || !is_string($confirmation)) {
                    throw new KnsStudentSettingsError('Complete all three password fields.');
                }
                try { knsValidateStudentPassword($password, $confirmation); }
                catch (InvalidArgumentException $error) { throw new KnsStudentSettingsError($error->getMessage()); }
                if ($prefs['auth_locked_until'] && $prefs['auth_locked_until'] > gmdate('Y-m-d H:i:s')) {
                    throw new KnsStudentSettingsError('Too many incorrect passwords. Try again in 15 minutes.', 429);
                }
                if (!password_verify($current, (string) $account['password_hash'])) {
                    $failures = $prefs['auth_locked_until'] ? 1 : min(5, (int) $prefs['auth_failures'] + 1);
                    $this->pdo->prepare('UPDATE student_account_settings SET auth_failures=?, auth_locked_until=? WHERE student_id=?')
                        ->execute([$failures,$failures >= 5 ? gmdate('Y-m-d H:i:s', time()+900) : null,$id]);
                    $this->pdo->commit();
                    throw new KnsStudentSettingsError('Your current password is incorrect.');
                }
                if (password_verify($password, $account['password_hash'])) throw new KnsStudentSettingsError('Choose a password different from your current password.');
                $this->pdo->prepare('UPDATE students SET password_hash=?, requires_password_change=0, temporary_password_expires_at=NULL WHERE id=?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT),$id]);
                $this->pdo->prepare('UPDATE student_account_settings SET auth_failures=0, auth_locked_until=NULL WHERE student_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM student_email_changes WHERE student_id=?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE student_id=?')->execute([$id]);
                $description = 'Your account password was changed.';
            } elseif ($action === 'appearance' || $action === 'language') {
                $key = $action === 'appearance' ? 'theme' : 'language';
                $value = $input[$key] ?? null;
                if (!in_array($value, $key === 'theme' ? ['light','dark'] : ['en','fil'], true)) throw new KnsStudentSettingsError('Choose a valid '.$key.'.');
                $this->pdo->prepare('UPDATE student_account_settings SET '.$key.'=? WHERE student_id=?')->execute([$value,$id]);
                $description = $key === 'theme' ? 'Your appearance preference was updated.' : 'Your language preference was updated.';
            } else {
                $phone = knsStudentSettingsText($input, 'phone_number', 30, false);
                $alternate = knsStudentSettingsText($input, 'alternate_contact', 30, false);
                foreach ([$phone,$alternate] as $value) {
                    if ($value !== '' && knsResetPhone($value) === '') throw new KnsStudentSettingsError('Enter a valid phone number, such as 09171234567 or +639171234567.');
                }
                if ($phone === '' && !filter_var($account['email'], FILTER_VALIDATE_EMAIL)) {
                    throw new KnsStudentSettingsError('Keep a valid phone number or verify an email so you can recover your account.');
                }
                $address = knsStudentSettingsText($input, 'address', 255, false, true);
                $this->pdo->prepare('UPDATE students SET contact_number=?, address=? WHERE id=?')->execute([$phone ?: null,$address ?: null,$id]);
                $this->pdo->prepare('UPDATE student_account_settings SET alternate_contact=? WHERE student_id=?')->execute([$alternate ?: null,$id]);
                $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE student_id=?')->execute([$id]);
                $description = 'Your contact details were updated.';
            }
            knsStudentActivity($this->pdo, $id, $description);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        } finally {
            unset($current, $password, $confirmation);
        }
        $state = $this->state($session);
        return ['state'=>$state,'message'=>$action === 'security' ? 'Your password has been changed.' : 'Your changes have been saved.',
            'notice'=>$this->notice($state, $description)];
    }

    public function notice(array $state, string $description): ?string
    {
        if (!$state['preferences']['notify_email'] || !$state['preferences']['notify_activity']
            || !filter_var($state['profile']['email'], FILTER_VALIDATE_EMAIL)) return null;
        try {
            ($this->deliver)($state['profile']['email'], 'KNS Portal account activity',
                $description . "\nIf this was not you, contact Student Services.");
            return null;
        } catch (Throwable $error) {
            error_log('Student activity email failed (' . get_class($error) . ').');
            return 'Your changes were saved, but the optional account activity email could not be sent.';
        }
    }
}
