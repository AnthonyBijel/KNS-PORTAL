<?php

require __DIR__ . '/../php/auth/session.php';

function adminProfileJson(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if (empty($_SESSION['admin_id'])) {
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

$adminId = (int) $_SESSION['admin_id'];
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

    if ($errors) {
        adminProfileJson(422, ['ok' => false, 'message' => 'Please check your profile details.', 'errors' => $errors]);
    }
}

try {
    require __DIR__ . '/../db.php';

    $stmt = $pdo->prepare(
        'SELECT admin_name, role, school_name, school_email, school_contact, school_address, profile_photo
         FROM admin_account
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$adminId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        if ($isPost) {
            adminProfileJson(404, ['ok' => false, 'message' => 'Admin account not found.']);
        }
        http_response_code(404);
        exit('Admin account not found.');
    }

    if ($isPost) {
        $stmt = $pdo->prepare(
            'UPDATE admin_account
             SET admin_name = ?, school_name = ?, school_email = ?, school_contact = ?, school_address = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $changes['admin_name'],
            $changes['school_name'],
            $changes['school_email'],
            $changes['school_contact'],
            $changes['school_address'],
            $adminId,
        ]);

        $_SESSION['admin_name'] = $changes['admin_name'];
        $_SESSION['admin_role'] = $profile['role'];

        adminProfileJson(200, [
            'ok' => true,
            'message' => 'Profile updated successfully.',
            'profile' => array_merge($changes, ['role' => $profile['role']]),
        ]);
    }
} catch (PDOException $error) {
    error_log('Admin profile: ' . $error->getMessage());
    if ($isPost) {
        adminProfileJson(500, ['ok' => false, 'message' => 'Unable to save your profile. Please try again.']);
    }
    http_response_code(500);
    exit('Unable to load the admin profile. Please try again.');
}

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

<div class="student-info-container admin-profile">
    <div class="student-info-header">
        <h1>Admin Profile</h1>
        <p>View and update your administrator and school details.</p>
    </div>

    <div class="profile">
        <div class="person-profile">
            <img src="<?= $escape($profilePhoto) ?>" alt="Admin profile photo">
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
                <h2>Administrator Information</h2>
                <p>Keep your profile and school contact details up to date.</p>
            </div>
        </div>

        <form id="admin-profile-form" action="profile.php" method="post">
            <input type="hidden" name="csrf" value="<?= $escape($_SESSION['csrf']) ?>">
            <p id="admin-profile-message" role="status" aria-live="polite"></p>
            <fieldset>
                <legend class="admin-profile-legend">Administrator and school details</legend>
                <div class="left-right-box">
                    <div class="left-info">
                        <div class="fullname-box">
                            <div class="info-profile-boxlogo"><img src="../asset/logo/user (2).png" alt=""></div>
                            <div class="info-type">
                                <label for="admin-profile-name">Admin Name</label>
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
                                <input id="admin-profile-school" name="school_name" maxlength="200" autocomplete="organization" value="<?= $escape($profile['school_name']) ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="right-info">
                        <div class="email-box">
                            <div class="info-profile-boxlogo"><img src="../asset/logo/envelope.png" alt=""></div>
                            <div class="info-type">
                                <label for="admin-profile-email">Email</label>
                                <input id="admin-profile-email" name="school_email" type="email" maxlength="254" autocomplete="email" value="<?= $escape($profile['school_email']) ?>">
                            </div>
                        </div>
                        <div class="contact-box">
                            <div class="info-profile-boxlogo"><img src="../asset/logo/chat-bubble-call.png" alt=""></div>
                            <div class="info-type">
                                <label for="admin-profile-contact">Contact Number</label>
                                <input id="admin-profile-contact" name="school_contact" type="tel" maxlength="30" autocomplete="tel" value="<?= $escape($profile['school_contact']) ?>">
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