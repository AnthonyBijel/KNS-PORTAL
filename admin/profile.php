<?php

require_once __DIR__ . '/../php/auth/staff-access.php';

function adminProfileJson(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$officeId = currentOfficeId();
$isOffice = $officeId !== null;

if (empty($_SESSION['admin_id']) && $officeId === null) {
    if ($isPost) {
        adminProfileJson(401, ['ok' => false, 'message' => 'Your session expired. Please sign in again.']);
    }
    header('Location: ../login.php');
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    adminProfileJson(405, ['ok' => false, 'message' => 'Method not allowed.']);
}

$adminId = $officeId ?? (int) $_SESSION['admin_id'];
$changes = [];

if ($isPost) {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        adminProfileJson(403, ['ok' => false, 'message' => 'Your form expired. Reload your profile and try again.']);
    }

    $fields = [
        'admin_name' => ['label' => 'Admin name', 'max' => 150, 'required' => true],
        'school_name' => ['label' => 'School name', 'max' => 200, 'required' => true],
        'school_email' => ['label' => 'Email', 'max' => 254, 'required' => false],
        'school_contact' => ['label' => 'Contact number', 'max' => 30, 'required' => false],
        'school_address' => ['label' => 'Address', 'max' => 255, 'required' => false],
    ];
    if ($isOffice) {
        unset($fields['school_name']);
        $fields['admin_name']['label'] = 'Full name';
        $fields['school_email']['required'] = true;
        $fields['school_contact']['required'] = true;
    }
    $errors = [];

    foreach ($fields as $name => $rules) {
        $value = $_POST[$name] ?? '';
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            $errors[$name] = $rules['label'] . ' must contain valid text.';
            continue;
        }
        $value = trim($value);
        if ($rules['required'] && $value === '') {
            $errors[$name] = $rules['label'] . ' is required.';
        } elseif (mb_strlen($value, 'UTF-8') > $rules['max']) {
            $errors[$name] = $rules['label'] . ' must be at most ' . $rules['max'] . ' characters.';
        }
        $changes[$name] = $value === '' && !$rules['required'] ? null : $value;
    }

    if (
        !isset($errors['school_email']) && !empty($changes['school_email'])
        && !filter_var($changes['school_email'], FILTER_VALIDATE_EMAIL)
    ) {
        $errors['school_email'] = 'Enter a valid email address.';
    }

    if ($isOffice && !isset($errors['school_contact'])) {
        $phone = (string) ($changes['school_contact'] ?? '');
        $digits = preg_replace('/\D/', '', $phone);
        if (!preg_match('/\A\+?[0-9 ()-]+\z/D', $phone) || strlen($digits) < 7 || strlen($digits) > 15) {
            $errors['school_contact'] = 'Enter a valid contact number with 7 to 15 digits.';
        }
    }

    if ($errors) {
        adminProfileJson(422, ['ok' => false, 'message' => 'Please check your profile details.', 'errors' => $errors]);
    }
}

try {
    require __DIR__ . '/../db.php';

    if ($isPost) $pdo->beginTransaction();
    $profile = knsRequireStaffAccess($pdo, 'profile', '../login.php', $isPost);

    if (!$profile) {
        if ($isPost) {
            adminProfileJson(404, ['ok' => false, 'message' => 'Admin account not found.']);
        }
        http_response_code(404);
        exit('Admin account not found.');
    }

    if ($isPost) {
        if ($isOffice) {
            // Office profiles edit only the signed-in office's personal details.
            $stmt = $pdo->prepare('UPDATE admin_account SET admin_name = ?, school_email = ?, school_contact = ?, school_address = ? WHERE id = ?');
            $stmt->execute([$changes['admin_name'], $changes['school_email'], $changes['school_contact'], $changes['school_address'], $adminId]);
            $changes['school_name'] = $profile['school_name'];
            $_SESSION['office_name'] = $changes['admin_name'];
            $_SESSION['office_role'] = $profile['role'];
        } else {
            $stmt = $pdo->prepare('UPDATE admin_account SET admin_name = ?, school_name = ?, school_email = ?, school_contact = ?, school_address = ? WHERE id = ?');
            $stmt->execute([$changes['admin_name'], $changes['school_name'], $changes['school_email'], $changes['school_contact'], $changes['school_address'], $adminId]);
            $_SESSION['admin_name'] = $changes['admin_name'];
            $_SESSION['admin_role'] = $profile['role'];
        }
        $pdo->commit();

        adminProfileJson(200, [
            'ok' => true,
            'message' => 'Profile updated successfully.',
            'profile' => array_merge($changes, ['role' => $profile['role']]),
        ]);
    }
} catch (PDOException $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($isPost && (int) ($error->errorInfo[1] ?? 0) === 1062) {
        adminProfileJson(409, ['ok' => false, 'message' => 'This email address is already in use.',
            'errors' => ['school_email' => 'This email address is already in use.']]);
    }
    error_log('Admin profile: ' . $error->getMessage());
    if ($isPost) {
        adminProfileJson(500, ['ok' => false, 'message' => 'Unable to save your profile. Please try again.']);
    }
    http_response_code(500);
    exit('Unable to load the admin profile. Please try again.');
}

