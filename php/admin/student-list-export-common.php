<?php
require_once __DIR__ . '/../academic-term-common.php';

final class KnsStudentListExportError extends RuntimeException
{
    public function __construct(string $message, public int $status = 422) { parent::__construct($message); }
}

final class KnsStudentListExport
{
    public function __construct(private PDO $pdo) {}

    public function cohorts(): array
    {
        $rows = $this->pdo->query('SELECT DISTINCT ' . knsTermYearSql('s') . " AS academic_year,
            TRIM(s.course) AS course, s.year_level, TRIM(s.section) AS section FROM students s
            WHERE s.archived_at IS NULL AND TRIM(s.course) <> '' AND TRIM(s.section) <> ''
            AND s.year_level > 0 AND TRIM(s.academic_year) <> ''
            ORDER BY academic_year DESC, course, year_level, section")->fetchAll(PDO::FETCH_ASSOC);
        $cohorts = [];
        foreach ($rows as $row) {
            try { $row['academic_year'] = knsAcademicYear($row['academic_year']); }
            catch (KnsAcademicTermError $error) { continue; }
            $row['year_level'] = (string) $row['year_level'];
            $cohorts[] = $row;
        }
        return $cohorts;
    }

    public function filters(array $input): array
    {
        $filters = [];
        foreach (['academic_year' => 20, 'course' => 150, 'year_level' => 3, 'section' => 50] as $key => $limit) {
            $value = $input[$key] ?? null;
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $limit || trim($value) === '') {
                throw new KnsStudentListExportError('Select an academic year, course, year level, and section before previewing.');
            }
            $filters[$key] = trim($value);
        }
        try { $filters['academic_year'] = knsAcademicYear($filters['academic_year']); }
        catch (KnsAcademicTermError $error) { throw new KnsStudentListExportError($error->getMessage()); }
        if (filter_var($filters['year_level'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 255]]) === false) {
            throw new KnsStudentListExportError('Select a valid year level.');
        }
        return $filters;
    }

    public function students(array $filters): array
    {
        $statement = $this->pdo->prepare("SELECT s.student_number,
            CONCAT_WS(' ', NULLIF(TRIM(s.first_name), ''), NULLIF(TRIM(s.middle_name), ''),
                NULLIF(TRIM(s.last_name), ''), NULLIF(TRIM(s.suffix), '')) AS full_name,
            TRIM(s.course) AS course, TRIM(s.section) AS section, s.year_level, "
            . knsTermYearSql('s') . ' AS academic_year FROM students s
            WHERE s.archived_at IS NULL AND ' . knsTermYearSql('s') . ' = ?
            AND TRIM(s.course) = ? AND s.year_level = ? AND TRIM(s.section) = ?
            ORDER BY s.last_name, s.first_name, s.middle_name, s.student_number, s.id');
        $statement->execute([$filters['academic_year'], $filters['course'], $filters['year_level'], $filters['section']]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}

function knsStudentListSignature(array $filters, array $students, string $csrf): string
{
    return hash_hmac('sha256', json_encode([$filters, $students], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $csrf);
}

function knsCreateStudentListTemplate(): \PhpOffice\PhpSpreadsheet\Spreadsheet
{
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $book->getProperties()->setCreator('KNS Portal')->setTitle('Student List')->setSubject('Filtered student roster');
    $sheet = $book->getActiveSheet();
    $sheet->setTitle('Student List');
    $sheet->mergeCells('A1:F1')->setCellValue('A1', 'KNS PORTAL | STUDENT LIST');
    $sheet->mergeCells('B3:F3')->setCellValue('A3', 'Academic Year');
    $sheet->mergeCells('B4:F4')->setCellValue('A4', 'Course');
    $sheet->setCellValue('A5', 'Year Level')->mergeCells('B5:C5');
    $sheet->setCellValue('D5', 'Section')->mergeCells('E5:F5');
    $sheet->setCellValue('A6', 'Total Students')->mergeCells('B6:C6');
    $sheet->setCellValue('D6', 'Generated (Manila)')->mergeCells('E6:F6');
    $sheet->fromArray([['Student Number', 'Full Name', 'Course', 'Section', 'Year Level', 'Academic Year']], null, 'A8');
    $sheet->getDefaultRowDimension()->setRowHeight(22);
    $sheet->getRowDimension(1)->setRowHeight(36);
    $sheet->getRowDimension(8)->setRowHeight(28);
    $sheet->getStyle('A1:F9')->getFont()->setName('Calibri')->setSize(11);
    $sheet->getStyle('A1:F1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '075B36']],
        'alignment' => ['vertical' => 'center'],
    ]);
    $sheet->getStyle('A8:F8')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '168250']],
        'alignment' => ['vertical' => 'center', 'wrapText' => true],
    ]);
    foreach (['A3:A6', 'D5:D6'] as $range) $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('075B36');
    foreach (['A' => 23, 'B' => 36, 'C' => 48, 'D' => 24, 'E' => 19, 'F' => 24] as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }
    $sheet->getStyle('A9:D9')->getNumberFormat()->setFormatCode('@');
    $sheet->freezePane('C9');
    $sheet->setAutoFilter('A8:F9');
    $sheet->setShowGridlines(false);
    $sheet->getPageSetup()->setOrientation('landscape')->setPaperSize(9)->setFitToWidth(1)->setFitToHeight(0)
        ->setRowsToRepeatAtTopByStartAndEnd(8, 8);
    $sheet->getHeaderFooter()->setOddFooter('&L KNS Portal &R Page &P of &N');
    return $book;
}

function knsStudentListWorkbook(array $students, array $filters): \PhpOffice\PhpSpreadsheet\Spreadsheet
{
    $book = \PhpOffice\PhpSpreadsheet\IOFactory::load(__DIR__ . '/../../downloads/KNS_Student_List_Template.xlsx');
    $sheet = $book->getActiveSheet();
    $string = \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;
    foreach (['B3' => $filters['academic_year'], 'B4' => $filters['course'], 'E5' => $filters['section'],
        'E6' => (new DateTimeImmutable('now', knsAcademicTermZone()))->format('Y-m-d H:i')] as $cell => $value) {
        $sheet->setCellValueExplicit($cell, $value, $string);
    }
    $sheet->setCellValue('B5', (int) $filters['year_level']);
    $sheet->setCellValue('B6', count($students));
    foreach ($students as $index => $student) {
        $row = $index + 9;
        foreach (['A' => 'student_number', 'B' => 'full_name', 'C' => 'course', 'D' => 'section', 'F' => 'academic_year'] as $column => $key) {
            // Keep leading zeroes, long student numbers, and formula-like text as literal text.
            $sheet->setCellValueExplicit($column . $row, (string) $student[$key], $string);
        }
        $sheet->setCellValue('E' . $row, (int) $student['year_level']);
        $sheet->getRowDimension($row)->setRowHeight(-1);
    }
    $last = max(9, count($students) + 8);
    $sheet->getStyle('A9:F' . $last)->applyFromArray([
        'font' => ['name' => 'Calibri', 'size' => 11],
        'alignment' => ['vertical' => 'center', 'wrapText' => true],
        'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'D8E6DC']]],
    ]);
    $sheet->getStyle('A9:A' . $last)->getNumberFormat()->setFormatCode('@');
    $sheet->getStyle('E9:F' . $last)->getAlignment()->setHorizontal('center');
    $sheet->setAutoFilter('A8:F' . $last);
    $sheet->getPageSetup()->setPrintArea('A1:F' . $last);
    return $book;
}
