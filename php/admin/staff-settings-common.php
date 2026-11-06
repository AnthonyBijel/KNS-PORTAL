<?php
require_once __DIR__ . '/../auth/password_reset_common.php';

final class KnsStaffSettingsError extends RuntimeException
{
    public function __construct(string $message, public int $status = 422) { parent::__construct($message); }
}

function knsStaffSettingsAccount(PDO $pdo, array $session, bool $lock = false): array
{
    $office = $session['office_id'] ?? null;
    $id = $office ?? ($session['admin_id'] ?? null);
    if (!is_int($id) || $id < 1 || isset($session['student_id'])
        || ($office !== null && isset($session['admin_id']))) {
        throw new KnsStaffSettingsError('A signed-in staff account is required.', 401);
    }
    $statement = $pdo->prepare('SELECT * FROM admin_account WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute([$id]); $account = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$account || (int) $account['is_active'] !== 1 || ($office !== null
        ? !in_array($account['role'], ['OSA', 'Registrar'], true) : $account['role'] !== 'Administrator')) {
        throw new KnsStaffSettingsError('Your account is unavailable. Please sign in again.', 403);
    }
    return $account;
}

function knsStaffPreferences(PDO $pdo, int $id): array
{
    $defaults = ['notify_announcements'=>1, 'notify_requests'=>1, 'notify_activity'=>1, 'notify_email'=>1,
        'theme'=>'light', 'language'=>'en', 'alternate_contact'=>null, 'created_at'=>gmdate('Y-m-d H:i:s'), 'installed'=>false];
    try {
        $statement = $pdo->prepare('SELECT * FROM staff_account_settings WHERE admin_id = ?');
        $statement->execute([$id]); $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $insert = $pdo->prepare('INSERT IGNORE INTO staff_account_settings(admin_id) VALUES (?)');
            $insert->execute([$id]); $statement->execute([$id]); $row = $statement->fetch(PDO::FETCH_ASSOC);
        }
        return array_merge($defaults, $row, ['installed'=>true]);
    } catch (PDOException $error) {
        if ($error->getCode() !== '42S02') throw $error;
        return $defaults;
    }
}

function knsStaffSettingsText(array $input, string $key, int $max, bool $required = true, bool $multiline = false): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $max
        || preg_match($multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u' : '/[\x00-\x1f\x7f]/u', $value) || ($required && trim($value) === '')) {
        throw new KnsStaffSettingsError('Enter a valid ' . str_replace('_', ' ', $key) . '.');
    }
    return trim($value);
}

function knsStaffSettingsPhoto(?string $path): string
{
    $path = str_replace('\\', '/', $path ?? '');
    return preg_match('~^(asset|uploads)/~', $path) && !str_contains($path, '..') && is_file(__DIR__ . '/../../' . $path)
        ? '../' . $path : '../asset/logo/user (1).png';
}

function knsStaffActivity(PDO $pdo, int $id, string $description): void
{
    $statement = $pdo->prepare('INSERT INTO staff_account_activity(admin_id, description) VALUES (?, ?)');
    $statement->execute([$id, $description]);
}

function knsStaffSettingsMailer(): Closure
{
    return static function(string $email, string $subject, string $message): void {
        knsDeliverMessage('email', $email, $subject, $message);
    };
}

final class KnsStaffSettings
{
    private Closure $deliver;
    public function __construct(private PDO $pdo, ?callable $deliver = null)
    {
        $this->deliver = $deliver ? Closure::fromCallable($deliver) : knsStaffSettingsMailer();
        $this->pdo->exec("SET time_zone = '+00:00'");
    }

