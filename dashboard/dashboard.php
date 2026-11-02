<?php

require __DIR__ . '/../php/auth/session.php';
requireStudent();

require __DIR__ . '/../db.php';

$stmt = $pdo->prepare(
    'SELECT first_name, middle_name, last_name, course, status
     FROM students
     WHERE id = ? AND status = ?
     LIMIT 1'
);

$stmt->execute([
    currentStudentId(),
    'Active'
]);

$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

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
    $student['last_name']
]));

$courseName = $student['course'];

$courseCode = match (true) {
    stripos($courseName, 'Computer Science') !== false => 'BSCS',
    stripos($courseName, 'Information Technology') !== false => 'BSIT',
    stripos($courseName, 'Business Administration') !== false => 'BSBA',
    stripos($courseName, 'Accountancy') !== false => 'BSA',
    stripos($courseName, 'Elementary Education') !== false => 'BEED',
    default => 'Student'
};

$initials = strtoupper(
    substr($student['first_name'], 0, 1) .
        substr($student['last_name'], 0, 1)
);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard.org</title>
    <link rel="stylesheet" href="dashboard.css?v=<?= filemtime(__DIR__ . '/dashboard.css') ?>">
    <link rel="stylesheet" href="announcement-share.css?v=<?= filemtime(__DIR__ . '/announcement-share.css') ?>">
    <link rel="stylesheet" href="../request/messenger.css?v=<?= filemtime(__DIR__ . '/../request/messenger.css') ?>">
    <link rel="stylesheet" href="notifications.css?v=<?= filemtime(__DIR__ . '/notifications.css') ?>">
</head>

<body>
    <!--HEADER CONTIANER-->

    <div class="header-main-contianer">
        <div class="header-left-box">

            <div class="left-text-box">
                <p class="bottom-left-header">My portal</p>
                <p class="top-left-header">Student services</p>

            </div>


        </div>
        <div class="header-right-box">
            <div class="bell-contianer">
                <button type="button" class="student-notification-bell" id="studentNotificationsBell"
                        aria-label="Notifications" aria-haspopup="dialog" aria-controls="studentNotificationPanel" aria-expanded="false">
                    <img class="bell-logo" src="../asset/logo/bell-notification-social-media.png" alt="" aria-hidden="true">
                    <span class="kn-bell-count" id="studentNotificationCount" hidden>0</span>
                </button>
            </div>
            <div class="middle-text">
                <div class="initial-box">
                    <p><?= e($initials) ?></p>
                </div>
            </div>
            <div class="last-tex-box">
                <p class="username-top">
                    <?= e($fullName) ?>
                </p>
                <p class="course-bottom">
                    <?= e($courseCode) ?> Student
                </p>
            </div>
        </div>
    </div>
    <!--SIDE-BAR-PANEL-->
    <div class="sidebar-contianer">
        <div class="portal-title-contianer">
            <div class="portal-title-logo-box">
                <img class="portal-title-logo" src="../asset/logo/273844945_520328949759168_1274024641438937791_n.jpg">
            </div>
            <div class="portal-title-box">
                <p class="KNS-PORTAL">KNS Portal</p>
                <p class="KOLEHIYO-NG-SUBIC">KOLEHIYO NG SUBIC</p>
            </div>

        </div>
        <div class="user-status-contianer">
            <div class="user-logo-contianer">
                <img class="user-logo" src="../asset/logo/user.svg">
            </div>
            <div class="user-status-sign">
                <p>Signed in as</p>
                <h1>Student</h1>
            </div>
        </div>
        <div class="sidebar-navigator">
            <h1 class="menu-text">MAIN MENU</h1>

            <div class="overview-button"
                onclick="loadPage('../module/overview.php')">
                <img class="overview-logo" src="../asset/logo/overview (1).png">
                <p>Overview</p>
            </div>



            <div class="results-button"
                onclick="loadPage('../module/module-results.html')">

                <img class="overview-logo" src="../asset/logo/stats (1).png">
                <p>Academic</p>
            </div>

            <div class="may-request-button"
                onclick="loadPage('../module/request.html')">
                <img class="overview-logo" src="../asset/logo/suggestion (1).png">
                <p>My Request</p>
            </div>
            <div class="student-information-button"
                onclick="loadPage('../module/studentInformation.php')">
                <img class="overview-logo" src="../asset/logo/user.png">
                <p>Student Information</p>
            </div>
        </div>


        <div class="help-contianer">
            <h1>Need help?</h1>
            <p>Contact Student Services</p>
        </div>
        <form class="logout-contianer" action="../php/auth/logout.php" method="post">
            <button class="logout-button" type="submit">
                <img class="logout-logo" src="../asset/logo/exit.png" alt="">
                <span>LOG OUT</span>
            </button>
        </form>
    </div>
    <!--main-content-->
    <div class="main-content-contianer" id="main-content-contianer"></div>




    <script src="../request/messenger.js?v=<?= filemtime(__DIR__ . '/../request/messenger.js') ?>"></script>
    <script src="notifications.js?v=<?= filemtime(__DIR__ . '/notifications.js') ?>"></script>
    <script src="announcement-share.js?v=<?= filemtime(__DIR__ . '/announcement-share.js') ?>"></script>
    <script src="dashboard.js?v=<?= filemtime(__DIR__ . '/dashboard.js') ?>"></script>
</body>

</html>