<?php

require_once __DIR__ . '/../php/auth/staff-access.php';
require_once __DIR__ . '/../php/admin/staff-settings-common.php';

function adminProfileJson(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

require __DIR__ . '/../db.php';
$profile = knsRequireStaffAccess($pdo, 'profile', '../login.php');
$isOffice = $profile['role'] !== 'Administrator';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    adminProfileJson(405, ['ok'=>false, 'message'=>'Edit your account details through Settings.']);
}
$staffPreferences = knsStaffPreferences($pdo, (int) $profile['id']);
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
<div class="student-info-container admin-profile" data-staff-theme="<?= $escape($staffPreferences['theme']) ?>" data-staff-language="<?= $escape($staffPreferences['language']) ?>">
    <div class="kns-office-registration-anchor">
        <div class="student-info-header">
            <h1><?= $isOffice ? $escape($profile['role']) . ' Profile' : 'Admin Profile' ?></h1>
            <p><?= $isOffice ? 'View your office profile and contact details. Edit them in Settings.' : 'View your administrator and school details. Edit them in Settings.' ?></p>
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
        <?php require __DIR__ . '/staff-settings-panel.php'; ?>
        <div class="person-profile admin-profile-photo-view">
            <img src="<?= $escape($profilePhoto) ?>" alt="Profile photo" data-admin-photo>
        </div>
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

    <section class="student-information">
        <div class="academic-info">
            <div class="grad-hat">
                <img src="../asset/logo/admin-alt.svg" alt="">
            </div>
            <div class="academic-text">
                <h2><?= $isOffice ? 'Office Information' : 'Administrator Information' ?></h2>
                <p><?= $isOffice ? 'Manage your profile and office contact details in Settings.' : 'Manage your profile and school contact details in Settings.' ?></p>
            </div>
        </div>

        <div id="admin-profile-details" aria-label="Account information">
            <fieldset>
                <legend class="admin-profile-legend"><?= $isOffice ? 'Office profile details' : 'Administrator and school details' ?></legend>
                <div class="left-right-box">
                    <div class="left-info">
                        <div class="fullname-box">
                            <div class="info-profile-boxlogo"><img src="../asset/logo/user (2).png" alt=""></div>
                            <div class="info-type">
                                <label for="admin-profile-name"><?= $isOffice ? 'Full Name' : 'Admin Name' ?></label>
                                <input id="admin-profile-name" name="admin_name" maxlength="150" readonly autocomplete="name" value="<?= $escape($profile['admin_name']) ?>">
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
                                <input id="admin-profile-school" name="school_name" maxlength="200" readonly autocomplete="organization" value="<?= $escape($profile['school_name']) ?>" >
                            </div>
                        </div>
                    </div>
                    <div class="right-info">
                        <div class="email-box">
                            <div class="info-profile-boxlogo"><img src="../asset/logo/envelope.png" alt=""></div>
                            <div class="info-type">
                                <label for="admin-profile-email">Email</label>
                                <input id="admin-profile-email" name="school_email" title="Use Settings > Change Email to verify a new address." type="email" maxlength="254" readonly autocomplete="email" value="<?= $escape($profile['school_email']) ?>" >
                            </div>
                        </div>
                        <div class="contact-box">
                            <div class="info-profile-boxlogo"><img src="../asset/logo/chat-bubble-call.png" alt=""></div>
                            <div class="info-type">
                                <label for="admin-profile-contact">Contact Number</label>
                                <input id="admin-profile-contact" name="school_contact" type="tel" maxlength="30" readonly autocomplete="tel" value="<?= $escape($profile['school_contact']) ?>" >
                            </div>
                        </div>
                        <div class="address-box">
                            <div class="info-profile-boxlogo"><img src="../asset/logo/postal-address (1).png" alt=""></div>
                            <div class="info-type">
                                <label for="admin-profile-address">Address</label>
                                <textarea id="admin-profile-address" name="school_address" maxlength="255" rows="2" readonly autocomplete="street-address"><?= $escape($profile['school_address']) ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="admin-profile-readonly-note">Manage these details in Settings.</p>
            </fieldset>
        </div>
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
