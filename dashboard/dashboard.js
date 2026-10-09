// Load dashboard fragments and initialize their controls after insertion.
let latestPageRequest = 0;
let announcementResizeObserver = null;

async function loadPage(page) {
    const container = document.getElementById("main-content-contianer");
    if (!container) return;

    const requestId = ++latestPageRequest;
    container.setAttribute("aria-busy", "true");

    try {
        const response = await fetch(page, { cache: "no-cache" });
        if (!response.ok) {
            throw new Error(`Failed to load ${page}: ${response.status}`);
        }
        const html = await response.text();
        if (requestId !== latestPageRequest) return;

        if (announcementResizeObserver) {
            announcementResizeObserver.disconnect();
            announcementResizeObserver = null;
        }
        container.innerHTML = html;
        initAnnouncement(container);
        initializeacademicsemyear();
        initResults();
        initassessment();
    } catch (error) {
        if (requestId !== latestPageRequest) return;
        console.error("Error loading dashboard page:", error);
        const message = document.createElement("p");
        message.setAttribute("role", "alert");
        message.textContent = "Unable to load this page. Please try the menu button again.";
        container.replaceChildren(message);
    } finally {
        if (requestId === latestPageRequest) {
            container.setAttribute("aria-busy", "false");
        }
    }
}

function initAnnouncement(container) {
    const filterBar = container.querySelector(".announcement-type-selection");
    if (!filterBar) return;

    const options = filterBar.querySelectorAll(".announcement-type");
    const posts = container.querySelectorAll(".announcement-content > .post-content");
    const emptyMessage = container.querySelector(".announcement-empty");
    const descriptions = [];

    posts.forEach(post => {
        const text = post.querySelector(".description-text");
        const button = post.querySelector(".see-more-btn");
        if (!text || !button) return;
        descriptions.push({ post, text, button });

        button.onclick = () => {
            const expanded = text.classList.toggle("expanded");
            button.textContent = expanded ? "See less" : "See more";
            button.setAttribute("aria-expanded", String(expanded));
            updateDescriptionButtons();
        };
    });

    function updateDescriptionButtons() {
        descriptions.forEach(({ post, text, button }) => {
            if (post.hidden) return;
            button.hidden = !text.classList.contains("expanded") &&
                text.scrollHeight <= text.clientHeight + 1;
        });
    }

    function applyFilter(category) {
        let visibleCount = 0;
        posts.forEach(post => {
            const matches = category === "all" || post.dataset.category === category;
            post.hidden = !matches;
            if (matches) visibleCount++;
        });
        options.forEach(option => {
            const selected = option.dataset.category === category;
            option.classList.toggle("selected", selected);
            option.setAttribute("aria-pressed", String(selected));
        });
        if (emptyMessage) {
            emptyMessage.hidden = visibleCount > 0;
            emptyMessage.textContent = category === "all"
                ? "No announcements yet."
                : "No announcements in this category yet.";
        }
        updateDescriptionButtons();
    }

    options.forEach(option => {
        option.onclick = () => applyFilter(option.dataset.category);
    });
    applyFilter("all");

    if (typeof ResizeObserver !== "undefined") {
        announcementResizeObserver = new ResizeObserver(updateDescriptionButtons);
        announcementResizeObserver.observe(container);
    }
}

//FOR ASSESSMENT.
function initassessment() {
    const activitys = document.querySelectorAll(".assessment button");
    activitys.forEach(activity => {
        activity.onclick= function () {
            activitys.forEach(item => {
                item.classList.remove("selected");
            });

            this.classList.add("selected");
         }
        });
    }
function initResults() {
    const rows = document.querySelectorAll(".grades-table tbody tr");
    rows.forEach(row => {
        row.onclick= function () {
            rows.forEach(item => {
                item.classList.remove("selected");
            });

            this.classList.add("selected");
             }
        });
    }


    //FOR SEE MORE AND SEE LESS DESCRIPTION FEATURE.
    // These buttons exist only after the overview HTML has been inserted.


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

loadPage("../module/overview.php");


//FOR RESULTS YEAR AND SEMESTER.
function initializeacademicsemyear() {
  const yearSelect = document.getElementById("academic-year");
  const semesterSelect = document.getElementById("semester");
  const summary = document.getElementById("selection-summary");

  if (!yearSelect || !semesterSelect) return;

  // Prevent duplicate setup.
  if (yearSelect.dataset.initialized === "true") return;

  const today = new Date();
  const startMonth = 8; // August; adjust to your school calendar.

  const startYear =
    today.getMonth() + 1 >= startMonth
      ? today.getFullYear()
      : today.getFullYear() - 1;

  yearSelect.replaceChildren();

  for (let i = 0; i < 5; i++) {
    const year = startYear - i;
    const option = document.createElement("option");

    option.value = year + "-" + (year + 1);
    option.textContent = year + " – " + (year + 1);

    yearSelect.appendChild(option);
  }

  function updateSelection() {
    if (!summary) return;

    summary.textContent =
      "Selected: " +
      yearSelect.selectedOptions[0].textContent +
      " • " +
      semesterSelect.selectedOptions[0].textContent;
  }

  yearSelect.addEventListener("change", updateSelection);
  semesterSelect.addEventListener("change", updateSelection);

  yearSelect.dataset.initialized = "true";
  updateSelection();
}


