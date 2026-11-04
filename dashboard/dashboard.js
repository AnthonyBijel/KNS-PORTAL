// Load dashboard fragments and initialize their controls after insertion.
let latestPageRequest = 0;
let announcementResizeObserver = null;
let academicResultsCleanup = null;
let requestMessengerCleanup = null;

async function loadPage(page, context = {}) {
    const container = document.getElementById("main-content-contianer");
    if (!container) return;

    const requestId = ++latestPageRequest;
    container.setAttribute("aria-busy", "true");

    try {
        const response = await fetch(page, { cache: "no-cache" });
        if (!response.ok) {
            throw new Error(`Failed to load ${page}: ${response.status}`);
        }
        if (response.redirected) {
            window.location.assign(response.url);
            return;
        }
        const html = await response.text();
        if (requestId !== latestPageRequest) return;

        if (announcementResizeObserver) {
            announcementResizeObserver.disconnect();
            announcementResizeObserver = null;
        }
        if (academicResultsCleanup) {
            academicResultsCleanup();
            academicResultsCleanup = null;
        }
        requestMessengerCleanup?.();
        requestMessengerCleanup = null;
        container.innerHTML = html;
        initAnnouncement(container);
        academicResultsCleanup = initAcademicResults(container);
        initStudentPhoto(container);
        requestMessengerCleanup = window.KNSRequests.init(container, "student");
        if (context.fromNotification) {
            const target = page.includes("overview.php") ? ".overview-button" : ".may-request-button";
            document.querySelectorAll(".overview-button,.results-button,.may-request-button,.student-information-button")
                .forEach(button => button.classList.toggle("selected", button.matches(target)));
        }
        if (context.announcementId) {
            const post = container.querySelector('[data-announcement-id="' + Number(context.announcementId) + '"]');
            if (!post) throw new Error("This announcement is no longer available.");
            const description = post.querySelector(".description-text");
            const expand = post.querySelector(".see-more-btn");
            if (description && expand && !description.classList.contains("expanded")) expand.click();
            post.classList.add("kn-announcement-target");
            post.scrollIntoView({block: "start", behavior: "auto"});
            setTimeout(() => post.classList.remove("kn-announcement-target"), 6500);
        }
        if (context.requestId) {
            const opened = await requestMessengerCleanup?.openConversation(Number(context.requestId));
            if (!opened && requestId === latestPageRequest) throw new Error("Unable to open the request conversation.");
        }
        return true;
    } catch (error) {
        if (requestId !== latestPageRequest) return false;
        console.error("Error loading dashboard page:", error);
        if (academicResultsCleanup) {
            academicResultsCleanup();
            academicResultsCleanup = null;
        }
        requestMessengerCleanup?.();
        requestMessengerCleanup = null;
        const message = document.createElement("p");
        message.setAttribute("role", "alert");
        message.textContent = "Unable to load this page. Please try the menu button again.";
        container.replaceChildren(message);
        return false;
    } finally {
        if (requestId === latestPageRequest) {
            container.setAttribute("aria-busy", "false");
        }
    }
}

