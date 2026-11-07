<?php
require_once __DIR__ . '/../php/auth/staff-access.php';
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../php/admin/staff-settings-common.php';
$admin = knsRequireStaffAccess($pdo, 'overview');
$portalPreferences = knsStaffPreferences($pdo, (int) $admin['id']);
$isOffice = $admin['role'] !== 'Administrator';

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="<?= e($portalPreferences['language']) ?>" data-staff-portal data-staff-theme="<?= e($portalPreferences['theme']) ?>" data-staff-language="<?= e($portalPreferences['language']) ?>">

<head>
    <script src="../js/tab-session.js?v=<?= filemtime(__DIR__ . '/../js/tab-session.js') ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard.org</title>
    <link rel="stylesheet" href="academic-terms.css?v=<?= filemtime(__DIR__ . '/academic-terms.css') ?>">
    <link rel="stylesheet" href="admin.css?v=<?= filemtime(__DIR__ . '/admin.css') ?>">
    <link rel="stylesheet" href="student-list-download.css?v=<?= filemtime(__DIR__ . '/student-list-download.css') ?>">
    <link rel="stylesheet" href="staff-settings.css?v=<?= filemtime(__DIR__ . '/staff-settings.css') ?>">
    <link rel="stylesheet" href="../dashboard/announcement-share.css?v=<?= filemtime(__DIR__ . '/../dashboard/announcement-share.css') ?>">
    <link rel="stylesheet" href="../request/messenger.css?v=<?= filemtime(__DIR__ . '/../request/messenger.css') ?>">
    <link rel="stylesheet" href="../dashboard/notifications.css?v=<?= filemtime(__DIR__ . '/../dashboard/notifications.css') ?>">
    <link rel="stylesheet" href="../dashboard/announcement-gallery.css?v=<?= filemtime(__DIR__ . '/../dashboard/announcement-gallery.css') ?>">
    <link rel="stylesheet" href="staff-portal.css?v=<?= filemtime(__DIR__ . '/staff-portal.css') ?>">
</head>

<body>
    <!--HEADER CONTIANER-->

    <div class="header-main-contianer">
        <div class="header-left-box">
            <div class="sidebar-logo-box"><img class="sidebar-logo" src="../asset/logo/sidebar-flip.png"></div>
            <div class="left-text-box">
                <p class="top-left-header"><?= $isOffice ? e($admin['role']) . ' services' : 'Admin services' ?></p>
                <p class="bottom-left-header">My portal</p>
            </div>


        </div>
        <div class="header-right-box">
            <div class="bell-contianer">
                <button type="button" class="portal-notification-bell" id="adminNotificationsBell"
                        aria-label="Notifications" aria-haspopup="dialog" aria-controls="adminNotificationPanel" aria-expanded="false">
                    <img class="bell-logo" src="../asset/logo/bell-notification-social-media.png" alt="" aria-hidden="true">
                    <span class="kn-bell-count" id="adminNotificationCount" hidden>0</span>
                </button>
            </div>
            <div class="middle-text">
                <div class="initial-box">
                    <P>AD</P>
                </div>
            </div>
            <div class="last-tex-box">
                <p class="username-top" data-admin-name>
                    <?= e($admin['admin_name']) ?>
                </p>
                <p class="course-bottom" data-admin-role>
                    <?= e($admin['role']) ?>
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
                <p class="KOLEHIYO-NG-SUBIC" data-school-name>
                    <?= e($admin['school_name']) ?>
                </p>
            </div>

        </div>
        <div class="user-status-contianer">
            <div class="user-logo-contianer">
                <img class="user-logo" src="../asset/logo/admin-alt.svg">
            </div>
            <div class="user-status-sign">
                <p>Signed in as</p>
                <h1 data-admin-name><?= e($admin['admin_name']) ?></h1>
            </div>
        </div>
        <div class="sidebar-navigator">
            <h1 class="menu-text">MAIN MENU</h1>

            <div class="overview-button"
                onclick="loadPage('overview.php')">
                <img class="overview-logo" src="../asset/logo/overview (1).png">
                <p>Overview</p>
            </div>



            <?php if (knsStaffMay($admin, 'results')): ?>
            <div class="results-button"
                onclick="loadPage('results.php')">

                <img class="overview-logo" src="../asset/logo/stats (1).png">
                <p>Results</p>
            </div>

            <?php endif; ?>
            <div class="may-request-button"
                onclick="loadPage('requests.html')">
                <img class="overview-logo" src="../asset/logo/suggestion (1).png">
                <p>My request</p>
            </div>
            <div class="student-information-button"
                onclick="loadPage('profile.php')">
                <img class="overview-logo" src="../asset/logo/user.png">
                <p><?= $isOffice ? 'My Profile' : 'Admin Profile' ?></p>
            </div>
        </div>


        <div class="help-contianer">
            <h1>Need help?</h1>
            <p>Contact Student Services</p>
        </div>
        <div class="logout-contianer">
            <img class="logout-logo" src="../asset/logo/exit.png">
            <p><a href="../php/auth/admin_logout.php">LOG OUT</a></p>
        </div>
    </div>
    <!--main-content-->
    <div class="main-content-contianer" id="main-content-contianer" data-staff-role="<?= e($admin['role']) ?>"></div>




    <script src="staff-translations.js?v=<?= filemtime(__DIR__ . '/staff-translations.js') ?>"></script>
    <script src="staff-portal.js?v=<?= filemtime(__DIR__ . '/staff-portal.js') ?>"></script>
    <script src="../request/messenger.js?v=<?= filemtime(__DIR__ . '/../request/messenger.js') ?>"></script>
    <script src="../dashboard/notifications.js?v=<?= filemtime(__DIR__ . '/../dashboard/notifications.js') ?>"></script>
    <script src="../dashboard/announcement-share.js?v=<?= filemtime(__DIR__ . '/../dashboard/announcement-share.js') ?>"></script>
    <script src="academic-terms.js?v=<?= filemtime(__DIR__ . '/academic-terms.js') ?>"></script>
    <script src="student-list-download.js?v=<?= filemtime(__DIR__ . '/student-list-download.js') ?>"></script>
    <script src="staff-settings.js?v=<?= filemtime(__DIR__ . '/staff-settings.js') ?>"></script>
    <script src="admin.js?v=<?= filemtime(__DIR__ . '/admin.js') ?>"></script>
    <script src="../dashboard/announcement-gallery.js?v=<?= filemtime(__DIR__ . '/../dashboard/announcement-gallery.js') ?>"></script>
</body>

</html>
