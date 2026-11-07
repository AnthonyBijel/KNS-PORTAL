<?php
require __DIR__ . '/php/auth/session.php';

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <script src="js/tab-session.js?v=<?= filemtime(__DIR__ . '/js/tab-session.js') ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <link rel="stylesheet" href="css/login.css">
    <link rel="stylesheet" href="css/password-reset.css?v=<?= filemtime(__DIR__ . '/css/password-reset.css') ?>">
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
                <div><a href="#password-recovery" id="forgot-password-link" aria-haspopup="dialog" aria-controls="password-reset-modal">Forgot password?</a></div>
            </div>
            <button type="submit">Sign in to student portal</button>

        </form>

    </div>














    <div class="kns-reset-overlay" id="password-reset-modal" hidden>
        <section class="kns-reset-card" role="dialog" aria-modal="true" aria-labelledby="reset-heading" aria-describedby="reset-intro" tabindex="-1">
            <button type="button" class="kns-reset-close" id="reset-close" aria-label="Close password recovery">&times;</button>
            <p class="kns-reset-step" id="reset-step">Step 1 of 2</p>
            <h2 id="reset-heading">Recover your password</h2>
            <p class="kns-reset-intro" id="reset-intro">Recover your student account using your saved contact details.</p>
            <p class="kns-reset-message" id="reset-message" role="status" aria-live="polite" hidden></p>

            <form id="reset-lookup-form">
                <label for="reset-student-number">Student number</label>
                <input id="reset-student-number" name="student_number" type="text" maxlength="30" autocomplete="username" placeholder="Enter your student number" required>
                <button type="submit">Find recovery options</button>
            </form>

            <form id="reset-send-form" hidden>
                <p class="kns-reset-student" id="reset-student-label"></p>
                <fieldset class="kns-reset-channels">
                    <legend>Where should we send your code?</legend>
                    <label class="kns-reset-channel">
                        <input type="radio" name="channel" value="email" id="reset-channel-email" required>
                        <span><strong>Email</strong><span id="reset-masked-email"></span></span>
                    </label>
                    <label class="kns-reset-channel">
                        <input type="radio" name="channel" value="sms" id="reset-channel-sms">
                        <span><strong>SMS</strong><span id="reset-masked-sms"></span></span>
                    </label>
                </fieldset>
                <button type="submit">Send recovery code</button>
                <button type="button" class="kns-reset-secondary" data-reset-back="lookup">Use a different student number</button>
            </form>

            <form id="reset-otp-form" hidden>
                <p class="kns-reset-destination" id="reset-destination"></p>
                <label for="reset-otp">Six-digit recovery code</label>
                <input id="reset-otp" name="otp" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" required>
                <p class="kns-reset-hint" id="reset-expiry"></p>
                <button type="submit">Verify code</button>
                <div class="kns-reset-actions">
                    <button type="button" class="kns-reset-secondary" id="reset-resend">Resend code</button>
                    <button type="button" class="kns-reset-secondary" data-reset-back="send">Change delivery option</button>
                </div>
            </form>

            <form id="reset-password-form" hidden>
                <label for="reset-new-password">New Password</label>
                <input id="reset-new-password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
                <p class="kns-reset-hint">Use at least 12 characters. Maximum 72 bytes.</p>
                <label for="reset-confirm-password">Confirm Password</label>
                <input id="reset-confirm-password" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
                <p class="kns-reset-hint" id="reset-grant-expiry"></p>
                <button type="submit">Update password</button>
            </form>

            <div id="reset-success" hidden>
                <p>You can now sign in to the student portal with your new password.</p>
                <button type="button" id="reset-done">Back to sign in</button>
            </div>
        </section>
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
            accountInput.maxLength = 30;
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
            accountInput.maxLength = 50;
            submitButton.textContent = "Sign in to admin portal";
        }

        accountOptions[0].addEventListener("click", selectStudentLogin);
        accountOptions[1].addEventListener("click", selectAdminLogin);

        selectStudentLogin();
    </script>
    <script src="js/password-reset.js?v=<?= filemtime(__DIR__ . '/js/password-reset.js') ?>"></script>
</body>

</html>