    public function state(array $session): array
    {
        $account = knsStaffSettingsAccount($this->pdo, $session);
        $prefs = knsStaffPreferences($this->pdo, (int) $account['id']);
        if (!$prefs['installed']) throw new KnsStaffSettingsError('Staff settings are not installed. Apply 20261106_staff_settings.sql.', 503);
        $statement = $this->pdo->prepare('SELECT new_email, expires_at, created_at FROM staff_email_changes
            WHERE admin_id = ? AND expires_at > UTC_TIMESTAMP() AND attempts < 5');
        $statement->execute([$account['id']]); $pending = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        $preferences = [];
        foreach (['notify_announcements','notify_requests','notify_activity','notify_email'] as $key) $preferences[$key] = (bool) $prefs[$key];
        foreach (['theme','language','alternate_contact'] as $key) $preferences[$key] = $prefs[$key];
        $available = true;
        try { knsResetDeliveryConfig('email'); } catch (Throwable $error) { $available = false; }
        return ['profile'=>['full_name'=>$account['admin_name'], 'role'=>$account['role'], 'school_name'=>$account['school_name'],
                'email'=>$account['school_email'], 'phone_number'=>$account['school_contact'],
                'address'=>$account['school_address'], 'photo_url'=>knsStaffSettingsPhoto($account['profile_photo'])],
            'preferences'=>$preferences, 'pending_email'=>$pending, 'email_available'=>$available];
    }

    public function save(array $session, string $action, array $input): array
    {
        if (!in_array($action, ['profile','notifications','security','appearance','language','contacts'], true)) {
            throw new KnsStaffSettingsError('Choose a valid settings action.');
        }
        $this->pdo->beginTransaction();
        try {
            $account = knsStaffSettingsAccount($this->pdo, $session, true);
            $id = (int) $account['id'];
            $prefs = knsStaffPreferences($this->pdo, $id);
            if (!$prefs['installed']) throw new KnsStaffSettingsError('Apply 20261106_staff_settings.sql before saving settings.', 503);
            if (array_key_exists('school_name', $input) && ($action !== 'profile' || $account['role'] !== 'Administrator')) {
                throw new KnsStaffSettingsError('Only the school administrator can edit the school name in Profile Settings.', 403);
            }
            $description = '';
            if ($action === 'profile') {
                $name = knsStaffSettingsText($input, 'full_name', 150);
                $statement = $this->pdo->prepare('UPDATE admin_account SET admin_name = ? WHERE id = ?');
                $statement->execute([$name, $id]);
                if (array_key_exists('school_name', $input)) {
                    $school = knsStaffSettingsText($input, 'school_name', 200);
                    $statement = $this->pdo->prepare('UPDATE admin_account SET school_name=? WHERE id=?');
                    $statement->execute([$school, $id]);
                }
                $description = 'Your profile name was updated.';
            } elseif ($action === 'notifications') {
                $values = [];
                foreach (['notify_announcements','notify_requests','notify_activity','notify_email'] as $key) {
                    if (!in_array($input[$key] ?? null, ['0','1',0,1], true)) throw new KnsStaffSettingsError('Choose valid notification preferences.');
                    $values[] = (int) $input[$key];
                }
                $statement = $this->pdo->prepare('UPDATE staff_account_settings SET notify_announcements=?, notify_requests=?, notify_activity=?, notify_email=? WHERE admin_id=?');
                $statement->execute([...$values, $id]); $description = 'Your notification preferences were updated.';
            } elseif ($action === 'security') {
                $current = $input['current_password'] ?? null;
                $password = $input['new_password'] ?? null;
                $confirmation = $input['password_confirmation'] ?? null;
                if (!is_string($current) || $current === '' || strlen($current) > 4096 || !is_string($password) || !is_string($confirmation)) {
                    throw new KnsStaffSettingsError('Complete all three password fields.');
                }
                try { knsValidateStudentPassword($password, $confirmation); }
                catch (InvalidArgumentException $error) { throw new KnsStaffSettingsError($error->getMessage()); }
                if ($prefs['auth_locked_until'] && $prefs['auth_locked_until'] > gmdate('Y-m-d H:i:s')) {
                    throw new KnsStaffSettingsError('Too many incorrect passwords. Try again in 15 minutes.', 429);
                }
                if (!password_verify($current, $account['password_hash'])) {
                    $failures = $prefs['auth_locked_until'] ? 1 : min(5, (int) $prefs['auth_failures'] + 1);
                    $statement = $this->pdo->prepare('UPDATE staff_account_settings SET auth_failures=?, auth_locked_until=? WHERE admin_id=?');
                    $statement->execute([$failures, $failures >= 5 ? gmdate('Y-m-d H:i:s', time()+900) : null, $id]);
                    $this->pdo->commit();
                    throw new KnsStaffSettingsError('Your current password is incorrect.');
                }
                if (password_verify($password, $account['password_hash'])) throw new KnsStaffSettingsError('Choose a new password different from your current password.');
                $statement = $this->pdo->prepare('UPDATE admin_account SET password_hash=? WHERE id=?');
                $statement->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
                $statement = $this->pdo->prepare('UPDATE staff_account_settings SET auth_failures=0, auth_locked_until=NULL WHERE admin_id=?');
                $statement->execute([$id]);
                $statement = $this->pdo->prepare('DELETE FROM staff_email_changes WHERE admin_id=?'); $statement->execute([$id]);
                $description = 'Your account password was changed.';
            } elseif ($action === 'appearance' || $action === 'language') {
                $key = $action === 'appearance' ? 'theme' : 'language';
                $value = $input[$key] ?? null;
                if (!in_array($value, $key === 'theme' ? ['light','dark'] : ['en','fil'], true)) throw new KnsStaffSettingsError('Choose a valid '.$key.'.');
                $statement = $this->pdo->prepare('UPDATE staff_account_settings SET '.$key.'=? WHERE admin_id=?');
                $statement->execute([$value, $id]);
                $description = $key === 'theme' ? 'Your appearance preference was updated.' : 'Your language preference was updated.';
            } else {
                $phone = knsStaffSettingsText($input, 'phone_number', 30, $account['role'] !== 'Administrator');
                $alternate = knsStaffSettingsText($input, 'alternate_contact', 30, false);
                foreach ([$phone, $alternate] as $value) {
                    $digits = preg_replace('/\D/', '', $value);
                    if ($value !== '' && (!preg_match('/^\+?[0-9 ()-]+$/D', $value) || strlen($digits) < 7 || strlen($digits) > 15)) {
                        throw new KnsStaffSettingsError('Enter contact numbers with 7 to 15 digits.');
                    }
                }
                $statement = $this->pdo->prepare('UPDATE admin_account SET school_contact=? WHERE id=?');
                $statement->execute([$phone ?: null, $id]);
                if (array_key_exists('address', $input)) {
                    $address = knsStaffSettingsText($input, 'address', 255, false, true);
                    $statement = $this->pdo->prepare('UPDATE admin_account SET school_address=? WHERE id=?');
                    $statement->execute([$address ?: null, $id]);
                }
                $statement = $this->pdo->prepare('UPDATE staff_account_settings SET alternate_contact=? WHERE admin_id=?');
                $statement->execute([$alternate ?: null, $id]); $description = 'Your contact details were updated.';
            }
            knsStaffActivity($this->pdo, $id, $description);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        $state = $this->state($session);
        $notice = $this->notice($state, $description);
        return ['state'=>$state, 'message'=>$action === 'security' ? 'Your password has been changed.' : 'Your changes have been saved.', 'notice'=>$notice];
    }

    public function notice(array $state, string $description): ?string
    {
        if (!$state['preferences']['notify_email'] || !$state['preferences']['notify_activity']
            || !filter_var($state['profile']['email'], FILTER_VALIDATE_EMAIL)) return null;
        try {
            ($this->deliver)($state['profile']['email'], 'KNS Portal account activity', $description . "\nIf this was not you, contact your school administrator.");
            return null;
        } catch (Throwable $error) {
            error_log('Staff activity email failed (' . get_class($error) . ').');
            return 'Your changes were saved, but the optional account activity email could not be sent.';
        }
    }
}
