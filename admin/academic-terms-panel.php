<?php
require_once __DIR__ . '/../php/auth/staff-access.php';
if (!isset($studentListStaff)) {
    require __DIR__ . '/../db.php';
    $studentListStaff = knsRequireStaffAccess($pdo, 'results');
}
$termCalendarIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18m-11 5 2 2 4-4"/></svg>';
?>
<div class="kns-terms" data-kns-terms data-endpoint="../php/admin/academic-terms.php"
     data-csrf="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>"
     data-can-schedule="<?= $studentListStaff['role'] === 'Administrator' ? 'true' : 'false' ?>">
    <button type="button" class="kns-terms-launch" data-terms-open aria-haspopup="dialog" aria-controls="kns-term-manager">
        <?= $termCalendarIcon ?><span>Manage Terms</span>
    </button>
    <div class="kns-terms-banner-summary" aria-live="polite">
        <div class="kns-terms-eyebrow">CURRENT TERM <span class="kns-terms-badge" data-terms-banner-status hidden></span></div>
        <div class="kns-terms-banner-name" data-terms-banner-name>Loading current term...</div>
        <div class="kns-terms-banner-end" data-terms-banner-end></div>
    </div>
    <dialog class="kns-terms-modal" id="kns-term-manager" aria-labelledby="kns-term-title" aria-describedby="kns-term-intro">
        <button type="button" class="kns-terms-close" data-terms-close aria-label="Close academic term management"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 5 14 14M19 5 5 19"/></svg></button>
        <h2 class="kns-terms-title" id="kns-term-title">Manage Academic Terms</h2>
        <p class="kns-terms-intro" id="kns-term-intro">View the current academic term and schedule the next term.</p>
        <div class="kns-terms-message" data-terms-message role="status" hidden></div>
        <div data-terms-schedule-view>
            <div class="kns-terms-current">
                <button type="button" class="kns-terms-edit" data-terms-edit hidden aria-label="Edit active academic term" title="Edit active academic term"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m16 3 5 5M4 20l5-1L21 7a2.1 2.1 0 0 0-5-5L4 14z"/></svg></button>
                <div class="kns-terms-calendar-icon"><?= $termCalendarIcon ?></div>
                <div class="kns-terms-current-copy">
                    <div class="kns-terms-current-heading">CURRENT TERM <span class="kns-terms-badge" data-terms-current-status hidden></span></div>
                    <div class="kns-terms-current-name" data-terms-current-name>Loading current term...</div>
                    <div class="kns-terms-current-end" data-terms-current-end></div>
                </div>
            </div>
            <form class="kns-terms-form" data-terms-form>
                <div class="kns-terms-form-heading"><h3>Schedule Next Academic Term</h3><span class="kns-terms-planned" data-terms-planned><?= $termCalendarIcon ?>Scheduled</span></div>
                <fieldset class="kns-terms-fields"<?= $studentListStaff['role'] !== 'Administrator' ? ' disabled' : '' ?>>
                    <div class="kns-terms-field"><label for="kns-term-year">Academic Year</label><input type="text" id="kns-term-year" name="academic_year" list="kns-term-years" placeholder="YYYY-YYYY" maxlength="20" required autocomplete="off"><datalist id="kns-term-years" data-terms-years></datalist></div>
                    <div class="kns-terms-field"><label for="kns-term-semester">Semester</label><select id="kns-term-semester" name="semester" required><option value="">Select semester</option><option>1st Semester</option><option>2nd Semester</option><option>Summer</option></select></div>
                    <div class="kns-terms-field"><label for="kns-term-start">Start Date</label><input type="date" id="kns-term-start" name="start_date" required></div>
                    <div class="kns-terms-field"><label for="kns-term-end">Official End Date</label><input type="date" id="kns-term-end" name="official_end_date" required></div>
                </fieldset>
                <?php if ($studentListStaff['role'] !== 'Administrator'): ?><p class="kns-terms-permission">Only the Administrator can schedule terms. You can view term history.</p><?php endif; ?>
                <div class="kns-terms-note"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v1"/></svg><span>The current term remains active through its official end date. The next term starts on its scheduled start date. Completed terms stay in history.</span></div>
                <div class="kns-terms-actions">
                    <button type="button" class="kns-terms-history-link" data-terms-history><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5v5h5M3 10a9 9 0 1 1 2 8M12 7v5l3 2"/></svg>View Term History</button>
                    <button type="button" class="kns-terms-cancel" data-terms-close>Cancel</button>
                    <button type="submit" class="kns-terms-submit"<?= $studentListStaff['role'] !== 'Administrator' ? ' disabled' : '' ?>>Schedule Term</button>
                </div>
            </form>
        </div>
        <section data-terms-confirm-view hidden aria-labelledby="kns-term-confirm-title">
            <h3 id="kns-term-confirm-title" class="kns-terms-confirm-title">Confirm Academic Term Changes</h3>
            <p class="kns-terms-confirm-intro">Review the changes below. Changes to term dates may affect student access.</p>
            <div class="kns-terms-table-wrap"><table class="kns-terms-table"><thead><tr><th scope="col">Detail</th><th scope="col">Current</th><th scope="col">New</th></tr></thead><tbody data-terms-review></tbody></table></div>
            <p class="kns-terms-permission" data-terms-resulting-status></p>
            <form data-terms-confirm-form>
                <fieldset class="kns-terms-password-fields">
                    <div class="kns-terms-field"><label for="kns-term-password">Your Admin / Registrar Password</label><input type="password" id="kns-term-password" name="confirmation_password" autocomplete="current-password" maxlength="4096" required aria-describedby="kns-term-password-note"></div>
                    <p class="kns-terms-permission" id="kns-term-password-note">Enter the current password for your signed-in Admin or Registrar account to apply these changes.</p>
                </fieldset>
                <div class="kns-terms-actions"><button type="button" class="kns-terms-cancel" data-terms-confirm-back>Back to Edit</button><button type="submit" class="kns-terms-submit">Confirm &amp; Update</button></div>
            </form>
        </section>
        <section class="kns-terms-history" data-terms-history-view hidden aria-label="Academic term history">
            <div class="kns-terms-history-heading"><h3>Term History</h3><button type="button" class="kns-terms-history-link" data-terms-back>Back to Manage Terms</button></div>
            <div class="kns-terms-table-wrap"><table class="kns-terms-table"><thead><tr><th>Academic Year</th><th>Semester</th><th>Start Date</th><th>Official End Date</th><th>Status</th><th>Records</th></tr></thead><tbody data-terms-history-rows><tr><td colspan="6">Loading terms...</td></tr></tbody></table></div>
            <section class="kns-terms-records" data-terms-records hidden aria-label="Saved academic records">
                <h3 class="kns-terms-records-title" data-terms-record-title></h3>
                <div class="kns-terms-record-tabs" role="group" aria-label="Record type"><button type="button" data-terms-kind="enrollments" aria-pressed="true">Enrollments</button><button type="button" data-terms-kind="grades" aria-pressed="false">Grades</button><button type="button" data-terms-kind="assessments" aria-pressed="false">Assessments</button></div>
                <div class="kns-terms-table-wrap"><table class="kns-terms-table"><thead data-terms-record-head></thead><tbody data-terms-record-rows></tbody></table></div>
                <div class="kns-terms-pagination"><button type="button" data-terms-page="previous">Previous</button><span data-terms-page-label></span><button type="button" data-terms-page="next">Next</button></div>
            </section>
            <div class="kns-terms-history-footer"><button type="button" class="kns-terms-cancel" data-terms-close>Close</button></div>
        </section>
    </dialog>
</div>
