<?php
require __DIR__ . '/php/auth/session.php';

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <link rel="stylesheet" href="css/login.css">
</head>

<body>
    <!--LOGIN-LEFT-CONTIANER-->

    <div class="left-contianer">
        <div class="school-title">
            <div class="logo-container">
                <img class="logo" src="asset/logo/273844945_520328949759168_1274024641438937791_n.jpg">
            </div>
            <div class="log-in-title">
                <div class="KNS-top-title">
                    <p>KNS Portal</p>
                </div>
                <div class="KNS-bottom-title">
                    <p>Kolehiyo ng Subic</p>
                </div>
            </div>
        </div>
        <div class="login-story-copy">
            <div class="login-story">
                <div class="hat-box">
                    <img class="graduate-hat" src="asset/logo/graduate-hat.png">
                    <div class="Your-school">
                        <p>Your school, one portal</p>
                    </div>

                </div>
                <div class="third-layer">
                    <h1>School services made simpler.</h1>
                </div>
            </div>
            <div class="fourth-layer">
                <p>Check your progress, submit requests, and stay informed from one secure workspace.</p>
            </div>
            <div class="benefits-box">
                <img class="status-logo" src="asset/logo/status-update.png">
                <p>View exam, quiz, and activity results</p>
            </div>
            <div class="benefits-box">
                <img class="status-logo" src="asset/logo/job-application.png">
                <p>View exam, quiz, and activity results</p>
            </div>
            <div class="benefits-box">
                <img class="status-logo" src="asset/logo/megaphone.png">
                <p>View exam, quiz, and activity results</p>
            </div>
        </div>
    </div>
    <!--RIGHT-LOGIN CONTIANER-->

    <div class="right-contianer">
        <form class="left-form"
            action="php/auth/login.php"
            method="POST">

            <input type="hidden"
                name="csrf"
                value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">

            <?php if ($error !== ''): ?>
                <p role="alert" style="color: #b42318;">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endif; ?>


            <div class="wel">
                <p>Welcome</p>
            </div>
            <div class="sign">Sign in to your portal</div>
            <div class="choose">Choose your account type to continue.</div>

            <div class="user-choose">
                <div class="user-student">
                    <div class="hat-boxxy"><img class="user-student-logo" src="asset/logo/student.svg"></div>
                    <p class="user-student-text">student</p>
                </div>
                <div class="user-student">
                    <div class="hat-boxxy"><img class="user-student-logo" src="asset/logo/admin-alt.svg"></div>
                    <p class="user-student-text">Admin</p>
                </div>

            </div>
            <p class="stu">student ID</p>
            <div class="id-input">
                <img class="user-input" src="asset/logo/user.svg">
                <input type="text"
                    name="student_number"
                    placeholder="Student number"
                    maxlength="30"
                    autocomplete="username"
                    required>
            </div>
            <p class="stu">Password</p>
            <div class="password-enter">
                <img class="user-input" src="asset/logo/user.svg">
                <input type="password"
                    name="password"
                    placeholder="Password"
                    class="password-input"
                    autocomplete="current-password"
                    required>
            </div>
            <div class="forget-checkbox">
                <div>
                    <input type="checkbox" id="remember">
                    <label class="remem" for="remember">Remember me</label>
                </div>
                <div><a href="">Forget password?</a></div>
            </div>
            <button type="submit">Sign in to student portal</button>

        </form>

    </div>














    <script>
        const accountOptions = document.querySelectorAll(".user-student");
        const loginForm = document.querySelector(".left-form");
        const accountInput = document.querySelector(".id-input input");
        const accountLabel = document.querySelectorAll(".stu")[0];
        const submitButton = loginForm.querySelector("button[type='submit']");

        function selectStudentLogin() {
            accountOptions.forEach(option => {
                option.classList.remove("selected");
            });

            accountOptions[0].classList.add("selected");

            loginForm.action = "php/auth/login.php";
            accountLabel.textContent = "student ID";
            accountInput.placeholder = "Student number";
            submitButton.textContent = "Sign in to student portal";
        }

        function selectAdminLogin() {
            accountOptions.forEach(option => {
                option.classList.remove("selected");
            });

            accountOptions[1].classList.add("selected");

            loginForm.action = "php/auth/admin_login.php";
            accountLabel.textContent = "Username";
            accountInput.placeholder = "Admin username";
            submitButton.textContent = "Sign in to admin portal";
        }

        accountOptions[0].addEventListener("click", selectStudentLogin);
        accountOptions[1].addEventListener("click", selectAdminLogin);

        selectStudentLogin();
    </script>
</body>

</html>