$photoFeedback = $_SESSION['admin_photo_feedback'] ?? null;
unset($_SESSION['admin_photo_feedback']);
$escape = static fn($value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$photoPath = str_replace('\\', '/', (string) ($profile['profile_photo'] ?? ''));
$profilePhoto = '../asset/logo/user (1).png';
if (
    preg_match('~^(asset|uploads)/~', $photoPath) && !str_contains($photoPath, '..')
    && is_file(__DIR__ . '/../' . $photoPath)
) {
    $profilePhoto = '../' . $photoPath;
}
?>

<?php if (!$isOffice): ?>
<link rel="stylesheet" href="office-registration.css?v=<?= filemtime(__DIR__ . '/office-registration.css') ?>">
<link rel="stylesheet" href="office-accounts.css?v=<?= filemtime(__DIR__ . '/office-accounts.css') ?>">
<?php endif; ?>
<div class="student-info-container admin-profile">
    <div class="kns-office-registration-anchor">
        <div class="student-info-header">
            <h1><?= $isOffice ? $escape($profile['role']) . ' Profile' : 'Admin Profile' ?></h1>
            <p><?= $isOffice ? 'View and update your office profile and contact details.' : 'View and update your administrator and school details.' ?></p>
        </div>
        <?php if (!$isOffice): ?>
        <button type="button" class="kns-office-accounts-launch" data-open-office-accounts
            aria-haspopup="dialog" aria-controls="kns-office-accounts">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="4" cy="6" r="1"></circle><circle cx="4" cy="12" r="1"></circle><circle cx="4" cy="18" r="1"></circle><path d="M9 6h11M9 12h11M9 18h11"></path></svg>
            Office Account List
        </button>
        <button type="button" class="kns-office-registration-launch" data-open-office-registration
            aria-haspopup="dialog" aria-controls="kns-office-registration">
            <span class="kns-office-registration-plus" aria-hidden="true">+</span>Register Office Account
        </button>
        <?php endif; ?>
    </div>

    <div class="profile">
        <form id="admin-photo-form" class="admin-photo-form"
            action="../php/admin/upload_profile_photo.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= $escape($_SESSION['csrf']) ?>">
            <input type="hidden" name="MAX_FILE_SIZE" value="2097152">
            <input id="admin-profile-photo" type="file" name="profile_photo"
                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                aria-label="Choose a new profile photo" hidden required>
            <button type="button" class="person-profile admin-photo-button" data-choose-photo
                aria-label="Change profile photo. Your selected photo is saved automatically."
                aria-describedby="admin-photo-help" title="Change profile photo (JPG, PNG, or WEBP; maximum 2 MB)">
                <img src="<?= $escape($profilePhoto) ?>" alt="<?= $isOffice ? 'Office' : 'Admin' ?> profile photo" data-admin-photo>
                <span class="admin-photo-overlay" aria-hidden="true"><span data-photo-label>Change photo</span></span>
                <span class="admin-photo-badge" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" focusable="false">
                        <path d="M14.5 4h-5L8 6H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-3l-1.5-2Z"></path>
                        <circle cx="12" cy="13" r="3"></circle>
                    </svg>
                </span>
            </button>
            <span id="admin-photo-help" class="admin-photo-help">Click your profile photo to choose a replacement.
                It saves automatically. JPG/JPEG, PNG, or WEBP; maximum 2 MB, 4096 pixels per side, and 12 megapixels.</span>
        </form>
        <div class="student-name">
            <h1 data-admin-name><?= $escape($profile['admin_name']) ?></h1>
            <p data-admin-role><?= $escape($profile['role']) ?></p>
            <p data-school-name><?= $escape($profile['school_name']) ?></p>
            <div class="profile-status">
                <span class="status-dot"></span>
                <span>Signed in</span>
            </div>
        </div>
    </div>

    <p id="admin-photo-message" class="admin-photo-message" role="status" aria-live="polite"
        data-state="<?= !empty($photoFeedback['ok']) ? 'success' : 'error' ?>"><?= $escape($photoFeedback['message'] ?? '') ?></p>
    <section class="student-information">
        <div class="academic-info">
            <div class="grad-hat">
                <img src="../asset/logo/admin-alt.svg" alt="">
            </div>
            <div class="academic-text">
                <h2><?= $isOffice ? 'Office Information' : 'Administrator Information' ?></h2>
                <p><?= $isOffice ? 'Keep your profile and office contact details up to date.' : 'Keep your profile and school contact details up to date.' ?></p>
            </div>
        </div>

        <form id="admin-profile-form" action="profile.php" method="post">
            <input type="hidden" name="csrf" value="<?= $escape($_SESSION['csrf']) ?>">
            <p id="admin-profile-message" role="status" aria-live="polite"></p>
            <fieldset>
                <legend class="admin-profile-legend"><?= $isOffice ? 'Office profile details' : 'Administrator and school details' ?></legend>
                <div class="left-right-box">
                    <div class="left-info">
                        <div class="fullname-box">
                            <div class="info-profile-boxlogo"><img src="../asset/logo/user (2).png" alt=""></div>
                            <div class="info-type">
                                <label for="admin-profile-name"><?= $isOffice ? 'Full Name' : 'Admin Name' ?></label>
                                <input id="admin-profile-name" name="admin_name" maxlength="150" autocomplete="name" value="<?= $escape($profile['admin_name']) ?>" required>
                            </div>
                        </div>
                        <div class="studentID-box">
                            <div class="info-profile-boxlogo"><img src="../asset/logo/id-badge.png" alt=""></div>
                            <div class="info-type">
                                <span class="admin-profile-label">Role</span>
                                <p data-admin-role><?= $escape($profile['role']) ?></p>
                            </div>
                        </div>
                        <div class="course-box">
                            <div class="info-profile-boxlogo"><img src="../asset/logo/book-bookmark.png" alt=""></div>
                            <div class="info-type">
                                <label for="admin-profile-school">School Name</label>
                                <input id="admin-profile-school" name="school_name" maxlength="200" autocomplete="organization" value="<?= $escape($profile['school_name']) ?>" <?= $isOffice ? 'readonly' : 'required' ?>>
                            </div>
                        </div>
                    </div>
                    <div class="right-info">
                        <div class="email-box">
                            <div class="info-profile-boxlogo"><img src="../asset/logo/envelope.png" alt=""></div>
                            <div class="info-type">
                                <label for="admin-profile-email">Email</label>
                                <input id="admin-profile-email" name="school_email" type="email" maxlength="254" autocomplete="email" value="<?= $escape($profile['school_email']) ?>" <?= $isOffice ? 'required' : '' ?>>
                            </div>
                        </div>
                        <div class="contact-box">
                            <div class="info-profile-boxlogo"><img src="../asset/logo/chat-bubble-call.png" alt=""></div>
                            <div class="info-type">
                                <label for="admin-profile-contact">Contact Number</label>
                                <input id="admin-profile-contact" name="school_contact" type="tel" maxlength="30" autocomplete="tel" value="<?= $escape($profile['school_contact']) ?>" <?= $isOffice ? 'required' : '' ?>>
                            </div>
                        </div>
                        <div class="address-box">
                            <div class="info-profile-boxlogo"><img src="../asset/logo/postal-address (1).png" alt=""></div>
                            <div class="info-type">
                                <label for="admin-profile-address">Address</label>
                                <textarea id="admin-profile-address" name="school_address" maxlength="255" rows="2" autocomplete="street-address"><?= $escape($profile['school_address']) ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="admin-profile-actions">
                    <button type="reset" class="admin-profile-reset">Reset</button>
                    <button type="submit" class="admin-profile-save">Save Changes</button>
                </div>
            </fieldset>
        </form>
    </section>
</div>

<?php if (!$isOffice): ?>
<dialog id="kns-office-registration" class="kns-office-registration-modal"
    aria-labelledby="kns-office-registration-title" aria-describedby="kns-office-registration-intro">
    <button type="button" class="kns-office-registration-close" data-close-office-registration aria-label="Close office registration">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m5 5 14 14M19 5 5 19"></path></svg>
    </button>
    <h2 id="kns-office-registration-title" class="kns-office-registration-title">Register Office Account</h2>
    <p id="kns-office-registration-intro" class="kns-office-registration-intro">Create an account for an office staff member.</p>
    <form id="kns-office-registration-form" action="../php/admin/office-accounts.php" method="post">
        <input type="hidden" name="csrf" value="<?= $escape($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="create">
    <div class="kns-office-registration-fields">
        <div class="kns-office-registration-field">
            <label for="kns-office-full-name">Full Name</label>
            <input id="kns-office-full-name" name="full_name" maxlength="150" required type="text" autocomplete="off" autofocus>
        </div>
        <div class="kns-office-registration-field">
            <label for="kns-office-username">Username</label>
            <input id="kns-office-username" name="username" minlength="3" maxlength="50" required type="text" autocomplete="off">
        </div>
        <div class="kns-office-registration-field">
            <label for="kns-office-email">Email</label>
            <input id="kns-office-email" name="email" maxlength="254" required type="email" autocomplete="off">
        </div>
        <div class="kns-office-registration-field">
            <label for="kns-office-phone">Phone Number</label>
            <input id="kns-office-phone" name="phone_number" maxlength="30" required type="tel" autocomplete="off">
        </div>
        <div class="kns-office-registration-field">
            <label for="kns-office-role">Office / Role</label>
            <select id="kns-office-role" name="role" required><option value="Registrar">Registrar</option><option value="OSA">OSA</option></select>
        </div>
        <div class="kns-office-registration-passwords">
            <div class="kns-office-registration-field">
                <label for="kns-office-password">Password</label>
                <input id="kns-office-password" name="password" minlength="12" maxlength="72" required type="password" autocomplete="new-password">
            </div>
            <div class="kns-office-registration-field">
                <label for="kns-office-confirm-password">Confirm Password</label>
                <input id="kns-office-confirm-password" name="password_confirmation" minlength="12" maxlength="72" required type="password" autocomplete="new-password">
            </div>
        </div>
    </div>
    <p class="kns-office-registration-message" data-office-registration-message role="status" aria-live="polite" hidden></p>
    <div class="kns-office-registration-actions">
        <button type="submit" class="kns-office-registration-create">Create Account</button>
        <button type="button" class="kns-office-registration-cancel" data-close-office-registration>Cancel</button>
    </div>
    </form>
</dialog>

<dialog id="kns-office-accounts" class="kns-office-accounts-modal kns-office-accounts-list"
    aria-labelledby="kns-office-accounts-title" aria-describedby="kns-office-accounts-note">
    <button type="button" class="kns-office-accounts-close" data-close-office-accounts aria-label="Close office accounts">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m5 5 14 14M19 5 5 19"></path></svg>
    </button>
    <div class="kns-office-accounts-heading">
        <h2 id="kns-office-accounts-title" class="kns-office-accounts-title">Office Accounts</h2>
        <button type="button" class="kns-office-accounts-register"><span aria-hidden="true">+</span>Register Account</button>
    </div>
    <p class="kns-office-accounts-message" data-office-accounts-message role="status" aria-live="polite" hidden></p>
    <div class="kns-office-accounts-table-wrap">
        <table class="kns-office-accounts-table" aria-label="Office accounts">
            <thead>
                <tr><th scope="col">Name</th><th scope="col">Email / Username</th><th scope="col">Office / Role</th><th scope="col">Status</th><th scope="col">Action</th></tr>
            </thead>
            <tbody><tr><td colspan="5" class="kns-office-accounts-state">Open the list to load office accounts.</td></tr></tbody>
        </table>
    </div>
    <p id="kns-office-accounts-note" class="kns-office-accounts-note">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"></circle><path d="M12 11v6M12 7h.01"></path></svg>
        Deleted accounts are deactivated; request history is preserved.
    </p>
</dialog>

<dialog id="kns-office-account-delete" class="kns-office-accounts-modal kns-office-accounts-confirmation"
    aria-labelledby="kns-office-accounts-delete-title" aria-describedby="kns-office-accounts-delete-intro">
    <button type="button" class="kns-office-accounts-close" data-close-office-account-delete aria-label="Close delete confirmation">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m5 5 14 14M19 5 5 19"></path></svg>
    </button>
    <h2 id="kns-office-accounts-delete-title" class="kns-office-accounts-title">Delete Office Account?</h2>
    <p id="kns-office-accounts-delete-intro" class="kns-office-accounts-intro">Confirm the selected office account.</p>
    <form id="kns-office-deactivation-form" action="../php/admin/office-accounts.php" method="post">
        <input type="hidden" name="csrf" value="<?= $escape($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="deactivate">
        <input type="hidden" name="account_id" value="">
    <dl class="kns-office-accounts-selected">
        <div><dt>Name</dt><dd data-selected-office-account-name></dd></div>
        <div><dt>Office / Role</dt><dd data-selected-office-account-role></dd></div>
    </dl>
    <label for="kns-office-accounts-admin-password" class="kns-office-accounts-password-label">Admin Password</label>
    <input id="kns-office-accounts-admin-password" name="admin_password" required class="kns-office-accounts-password" type="password" autocomplete="off" aria-describedby="kns-office-accounts-password-help" autofocus>
    <p id="kns-office-accounts-password-help" class="kns-office-accounts-password-help">Enter your own password for the Admin account you are currently signed in with.</p>
    <p class="kns-office-accounts-message" data-office-deactivation-message role="status" aria-live="polite" hidden></p>
    <div class="kns-office-accounts-actions">
        <button type="button" class="kns-office-accounts-cancel" data-close-office-account-delete>Cancel</button>
        <button type="submit" class="kns-office-accounts-delete-visual">Delete Account</button>
    </div>
    </form>
</dialog>

<?php endif; ?>