function initAnnouncement(container) {
    const root = container.querySelector('.student-announcements');
    if (!root) return;
    const filterBar = root.querySelector('.announcement-type-selection');
    const options = filterBar.querySelectorAll('.announcement-type');
    const posts = [...root.querySelectorAll('.announcement-content > .post-content')];
    const emptyMessage = root.querySelector('.announcement-empty');
    const status = root.querySelector('[data-announcement-status]');
    const search = root.querySelector('[data-announcement-search]');
    const sort = root.querySelector('[data-announcement-sort]');
    const feed = root.querySelector('.announcement-content');
    const normalize = value => value.normalize('NFKC').toLocaleLowerCase().replace(/\s+/g, ' ').trim();
    const records = posts.map((post, index) => ({
        post, index, text: normalize(post.dataset.searchText),
        created: Number(post.dataset.postTimestamp) || 0,
        expires: Date.parse(post.dataset.expiresAt) || Infinity,
    }));
    const serverClock = Number(feed.dataset.serverTime) || Date.now();
    const startedAt = performance.now();
    const serverNow = () => serverClock + performance.now() - startedAt;
    let selectedCategory = 'all';
    let appliedSearch = '';
    const descriptions = posts.map(post => ({
        post, text: post.querySelector('.description-text'), button: post.querySelector('.see-more-btn'),
    }));
    descriptions.forEach(({text, button}) => {
        button.onclick = () => {
            const expanded = text.classList.toggle('expanded');
            button.textContent = expanded ? 'See less' : 'See more';
            button.setAttribute('aria-expanded', String(expanded));
            updateDescriptionButtons();
        };
    });
    function updateDescriptionButtons() {
        descriptions.forEach(({post, text, button}) => {
            if (post.hidden) return;
            button.hidden = !text.classList.contains('expanded') && text.scrollHeight <= text.clientHeight + 1;
        });
    }
    function applyFilter(category) {
        selectedCategory = category;
        const now = serverNow();
        const words = appliedSearch.split(' ').filter(Boolean);
        let visibleCount = 0;
        records.forEach(({post, text, expires}) => {
            const matches = expires > now && (category === 'all' || post.dataset.category === category)
                && words.every(word => text.includes(word));
            post.hidden = !matches;
            if (matches) visibleCount++;
            else post.querySelectorAll('video').forEach(video => video.pause());
        });
        options.forEach(option => {
            const selected = option.dataset.category === category;
            option.classList.toggle('selected', selected);
            option.setAttribute('aria-pressed', String(selected));
        });
        emptyMessage.hidden = visibleCount > 0;
        emptyMessage.textContent = appliedSearch ? 'No matching announcements. Try a different search or category.'
            : (category === 'all' ? 'No announcements yet.' : 'No announcements in this category yet.');
        status.textContent = `${visibleCount} ${visibleCount === 1 ? 'announcement' : 'announcements'}`;
        requestAnimationFrame(updateDescriptionButtons);
    }
    function sortPosts() {
        const ordered = [...records].sort((a, b) =>
            (sort.value === 'oldest' ? a.created - b.created : b.created - a.created) || a.index - b.index);
        const fragment = document.createDocumentFragment();
        ordered.forEach(({post}) => fragment.appendChild(post));
        feed.insertBefore(fragment, emptyMessage);
    }
    options.forEach(option => { option.onclick = () => applyFilter(option.dataset.category); });
    root.querySelector('.sa-search').addEventListener('submit', event => {
        event.preventDefault();
        appliedSearch = normalize(search.value);
        applyFilter(selectedCategory);
    });
    sort.addEventListener('change', () => {
        sortPosts();
        applyFilter(selectedCategory);
    });
    applyFilter('all');
    function scheduleExpiration() {
        const now = serverNow();
        const next = records.reduce((nearest, record) => record.expires > now ? Math.min(nearest, record.expires) : nearest, Infinity);
        if (!Number.isFinite(next)) return;
        setTimeout(() => {
            if (!feed.isConnected) return;
            applyFilter(selectedCategory);
            scheduleExpiration();
        }, Math.min(60000, Math.max(1, next - now + 50)));
    }
    scheduleExpiration();
    if (typeof ResizeObserver !== 'undefined') {
        announcementResizeObserver = new ResizeObserver(updateDescriptionButtons);
        announcementResizeObserver.observe(root);
    }
}

//NAVIGATION PANEL FOR SIDEBAR.
const type = document.querySelectorAll(".overview-button, .results-button, .may-request-button, .student-information-button");
type.forEach(option => {
    option.addEventListener("click", function () {
        type.forEach(item => {
            item.classList.remove("selected");
        });

        this.classList.add("selected");
    });
});

const initialStudentPage = new URLSearchParams(window.location.search).get("view") === "student-information"
    ? "../module/studentInformation.php" : "../module/overview.php";
if (initialStudentPage.includes("studentInformation")) {
    document.querySelector(".student-information-button")?.classList.add("selected");
}
loadPage(initialStudentPage);
window.KNSNotifications.init({
    navigate: async notification => {
        const page = notification.type === "announcement" ? "../module/overview.php" : "../module/request.html";
        const context = notification.type === "announcement"
            ? {announcementId: notification.announcement_id, fromNotification: true}
            : {requestId: notification.request_id, fromNotification: true};
        const opened = await loadPage(page, context);
        if (!opened) throw new Error("Unable to open this notification. Please try again.");
    }
});


