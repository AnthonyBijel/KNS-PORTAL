<?php
if (!isset($profile, $escape, $profilePhoto)) { http_response_code(404); exit; }
$settingsPaths = [
    'profile'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2z"/>',
    'notifications'=>'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
    'security'=>'<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6zM8 12l3 3 5-6"/>',
    'appearance'=>'<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8m-4-4v4"/>',
    'language'=>'<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a18 18 0 0 0 0 20 18 18 0 0 0 0-20"/>',
    'email'=>'<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 5 10 8 10-8"/>',
    'contacts'=>'<rect x="2" y="4" width="20" height="16" rx="2"/><circle cx="8" cy="10" r="2"/><path d="M5 17v-1a3 3 0 0 1 6 0v1m3-8h5m-5 4h5m-5 4h3"/>',
];
$settingsIcon = static fn($key) => '<svg viewBox="0 0 24 24" aria-hidden="true">'.$settingsPaths[$key].'</svg>';
?>
<div class="kns-staff-settings" data-staff-settings data-endpoint="../php/admin/staff-settings.php"
     data-photo-endpoint="../php/admin/upload_profile_photo.php" data-csrf="<?= $escape($_SESSION['csrf']) ?>">
    <button type="button" class="ks-gear" data-settings-gear aria-label="Open account settings" title="Settings"
            aria-haspopup="menu" aria-expanded="false" aria-controls="ks-settings-menu">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 3 1-2h4l1 2 2 1 2-1 2 3-1 2v3l2 1v4l-2 1-1 2 1 2-3 2-2-1h-3l-1 2H8l-1-2-2-1-2 1-2-3 1-2v-3l-2-1V9l2-1 1-2-1-2 3-2 2 1z" transform="translate(2 1) scale(.83)"/><circle cx="12" cy="12" r="3"/></svg>
    </button>
    <div class="ks-menu" id="ks-settings-menu" hidden>
        <h3 data-ks-text="settings">Settings</h3>
        <div role="menu" aria-label="Account settings">
        <?php foreach (['profile'=>'Profile Settings','notifications'=>'Notifications','security'=>'Security','appearance'=>'Appearance','language'=>'Language','email'=>'Change Email','contacts'=>'Contact Details'] as $key=>$label): ?>
            <button type="button" role="menuitem" tabindex="-1" data-settings-open="<?= $key ?>"><?= $settingsIcon($key) ?><span data-ks-text="<?= $key ?>"><?= $label ?></span></button>
        <?php endforeach; ?>
        </div>
    </div>
    <dialog class="ks-modal" id="ks-settings-dialog" aria-labelledby="ks-panel-title">
        <button type="button" class="ks-close" data-settings-close aria-label="Close settings"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 5 14 14M19 5 5 19"/></svg></button>
        <div class="ks-account"><img src="<?= $escape($profilePhoto) ?>" alt="" data-settings-avatar><div><strong data-settings-account-role></strong><span data-settings-school></span></div></div>
        <div class="ks-title"><span data-settings-title-icon></span><h2 id="ks-panel-title"></h2></div>
        <p class="ks-feedback" data-settings-feedback role="status" aria-live="polite" hidden></p>

        <section data-settings-panel="profile" hidden>
            <form class="ks-form" data-settings-action="profile"><fieldset>
                <div class="ks-profile-grid">
                    <div class="ks-photo-box"><button type="button" class="ks-photo" data-settings-choose-photo aria-label="Change profile photo"><img src="<?= $escape($profilePhoto) ?>" alt="Your profile photo" data-settings-avatar><span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6h4l2-3h4l2 3h4v15H4z"/><circle cx="12" cy="13" r="4"/></svg></span></button><input type="file" data-settings-photo accept=".jpg,.jpeg,.png,.webp" hidden></div>
                    <div class="ks-fields"><label><span data-ks-text="full_name">Full name</span><input name="full_name" autocomplete="name" maxlength="150" required></label><label><span data-ks-text="role">Role / Office</span><input data-settings-role readonly></label><label><span data-ks-text="school">School</span><input name="school_name" data-settings-school-input maxlength="200" autocomplete="organization" <?= $isOffice ? 'readonly disabled' : 'required' ?>></label></div>
                </div>
                <p class="ks-note" data-ks-text="photo_help">Photo saves automatically. JPG, PNG or WEBP; maximum 2 MB.</p>
                <button type="submit" class="ks-save" data-ks-text="save_changes">Save changes</button>
            </fieldset></form>
        </section>
        <section data-settings-panel="notifications" hidden>
            <form class="ks-form" data-settings-action="notifications"><fieldset>
                <div class="ks-toggles">
                    <?php foreach (['notify_announcements'=>['appearance','system_announcements','System announcements'],'notify_requests'=>['profile','student_requests','Student requests'],'notify_activity'=>['security','account_activity','Account activity'],'notify_email'=>['email','email_notifications','Email notifications']] as $key=>[$icon,$text,$label]): ?>
                    <label class="ks-toggle"><?= $settingsIcon($icon) ?><span data-ks-text="<?= $text ?>"><?= $label ?></span><input type="checkbox" role="switch" name="<?= $key ?>"><i aria-hidden="true"></i></label>
                    <?php endforeach; ?>
                </div>
                <p class="ks-note" data-ks-text="notification_help">Email preferences control optional account-change notices. Verification codes are always sent when requested.</p>
                <button type="submit" class="ks-save" data-ks-text="save_preferences">Save preferences</button>
            </fieldset></form>
        </section>
        <section data-settings-panel="security" hidden>
            <form class="ks-form" data-settings-action="security"><fieldset>
                <?php foreach (['current_password'=>['current_password','Current password','current-password'],'new_password'=>['new_password','New password','new-password'],'password_confirmation'=>['confirm_password','Confirm new password','new-password']] as $key=>[$text,$label,$autocomplete]): ?>
                <label><span data-ks-text="<?= $text ?>"><?= $label ?></span><div class="ks-password"><input type="password" name="<?= $key ?>" autocomplete="<?= $autocomplete ?>" required<?= $key !== 'current_password' ? ' minlength="12" maxlength="72"' : ' maxlength="4096"' ?>><button type="button" data-settings-show-password aria-label="Show password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7"/><circle cx="12" cy="12" r="3"/></svg></button></div></label>
                <?php endforeach; ?>
                <p class="ks-note" data-ks-text="password_help">Use at least 12 characters. The new passwords must match.</p>
                <button type="submit" class="ks-save" data-ks-text="change_password">Change password</button>
            </fieldset></form>
        </section>
        <section data-settings-panel="appearance" hidden>
            <form class="ks-form" data-settings-action="appearance"><fieldset>
                <span class="ks-label" data-ks-text="theme">Theme</span>
                <div class="ks-themes">
                    <?php foreach (['light'=>'Light','dark'=>'Dark'] as $value=>$label): ?>
                    <label class="ks-theme-card"><input type="radio" name="theme" value="<?= $value ?>" required><span class="ks-theme-preview ks-preview-<?= $value ?>" aria-hidden="true"><i></i><i></i><i></i></span><strong data-ks-text="<?= $value ?>"><?= $label ?></strong></label>
                    <?php endforeach; ?>
                </div>
                <p class="ks-note" data-ks-text="appearance_help">Applies to your entire signed-in portal.</p>
                <button type="submit" class="ks-save" data-ks-text="save">Save</button>
            </fieldset></form>
        </section>
        <section data-settings-panel="language" hidden>
            <form class="ks-form" data-settings-action="language"><fieldset>
                <p class="ks-intro" data-ks-text="language_help">Select your preferred language for your entire signed-in portal.</p>
                <label class="ks-language"><input type="radio" name="language" value="en" required><span>English</span></label>
                <label class="ks-language"><input type="radio" name="language" value="fil" required><span>Filipino</span></label>
                <button type="submit" class="ks-save" data-ks-text="save">Save</button>
            </fieldset></form>
        </section>
        <section data-settings-panel="email" hidden>
            <form class="ks-form" data-settings-action="send_email"><fieldset>
                <label><span data-ks-text="current_email">Current email</span><input type="email" data-settings-current-email readonly></label>
                <label><span data-ks-text="new_email">New email</span><input type="email" name="new_email" autocomplete="email" maxlength="254" required></label>
                <label><span data-ks-text="confirm_email">Confirm new email</span><input type="email" name="email_confirmation" autocomplete="off" maxlength="254" required></label>
                <p class="ks-note" data-settings-mail-availability></p>
                <button type="submit" class="ks-save" data-settings-send-email data-ks-text="send_code">Send verification code</button>
            </fieldset></form>
            <form class="ks-form ks-verify" data-settings-action="verify_email" hidden><fieldset>
                <p class="ks-note" data-settings-pending-email></p>
                <label><span data-ks-text="verification_code">Verification code</span><input name="verification_code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" required></label>
                <button type="submit" class="ks-save" data-ks-text="verify_email">Verify and update email</button>
            </fieldset></form>
        </section>
        <section data-settings-panel="contacts" hidden>
            <form class="ks-form" data-settings-action="contacts"><fieldset>
                <label><span data-ks-text="phone">Phone number</span><input type="tel" name="phone_number" autocomplete="tel" maxlength="30"></label>
                <label><span data-ks-text="alternate">Alternate contact</span><input type="tel" name="alternate_contact" autocomplete="off" maxlength="30"></label>
                <label><span data-ks-text="address">Address</span><textarea name="address" autocomplete="street-address" maxlength="255" rows="2"></textarea></label>
                <button type="submit" class="ks-save" data-ks-text="save_changes">Save changes</button>
            </fieldset></form>
        </section>
    </dialog>
</div>
