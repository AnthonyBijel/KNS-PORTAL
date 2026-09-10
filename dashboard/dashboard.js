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

            if (page.includes("overview.html")) {
                initAnnouncement();
            }
        })
        .catch(error => {
            console.error("Error:", error);
        });
}

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

    // These buttons exist only after the overview HTML has been inserted.
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

const type = document.querySelectorAll(".overview-button, .results-button, .may-request-button, .student-information-button, .announcement-button");
type.forEach(option => {
    option.addEventListener("click", function () {
        type.forEach(item => {
            item.classList.remove("selected");
        });

        this.classList.add("selected");
    });
});

loadPage("../module/overview.html");