// The profile fragment is inserted with innerHTML, so bind its controls after loading it.
function initStudentPhoto(container) {
    const form = container.querySelector("#student-photo-form");
    if (!form) return;
    const input = form.querySelector("[name='profile_photo']");
    const button = form.querySelector("[data-choose-photo]");
    const label = button.querySelector("[data-photo-label]");
    const message = container.querySelector("#student-photo-message");
    const image = button.querySelector("[data-student-photo]");
    const buttonLabel = button.getAttribute("aria-label");
    let uploading = false;

    button.addEventListener("click", () => {
        if (!uploading) input.click();
    });
    input.addEventListener("change", () => {
        if (!uploading && input.files.length) form.requestSubmit();
    });

    form.addEventListener("submit", async event => {
        event.preventDefault();
        if (uploading) return;
        const file = input.files[0];
        if (!file) return;
        if (file.size > 2 * 1024 * 1024) {
            message.textContent = "Choose a photo at most 2 MB in size.";
            message.dataset.state = "error";
            input.value = "";
            return;
        }
        const body = new FormData(form);
        uploading = true;
        button.disabled = true;
        input.disabled = true;
        form.setAttribute("aria-busy", "true");
        button.setAttribute("aria-label", "Uploading your profile photo");
        label.textContent = "Uploading...";
        message.textContent = "Saving your profile photo...";
        message.dataset.state = "pending";
        try {
            const response = await fetch(form.action, {
                method: "POST",
                credentials: "same-origin",
                headers: { Accept: "application/json" },
                body,
            });
            if (response.status === 401) {
                window.location.assign("../login.php");
                return;
            }
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.message || "Unable to save your photo.");
            }
            image.src = "../" + data.photo_path;
            message.textContent = data.message;
            message.dataset.state = "success";
        } catch (error) {
            message.textContent = error.message || "Unable to upload your photo. Please try again.";
            message.dataset.state = "error";
        } finally {
            input.value = ""; // Selecting the same file again must still trigger a change.
            uploading = false;
            button.disabled = false;
            input.disabled = false;
            form.removeAttribute("aria-busy");
            button.setAttribute("aria-label", buttonLabel);
            label.textContent = "Change photo";
        }
    });
}

