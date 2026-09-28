//THIS IS FOR LOADING PAGES INSIDE OF DASHBOARD MAIN CONTIANER.
function loadPage(page) {
    return fetch(page)
        .then(response => {
            if (!response.ok) {
                throw new Error(`Failed to load ${page}: ${response.status}`);
            }

            return response.text();
        })
        .then(html => {
            document.getElementById("main-content-contianer").innerHTML = html;
            initializeacademicsemyear();
            initResults();
            initassessment();
    

            if (page.includes("overview.html")) {
                initAnnouncement();
            }
        })
        .catch(error => {
            console.error("Error:", error);
        });
}
//ANNOUNCEMENT TYPE .
function initAnnouncement() {
    const options = document.querySelectorAll(".announcement-type");
    options.forEach(option => {
        option.addEventListener("click", function () {
            options.forEach(item => {
                item.classList.remove("selected");
            });

            this.classList.add("selected");
        });
    });


document.querySelectorAll(".see-more-btn").forEach(button => {
        button.addEventListener("click", function () {
            const text = this.parentElement.querySelector(".description-text");

            text.classList.toggle("expanded");

            if (text.classList.contains("expanded")) {
                this.textContent = "See less";
            } else {
                this.textContent = "See more";
            }
        });
    });
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

loadPage("../module/overview.html");


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


