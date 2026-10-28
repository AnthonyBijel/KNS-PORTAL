<?php
require __DIR__ . '/../php/auth/session.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require __DIR__ . '/../db.php';

$stmt = $pdo->prepare(
    'SELECT admin_name, role, school_name, school_email, school_contact, school_address, profile_photo
     FROM admin_account
     WHERE id = ?
     LIMIT 1'
);
$stmt->execute([(int) $_SESSION['admin_id']]);

$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role']);
    header('Location: ../login.php');
    exit;
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>

<body>

</body>

</html>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard.org</title>
    <link rel="stylesheet" href="admin.css?v=grade-upload-20261029">
</head>

<body>
    <!--HEADER CONTIANER-->

    <div class="header-main-contianer">
        <div class="header-left-box">
            <div class="sidebar-logo-box"><img class="sidebar-logo" src="../asset/logo/sidebar-flip.png"></div>
            <div class="left-text-box">
                <p class="top-left-header">Admin services</p>
                <p class="bottom-left-header">My portal</p>
            </div>


        </div>
        <div class="header-right-box">
            <div class="bell-contianer">
                <img class="bell-logo" src="../asset/logo/bell-notification-social-media.png">
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



            <div class="results-button"
                onclick="loadPage('results.php')">

                <img class="overview-logo" src="../asset/logo/stats (1).png">
                <p>Results</p>
            </div>

            <div class="may-request-button"
                onclick="loadPage('requests.html')">
                <img class="overview-logo" src="../asset/logo/suggestion (1).png">
                <p>My request</p>
            </div>
            <div class="student-information-button"
                onclick="loadPage('profile.php')">
                <img class="overview-logo" src="../asset/logo/user.png">
                <p>Admin Profile</p>
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
    <div class="main-content-contianer" id="main-content-contianer"></div>




    <script src="admin.js?v=grade-upload-20261029"></script>
</body>

</html>