// Academic data is scoped by the server to the signed-in student.
function initAcademicResults(container) {
    const view = container.querySelector("[data-academic-results]");
    if (!view) return null;
    const yearSelect = view.querySelector("#academic-year");
    const courseSelect = view.querySelector("#academic-course");
    const semesterSelect = view.querySelector("#semester");
    const periodSelect = view.querySelector("#grading-period");
    const summary = view.querySelector("#selection-summary");
    const message = view.querySelector("#academic-results-message");
    const gradesBody = view.querySelector("[data-subject-grades]");
    const assessmentsBody = view.querySelector("[data-assessment-results]");
    const subjectTitle = view.querySelector("#assessment-subject-title");
    const typeButtons = [...view.querySelectorAll("[data-assessment-type]")];
    const controller = new AbortController();
    let grades = [], assessments = [], subjects = [];
    let selectedSubject = "", selectedType = "all";
    let disposed = false, loading = false, loaded = false;

    function yearKey(value) {
        return String(value ?? "").trim().replace(/^(\d{4})\s*[-\u2013\u2014/]\s*(\d{4})$/, "$1-$2");
    }
    function semesterInfo(value) {
        const text = String(value ?? "").trim();
        const key = text.toLowerCase().replace(/[\s._-]/g, "");
        for (const [number, ordinal, word] of [[1, "1st", "first"], [2, "2nd", "second"], [3, "3rd", "third"]]) {
            if ([String(number), ordinal, ordinal + "sem", ordinal + "semester", word, word + "sem", word + "semester"].includes(key)) {
                return { value: String(number), label: ordinal + " Semester", order: number };
            }
        }
        if (["summer", "summerterm", "midyear", "midyearterm"].includes(key)) {
            return { value: "summer", label: "Summer", order: 4 };
        }
        return { value: text.toLowerCase(), label: text, order: 99 };
    }
    function periodInfo(value) {
        const text = String(value ?? "").trim();
        const key = text.toLowerCase().replace(/\s+/g, "");
        if (["prelim", "prelims", "preliminary"].includes(key)) return { value: "prelim", label: "Prelim", order: 1 };
        if (["midterm", "midterms"].includes(key)) return { value: "midterm", label: "Midterm", order: 2 };
        if (["final", "finals"].includes(key)) return { value: "final", label: "Final", order: 3 };
        return { value: text.toLowerCase(), label: text, order: 99 };
    }
    function prepare(row) {
        return { ...row, _year: yearKey(row.academic_year), _semester: semesterInfo(row.semester),
            _code: String(row.subject_code ?? "").trim().toUpperCase() };
    }
    function options(select, values, preferred, emptyLabel, chooseLast = false) {
        select.replaceChildren();
        if (!values.length) {
            select.add(new Option(emptyLabel, ""));
            select.disabled = true;
            return;
        }
        values.forEach(item => select.add(new Option(item.label, item.value)));
        select.disabled = false;
        select.value = values.some(item => item.value === preferred) ? preferred
            : values[chooseLast ? values.length - 1 : 0].value;
    }
    function emptyRow(body, columns, text) {
        const row = document.createElement("tr");
        row.className = "academic-empty-row";
        const cell = document.createElement("td");
        cell.colSpan = columns;
        cell.textContent = text;
        row.appendChild(cell);
        body.replaceChildren(row);
    }
    function cell(text) {
        const td = document.createElement("td");
        td.textContent = text;
        return td;
    }
    function grade(value) {
        return value === null || value === undefined || value === "" ? "-" : Number(value).toFixed(2);
    }
    function score(value) {
        return new Intl.NumberFormat("en-PH", { maximumFractionDigits: 2 }).format(Number(value));
    }
    function postedDate(value) {
        if (!value) return "-";
        const date = new Date(String(value) + "T00:00:00");
        return Number.isNaN(date.getTime()) ? "-" : new Intl.DateTimeFormat("en-PH", {
            month: "short", day: "numeric", year: "numeric"
        }).format(date);
    }
    function inTerm(row) {
        return row._year === yearSelect.value && row._semester.value === semesterSelect.value;
    }
    function subjectAssessments() {
        return assessments.filter(row => inTerm(row) && row._code === selectedSubject);
    }
    function renderAssessments() {
        typeButtons.forEach(button => {
            const selected = button.dataset.assessmentType === selectedType;
            button.classList.toggle("selected", selected);
            button.setAttribute("aria-pressed", String(selected));
            button.disabled = !selectedSubject;
        });
        const rows = subjectAssessments().filter(row =>
            (selectedType === "all" || String(row.assessment_type).toLowerCase() === selectedType) &&
            (periodSelect.value === "all" || periodInfo(row.grading_period).value === periodSelect.value));
        if (!rows.length) {
            emptyRow(assessmentsBody, 4, selectedSubject
                ? "No assessments posted for this subject and these filters."
                : "Select a subject to view its assessments.");
            return;
        }
        const fragment = document.createDocumentFragment();
        rows.forEach(result => {
            const row = document.createElement("tr");
            const name = cell(result.assessment_name);
            const details = [result.grading_period, result.remarks].filter(value => value !== null && value !== "");
            if (details.length) {
                const note = document.createElement("small");
                note.className = "assessment-result-details";
                note.textContent = details.join(" | ");
                name.appendChild(note);
            }
            row.append(name, cell(result.assessment_type), cell(score(result.score) + " / " + score(result.max_score)), cell(postedDate(result.date_posted)));
            fragment.appendChild(row);
        });
        assessmentsBody.replaceChildren(fragment);
    }
    function renderSubjects() {
        const map = new Map();
        grades.filter(inTerm).forEach(row => {
            if (!map.has(row._code)) map.set(row._code, row);
        });
        // An assessment-only upload must remain visible even before grades are posted.
        assessments.filter(inTerm).forEach(row => {
            if (!map.has(row._code)) map.set(row._code, { ...row,
                prelim_grade: null, midterm_grade: null, final_grade: null, overall_grade: null });
        });
        subjects = [...map.values()].sort((a, b) => String(a.subject_name).localeCompare(String(b.subject_name)));
        if (!subjects.some(row => row._code === selectedSubject)) selectedSubject = subjects[0]?._code ?? "";
        const fragment = document.createDocumentFragment();
        subjects.forEach(result => {
            const row = document.createElement("tr");
            row.classList.toggle("selected", result._code === selectedSubject);
            const name = document.createElement("td");
            const button = document.createElement("button");
            button.type = "button";
            button.className = "subject-results-button";
            button.textContent = result.subject_name;
            button.title = result.subject_code;
            button.setAttribute("aria-pressed", String(result._code === selectedSubject));
            button.setAttribute("aria-label", "View assessments for " + result.subject_name);
            name.appendChild(button);
            row.append(name, cell(grade(result.prelim_grade)), cell(grade(result.midterm_grade)),
                cell(grade(result.final_grade)), cell(grade(result.overall_grade)));
            row.addEventListener("click", () => {
                selectedSubject = result._code;
                selectedType = "all";
                periodSelect.value = "all";
                renderSubjects();
            });
            fragment.appendChild(row);
        });
        gradesBody.replaceChildren(fragment);
        if (!subjects.length) emptyRow(gradesBody, 5, "No grades or assessments have been posted for this academic year and semester.");
        const subject = subjects.find(row => row._code === selectedSubject);
        subjectTitle.textContent = subject?.subject_name ?? "Subject Assessments";
        subjectTitle.title = subject?.subject_code ?? "";
        const periods = new Map();
        subjectAssessments().forEach(row => {
            const period = periodInfo(row.grading_period);
            if (period.value) periods.set(period.value, period);
        });
        options(periodSelect, [{ value: "all", label: "All Periods" }, ...[...periods.values()].sort((a, b) => a.order - b.order)],
            periodSelect.value, "All Periods");
        periodSelect.disabled = !periods.size;
        summary.textContent = yearSelect.value && semesterSelect.value
            ? "Selected: " + yearSelect.value + " | " + semesterSelect.selectedOptions[0].textContent
            : "No academic results have been posted yet.";
        renderAssessments();
    }
    function updateSemesters(preferred) {
        const terms = new Map();
        [...grades, ...assessments].filter(row => row._year === yearSelect.value).forEach(row => {
            terms.set(row._semester.value, row._semester);
        });
        options(semesterSelect, [...terms.values()].sort((a, b) => a.order - b.order || a.label.localeCompare(b.label)),
            preferred, "No semesters available", true);
        renderSubjects();
    }
    function resetSubjectFilters() {
        selectedSubject = "";
        selectedType = "all";
        periodSelect.value = "all";
    }
    yearSelect.addEventListener("change", () => { resetSubjectFilters(); updateSemesters(semesterSelect.value); });
    semesterSelect.addEventListener("change", () => { resetSubjectFilters(); renderSubjects(); });
    periodSelect.addEventListener("change", renderAssessments);
    typeButtons.forEach(button => button.addEventListener("click", () => {
        selectedType = button.dataset.assessmentType;
        renderAssessments();
    }));

    async function refreshResults() {
        if (disposed || loading || !view.isConnected) return;
        loading = true;
        view.setAttribute("aria-busy", "true");
        if (!loaded) message.textContent = "Loading academic results...";
        try {
            const response = await fetch(view.dataset.resultsUrl, {
                credentials: "same-origin", cache: "no-store", signal: controller.signal,
                headers: { Accept: "application/json" }
            });
            if (disposed || !view.isConnected) return;
            if (response.status === 401) {
                window.location.assign("../login.php");
                return;
            }
            const data = await response.json();
            if (disposed || !view.isConnected) return;
            if (!response.ok || !data.ok) throw new Error(data.message || "Unable to load your academic results.");
            if (!Array.isArray(data.subject_grades) || !Array.isArray(data.assessments)) throw new Error("Unable to read academic results.");
            const course = String(data.course ?? "").trim();
            options(courseSelect, course ? [{ value: course, label: course }] : [],
                courseSelect.value, "No course available");
            const previousYear = yearSelect.value;
            const previousSemester = semesterSelect.value;
            grades = data.subject_grades.map(prepare);
            assessments = data.assessments.map(prepare);
            const years = [...new Set([...grades, ...assessments].map(row => row._year))]
                .sort((a, b) => b.localeCompare(a, undefined, { numeric: true }));
            options(yearSelect, years.map(value => ({ value, label: value })), previousYear, "No academic years available");
            updateSemesters(previousSemester);
            loaded = true;
            message.textContent = "";
            message.removeAttribute("data-state");
        } catch (error) {
            if (disposed || error.name === "AbortError") return;
            message.textContent = "Unable to load or refresh academic results. Reopen Academic and try again.";
            message.dataset.state = "error";
            if (!loaded) {
                emptyRow(gradesBody, 5, "Academic results could not be loaded.");
                emptyRow(assessmentsBody, 4, "Assessment results could not be loaded.");
            }
        } finally {
            loading = false;
            if (!disposed) view.removeAttribute("aria-busy");
        }
    }
    const onVisible = () => { if (!document.hidden) refreshResults(); };
    window.addEventListener("focus", refreshResults);
    document.addEventListener("visibilitychange", onVisible);
    refreshResults();
    return () => {
        disposed = true;
        controller.abort();
        window.removeEventListener("focus", refreshResults);
        document.removeEventListener("visibilitychange", onVisible);
    };
}
