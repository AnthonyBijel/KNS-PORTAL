<?php

require __DIR__ . '/../php/auth/session.php';
requireStudent();

require __DIR__ . '/../db.php';

$stmt = $pdo->prepare(
    'SELECT
        student_number,
        first_name,
        middle_name,
        last_name,
        suffix,
        course,
        year_level,
        section,
        status,
        email,
        contact_number,
        address,
        birth_date,
        guardian_name,
        profile_photo
     FROM students
     WHERE id = ? AND status = ?
     LIMIT 1'
);

$stmt->execute([
    $_SESSION['student_id'],
    'Active'
]);

$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    http_response_code(404);
    exit('Student record not found.');
}

$photoFeedback = $_SESSION['student_photo_feedback'] ?? null;
unset($_SESSION['student_photo_feedback']);

function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$fullName = implode(' ', array_filter([
    $student['first_name'],
    $student['middle_name'],
    $student['last_name'],
    $student['suffix']
]));

$yearLevel = match ((int) $student['year_level']) {
    1 => '1st Year',
    2 => '2nd Year',
    3 => '3rd Year',
    4 => '4th Year',
    default => (string) $student['year_level'] . 'th Year'
};

$birthDate = $student['birth_date']
    ? date('F j, Y', strtotime($student['birth_date']))
    : 'Not yet provided';

$profilePhoto = !empty($student['profile_photo'])
    ? '../' . ltrim($student['profile_photo'], '/')
    : '../asset/logo/user (1).png';
?>

<div class="student-info-container">

    <h1>STUDENT INFORMATION</h1>
    <p>View your personal and academic details.</p>

    <div class="profile">

        <form id="student-photo-form" class="student-photo-form"
            action="../php/student/upload_profile_photo.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="MAX_FILE_SIZE" value="2097152">
            <input id="student-profile-photo" type="file" name="profile_photo"
                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                aria-label="Choose a new profile photo" hidden required>
            <button type="button" class="person-profile student-photo-button" data-choose-photo
                aria-label="Change profile photo. Your selected photo is saved automatically."
                aria-describedby="student-photo-help" title="Change profile photo (JPG, PNG, or WEBP; maximum 2 MB)">
                <img src="<?= e($profilePhoto) ?>" alt="Student profile photo" data-student-photo>
                <span class="student-photo-overlay" aria-hidden="true"><span data-photo-label>Change photo</span></span>
                <span class="student-photo-badge" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" focusable="false">
                        <path d="M14.5 4h-5L8 6H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-3l-1.5-2Z"></path>
                        <circle cx="12" cy="13" r="3"></circle>
                    </svg>
                </span>
            </button>
            <span id="student-photo-help" class="student-photo-help">Click your profile photo to choose a replacement.
                It saves automatically. JPG/JPEG, PNG, or WEBP; maximum 2 MB, 4096 pixels per side, and 12 megapixels.</span>
        </form>

        <div class="student-name">
            <h1><?= e($fullName) ?></h1>
            <p><?= e($student['course']) ?></p>
            <p>KOLEHIYO NG SUBIC</p>
        </div>

        <div class="student-status">
            <img src="../asset/logo/graduate-hat.png" alt="">
            <div class="current-status">
                <h1>STUDENT STATUS</h1>
                <p><?= e($student['status']) ?></p>
            </div>
        </div>

    </div>

    <p id="student-photo-message" class="student-photo-message" role="status" aria-live="polite"
        data-state="<?= !empty($photoFeedback['ok']) ? 'success' : 'error' ?>"><?= e($photoFeedback['message'] ?? '') ?></p>

    <div class="student-information">

        <h2>Personal Information</h2>

        <div class="left-right-box">

            <div class="left-info">

                <div class="fullname-box">
                    <img src="../asset/logo/user (2).png" alt="">
                    <div class="info-type">
                        <h1>Full Name</h1>
                        <p><?= e($fullName) ?></p>
                    </div>
                </div>

                <div class="studentID-box">
                    <img src="../asset/logo/id-badge.png" alt="">
                    <div class="info-type">
                        <h1>Student ID</h1>
                        <p><?= e($student['student_number']) ?></p>
                    </div>
                </div>

                <div class="course-box">
                    <img src="../asset/logo/book-bookmark.png" alt="">
                    <div class="info-type">
                        <h1>Course</h1>
                        <p><?= e($student['course']) ?></p>
                    </div>
                </div>

                <div class="yearlevel-box">
                    <img src="../asset/logo/layer.png" alt="">
                    <div class="info-type">
                        <h1>Year Level</h1>
                        <p><?= e($yearLevel) ?></p>
                    </div>
                </div>

                <div class="section-box">
                    <img src="../asset/logo/people-line.png" alt="">
                    <div class="info-type">
                        <h1>Section</h1>
                        <p><?= e($student['section']) ?></p>
                    </div>
                </div>

            </div>

            <div class="right-info">

                <div class="email-box">
                    <img src="../asset/logo/envelope.png" alt="">
                    <div class="info-type">
                        <h1>Email</h1>
                        <p><?= e($student['email'] ?: 'Not yet provided') ?></p>
                    </div>
                </div>

                <div class="contact-box">
                    <img src="../asset/logo/chat-bubble-call.png" alt="">
                    <div class="info-type">
                        <h1>Contact Number</h1>
                        <p><?= e($student['contact_number'] ?: 'Not yet provided') ?></p>
                    </div>
                </div>

                <div class="address-box">
                    <img src="../asset/logo/postal-address (1).png" alt="">
                    <div class="info-type">
                        <h1>Address</h1>
                        <p><?= e($student['address'] ?: 'Not yet provided') ?></p>
                    </div>
                </div>

                <div class="birthday-box">
                    <img src="../asset/logo/calendar-birhtday-cake.png" alt="">
                    <div class="info-type">
                        <h1>Birthday</h1>
                        <p><?= e($birthDate) ?></p>
                    </div>
                </div>

                <div class="guardian-box">
                    <img src="../asset/logo/family.png" alt="">
                    <div class="info-type">
                        <h1>Guardian</h1>
                        <p><?= e($student['guardian_name'] ?: 'Not yet provided') ?></p>
                    </div>
                </div>

            </div>

        </div>

    </div>

</div>