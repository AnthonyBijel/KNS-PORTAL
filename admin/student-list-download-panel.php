<?php
require_once __DIR__ . '/../php/auth/staff-access.php';
if (!isset($studentListStaff)) {
    require __DIR__ . '/../db.php';
    $studentListStaff = knsRequireStaffAccess($pdo, 'results');
}
?>
<div class="kns-student-download" data-student-download data-endpoint="../php/admin/student-list-export.php"
     data-csrf="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
    <button type="button" class="kns-list-launch" data-list-open aria-haspopup="dialog" aria-controls="kns-student-download">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m-4-4 4 4 4-4M4 16v4h16v-4"/></svg>Download List
    </button>
    <dialog id="kns-student-download" class="kns-list-modal" aria-labelledby="kns-list-title" aria-describedby="kns-list-intro">
        <button type="button" class="kns-list-close" data-list-close aria-label="Close Download List"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 5 14 14M19 5 5 19"/></svg></button>
        <span class="kns-list-eyebrow">EXCEL EXPORT</span>
        <h2 id="kns-list-title">Download Student List</h2>
        <p id="kns-list-intro" class="kns-list-intro">Choose a group, preview its students, then download the Excel list.</p>
        <form data-list-filters>
            <fieldset class="kns-list-filters">
                <?php foreach (['academic_year' => 'Academic Year', 'course' => 'Course', 'year_level' => 'Year Level', 'section' => 'Section'] as $name => $label): ?>
                <div class="kns-list-field"><label for="kns-list-<?= $name ?>"><?= $label ?></label><select id="kns-list-<?= $name ?>" name="<?= $name ?>" required disabled><option value="">Select <?= strtolower($label) ?></option></select></div>
                <?php endforeach; ?>
            </fieldset>
            <div class="kns-list-filter-actions"><button type="button" class="kns-list-reset" data-list-reset>Reset Filters</button><button type="submit" class="kns-list-primary" data-list-preview disabled>Preview List</button></div>
        </form>
        <p class="kns-list-message" data-list-message role="status" aria-live="polite">Select all four filters to preview a student list.</p>
        <section class="kns-list-preview" data-list-preview-section hidden aria-labelledby="kns-list-preview-title">
            <div class="kns-list-preview-heading"><h3 id="kns-list-preview-title">Student Preview</h3><span class="kns-list-count" data-list-count></span></div>
            <div class="kns-list-table-scroll" tabindex="0" aria-label="Student preview table">
                <table class="kns-list-table"><thead><tr><th scope="col">Student Number</th><th scope="col">Full Name</th><th scope="col">Course</th><th scope="col">Section</th><th scope="col">Year Level</th><th scope="col">Academic Year</th></tr></thead><tbody data-list-rows></tbody></table>
            </div>
            <div class="kns-list-pagination"><span data-list-page-label></span><button type="button" data-list-page="previous" aria-label="Previous preview page">Previous</button><button type="button" data-list-page="next" aria-label="Next preview page">Next</button></div>
        </section>
        <div class="kns-list-footer"><p>The Excel file includes all students in the preview.</p><button type="button" class="kns-list-secondary" data-list-close>Cancel</button><button type="button" class="kns-list-primary" data-list-download disabled>Download Excel</button></div>
    </dialog>
</div>
