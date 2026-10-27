<header class="student-list-header">
    <div class="student-list-header-content">
        <span class="student-list-header-label">STUDENT MANAGEMENT</span>

        <h1>Students List</h1>

        <p>
            Manage student accounts, information, and grades.
            Use filters or search to quickly find a student.
        </p>
    </div>
</header>

<div class="student-list-upload">

</div>

<section class="student-list-upload">

    <!-- Upload Student List -->
    <div class="upload-card student-card">
        <div class="excel-icon">
            <span>X</span>
        </div>

        <div class="upload-info">
            <h3>Upload Student List (Excel)</h3>
            <p>Register student accounts using an Excel file.</p>

            <a href="#" class="template-link">
                <span>↓</span> Download Excel Template
            </a>
        </div>

        <form
            action="../php/admin/upload_students_zip.php"
            method="POST"
            enctype="multipart/form-data"
            class="student-upload-form">
            <input
                type="file"
                name="student_zip"
                accept=".zip"
                required>

            <button
                type="submit"
                class="upload-btn green-btn">
                <span>↑</span>
                Upload Students
            </button>
        </form>
    </div>


    <!-- Upload Grades -->
    <div class="upload-card grades-card">
        <div class="excel-icon blue-excel">
            <span>X</span>
        </div>

        <div class="upload-info">
            <h3>Upload Grades (Excel)</h3>
            <p>Upload student scores/grades for selected subject,<br>
                year and semester.</p>

            <a href="#" class="template-link">
                <span>↓</span> Download Grade Template
            </a>
        </div>

        <button class="upload-btn blue-btn">
            <span>↥</span>
            Upload Grades
        </button>
    </div>

</section>

<section class="student-list-filters">

    <div class="filter-group">
        <label for="academic-year">Academic Year</label>
        <select id="academic-year">
            <option>2026 - 2027</option>
            <option>2025 - 2026</option>
            <option>2024 - 2025</option>
        </select>
    </div>

    <div class="filter-group">
        <label for="semester">Semester</label>
        <select id="semester">
            <option>1st Semester</option>
            <option>2nd Semester</option>
        </select>
    </div>

    <div class="filter-group">
        <label for="course">Course</label>
        <select id="course">
            <option>All Courses</option>
            <option>BS Business Administration</option>
            <option>BS Computer Science</option>
            <option>BS Accountancy</option>
            <option>BS Elementary Education</option>
        </select>
    </div>

    <div class="filter-group">
        <label for="year-level">Year Level</label>
        <select id="year-level">
            <option>All Year Levels</option>
            <option>1st Year</option>
            <option>2nd Year</option>
            <option>3rd Year</option>
            <option>4th Year</option>
        </select>
    </div>

    <div class="filter-group">
        <label for="section">Set</label>
        <select id="section">
            <option>All Sets</option>
            <option>Set A</option>
            <option>Set B</option>
            <option>Set C</option>
            <option>Set D</option>
            <option>Set E</option>
            <option>Set F</option>
            <option>Set G</option>
            <option>Set H</option>
            <option>Set I</option>
            <option>Set J</option>
            <option>Set K</option>
            <option>Set L</option>
            <option>Set M</option>
            <option>Set N</option>
        </select>
    </div>

    <button type="button" class="filter-reset">
        ⭯ <span>Reset</span>
    </button>

    <button type="button" class="filter-apply">
        🝖 <span>Apply Filter</span>
    </button>

</section>

<div class="results-main-table">

    <div class="results-main-table-header">
        <img src="..//asset/logo/people.png" alt="">
        <h1>Students List</h1>

        <div class="studentlist-total">
            <h1>Total Student:</h1>
            <p>214</p>
        </div>
        <div class="searchbox">
            <img src="..//asset/logo/image.png" alt="">
            <input class="searchbar" type="text" placeholder="Search by name, student, or section...">
        </div>

    </div>

    <table class="results-table">
        <thead>
            <tr>
                <th> <input type="checkbox"></th>
                <th>#</th>
                <th>Student Number</th>
                <th>Student Name</th>
                <th>course</th>
                <th>Year Level</th>
                <th>Section</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><input type="checkbox"></td>
                <td>1</td>
                <td>2024-2025</td>
                <td>Abca,john B.</td>
                <td>BSCS</td>
                <td>1st year</td>
                <td>BSCS H</td>
                <td>
                    <div class="student-active-list">
                        <span class="status-dot-table"></span>
                        <span>Active</span>
                    </div>
                </td>
            </tr>

            <tr>
                <td><input type="checkbox"></td>
                <td>1</td>
                <td>2024-2025</td>
                <td>Abca,john B.</td>
                <td>BSCS</td>
                <td>1st year</td>
                <td>BSCS H</td>
                <td>
                    <div class="student-active-list">
                        <span class="status-dot-table"></span>
                        <span>Active</span>
                    </div>
                </td>
            </tr>

            <tr>
                <td><input type="checkbox"></td>
                <td>1</td>
                <td>2024-2025</td>
                <td>Abca,john B.</td>
                <td>BSCS</td>
                <td>1st year</td>
                <td>BSCS H</td>
                <td>
                    <div class="student-active-list">
                        <span class="status-dot-table"></span>
                        <span>Active</span>
                    </div>
                </td>
            </tr>

            <tr>
                <td><input type="checkbox"></td>
                <td>1</td>
                <td>2024-2025</td>
                <td>Abca,john B.</td>
                <td>BSCS</td>
                <td>1st year</td>
                <td>BSCS H</td>
                <td class="status-cell">
                    <div class="student-active-list">
                        <span class="status-dot-table"></span>
                        <span>Active</span>
                    </div>
                </td>
            </tr>

            <tr>
                <td><input type="checkbox"></td>
                <td>1</td>
                <td>2024-2025</td>
                <td>Abca,john B.</td>
                <td>BSCS</td>
                <td>1st year</td>
                <td>BSCS H</td>
                <td>
                    <div class="student-active-list">
                        <span class="status-dot-table"></span>
                        <span>Active</span>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>

    <!-- BOTTOM CONTROLS -->
    <div class="student-table-footer">

        <!-- Show entries -->
        <div class="entries-control">

            <span>Show</span>

            <select>
                <option>10</option>
                <option>25</option>
                <option>50</option>
                <option>100</option>
            </select>

            <span>entries</span>

        </div>


        <!-- Pagination -->
        <div class="pagination">

            <button class="page-arrow">‹</button>

            <button class="current-page">1</button>

            <button>2</button>
            <button>3</button>
            <button>4</button>
            <button>5</button>

            <button class="page-arrow">›</button>

        </div>

    </div>