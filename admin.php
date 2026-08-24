<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/learners.php';
require_once __DIR__ . '/app/reports.php';
require_once __DIR__ . '/app/sections.php';
require_once __DIR__ . '/app/teachers.php';
require_once __DIR__ . '/app/announcements.php';
require_once __DIR__ . '/app/issues.php';
require_once __DIR__ . '/app/theme_settings.php';
require_once __DIR__ . '/app/health.php';
require_once __DIR__ . '/app/schedules.php';
require_once __DIR__ . '/password_resets.php';

function format_report_time(?string $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? (string) $value : date('h:i A', $timestamp);
}

function format_report_date(string $value, string $format = 'M j, Y'): string
{
    $timestamp = strtotime($value);

    return $timestamp === false ? $value : date($format, $timestamp);
}

function report_filter_summary_items(array $filters, array $sections, array $learners): array
{
    $items = [];

    if ($filters['report_type'] === '') {
        return $items;
    }

    $reportTypeLabels = attendance_report_type_options();
    $items['Report Type'] = $reportTypeLabels[$filters['report_type']] ?? 'Attendance Report';

    if (in_array($filters['report_type'], ['daily_attendance', 'section_attendance'], true) && $filters['report_date'] !== '') {
        $items['Report Date'] = format_report_date($filters['report_date']);
    }

    if ($filters['report_type'] === 'monthly_summary' && $filters['report_month'] !== '') {
        $monthStamp = strtotime($filters['report_month'] . '-01');
        $items['Month'] = $monthStamp === false ? $filters['report_month'] : date('F Y', $monthStamp);
    }

    if ($filters['report_type'] === 'learner_history' && $filters['report_month'] !== '') {
        $monthStamp = strtotime($filters['report_month'] . '-01');
        $items['Month'] = $monthStamp === false ? $filters['report_month'] : date('F Y', $monthStamp);
    }

    if (in_array($filters['report_type'], ['late_absence', 'attendance_logs'], true)) {
        $items['Date Range'] = format_report_date($filters['date_from']) . ' to ' . format_report_date($filters['date_to']);
    }

    if ($filters['section_id'] !== '') {
        foreach ($sections as $section) {
            if ((string) $section['id'] === (string) $filters['section_id']) {
                $items['Section'] = ($section['grade_level'] ?? 'N/A') . ' - ' . ($section['name'] ?? 'Unknown');
                break;
            }
        }
    }

    if ($filters['learner_id'] !== '') {
        foreach ($learners as $learner) {
            if ((string) $learner['id'] === (string) $filters['learner_id']) {
                $items['Learner'] = trim($learner['last_name'] . ', ' . $learner['first_name'] . ' ' . $learner['middle_name']) . ' [' . $learner['lrn'] . ']';
                break;
            }
        }
    }

    return $items;
}

function admin_percent(int $value, int $total): int
{
    if ($total <= 0) {
        return 0;
    }

    return max(0, min(100, (int) round(($value / $total) * 100)));
}

function admin_chart_color(?string $value, string $fallback = '#b45309'): string
{
    $value = trim((string) $value);

    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : $fallback;
}

function admin_has_learner_filters(array $filters): bool
{
    foreach (['keyword', 'status', 'grade_level', 'section_id', 'grade_section'] as $key) {
        if (trim((string) ($filters[$key] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

$user = require_roles(['admin']);

$allowedModules = [
    'dashboard' => 'Dashboard',
    'learner_management' => 'Learner Management',
    'learner_accounts' => 'Learner Accounts',
    'class_schedule' => 'Class Schedule',
    'sections_management' => 'Sections Management',
    'teacher_management' => 'Teacher Management',
    'attendance_reports' => 'Attendance Reports',
    'announcements' => 'Announcements',
    'reported_issues' => 'Reported Issues',
    'password_resets' => 'Password Resets',
    'settings' => 'Settings',
];

$module = (string) ($_GET['module'] ?? 'dashboard');
if ($module === 'attendance_module') {
    $module = 'dashboard';
}

if (!array_key_exists($module, $allowedModules)) {
    $module = 'dashboard';
}

$stats = [
    'today_logs' => 0,
    'today_learners' => 0,
    'last_scan' => 'No scans yet',
    'today_logins' => 0,
];
$dashboardSchoolYear = null;
$dashboardStats = [
    'total_learners' => 0,
    'male_learners' => 0,
    'female_learners' => 0,
    'total_sections' => 0,
    'total_teachers' => 0,
    'measured_learners' => 0,
    'first_dose_count' => 0,
    'second_dose_count' => 0,
    'feeding_count' => 0,
    'today_logs' => 0,
    'today_learners' => 0,
    'last_scan' => 'No scans yet',
];
$gradeEnrollmentRows = [];
$healthBmiRows = [];
$healthDewormingCounts = ['total_learners' => 0, 'first_dose_count' => 0, 'second_dose_count' => 0, 'no_dose_count' => 0];
$healthFeedingCounts = ['total_learners' => 0, 'recipient_count' => 0, 'non_recipient_count' => 0];
$maxGradeEnrollment = 0;
$attendanceCoverage = [
    'total_learners' => 0,
    'scanned_learners' => 0,
    'not_scanned_learners' => 0,
    'coverage_percent' => 0,
];
$attendanceStatusRows = [];
$attendanceHourRows = [];
$attendanceGradeRows = [];
$latestLogs = [];
$dataWarning = null;
$learnerFlash = flash_get('learner_management');
$learnerForm = learner_form_defaults();
$learnerRows = [];
$learnerAccountRows = [];
$learnerAccountFlash = flash_get('learner_accounts');
$learnerAccountSectionId = isset($_GET['account_section_id']) && ctype_digit((string) $_GET['account_section_id'])
    ? (int) $_GET['account_section_id']
    : 0;
$learnerAccountSections = [];
$learnerAccountRows = [];
$scheduleSections = [];
$scheduleSectionId = (int) ($_GET['section_id'] ?? 0);
$adminScheduleRows = [];
$learnerFilters = learner_list_filters();
$learnerFiltersApplied = admin_has_learner_filters($learnerFilters);
$learnerSections = [];
$learnerFilterGradeOptions = [];
$learnerSchoolYear = null;
$learnerEditId = isset($_GET['edit_learner_id']) ? (int) $_GET['edit_learner_id'] : null;
$reportWarning = null;
$reportFilters = attendance_report_filters();
$reportTypeOptions = attendance_report_type_options();
$reportRows = [];
$reportMeta = [
    'title' => 'Attendance Reports',
    'description' => 'Review attendance data using the available report views.',
];
$reportSections = [];
$reportLearners = [];
$reportSchoolYear = null;
$reportSummaryItems = [];
$reportFilterMap = attendance_report_filter_map();
$sectionFlash = flash_get('sections_management');
$sectionForm = section_form_defaults();
$sectionAdviserOptions = [];
$sectionRows = [];
$sectionEditId = isset($_GET['edit_section_id']) ? (int) $_GET['edit_section_id'] : null;
$teacherFlash = flash_get('teacher_management');
$teacherForm = teacher_form_defaults();
$teacherRows = [];
$teacherSections = [];
$teacherEditId = isset($_GET['edit_teacher_id']) ? (int) $_GET['edit_teacher_id'] : null;
$announcementFlash = flash_get('announcements_management');
$announcementForm = ['id' => null, 'title' => '', 'content' => '', 'is_published' => 0];
$announcementRows = [];
$issueFlash = flash_get('admin_issues');
$issueRows = [];
$issueForm = issue_form_defaults();
$issueEditId = isset($_GET['edit_issue_id']) ? (int) $_GET['edit_issue_id'] : null;
$announcementEditId = isset($_GET['edit_announcement_id']) ? (int) $_GET['edit_announcement_id'] : null;
$settingsFlash = flash_get('admin_settings');
$passwordResetFlash = flash_get('admin_password_resets');
$pendingPasswordResets = [];
$themeColors = [];
$activeThemeKey = 'default';
$systemLoginLogs = [];

announcements_bootstrap();
theme_settings_bootstrap();

if ($module === 'class_schedule') {
    try {
        $scheduleSections = admin_schedule_sections();
        $validSectionIds = array_map(static fn (array $section): int => (int) $section['id'], $scheduleSections);

        if ($scheduleSectionId > 0 && in_array($scheduleSectionId, $validSectionIds, true)) {
            $adminScheduleRows = admin_schedule_rows_for_section($scheduleSectionId);
        } else {
            $scheduleSectionId = 0;
        }
    } catch (Throwable $exception) {
        $dataWarning = $exception->getMessage();
    }
}


if ($module === 'learner_management') {
    try {
        $learnerSchoolYear = require_current_school_year();
        $learnerSections = learner_sections();
        $learnerFilterGradeOptions = array_values(array_unique(array_map(
            static fn (array $section): string => (string) $section['grade_level'],
            $learnerSections
        )));

        if (is_post()) {
            if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
                throw new RuntimeException('Invalid form token. Please refresh the page.');
            }

            $formAction = (string) ($_POST['form_action'] ?? '');

            if ($formAction === 'save_learner') {
                $learnerForm = learner_normalize_payload($_POST);
                learner_save($learnerForm);
                flash_set('learner_management', $learnerForm['id'] === null ? 'Learner created successfully.' : 'Learner updated successfully.');
                redirect('admin.php?module=learner_management');
            }

            if ($formAction === 'delete_learner') {
                learner_delete((int) ($_POST['learner_id'] ?? 0));
                flash_set('learner_management', 'Learner deleted successfully.');
                redirect('admin.php?module=learner_management');
            }

            if ($formAction === 'import_learners') {
                $importedCount = learner_import_file($_FILES['import_file'] ?? []);
                flash_set('learner_management', 'Imported ' . $importedCount . ' learner(s) successfully.');
                redirect('admin.php?module=learner_management');
            }
        }

        if ($learnerEditId !== null && $learnerForm['id'] === null) {
            $existingLearner = learner_find($learnerEditId);
            if ($existingLearner !== null) {
                $learnerForm = $existingLearner;
            }
        }

        $learnerRows = $learnerFiltersApplied ? learner_list($learnerFilters) : [];
    } catch (Throwable $exception) {
        $learnerFlash = [
            'type' => 'error',
            'message' => $exception->getMessage(),
        ];
    }
}

if ($module === 'learner_accounts') {
    try {
        $learnerAccountSections = learner_sections();

        if (is_post()) {
            if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
                throw new RuntimeException('Invalid form token. Please refresh the page.');
            }

            $learnerId = (int) ($_POST['learner_id'] ?? 0);
            $formAction = (string) ($_POST['form_action'] ?? '');

            if ($formAction === 'activate_learner_account' || $formAction === 'reset_learner_account') {
                $learner = learner_find($learnerId);

                if ($learner === null) {
                    throw new RuntimeException('Learner record was not found.');
                }

                learner_activate_account($learnerId, (string) ($learner['lrn'] ?? ''), (string) ($learner['lrn'] ?? ''));
                flash_set('learner_accounts', $formAction === 'reset_learner_account'
                    ? 'Learner account reset. The LRN is the temporary username and password.'
                    : 'Learner account activated. The LRN is the temporary username and password.');
                redirect('admin.php?module=learner_accounts');
            }

            if ($formAction === 'deactivate_learner_account') {
                learner_set_account_active($learnerId, false);
                flash_set('learner_accounts', 'Learner account deactivated.');
                redirect('admin.php?module=learner_accounts');
            }
        }

        $learnerAccountRows = learner_account_rows($learnerAccountSectionId > 0 ? $learnerAccountSectionId : null);
    } catch (Throwable $exception) {
        $learnerAccountFlash = [
            'type' => 'error',
            'message' => $exception->getMessage(),
        ];
    }
}

if ($module === 'sections_management') {
    try {
        require_current_school_year();
        $sectionAdviserOptions = section_adviser_options($sectionForm['adviser_name']);

        if (is_post()) {
            if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
                throw new RuntimeException('Invalid form token. Please refresh the page.');
            }

            $formAction = (string) ($_POST['form_action'] ?? '');

            if ($formAction === 'save_section') {
                $sectionForm = section_normalize_payload($_POST);
                $sectionAdviserOptions = section_adviser_options($sectionForm['adviser_name']);
                section_save($sectionForm);
                flash_set('sections_management', $sectionForm['id'] === null ? 'Section created successfully.' : 'Section updated successfully.');
                redirect('admin.php?module=sections_management');
            }

            if ($formAction === 'delete_section') {
                section_delete((int) ($_POST['section_id'] ?? 0));
                flash_set('sections_management', 'Section deleted successfully.');
                redirect('admin.php?module=sections_management');
            }
        }

        if ($sectionEditId !== null && $sectionForm['id'] === null) {
            $existingSection = section_find($sectionEditId);
            if ($existingSection !== null) {
                $sectionForm = $existingSection;
                $sectionAdviserOptions = section_adviser_options($sectionForm['adviser_name']);
            }
        }

        $sectionRows = section_list();
    } catch (Throwable $exception) {
        $sectionFlash = [
            'type' => 'error',
            'message' => $exception->getMessage(),
        ];
    }
}

if ($module === 'teacher_management') {
    try {
        teacher_management_bootstrap();
        $teacherSections = teacher_section_options();

        if (is_post()) {
            if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
                throw new RuntimeException('Invalid form token. Please refresh the page.');
            }

            $formAction = (string) ($_POST['form_action'] ?? '');

            if ($formAction === 'save_teacher') {
                $teacherForm = teacher_normalize_payload($_POST);
                teacher_save($teacherForm);
                flash_set('teacher_management', $teacherForm['id'] === null ? 'Teacher account created successfully.' : 'Teacher account updated successfully.');
                redirect('admin.php?module=teacher_management');
            }

            if ($formAction === 'delete_teacher') {
                teacher_delete((int) ($_POST['teacher_id'] ?? 0));
                flash_set('teacher_management', 'Teacher account deleted successfully.');
                redirect('admin.php?module=teacher_management');
            }
        }

        if ($teacherEditId !== null && $teacherForm['id'] === null) {
            $existingTeacher = teacher_find($teacherEditId);
            if ($existingTeacher !== null) {
                $teacherForm = $existingTeacher;
            }
        }

        $teacherRows = teacher_list();
        $teacherSections = teacher_section_options();
    } catch (Throwable $exception) {
        $teacherFlash = [
            'type' => 'error',
            'message' => $exception->getMessage(),
        ];
    }
}

if ($module === 'announcements') {
    try {
        if (is_post()) {
            if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
                throw new RuntimeException('Invalid form token. Please refresh the page.');
            }

            $formAction = (string) ($_POST['form_action'] ?? '');

            if ($formAction === 'save_announcement') {
                announcement_save($_POST, (int) $user['id']);
                flash_set('announcements_management', 'Announcement saved successfully.');
                redirect('admin.php?module=announcements');
            }

            if ($formAction === 'delete_announcement') {
                announcement_delete((int) ($_POST['announcement_id'] ?? 0));
                flash_set('announcements_management', 'Announcement deleted successfully.');
                redirect('admin.php?module=announcements');
            }
        }

        if ($announcementEditId !== null) {
            $existingAnnouncement = announcement_find($announcementEditId);
            if ($existingAnnouncement !== null) {
                $announcementForm = $existingAnnouncement;
            }
        }

        $announcementRows = announcement_list();
    } catch (Throwable $exception) {
        $announcementFlash = [
            'type' => 'error',
            'message' => $exception->getMessage(),
        ];
    }
}

if ($module === 'reported_issues') {
    try {
        issue_bootstrap();

        if (is_post()) {
            if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
                throw new RuntimeException('Invalid form token. Please refresh the page.');
            }

            $formAction = (string) ($_POST['form_action'] ?? '');

            if ($formAction === 'update_issue_status') {
                issue_update_status((int) ($_POST['issue_id'] ?? 0), (string) ($_POST['status'] ?? 'open'));
                flash_set('admin_issues', 'Issue status updated successfully.');
                redirect('admin.php?module=reported_issues');
            }
        }

        $issueRows = issue_list_for_admin();
        if ($issueEditId !== null) {
            $issueForm = issue_find($issueEditId) ?? issue_form_defaults();
        }
    } catch (Throwable $exception) {
        $issueFlash = [
            'type' => 'error',
            'message' => $exception->getMessage(),
        ];
    }
}

if ($module === 'password_resets') {
    try {
        password_resets_bootstrap();

        if (is_post()) {
            if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
                throw new RuntimeException('Invalid form token. Please refresh the page.');
            }

            $formAction = (string) ($_POST['form_action'] ?? '');
            $requestId = (int) ($_POST['request_id'] ?? 0);

            if ($formAction === 'approve_reset') {
                $newPassword = approve_password_reset($requestId, (int) $user['id']);
                flash_set('admin_password_resets', 'Password has been reset. The new password is: ' . $newPassword);
                redirect('admin.php?module=password_resets');
            }

            if ($formAction === 'deny_reset') {
                deny_password_reset($requestId, (int) $user['id']);
                flash_set('admin_password_resets', 'Password reset request has been denied.');
                redirect('admin.php?module=password_resets');
            }
        }

        $pendingPasswordResets = get_pending_password_requests();
    } catch (Throwable $exception) {
        $passwordResetFlash = ['type' => 'error', 'message' => $exception->getMessage()];
    }
}
if ($module === 'settings') {
    try {
        if (is_post() && ($_POST['form_action'] ?? '') === 'save_theme') {
            if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
                throw new RuntimeException('Invalid form token. Please refresh the page.');
            }
            theme_colors_save((string) ($_POST['theme_key'] ?? ''));
            flash_set('admin_settings', 'Portal theme saved successfully.');
            redirect('admin.php?module=settings');
        }

        if (is_post() && ($_POST['form_action'] ?? '') === 'reset_theme') {
            if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
                throw new RuntimeException('Invalid form token. Please refresh the page.');
            }
            theme_colors_reset();
            flash_set('admin_settings', 'Theme colors have been reset to default.');
            redirect('admin.php?module=settings');
        }
    } catch (Throwable $exception) {
        $settingsFlash = [
            'type' => 'error',
            'message' => $exception->getMessage(),
        ];
    }

    $themeColors = theme_colors();
    $activeThemeKey = theme_active_key();
    $stats['today_logins'] = (int) database()->query('SELECT COUNT(*) FROM auth_login_logs WHERE login_status = \'success\' AND DATE(logged_in_at) = CURDATE()')->fetchColumn();
    $systemLoginLogs = auth_recent_login_logs(10);
}

if ($module === 'dashboard') {
    try {
        health_portal_bootstrap();
        $dashboardSchoolYear = current_school_year();

        if ($dashboardSchoolYear !== null) {
            $syId = (int) $dashboardSchoolYear['id'];

            // 1. Enrolled learners summary and gender distribution
            $enrollmentStmt = database()->prepare(
                'SELECT
                    COUNT(DISTINCT le.id) AS total_learners,
                    COUNT(DISTINCT CASE WHEN LOWER(l.sex) = \'male\' THEN le.id END) AS male_learners,
                    COUNT(DISTINCT CASE WHEN LOWER(l.sex) = \'female\' THEN le.id END) AS female_learners
                 FROM learner_enrollments le
                 INNER JOIN learners l ON l.id = le.learner_id
                 WHERE le.school_year_id = :school_year_id
                   AND le.enrollment_status = \'enrolled\'
                   AND l.current_status = \'active\''
            );
            $enrollmentStmt->execute(['school_year_id' => $syId]);
            $enrollmentRow = $enrollmentStmt->fetch() ?: [];
            $dashboardStats['total_learners'] = (int) ($enrollmentRow['total_learners'] ?? 0);
            $dashboardStats['male_learners'] = (int) ($enrollmentRow['male_learners'] ?? 0);
            $dashboardStats['female_learners'] = (int) ($enrollmentRow['female_learners'] ?? 0);

            // 2. Grade-level enrollment breakdown
            $gradeStmt = database()->prepare(
                'SELECT
                    le.grade_level,
                    COUNT(DISTINCT le.id) AS total_learners,
                    COUNT(DISTINCT CASE WHEN LOWER(l.sex) = \'male\' THEN le.id END) AS male_count,
                    COUNT(DISTINCT CASE WHEN LOWER(l.sex) = \'female\' THEN le.id END) AS female_count,
                    COUNT(DISTINCT CASE WHEN DATE(asl.scanned_at) = CURDATE() THEN le.id END) AS scanned_learners
                 FROM learner_enrollments le
                 INNER JOIN learners l ON l.id = le.learner_id
                 LEFT JOIN attendance_scan_logs asl ON asl.learner_enrollment_id = le.id AND DATE(asl.scanned_at) = CURDATE()
                 WHERE le.school_year_id = :school_year_id
                   AND le.enrollment_status = \'enrolled\'
                   AND l.current_status = \'active\'
                 GROUP BY le.grade_level
                 ORDER BY FIELD(le.grade_level, \'Kinder\', \'Grade 1\', \'Grade 2\', \'Grade 3\', \'Grade 4\', \'Grade 5\', \'Grade 6\', \'Grade 7\', \'Grade 8\', \'Grade 9\', \'Grade 10\', \'Grade 11\', \'Grade 12\'), le.grade_level ASC'
            );
            $gradeStmt->execute(['school_year_id' => $syId]);
            $gradeEnrollmentRows = $gradeStmt->fetchAll();

            foreach ($gradeEnrollmentRows as $grow) {
                $maxGradeEnrollment = max($maxGradeEnrollment, (int) ($grow['total_learners'] ?? 0));
            }
            $attendanceGradeRows = $gradeEnrollmentRows;

            // 3. Total sections & teachers
            $sectionsStmt = database()->prepare('SELECT COUNT(*) FROM sections WHERE school_year_id = :school_year_id');
            $sectionsStmt->execute(['school_year_id' => $syId]);
            $dashboardStats['total_sections'] = (int) $sectionsStmt->fetchColumn();

            $teachersStmt = database()->query('SELECT COUNT(*) FROM users WHERE role = \'teacher\' AND is_active = 1');
            $dashboardStats['total_teachers'] = (int) $teachersStmt->fetchColumn();

            // 4. Health stats
            $healthDashboardStats = health_dashboard_stats();
            $dashboardStats['measured_learners'] = (int) ($healthDashboardStats['measured_learners'] ?? 0);
            $dashboardStats['first_dose_count'] = (int) ($healthDashboardStats['first_dose_count'] ?? 0);
            $dashboardStats['second_dose_count'] = (int) ($healthDashboardStats['second_dose_count'] ?? 0);
            $dashboardStats['feeding_count'] = (int) ($healthDashboardStats['feeding_count'] ?? 0);

            $healthBmiRows = health_dashboard_bmi_remarks_rows();
            $healthDewormingCounts = health_deworming_status_counts();
            $healthFeedingCounts = health_feeding_program_status_counts();

            // 5. Attendance stats & coverage
            $statsStatement = database()->query(
                'SELECT
                    COUNT(*) AS today_logs,
                    COUNT(DISTINCT learner_enrollment_id) AS today_learners,
                    MAX(scanned_at) AS last_scan
                 FROM attendance_scan_logs
                 WHERE DATE(scanned_at) = CURDATE()'
            );
            $statsRow = $statsStatement->fetch() ?: [];
            $stats['today_logs'] = (int) ($statsRow['today_logs'] ?? 0);
            $stats['today_learners'] = (int) ($statsRow['today_learners'] ?? 0);
            $stats['last_scan'] = !empty($statsRow['last_scan'])
                ? date('M j, Y h:i A', strtotime((string) $statsRow['last_scan']))
                : 'No scans yet';
            $dashboardStats['today_logs'] = $stats['today_logs'];
            $dashboardStats['today_learners'] = $stats['today_learners'];
            $dashboardStats['last_scan'] = $stats['last_scan'];

            $coverageStatement = database()->prepare(
                'SELECT
                    COUNT(DISTINCT le.id) AS total_learners,
                    COUNT(DISTINCT CASE WHEN DATE(asl.scanned_at) = CURDATE() THEN le.id END) AS scanned_learners
                 FROM learner_enrollments le
                 INNER JOIN learners l ON l.id = le.learner_id
                 LEFT JOIN attendance_scan_logs asl ON asl.learner_enrollment_id = le.id
                 WHERE le.school_year_id = :school_year_id
                   AND le.enrollment_status = \'enrolled\'
                   AND l.current_status = \'active\''
            );
            $coverageStatement->execute(['school_year_id' => $syId]);
            $coverageRow = $coverageStatement->fetch() ?: [];
            $totalLearners = (int) ($coverageRow['total_learners'] ?? 0);
            $scannedLearners = (int) ($coverageRow['scanned_learners'] ?? 0);
            $notScannedLearners = max(0, $totalLearners - $scannedLearners);

            $attendanceCoverage = [
                'total_learners' => $totalLearners,
                'scanned_learners' => $scannedLearners,
                'not_scanned_learners' => $notScannedLearners,
                'coverage_percent' => admin_percent($scannedLearners, $totalLearners),
            ];

            $statusStatement = database()->prepare(
                'SELECT
                    al.code,
                    al.label,
                    al.color_hex,
                    COUNT(CASE WHEN le.id IS NOT NULL AND l.id IS NOT NULL THEN ar.id END) AS total
                 FROM attendance_legends al
                 LEFT JOIN attendance_records ar
                    ON ar.legend_id = al.id
                   AND ar.attendance_date = CURDATE()
                 LEFT JOIN learner_enrollments le
                    ON le.id = ar.learner_enrollment_id
                   AND le.school_year_id = :school_year_id
                   AND le.enrollment_status = \'enrolled\'
                 LEFT JOIN learners l
                    ON l.id = le.learner_id
                   AND l.current_status = \'active\'
                 GROUP BY al.id, al.code, al.label, al.color_hex
                 ORDER BY al.code ASC'
            );
            $statusStatement->execute(['school_year_id' => $syId]);
            $attendanceStatusRows = $statusStatement->fetchAll();

            $hourStatement = database()->prepare(
                'SELECT
                    HOUR(asl.scanned_at) AS hour_value,
                    DATE_FORMAT(asl.scanned_at, \'%l %p\') AS hour_label,
                    COUNT(*) AS total
                 FROM attendance_scan_logs asl
                 INNER JOIN learner_enrollments le ON le.id = asl.learner_enrollment_id
                 INNER JOIN learners l ON l.id = le.learner_id
                 WHERE le.school_year_id = :school_year_id
                   AND le.enrollment_status = \'enrolled\'
                   AND l.current_status = \'active\'
                   AND DATE(asl.scanned_at) = CURDATE()
                 GROUP BY HOUR(asl.scanned_at), DATE_FORMAT(asl.scanned_at, \'%l %p\')
                 ORDER BY hour_value ASC'
            );
            $hourStatement->execute(['school_year_id' => $syId]);
            $attendanceHourRows = $hourStatement->fetchAll();
        }

        $logsStatement = database()->query(
            'SELECT
                asl.scanned_at,
                CONCAT(l.first_name, \' \', l.last_name) AS learner_name,
                l.lrn,
                CONCAT(le.grade_level, \' / \', COALESCE(s.name, \'Unassigned\')) AS grade_section,
                CONCAT(asl.slot_label, \' recorded as \', al.label) AS log_entry
             FROM attendance_scan_logs asl
             INNER JOIN learner_enrollments le ON le.id = asl.learner_enrollment_id
             INNER JOIN learners l ON l.id = le.learner_id
             LEFT JOIN sections s ON s.id = le.section_id
             INNER JOIN attendance_legends al ON al.id = asl.legend_id
             ORDER BY asl.scanned_at DESC, asl.id DESC
             LIMIT 8'
        );
        $latestLogs = $logsStatement->fetchAll();
    } catch (Throwable $exception) {
        $dataWarning = 'Dashboard overview data is unavailable right now: ' . $exception->getMessage();
    }
}

if ($module === 'settings') {
    $stats['today_logins'] = (int) database()->query('SELECT COUNT(*) FROM auth_login_logs WHERE login_status = \'success\' AND DATE(logged_in_at) = CURDATE()')->fetchColumn();
    $systemLoginLogs = auth_recent_login_logs(10);
}


if ($module === 'attendance_reports') {
    try {
        $reportSchoolYear = require_current_school_year();
        $reportSections = learner_sections();
        $reportLearners = attendance_report_learner_options();
        $reportMeta = attendance_report_data($reportFilters);
        $reportRows = $reportMeta['rows'] ?? [];
        $reportSummaryItems = report_filter_summary_items($reportFilters, $reportSections, $reportLearners);
    } catch (Throwable $exception) {
        $reportWarning = $exception->getMessage();
    }
}

$gradeLevelOptions = learner_grade_level_options();
$statusOptions = ['active', 'inactive', 'graduated', 'transferred'];
$sexOptions = ['male', 'female'];
$attendanceStatusTotal = 0;
$attendanceMaxHourlyScans = 0;
$attendanceMaxGradeLearners = 0;

foreach ($attendanceStatusRows as $row) {
    $attendanceStatusTotal += (int) ($row['total'] ?? 0);
}

foreach ($attendanceHourRows as $row) {
    $attendanceMaxHourlyScans = max($attendanceMaxHourlyScans, (int) ($row['total'] ?? 0));
}

foreach ($attendanceGradeRows as $row) {
    $attendanceMaxGradeLearners = max($attendanceMaxGradeLearners, (int) ($row['total_learners'] ?? 0));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php echo theme_stylesheet_markup(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escape(APP_NAME); ?> Admin</title>
    <link rel="stylesheet" href="<?php echo escape(asset_url('assets/css/app.css')); ?>">
</head>
<body class="dashboard-body admin-dashboard">
    <button
        id="sidebar-toggle"
        class="sidebar-toggle-button"
        type="button"
        data-sidebar-label="admin menu"
        aria-label="Open admin menu"
        aria-controls="admin-sidebar"
        aria-expanded="false"
    >
        <span></span>
        <span></span>
        <span></span>
    </button>
    <div id="sidebar-backdrop" class="sidebar-backdrop" hidden></div>

    <main class="dashboard-shell admin-shell wide-admin-shell">
        <section class="admin-layout">
            <aside id="admin-sidebar" class="admin-sidebar">
                <div class="sidebar-profile">
                    <p class="eyebrow">Admin Profile</p>
                    <h1>Portal Admin</h1>
                    <p class="sidebar-user"><?php echo escape($user['username']); ?></p>
                    <p class="sidebar-email"><?php echo escape($user['email']); ?></p>
                </div>

                <nav class="sidebar-nav" aria-label="Admin Navigation">
                    <div class="menu-group">
                        <p class="menu-group-title">Overview</p>
                        <a href="<?php echo escape(route_url('admin.php?module=dashboard')); ?>" class="submenu-link<?php echo $module === 'dashboard' ? ' active' : ''; ?>">Dashboard</a>
                    </div>

                    <div class="menu-group">
                        <p class="menu-group-title">Management</p>
                        <a href="<?php echo escape(route_url('admin.php?module=learner_management')); ?>" class="submenu-link<?php echo $module === 'learner_management' ? ' active' : ''; ?>">Learner Management</a>
                        <a href="<?php echo escape(route_url('admin.php?module=learner_accounts')); ?>" class="submenu-link<?php echo $module === 'learner_accounts' ? ' active' : ''; ?>">Learner Accounts</a>
                        <a href="<?php echo escape(route_url('admin.php?module=class_schedule')); ?>" class="submenu-link<?php echo $module === 'class_schedule' ? ' active' : ''; ?>">Class Schedule</a>
                        <a href="<?php echo escape(route_url('admin.php?module=sections_management')); ?>" class="submenu-link<?php echo $module === 'sections_management' ? ' active' : ''; ?>">Sections Management</a>
                        <a href="<?php echo escape(route_url('admin.php?module=teacher_management')); ?>" class="submenu-link<?php echo $module === 'teacher_management' ? ' active' : ''; ?>">Teacher Management</a>
                        <a href="<?php echo escape(route_url('admin.php?module=attendance_reports')); ?>" class="submenu-link<?php echo $module === 'attendance_reports' ? ' active' : ''; ?>">Attendance Reports</a>
                    </div>

                    <div class="menu-group">
                        <p class="menu-group-title">System</p>
                        <a href="<?php echo escape(route_url('admin.php?module=announcements')); ?>" class="submenu-link<?php echo $module === 'announcements' ? ' active' : ''; ?>">Announcements</a>
                        <a href="<?php echo escape(route_url('admin.php?module=reported_issues')); ?>" class="submenu-link<?php echo $module === 'reported_issues' ? ' active' : ''; ?>">Reported Issues</a>
                        <a href="<?php echo escape(route_url('admin.php?module=password_resets')); ?>" class="submenu-link<?php echo $module === 'password_resets' ? ' active' : ''; ?>">Password Resets</a>
                        <a href="<?php echo escape(route_url('admin.php?module=settings')); ?>" class="submenu-link<?php echo $module === 'settings' ? ' active' : ''; ?>">Settings</a>
                        <a href="<?php echo escape(route_url('change_password.php')); ?>" class="submenu-link">Change Password</a>
                    </div>
                </nav>

                <div class="sidebar-footer">
                    <a href="<?php echo escape(route_url('logout.php')); ?>" class="secondary-link full-width-link">Logout</a>
                </div>
            </aside>

            <section class="admin-main-panel">
                <?php if ($module === 'dashboard'): ?>
                    <header class="admin-page-header">
                        <div class="admin-page-title">
                            <img class="school-logo header-logo" src="<?php echo escape(school_logo_url()); ?>" alt="School logo">
                            <div class="header-copy">
                                <p class="eyebrow">Overview</p>
                                <h2>Admin Dashboard</h2>
                                <p>Comprehensive metrics on learner enrollment, health coordinator insights, and school operations.</p>
                            </div>
                        </div>
                    </header>

                    <?php if ($dataWarning !== null): ?>
                        <div class="alert error"><?php echo escape($dataWarning); ?></div>
                    <?php endif; ?>

                    <section class="admin-stat-grid">
                        <article class="admin-stat-card">
                            <span class="stat-label">Enrolled Learners</span>
                            <strong><?php echo escape((string) $dashboardStats['total_learners']); ?></strong>
                            <small class="stat-meta"><?php echo escape((string) $dashboardStats['male_learners']); ?> male · <?php echo escape((string) $dashboardStats['female_learners']); ?> female</small>
                        </article>

                        <article class="admin-stat-card">
                            <span class="stat-label">School Staff</span>
                            <strong><?php echo escape((string) $dashboardStats['total_sections']); ?> sections</strong>
                            <small class="stat-meta"><?php echo escape((string) $dashboardStats['total_teachers']); ?> active teachers</small>
                        </article>

                        <article class="admin-stat-card">
                            <span class="stat-label">BMI Profiling</span>
                            <strong><?php echo escape((string) $dashboardStats['measured_learners']); ?></strong>
                            <small class="stat-meta"><?php echo escape((string) admin_percent($dashboardStats['measured_learners'], max(1, $dashboardStats['total_learners']))); ?>% of learners measured</small>
                        </article>
                    </section>

                    <!-- Graph 1: Learner Enrollment per Grade Level -->
                    <section style="margin-top: 20px;">
                        <article class="admin-module-card">
                            <div class="panel-heading compact-heading">
                                <h2>Learner Enrollment per Grade Level</h2>
                                <p>Active enrolled learners by gender across grade levels for school year <?php echo escape($dashboardSchoolYear['label'] ?? 'Current'); ?>.</p>
                            </div>

                            <?php if ($gradeEnrollmentRows === []): ?>
                                <div class="alert neutral" style="margin-top: 14px;">No learner enrollment records found for the active school year.</div>
                            <?php else: ?>
                                <!-- Legend -->
                                <div style="display: flex; gap: 20px; margin-top: 14px; margin-bottom: 10px; flex-wrap: wrap;">
                                    <span style="display: flex; align-items: center; gap: 6px; font-size: 0.84rem; color: var(--muted);">
                                        <span style="width: 12px; height: 12px; border-radius: 3px; background: #3b82f6; display: inline-block; flex-shrink: 0;"></span>
                                        Male
                                    </span>
                                    <span style="display: flex; align-items: center; gap: 6px; font-size: 0.84rem; color: var(--muted);">
                                        <span style="width: 12px; height: 12px; border-radius: 3px; background: #ec4899; display: inline-block; flex-shrink: 0;"></span>
                                        Female
                                    </span>
                                    <span style="display: flex; align-items: center; gap: 6px; font-size: 0.84rem; color: var(--muted); margin-left: auto;">
                                        Total: <strong style="color: var(--ink);"><?php echo escape((string) $dashboardStats['total_learners']); ?> learners</strong>
                                    </span>
                                </div>

                                <div style="display: grid; gap: 12px; margin-top: 4px;">
                                    <?php foreach ($gradeEnrollmentRows as $grow): ?>
                                        <?php
                                        $gradeCount  = (int) ($grow['total_learners'] ?? 0);
                                        $maleCount   = (int) ($grow['male_count'] ?? 0);
                                        $femaleCount = (int) ($grow['female_count'] ?? 0);
                                        $maleShare = $gradeCount > 0 ? max(0, min(100, (int) round(($maleCount / $gradeCount) * 100))) : 0;
                                        $femaleShare = $gradeCount > 0 ? max(0, min(100, (int) round(($femaleCount / $gradeCount) * 100))) : 0;
                                        ?>
                                        <div style="display: grid; grid-template-columns: 100px 1fr; gap: 10px; align-items: center;">
                                            <span style="font-size: 0.84rem; font-weight: 600; color: var(--ink); text-align: right; padding-right: 4px;">
                                                <?php echo escape($grow['grade_level']); ?>
                                            </span>
                                            <div style="display: grid; gap: 6px;">
                                                <div style="height: 18px; width: 100%; background: rgba(31,41,51,0.07); border-radius: 999px; overflow: hidden; display: flex;">
                                                    <?php if ($maleCount > 0): ?>
                                                        <div style="height: 100%; width: <?php echo escape((string) $maleShare); ?>%; background: #3b82f6; transition: width 0.4s ease;"></div>
                                                    <?php endif; ?>
                                                    <?php if ($femaleCount > 0): ?>
                                                        <div style="height: 100%; width: <?php echo escape((string) $femaleShare); ?>%; background: #ec4899; transition: width 0.4s ease;"></div>
                                                    <?php endif; ?>
                                                </div>
                                                <span style="font-size: 0.74rem; color: var(--muted); margin-top: 1px;">
                                                    Total: <?php echo escape((string) $gradeCount); ?> learners · <?php echo escape((string) $maleCount); ?> M / <?php echo escape((string) $femaleCount); ?> F (<?php echo escape((string) admin_percent($gradeCount, max(1, $dashboardStats['total_learners']))); ?>%)
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    </section>

                    <section style="margin-top: 24px;">
                        <div class="panel-heading compact-heading" style="margin-bottom: 12px;">
                            <h2>Health Coordinator Graphs</h2>
                            <p>Nutritional status profiling, deworming dose monitoring, and feeding program coverage.</p>
                        </div>

                        <div class="chart-container">
                            <article class="chart-card">
                                <h3 class="chart-title">BMI Remarks Distribution</h3>
                                <?php
                                    $bmiTotal = array_sum(array_column($healthBmiRows, 'total'));
                                    $slice1 = admin_percent($healthBmiRows[0]['total'] ?? 0, $bmiTotal);
                                    $slice2 = $slice1 + admin_percent($healthBmiRows[1]['total'] ?? 0, $bmiTotal);
                                    $slice3 = $slice2 + admin_percent($healthBmiRows[2]['total'] ?? 0, $bmiTotal);
                                    $slice4 = $slice3 + admin_percent($healthBmiRows[3]['total'] ?? 0, $bmiTotal);
                                ?>
                                <div class="pie-chart" style="
                                    --slice1: <?php echo escape((string) $slice1); ?>%;
                                    --slice2: <?php echo escape((string) $slice2); ?>%;
                                    --slice3: <?php echo escape((string) $slice3); ?>%;
                                    --slice4: <?php echo escape((string) $slice4); ?>%;
                                ">
                                    <span><?php echo escape((string) $bmiTotal); ?><br><small style="font-weight: normal; font-size: 0.72rem;">Learners</small></span>
                                </div>
                                <div class="chart-legend">
                                    <?php foreach ($healthBmiRows as $bmiRow): ?>
                                        <div class="chart-legend-item">
                                            <span>
                                                <span class="chart-legend-color" style="background-color: <?php echo escape($bmiRow['color']); ?>;"></span>
                                                <?php echo escape($bmiRow['label']); ?>
                                            </span>
                                            <strong><?php echo escape((string) $bmiRow['total']); ?> (<?php echo escape((string) admin_percent($bmiRow['total'], $bmiTotal)); ?>%)</strong>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </article>

                            <article class="chart-card">
                                <h3 class="chart-title">Deworming Status</h3>
                                <?php
                                    $dewormingTotal = max(1, $healthDewormingCounts['total_learners']);
                                    $firstDosePercent = admin_percent($healthDewormingCounts['first_dose_count'], $dewormingTotal);
                                    $secondDosePercent = admin_percent($healthDewormingCounts['second_dose_count'], $dewormingTotal);
                                    $noDosePercent = admin_percent($healthDewormingCounts['no_dose_count'], $dewormingTotal);
                                ?>
                                <div class="bar-chart-container">
                                    <div class="bar-chart-bar" style="height: <?php echo escape((string) max(4, $firstDosePercent)); ?>%; background-color: var(--success);">
                                        <span><?php echo escape((string) $healthDewormingCounts['first_dose_count']); ?></span>
                                    </div>
                                    <div class="bar-chart-bar" style="height: <?php echo escape((string) max(4, $secondDosePercent)); ?>%; background-color: var(--info);">
                                        <span><?php echo escape((string) $healthDewormingCounts['second_dose_count']); ?></span>
                                    </div>
                                    <div class="bar-chart-bar" style="height: <?php echo escape((string) max(4, $noDosePercent)); ?>%; background-color: var(--muted);">
                                        <span><?php echo escape((string) $healthDewormingCounts['no_dose_count']); ?></span>
                                    </div>
                                </div>
                                <div style="display: flex; justify-content: space-around; width: 100%; margin-top: 5px;">
                                    <div class="bar-chart-label">1st Dose</div>
                                    <div class="bar-chart-label">2nd Dose</div>
                                    <div class="bar-chart-label">No Dose</div>
                                </div>
                                <div class="chart-legend">
                                    <div class="chart-legend-item">
                                        <span><span class="chart-legend-color" style="background-color: var(--success);"></span>1st Dose</span>
                                        <strong><?php echo escape((string) $healthDewormingCounts['first_dose_count']); ?> (<?php echo escape((string) $firstDosePercent); ?>%)</strong>
                                    </div>
                                    <div class="chart-legend-item">
                                        <span><span class="chart-legend-color" style="background-color: var(--info);"></span>2nd Dose</span>
                                        <strong><?php echo escape((string) $healthDewormingCounts['second_dose_count']); ?> (<?php echo escape((string) $secondDosePercent); ?>%)</strong>
                                    </div>
                                    <div class="chart-legend-item">
                                        <span><span class="chart-legend-color" style="background-color: var(--muted);"></span>No Dose</span>
                                        <strong><?php echo escape((string) $healthDewormingCounts['no_dose_count']); ?> (<?php echo escape((string) $noDosePercent); ?>%)</strong>
                                    </div>
                                </div>
                            </article>

                            <article class="chart-card">
                                <h3 class="chart-title">Feeding Program (SBFP)</h3>
                                <?php
                                    $feedingTotal = max(1, $healthFeedingCounts['total_learners']);
                                    $recipientPct = admin_percent($healthFeedingCounts['recipient_count'], $feedingTotal);
                                    $nonRecipientPct = admin_percent($healthFeedingCounts['non_recipient_count'], $feedingTotal);
                                ?>
                                <div class="bar-chart-container">
                                    <div class="bar-chart-bar" style="height: <?php echo escape((string) max(4, $recipientPct)); ?>%; background-color: #10b981;">
                                        <span><?php echo escape((string) $healthFeedingCounts['recipient_count']); ?></span>
                                    </div>
                                    <div class="bar-chart-bar" style="height: <?php echo escape((string) max(4, $nonRecipientPct)); ?>%; background-color: var(--muted);">
                                        <span><?php echo escape((string) $healthFeedingCounts['non_recipient_count']); ?></span>
                                    </div>
                                </div>
                                <div style="display: flex; justify-content: space-around; width: 100%; margin-top: 5px;">
                                    <div class="bar-chart-label">Recipients</div>
                                    <div class="bar-chart-label">Non‑Recipients</div>
                                </div>
                                <div class="chart-legend">
                                    <div class="chart-legend-item">
                                        <span><span class="chart-legend-color" style="background-color: #10b981;"></span>Recipients</span>
                                        <strong><?php echo escape((string) $healthFeedingCounts['recipient_count']); ?> (<?php echo escape((string) $recipientPct); ?>%)</strong>
                                    </div>
                                    <div class="chart-legend-item">
                                        <span><span class="chart-legend-color" style="background-color: var(--muted);"></span>Non‑Recipients</span>
                                        <strong><?php echo escape((string) $healthFeedingCounts['non_recipient_count']); ?> (<?php echo escape((string) $nonRecipientPct); ?>%)</strong>
                                    </div>
                                </div>
                            </article>
                        </div>
                    </section>
                <?php elseif ($module === 'learner_accounts'): ?>
                    <header class="admin-page-header">
                        <div class="admin-page-title">
                            <img class="school-logo header-logo" src="<?php echo escape(school_logo_url()); ?>" alt="School logo">
                            <div class="header-copy">
                                <p class="eyebrow">Management</p>
                                <h2>Learner Accounts</h2>
                                <p>Activate, reset, or deactivate learner portal access. New and reset accounts use the learner LRN as a temporary username and password.</p>
                            </div>
                        </div>
                    </header>

                    <?php if ($learnerAccountFlash !== null): ?>
                        <div class="alert <?php echo escape($learnerAccountFlash['type']); ?>"><?php echo escape($learnerAccountFlash['message']); ?></div>
                    <?php endif; ?>

                    <section class="admin-module-card">
                        <form method="get" class="learner-account-filter">
                            <input type="hidden" name="module" value="learner_accounts">
                            <div>
                                <label for="account_section_id">Section</label>
                                <select id="account_section_id" name="account_section_id">
                                    <option value="">All sections</option>
                                    <?php foreach ($learnerAccountSections as $section): ?>
                                        <option value="<?php echo escape((string) $section['id']); ?>"<?php echo $learnerAccountSectionId === (int) $section['id'] ? ' selected' : ''; ?>>
                                            <?php echo escape($section['grade_level'] . ' - ' . $section['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="learner-filter-actions">
                                <button type="submit" class="primary-button">Apply Filter</button>
                                <a href="<?php echo escape(route_url('admin.php?module=learner_accounts')); ?>" class="secondary-link">Reset</a>
                            </div>
                        </form>
                        <div class="table-shell">
                            <table class="records-table learner-table admin-management-table">
                                <thead>
                                    <tr>
                                        <th>Learner</th>
                                        <th>LRN</th>
                                        <th>Learner Status</th>
                                        <th>Account</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($learnerAccountRows === []): ?>
                                        <tr><td colspan="5" class="empty-row">No learners are available.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($learnerAccountRows as $accountLearner): ?>
                                            <?php
                                            $hasAccount = !empty($accountLearner['user_id']);
                                            $accountIsActive = $hasAccount && (int) ($accountLearner['account_is_active'] ?? 0) === 1;
                                            ?>
                                            <tr>
                                                <td><?php echo escape(trim($accountLearner['last_name'] . ', ' . $accountLearner['first_name'] . ' ' . $accountLearner['middle_name'])); ?></td>
                                                <td><?php echo escape($accountLearner['lrn']); ?></td>
                                                <td><?php echo escape(ucfirst((string) $accountLearner['current_status'])); ?></td>
                                                <td><?php echo escape(!$hasAccount ? 'Not activated' : ($accountIsActive ? 'Active' : 'Deactivated')); ?></td>
                                                <td>
                                                    <div class="table-actions">
                                                        <form method="post" class="inline-form">
                                                            <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token()); ?>">
                                                            <input type="hidden" name="learner_id" value="<?php echo escape((string) $accountLearner['id']); ?>">
                                                            <input type="hidden" name="form_action" value="<?php echo $hasAccount ? 'reset_learner_account' : 'activate_learner_account'; ?>">
                                                            <button type="submit" class="primary-button small-link"><?php echo !$hasAccount ? 'Activate' : ($accountIsActive ? 'Reset LRN Login' : 'Reactivate Account'); ?></button>
                                                        </form>
                                                        <?php if ($hasAccount && $accountIsActive): ?>
                                                            <form method="post" class="inline-form" onsubmit="return confirm('Deactivate this learner account?');">
                                                                <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token()); ?>">
                                                                <input type="hidden" name="learner_id" value="<?php echo escape((string) $accountLearner['id']); ?>">
                                                                <input type="hidden" name="form_action" value="deactivate_learner_account">
                                                                <button type="submit" class="danger-button">Deactivate</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php elseif ($module === 'class_schedule'): ?>
                    <?php
                    $adminScheduleGrid = [];
                    foreach ($adminScheduleRows as $scheduleRow) {
                        $timeKey = (string) $scheduleRow['time_start'] . '|' . (string) $scheduleRow['time_end'];
                        if (!isset($adminScheduleGrid[$timeKey])) {
                            $adminScheduleGrid[$timeKey] = [
                                'time_start' => (string) $scheduleRow['time_start'],
                                'time_end' => (string) $scheduleRow['time_end'],
                                'days' => [],
                            ];
                        }
                        $adminScheduleGrid[$timeKey]['days'][(string) $scheduleRow['day_of_week']][] = (string) $scheduleRow['subject'];
                    }
                    $selectedScheduleSection = null;
                    foreach ($scheduleSections as $scheduleSection) {
                        if ((int) $scheduleSection['id'] === $scheduleSectionId) {
                            $selectedScheduleSection = $scheduleSection;
                            break;
                        }
                    }
                    ?>
                    <header class="admin-page-header">
                        <div class="admin-page-title">
                            <img class="school-logo header-logo" src="<?php echo escape(school_logo_url()); ?>" alt="School logo">
                            <div class="header-copy">
                                <p class="eyebrow">Management</p>
                                <h2>Class Schedule</h2>
                                <p>Select a current school-year grade level and section to view its read-only class schedule.</p>
                            </div>
                        </div>
                    </header>

                    <?php if ($dataWarning !== null): ?><div class="alert error"><?php echo escape($dataWarning); ?></div><?php endif; ?>

                    <section class="admin-module-card">
                        <form method="get" class="report-filter-grid">
                            <input type="hidden" name="module" value="class_schedule">
                            <div class="report-filter-field report-filter-field-wide">
                                <label for="admin_schedule_section">Grade Level and Section</label>
                                <select id="admin_schedule_section" name="section_id"<?php echo $scheduleSections === [] ? ' disabled' : ''; ?> required>
                                    <option value="">Select grade level and section</option>
                                    <?php foreach ($scheduleSections as $scheduleSection): ?>
                                        <option value="<?php echo escape((string) $scheduleSection['id']); ?>"<?php echo $scheduleSectionId === (int) $scheduleSection['id'] ? ' selected' : ''; ?>>
                                            <?php echo escape($scheduleSection['grade_level'] . ' - ' . $scheduleSection['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="report-actions">
                                <button type="submit" class="primary-button"<?php echo $scheduleSections === [] ? ' disabled' : ''; ?>>View Schedule</button>
                                <a href="<?php echo escape(route_url('admin.php?module=class_schedule')); ?>" class="secondary-link">Reset</a>
                            </div>
                        </form>
                    </section>

                    <?php if ($selectedScheduleSection !== null): ?>
                        <section class="admin-module-card">
                            <div class="panel-heading compact-heading">
                                <h2><?php echo escape($selectedScheduleSection['grade_level'] . ' - ' . $selectedScheduleSection['name']); ?></h2>
                                <p>Read-only weekly class schedule</p>
                            </div>
                            <?php if ($adminScheduleGrid === []): ?>
                                <div class="alert neutral">No class schedule has been entered for this section.</div>
                            <?php else: ?>
                                <div class="table-shell schedule-table-shell">
                                    <table class="records-table schedule-table">
                                        <thead><tr><th>Day/Time</th><?php foreach (teacher_schedule_days() as $scheduleDay): ?><th><?php echo escape($scheduleDay); ?></th><?php endforeach; ?></tr></thead>
                                        <tbody>
                                        <?php foreach ($adminScheduleGrid as $scheduleSlot): ?>
                                            <tr>
                                                <th><?php echo escape(teacher_schedule_time_label($scheduleSlot['time_start']) . ' - ' . teacher_schedule_time_label($scheduleSlot['time_end'])); ?></th>
                                                <?php foreach (teacher_schedule_days() as $scheduleDay): ?>
                                                    <td><?php foreach ($scheduleSlot['days'][$scheduleDay] ?? [] as $scheduleSubject): ?><div class="schedule-cell-entry"><div class="schedule-subject"><?php echo escape($scheduleSubject); ?></div></div><?php endforeach; ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                <?php elseif ($module === 'learner_management'): ?>
                    <header class="admin-page-header">
                        <div class="admin-page-title">
                            <img class="school-logo header-logo" src="<?php echo escape(school_logo_url()); ?>" alt="School logo">
                            <div class="header-copy">
                                <p class="eyebrow">Management</p>
                                <h2>Learner Management</h2>
                                <p>Manage learners, import master lists, and maintain current school-year grade, section, and basic profile assignments.</p>
                            </div>
                        </div>
                    </header>

                    <?php if ($learnerFlash !== null): ?>
                        <div class="alert <?php echo escape($learnerFlash['type']); ?>"><?php echo escape($learnerFlash['message']); ?></div>
                    <?php endif; ?>

                    <section class="learner-admin-grid">
                        <article class="admin-module-card">
                            <?php if ($learnerForm['id'] !== null): ?>
                                <div class="teacher-profile-identity" style="margin-bottom: 1rem;">
                                    <div class="teacher-profile-photo-frame">
                                        <img
                                            class="teacher-profile-photo"
                                            src="<?php echo escape(learner_photo_url($learnerForm['lrn'])); ?>"
                                            alt="<?php echo escape($learnerForm['first_name'] . ' ' . $learnerForm['last_name']); ?> photo"
                                        >
                                    </div>
                                    <div class="teacher-profile-identity-copy">
                                        <p class="meta-label dark">Editing Learner</p>
                                        <div class="teacher-readonly-field"><?php echo escape(trim($learnerForm['last_name'] . ', ' . $learnerForm['first_name'])); ?></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="panel-heading compact-heading">
                                <h2><?php echo $learnerForm['id'] === null ? 'Add Learner' : 'Edit Learner'; ?></h2>
                                <p>Current school year: <?php echo escape($learnerSchoolYear['label'] ?? 'Not set'); ?></p>
                            </div>

                            <form method="post" class="learner-form-grid">
                                <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token()); ?>">
                                <input type="hidden" name="form_action" value="save_learner">
                                <input type="hidden" name="id" value="<?php echo escape((string) ($learnerForm['id'] ?? '')); ?>">

                                <div>
                                    <label for="lrn">LRN</label>
                                    <input id="lrn" name="lrn" type="text" inputmode="numeric" minlength="12" maxlength="12" pattern="\d{12}" value="<?php echo escape($learnerForm['lrn']); ?>" required>
                                </div>

                                <div>
                                    <label for="first_name">First Name</label>
                                    <input id="first_name" name="first_name" type="text" value="<?php echo escape($learnerForm['first_name']); ?>" required>
                                </div>

                                <div>
                                    <label for="middle_name">Middle Name</label>
                                    <input id="middle_name" name="middle_name" type="text" value="<?php echo escape($learnerForm['middle_name']); ?>">
                                </div>

                                <div>
                                    <label for="last_name">Last Name</label>
                                    <input id="last_name" name="last_name" type="text" value="<?php echo escape($learnerForm['last_name']); ?>" required>
                                </div>

                                <div>
                                    <label for="birthdate">Birthdate</label>
                                    <input
                                        id="birthdate"
                                        name="birthdate"
                                        type="date"
                                        value="<?php echo escape($learnerForm['birthdate']); ?>"
                                        data-age-target="learner_age_display"
                                        data-age-reference-date="<?php echo escape(learner_reference_date_for_school_year($learnerSchoolYear)); ?>"
                                    >
                                </div>

                                <div>
                                    <label>Age as of first Friday of June</label>
                                    <div id="learner_age_display" class="teacher-readonly-field">
                                        <?php
                                        $learnerAge = learner_age_for_school_year($learnerForm['birthdate'] !== '' ? $learnerForm['birthdate'] : null, $learnerSchoolYear);
                                        echo escape($learnerAge !== null ? (string) $learnerAge : '-');
                                        ?>
                                    </div>
                                </div>

                                <div>
                                    <label for="mother_tongue">Mother Tongue</label>
                                    <input id="mother_tongue" name="mother_tongue" type="text" value="<?php echo escape($learnerForm['mother_tongue']); ?>">
                                </div>

                                <div>
                                    <label for="religion">Religion</label>
                                    <select id="religion" name="religion">
                                        <option value="">Select religion</option>
                                        <?php foreach (learner_religion_options_with_selected($learnerForm['religion']) as $option): ?>
                                            <option value="<?php echo escape($option); ?>"<?php echo $learnerForm['religion'] === $option ? ' selected' : ''; ?>><?php echo escape($option); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="has_disability">Learner with disability</label>
                                    <select id="has_disability" name="has_disability">
                                        <option value="0"<?php echo (string) $learnerForm['has_disability'] !== '1' ? ' selected' : ''; ?>>No</option>
                                        <option value="1"<?php echo (string) $learnerForm['has_disability'] === '1' ? ' selected' : ''; ?>>Yes</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="disability_basis">Basis</label>
                                    <select id="disability_basis" name="disability_basis">
                                        <option value="">Select basis</option>
                                        <option value="diagnosis"<?php echo $learnerForm['disability_basis'] === 'diagnosis' ? ' selected' : ''; ?>>With diagnosis</option>
                                        <option value="manifestation"<?php echo $learnerForm['disability_basis'] === 'manifestation' ? ' selected' : ''; ?>>With manifestation</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="disability_type">Disability type / details</label>
                                    <input id="disability_type" name="disability_type" type="text" maxlength="255" value="<?php echo escape($learnerForm['disability_type']); ?>" placeholder="e.g., visual impairment">
                                </div>

                                <div>
                                    <label for="sex">Sex</label>
                                    <select id="sex" name="sex">
                                        <option value="">Select sex</option>
                                        <?php foreach ($sexOptions as $option): ?>
                                            <option value="<?php echo escape($option); ?>"<?php echo $learnerForm['sex'] === $option ? ' selected' : ''; ?>><?php echo escape(ucfirst($option)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="current_status">Status</label>
                                    <select id="current_status" name="current_status">
                                        <?php foreach ($statusOptions as $option): ?>
                                            <option value="<?php echo escape($option); ?>"<?php echo $learnerForm['current_status'] === $option ? ' selected' : ''; ?>><?php echo escape(ucfirst($option)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="grade_level">Grade Level</label>
                                    <select id="grade_level" name="grade_level" required>
                                        <option value="">Select grade level</option>
                                        <?php foreach ($gradeLevelOptions as $option): ?>
                                            <option value="<?php echo escape($option); ?>"<?php echo $learnerForm['grade_level'] === $option ? ' selected' : ''; ?>><?php echo escape($option); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="section_id">Section</label>
                                    <select id="section_id" name="section_id">
                                        <option value="">Unassigned</option>
                                        <?php foreach ($learnerSections as $section): ?>
                                            <option value="<?php echo escape((string) $section['id']); ?>"<?php echo (string) $learnerForm['section_id'] === (string) $section['id'] ? ' selected' : ''; ?>>
                                                <?php echo escape($section['grade_level'] . ' - ' . $section['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="learner-form-actions">
                                    <button type="submit" class="primary-button"><?php echo $learnerForm['id'] === null ? 'Save Learner' : 'Update Learner'; ?></button>
                                    <?php if ($learnerForm['id'] !== null): ?>
                                        <a href="<?php echo escape(route_url('admin.php?module=learner_management')); ?>" class="secondary-link">Cancel Edit</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </article>

                        <article class="admin-module-card">
                            <div class="panel-heading compact-heading">
                                <h2>Import Learners</h2>
                                <p>Use the provided template, then import by CSV or the matching XLS template.</p>
                            </div>

                            <div class="template-actions">
                                <a href="<?php echo escape(route_url('download_learner_template.php?format=csv')); ?>" class="secondary-link">Download CSV Template</a>
                                <a href="<?php echo escape(route_url('download_learner_template.php?format=xls')); ?>" class="secondary-link">Download XLS Template</a>
                            </div>

                            <form method="post" enctype="multipart/form-data" class="import-form">
                                <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token()); ?>">
                                <input type="hidden" name="form_action" value="import_learners">

                                <div>
                                    <label for="import_file">Learner File</label>
                                    <input id="import_file" name="import_file" type="file" accept=".csv,.xls" required>
                                </div>

                                <p class="import-note">The learner import now accepts birthdate, mother tongue, religion, and address fields. Age is auto-computed from the first Friday of June of the current school year.</p>
                                <button type="submit" class="primary-button">Import Learners</button>
                            </form>
                        </article>
                    </section>

                    <section class="admin-module-card">
                        <div class="panel-heading compact-heading">
                            <h2>Learner List</h2>
                            <p>Filter and manage the current list of learners.</p>
                        </div>

                        <form method="get" class="learner-filter-grid">
                            <input type="hidden" name="module" value="learner_management">

                            <div>
                                <label for="keyword">Search</label>
                                <input id="keyword" name="keyword" type="text" value="<?php echo escape($learnerFilters['keyword']); ?>" placeholder="Name, LRN, or learner number">
                            </div>

                            <div>
                                <label for="filter_grade_section">Grade / Section</label>
                                <select id="filter_grade_section" name="grade_section">
                                    <option value="">All grades and sections</option>
                                    <?php foreach ($learnerFilterGradeOptions as $option): ?>
                                        <option value="<?php echo escape('grade:' . $option); ?>"<?php echo $learnerFilters['grade_section'] === 'grade:' . $option ? ' selected' : ''; ?>>
                                            <?php echo escape($option . ' - All Sections'); ?>
                                        </option>
                                        <?php foreach ($learnerSections as $section): ?>
                                            <?php if ((string) $section['grade_level'] !== $option): ?>
                                                <?php continue; ?>
                                            <?php endif; ?>
                                            <option value="<?php echo escape('section:' . (string) $section['id']); ?>"<?php echo $learnerFilters['grade_section'] === 'section:' . (string) $section['id'] ? ' selected' : ''; ?>>
                                                <?php echo escape($section['grade_level'] . ' - ' . $section['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label for="filter_status">Status</label>
                                <select id="filter_status" name="status">
                                    <option value="">All statuses</option>
                                    <?php foreach ($statusOptions as $option): ?>
                                        <option value="<?php echo escape($option); ?>"<?php echo $learnerFilters['status'] === $option ? ' selected' : ''; ?>><?php echo escape(ucfirst($option)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="learner-filter-actions">
                                <button type="submit" class="primary-button">Apply Filters</button>
                                <a href="<?php echo escape(route_url('admin.php?module=learner_management')); ?>" class="secondary-link">Reset</a>
                            </div>
                        </form>

                        <?php if (!$learnerFiltersApplied): ?>
                            <div class="alert neutral">Select a filter and apply it to display learner records.</div>
                        <?php else: ?>
                            <div class="table-shell">
                                <table class="records-table learner-table admin-management-table learner-list-table">
                                <thead>
                                    <tr>
                                        <th>LRN</th>
                                        <th>Name</th>
                                        <th>Birthdate</th>
                                        <th>Age</th>
                                        <th>Mother Tongue</th>
                                        <th>Religion</th>
                                        <th>Disability</th>
                                        <th>Grade</th>
                                        <th>Section</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($learnerRows === []): ?>
                                        <tr>
                                            <td colspan="11" class="empty-row">No learners matched the current filter.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($learnerRows as $learner): ?>
                                            <tr>
                                                <td><?php echo escape($learner['lrn']); ?></td>
                                                <td>
                                                    <a href="<?php echo escape(route_url('admin.php?module=learner_management&edit_learner_id=' . $learner['id'])); ?>" class="table-inline-link">
                                                        <?php echo escape(trim($learner['last_name'] . ', ' . $learner['first_name'] . ' ' . $learner['middle_name'])); ?>
                                                    </a>
                                                </td>
                                                <td><?php echo escape($learner['birthdate'] !== null && $learner['birthdate'] !== '' ? $learner['birthdate'] : '-'); ?></td>
                                                <td><?php $rowAge = learner_age_for_school_year($learner['birthdate'] ?? null, $learnerSchoolYear); echo escape($rowAge !== null ? (string) $rowAge : '-'); ?></td>
                                                <td><?php echo escape(trim((string) ($learner['mother_tongue'] ?? '')) !== '' ? (string) $learner['mother_tongue'] : '-'); ?></td>
                                                <td><?php echo escape(trim((string) ($learner['religion'] ?? '')) !== '' ? (string) $learner['religion'] : '-'); ?></td>
                                                <td><?php echo (int) ($learner['has_disability'] ?? 0) === 1 ? escape(ucfirst((string) $learner['disability_basis']) . ': ' . $learner['disability_type']) : 'No'; ?></td>
                                                <td><?php echo escape($learner['grade_level'] ?? '-'); ?></td>
                                                <td><?php echo escape($learner['section_name'] ?? '-'); ?></td>
                                                <td><?php echo escape(ucfirst($learner['current_status'])); ?></td>
                                                <td>
                                                    <div class="table-actions">
                                                        <a href="<?php echo escape(route_url('admin.php?module=learner_management&edit_learner_id=' . $learner['id'])); ?>" class="secondary-link small-link">Edit</a>
                                                        <form method="post" class="inline-form" onsubmit="return confirm('Delete this learner?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token()); ?>">
                                                            <input type="hidden" name="form_action" value="delete_learner">
                                                            <input type="hidden" name="learner_id" value="<?php echo escape((string) $learner['id']); ?>">
                                                            <button type="submit" class="danger-button">Delete</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php elseif ($module === 'sections_management'): ?>
                    <header class="admin-page-header">
                        <div class="admin-page-title">
                            <img class="school-logo header-logo" src="<?php echo escape(school_logo_url()); ?>" alt="School logo">
                            <div class="header-copy">
                                <p class="eyebrow">Management</p>
                                <h2>Sections Management</h2>
                                <p>Create, update, and remove sections for the current school year before assigning teachers and learners.</p>
                            </div>
                        </div>
                    </header>

                    <?php if ($sectionFlash !== null): ?>
                        <div class="alert <?php echo escape($sectionFlash['type']); ?>"><?php echo escape($sectionFlash['message']); ?></div>
                    <?php endif; ?>

                    <section>
                        <article class="admin-module-card">
                            <div class="panel-heading compact-heading">
                                <h2><?php echo $sectionForm['id'] === null ? 'Add Section' : 'Edit Section'; ?></h2>
                                <p>Sections are saved under the current school year only.</p>
                            </div>

                            <form method="post" class="learner-form-grid">
                                <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token()); ?>">
                                <input type="hidden" name="form_action" value="save_section">
                                <input type="hidden" name="id" value="<?php echo escape((string) ($sectionForm['id'] ?? '')); ?>">

                                <div>
                                    <label for="section_name">Section Name</label>
                                    <input id="section_name" name="name" type="text" value="<?php echo escape($sectionForm['name']); ?>" required>
                                </div>

                                <div>
                                    <label for="section_grade_level">Grade Level</label>
                                    <select id="section_grade_level" name="grade_level" required>
                                        <option value="">Select grade level</option>
                                        <?php foreach ($gradeLevelOptions as $option): ?>
                                            <option value="<?php echo escape($option); ?>"<?php echo $sectionForm['grade_level'] === $option ? ' selected' : ''; ?>><?php echo escape($option); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="teacher-form-grid-full">
                                    <label for="section_adviser_name">Adviser Name</label>
                                    <select id="section_adviser_name" name="adviser_name">
                                        <option value="">Select adviser account</option>
                                        <?php if ($sectionAdviserOptions === []): ?>
                                            <option value="" disabled>No active teacher accounts available</option>
                                        <?php else: ?>
                                            <?php foreach ($sectionAdviserOptions as $option): ?>
                                                <option value="<?php echo escape((string) $option['value']); ?>"<?php echo $sectionForm['adviser_name'] === (string) $option['value'] ? ' selected' : ''; ?>>
                                                    <?php echo escape((string) $option['label']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>

                                <div class="learner-form-actions">
                                    <button type="submit" class="primary-button"><?php echo $sectionForm['id'] === null ? 'Save Section' : 'Update Section'; ?></button>
                                    <?php if ($sectionForm['id'] !== null): ?>
                                        <a href="<?php echo escape(route_url('admin.php?module=sections_management')); ?>" class="secondary-link">Cancel Edit</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </article>
                    </section>

                    <section class="admin-module-card">
                        <div class="panel-heading compact-heading">
                            <h2>Sections</h2>
                            <p>Review current school-year sections, adviser names, and assignment usage.</p>
                        </div>

                        <div class="table-shell">
                            <table class="records-table learner-table admin-management-table sections-table">
                                <thead>
                                    <tr>
                                        <th>Grade</th>
                                        <th>Section</th>
                                        <th>Adviser</th>
                                        <th>School Year</th>
                                        <th>Teachers</th>
                                        <th>Learners</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($sectionRows === []): ?>
                                        <tr>
                                            <td colspan="7" class="empty-row">No sections are available for the current school year.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($sectionRows as $section): ?>
                                            <tr>
                                                <td><?php echo escape($section['grade_level']); ?></td>
                                                <td><?php echo escape($section['name']); ?></td>
                                                <td><?php echo escape(trim((string) ($section['adviser_name'] ?? '')) !== '' ? (string) $section['adviser_name'] : '-'); ?></td>
                                                <td><?php echo escape($section['school_year_label']); ?></td>
                                                <td><?php echo escape((string) $section['assigned_teacher_count']); ?></td>
                                                <td><?php echo escape((string) $section['learner_count']); ?></td>
                                                <td>
                                                    <div class="table-actions">
                                                        <a href="<?php echo escape(route_url('admin.php?module=sections_management&edit_section_id=' . $section['id'])); ?>" class="secondary-link small-link">Edit</a>
                                                        <form method="post" class="inline-form" onsubmit="return confirm('Delete this section?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token()); ?>">
                                                            <input type="hidden" name="form_action" value="delete_section">
                                                            <input type="hidden" name="section_id" value="<?php echo escape((string) $section['id']); ?>">
                                                            <button type="submit" class="danger-button">Delete</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php elseif ($module === 'teacher_management'): ?>
                    <header class="admin-page-header">
                        <div class="admin-page-title">
                            <img class="school-logo header-logo" src="<?php echo escape(school_logo_url()); ?>" alt="School logo">
                            <div class="header-copy">
                                <p class="eyebrow">Management</p>
                                <h2>Teacher Management</h2>
                                <p>Create teacher accounts, capture complete names, and assign an advisory section for the teacher portal.</p>
                            </div>
                        </div>
                    </header>

                    <?php if ($teacherFlash !== null): ?>
                        <div class="alert <?php echo escape($teacherFlash['type']); ?>"><?php echo escape($teacherFlash['message']); ?></div>
                    <?php endif; ?>

                    <section>
                        <article class="admin-module-card">
                            <div class="panel-heading compact-heading">
                                <h2><?php echo $teacherForm['id'] === null ? 'Add Teacher Account' : 'Edit Teacher Account'; ?></h2>
                                <p>Assign each teacher to an advisory section for the selected school year.</p>
                            </div>

                            <form method="post" class="learner-form-grid">
                                <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token()); ?>">
                                <input type="hidden" name="form_action" value="save_teacher">
                                <input type="hidden" name="id" value="<?php echo escape((string) ($teacherForm['id'] ?? '')); ?>">

                                <div>
                                    <label for="teacher_username">Username</label>
                                    <input id="teacher_username" name="username" type="text" value="<?php echo escape($teacherForm['username']); ?>" required>
                                </div>

                                <div>
                                    <label for="teacher_email">Email</label>
                                    <input id="teacher_email" name="email" type="email" value="<?php echo escape($teacherForm['email']); ?>" required>
                                </div>

                                <div>
                                    <label for="teacher_first_name">First Name</label>
                                    <input id="teacher_first_name" name="first_name" type="text" value="<?php echo escape($teacherForm['first_name']); ?>" required>
                                </div>

                                <div>
                                    <label for="teacher_middle_name">Middle Name</label>
                                    <input id="teacher_middle_name" name="middle_name" type="text" value="<?php echo escape($teacherForm['middle_name']); ?>">
                                </div>

                                <div>
                                    <label for="teacher_last_name">Last Name</label>
                                    <input id="teacher_last_name" name="last_name" type="text" value="<?php echo escape($teacherForm['last_name']); ?>" required>
                                </div>

                                <div>
                                    <label for="teacher_password">Password</label>
                                    <input id="teacher_password" name="password" type="password" value="<?php echo escape($teacherForm['password']); ?>"<?php echo $teacherForm['id'] === null ? ' required' : ''; ?>>
                                </div>

                                <div class="teacher-form-grid-full">
                                    <label for="teacher_section_id">Assigned Section</label>
                                    <select id="teacher_section_id" name="section_id" required>
                                        <option value="">Select section</option>
                                        <?php foreach ($teacherSections as $section): ?>
                                            <?php
                                            $isSelectedSection = (string) $section['id'] === (string) ($teacherForm['section_id'] ?? '');
                                            $assignedTeacherUsername = (string) ($section['assigned_teacher_username'] ?? '');
                                            $assignedTeacherName = trim((string) ($section['assigned_teacher_name'] ?? ''));
                                            $isAssignedToOther = $assignedTeacherUsername !== '' && $assignedTeacherUsername !== $teacherForm['username'];
                                            $sectionSuffix = $isAssignedToOther
                                                ? 'Assigned to ' . ($assignedTeacherName !== '' ? $assignedTeacherName : $assignedTeacherUsername)
                                                : 'Available';
                                            $sectionLabel = $section['school_year_label'] . ' - ' . $section['grade_level'] . ' - ' . $section['name'] . ' (' . $sectionSuffix . ')';
                                            ?>
                                            <option
                                                value="<?php echo escape((string) $section['id']); ?>"
                                                <?php echo $isSelectedSection ? ' selected' : ''; ?>
                                                <?php echo $isAssignedToOther ? ' disabled' : ''; ?>
                                            >
                                                <?php echo escape($sectionLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="learner-form-actions">
                                    <button type="submit" class="primary-button"><?php echo $teacherForm['id'] === null ? 'Save Teacher' : 'Update Teacher'; ?></button>
                                    <?php if ($teacherForm['id'] !== null): ?>
                                        <a href="<?php echo escape(route_url('admin.php?module=teacher_management')); ?>" class="secondary-link">Cancel Edit</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </article>
                    </section>

                    <section class="admin-module-card">
                        <div class="panel-heading compact-heading">
                            <h2>Teacher Accounts</h2>
                            <p>Review section assignments and manage teacher portal access.</p>
                        </div>

                        <div class="table-shell">
                            <table class="records-table learner-table admin-management-table teachers-table">
                                <thead>
                                    <tr>
                                        <th>Teacher Name</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Grade</th>
                                        <th>Section</th>
                                        <th>School Year</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($teacherRows === []): ?>
                                        <tr>
                                            <td colspan="7" class="empty-row">No teacher accounts are available yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($teacherRows as $teacher): ?>
                                            <tr>
                                                <td><?php echo escape(trim($teacher['first_name'] . ' ' . $teacher['middle_name'] . ' ' . $teacher['last_name']) !== '' ? trim($teacher['first_name'] . ' ' . $teacher['middle_name'] . ' ' . $teacher['last_name']) : $teacher['username']); ?></td>
                                                <td><?php echo escape($teacher['username']); ?></td>
                                                <td><?php echo escape($teacher['email']); ?></td>
                                                <td><?php echo escape($teacher['grade_level']); ?></td>
                                                <td><?php echo escape($teacher['section_name']); ?></td>
                                                <td><?php echo escape($teacher['school_year_label']); ?></td>
                                                <td>
                                                    <div class="table-actions">
                                                        <a href="<?php echo escape(route_url('admin.php?module=teacher_management&edit_teacher_id=' . $teacher['id'])); ?>" class="secondary-link small-link">Edit</a>
                                                        <form method="post" class="inline-form" onsubmit="return confirm('Delete this teacher account?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token()); ?>">
                                                            <input type="hidden" name="form_action" value="delete_teacher">
                                                            <input type="hidden" name="teacher_id" value="<?php echo escape((string) $teacher['id']); ?>">
                                                            <button type="submit" class="danger-button">Delete</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php elseif ($module === 'attendance_reports'): ?>
                    <header class="admin-page-header">
                        <div>
                            <p class="eyebrow">Reports</p>
                            <h2>Attendance Reports</h2>
                            <p>Generate daily, monthly, section-based, learner, and audit-style attendance reports.</p>
                        </div>
                    </header>

                    <?php if ($reportWarning !== null): ?>
                        <div class="alert error"><?php echo escape($reportWarning); ?></div>
                    <?php else: ?>
                        <section class="admin-module-card report-card no-print">
                            <div class="panel-heading compact-heading">
                                <h2>Report Filters</h2>
                                <p>Current school year: <?php echo escape($reportSchoolYear['label'] ?? 'Not set'); ?></p>
                            </div>

                            <form
                                method="get"
                                class="report-filter-grid"
                                id="report-filter-form"
                                data-report-filter-map="<?php echo escape((string) json_encode($reportFilterMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)); ?>"
                            >
                                <input type="hidden" name="module" value="attendance_reports">

                                <div class="report-filter-field report-filter-field-wide" data-report-filter-key="report_type">
                                    <label for="report_type">Report Type</label>
                                    <select id="report_type" name="report_type">
                                        <option value=""<?php echo $reportFilters['report_type'] === '' ? ' selected' : ''; ?>>Select report type</option>
                                        <?php foreach ($reportTypeOptions as $value => $label): ?>
                                            <option value="<?php echo escape($value); ?>"<?php echo $reportFilters['report_type'] === $value ? ' selected' : ''; ?>>
                                                <?php echo escape($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                </select>
                                </div>

                                <div class="report-filter-field" data-report-filter-key="date_range"<?php echo in_array('date_range', $reportMeta['filters'] ?? [], true) ? '' : ' hidden'; ?>>
                                    <label for="date_from">Date From</label>
                                    <input id="date_from" name="date_from" type="date" value="<?php echo escape($reportFilters['date_from']); ?>">
                                </div>

                                <div class="report-filter-field" data-report-filter-key="date_range"<?php echo in_array('date_range', $reportMeta['filters'] ?? [], true) ? '' : ' hidden'; ?>>
                                    <label for="date_to">Date To</label>
                                    <input id="date_to" name="date_to" type="date" value="<?php echo escape($reportFilters['date_to']); ?>">
                                </div>

                                <div class="report-filter-field" data-report-filter-key="report_date"<?php echo in_array('report_date', $reportMeta['filters'] ?? [], true) ? '' : ' hidden'; ?>>
                                    <label for="report_date">Report Date</label>
                                    <input id="report_date" name="report_date" type="date" value="<?php echo escape($reportFilters['report_date']); ?>">
                                </div>

                                <div class="report-filter-field" data-report-filter-key="report_month"<?php echo in_array('report_month', $reportMeta['filters'] ?? [], true) ? '' : ' hidden'; ?>>
                                    <label for="report_month">Report Month</label>
                                    <input id="report_month" name="report_month" type="month" value="<?php echo escape($reportFilters['report_month']); ?>">
                                </div>

                                <div class="report-filter-field" data-report-filter-key="section_id"<?php echo in_array('section_id', $reportMeta['filters'] ?? [], true) ? '' : ' hidden'; ?>>
                                    <label for="report_section_id">Grade Level and Section</label>
                                    <select id="report_section_id" name="section_id">
                                        <option value="">All sections</option>
                                        <?php foreach ($reportSections as $section): ?>
                                            <option value="<?php echo escape((string) $section['id']); ?>"<?php echo $reportFilters['section_id'] === (string) $section['id'] ? ' selected' : ''; ?>>
                                                <?php echo escape($section['grade_level'] . ' - ' . $section['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="report-filter-field report-filter-field-wide" data-report-filter-key="learner_id"<?php echo in_array('learner_id', $reportMeta['filters'] ?? [], true) ? '' : ' hidden'; ?>>
                                    <label for="report_learner_id">Learner</label>
                                    <select id="report_learner_id" name="learner_id">
                                        <option value="">All learners</option>
                                        <?php foreach ($reportLearners as $learner): ?>
                                            <?php $learnerLabel = trim($learner['last_name'] . ', ' . $learner['first_name'] . ' ' . $learner['middle_name']); ?>
                                            <option value="<?php echo escape((string) $learner['id']); ?>"<?php echo $reportFilters['learner_id'] === (string) $learner['id'] ? ' selected' : ''; ?>>
                                                <?php echo escape($learnerLabel . ' [' . $learner['lrn'] . ']'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="report-actions">
                                    <button type="submit" class="primary-button">Generate Report</button>
                                    <a href="<?php echo escape(route_url('admin.php?module=attendance_reports')); ?>" class="secondary-link">Reset</a>
                                    <button type="button" class="ghost-button" onclick="window.print()"<?php echo $reportFilters['report_type'] === '' ? ' disabled' : ''; ?>>Print Report</button>
                                </div>
                            </form>
                        </section>

                        <section class="admin-module-card report-card report-print-area">
                            <div class="report-print-header">
                                <div class="admin-page-title">
                                    <img class="school-logo header-logo" src="<?php echo escape(school_logo_url()); ?>" alt="School logo">
                                    <div class="header-copy">
                                        <p class="eyebrow">Attendance Report</p>
                                        <h2><?php echo escape($reportMeta['title']); ?></h2>
                                        <p><?php echo escape($reportMeta['description']); ?></p>
                                    </div>
                                </div>

                                <div class="report-print-meta">
                                    <p><strong>School Year:</strong> <?php echo escape($reportSchoolYear['label'] ?? 'Not set'); ?></p>
                                    <p><strong>Generated:</strong> <?php echo escape(date('F j, Y h:i A')); ?></p>
                                    <p><strong>Total Records:</strong> <?php echo escape((string) count($reportRows)); ?></p>
                                </div>
                            </div>

                            <?php if ($reportSummaryItems !== []): ?>
                                <div class="report-filter-summary">
                                    <?php foreach ($reportSummaryItems as $label => $value): ?>
                                        <div class="report-filter-chip">
                                            <span><?php echo escape($label); ?></span>
                                            <strong><?php echo escape($value); ?></strong>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($reportFilters['report_type'] === 'monthly_summary' && !empty($reportMeta['is_selected'])): ?>
                                <div class="monthly-report-info">
                                    <p><strong>Section:</strong> <?php echo escape($reportMeta['section_label'] ?? 'All Sections'); ?></p>
                                    <p><strong>Month:</strong> <?php echo escape($reportMeta['month_label'] ?? '-'); ?></p>
                                    <p><strong>Year:</strong> <?php echo escape($reportMeta['year_label'] ?? '-'); ?></p>
                                </div>
                            <?php endif; ?>

                            <p class="report-summary-note">
                                Showing <?php echo escape((string) count($reportRows)); ?> record(s).
                            </p>

                            <?php if (empty($reportMeta['is_selected'])): ?>
                                <div class="alert neutral">Select a report type first to display the needed filter fields and report data.</div>
                            <?php elseif (!empty($reportMeta['requires_learner']) && $reportFilters['learner_id'] === ''): ?>
                                <div class="alert neutral">Select a learner, then generate the report to view attendance history.</div>
                            <?php endif; ?>

                            <?php if (!empty($reportMeta['is_selected'])): ?>
                            <div class="table-shell">
                                <?php if (in_array($reportFilters['report_type'], ['daily_attendance', 'section_attendance'], true)): ?>
                                    <table class="records-table report-table">
                                        <thead>
                                            <tr>
                                                <th>Learner No.</th>
                                                <th>LRN</th>
                                                <th>Name</th>
                                                <th>Grade</th>
                                                <th>Section</th>
                                                <th>Status</th>
                                                <th>AM In</th>
                                                <th>AM Out</th>
                                                <th>PM In</th>
                                                <th>PM Out</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($reportRows === []): ?>
                                                <tr>
                                                    <td colspan="10" class="empty-row">No attendance records matched the selected filters.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($reportRows as $row): ?>
                                                    <tr>
                                                        <td><?php echo escape($row['learner_number']); ?></td>
                                                        <td><?php echo escape($row['lrn']); ?></td>
                                                        <td><?php echo escape($row['learner_name']); ?></td>
                                                        <td><?php echo escape($row['grade_level']); ?></td>
                                                        <td><?php echo escape($row['section_name']); ?></td>
                                                        <td><span class="table-status"><?php echo escape($row['attendance_status']); ?></span></td>
                                                        <td><?php echo escape(format_report_time($row['am_time_in'])); ?></td>
                                                        <td><?php echo escape(format_report_time($row['am_time_out'])); ?></td>
                                                        <td><?php echo escape(format_report_time($row['pm_time_in'])); ?></td>
                                                        <td><?php echo escape(format_report_time($row['pm_time_out'])); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                <?php elseif ($reportFilters['report_type'] === 'monthly_summary'): ?>
                                    <table class="records-table report-table monthly-report-table">
                                        <thead>
                                            <tr>
                                                <th>No.</th>
                                                <th>Name</th>
                                                <?php foreach (($reportMeta['days_in_month'] ?? []) as $dayNumber): ?>
                                                    <th><?php echo escape((string) $dayNumber); ?></th>
                                                <?php endforeach; ?>
                                                <th>Total Absences</th>
                                                <th>Total Present Days</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($reportRows === []): ?>
                                                <tr>
                                                    <td colspan="<?php echo escape((string) (count($reportMeta['days_in_month'] ?? []) + 4)); ?>" class="empty-row">No monthly attendance data matched the selected filters.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($reportRows as $row): ?>
                                                    <tr>
                                                        <td><?php echo escape((string) $row['no']); ?></td>
                                                        <td><?php echo escape($row['learner_name']); ?></td>
                                                        <?php foreach (($reportMeta['days_in_month'] ?? []) as $dayNumber): ?>
                                                            <td><?php echo escape($row['days'][$dayNumber] ?? ''); ?></td>
                                                        <?php endforeach; ?>
                                                        <td><?php echo escape((string) $row['total_absences']); ?></td>
                                                        <td><?php echo escape((string) $row['total_present_days']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                        <?php if ($reportRows !== []): ?>
                                            <tfoot>
                                                <tr>
                                                    <th colspan="2">Total Present Per Day</th>
                                                    <?php foreach (($reportMeta['days_in_month'] ?? []) as $dayNumber): ?>
                                                        <th><?php echo escape((string) (($reportMeta['day_totals'][$dayNumber] ?? 0))); ?></th>
                                                    <?php endforeach; ?>
                                                    <th><?php echo escape((string) ($reportMeta['overall_absences'] ?? 0)); ?></th>
                                                    <th><?php echo escape((string) ($reportMeta['overall_present_days'] ?? 0)); ?></th>
                                                </tr>
                                            </tfoot>
                                        <?php endif; ?>
                                    </table>
                                <?php elseif ($reportFilters['report_type'] === 'learner_history'): ?>
                                    <table class="records-table report-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Status</th>
                                                <th>AM In</th>
                                                <th>AM Out</th>
                                                <th>PM In</th>
                                                <th>PM Out</th>
                                                <th>Grade</th>
                                                <th>Section</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($reportRows === []): ?>
                                                <tr>
                                                    <td colspan="8" class="empty-row">No learner attendance history matched the selected range.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($reportRows as $row): ?>
                                                    <tr>
                                                        <td><?php echo escape(format_report_date($row['attendance_date'], 'Y-m-d')); ?></td>
                                                        <td><span class="table-status"><?php echo escape($row['attendance_status']); ?></span></td>
                                                        <td><?php echo escape(format_report_time($row['am_time_in'])); ?></td>
                                                        <td><?php echo escape(format_report_time($row['am_time_out'])); ?></td>
                                                        <td><?php echo escape(format_report_time($row['pm_time_in'])); ?></td>
                                                        <td><?php echo escape(format_report_time($row['pm_time_out'])); ?></td>
                                                        <td><?php echo escape($row['grade_level']); ?></td>
                                                        <td><?php echo escape($row['section_name']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                <?php elseif ($reportFilters['report_type'] === 'late_absence'): ?>
                                    <table class="records-table report-table">
                                        <thead>
                                            <tr>
                                                <th>Learner No.</th>
                                                <th>LRN</th>
                                                <th>Name</th>
                                                <th>Grade</th>
                                                <th>Section</th>
                                                <th>Late</th>
                                                <th>Absent</th>
                                                <th>Excused</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($reportRows === []): ?>
                                                <tr>
                                                    <td colspan="8" class="empty-row">No late or absence records matched the selected range.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($reportRows as $row): ?>
                                                    <tr>
                                                        <td><?php echo escape($row['learner_number']); ?></td>
                                                        <td><?php echo escape($row['lrn']); ?></td>
                                                        <td><?php echo escape($row['learner_name']); ?></td>
                                                        <td><?php echo escape($row['grade_level']); ?></td>
                                                        <td><?php echo escape($row['section_name']); ?></td>
                                                        <td><?php echo escape((string) $row['late_count']); ?></td>
                                                        <td><?php echo escape((string) $row['absent_count']); ?></td>
                                                        <td><?php echo escape((string) $row['excused_count']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <table class="records-table report-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Time</th>
                                                <th>Slot</th>
                                                <th>Status</th>
                                                <th>Learner No.</th>
                                                <th>LRN</th>
                                                <th>Name</th>
                                                <th>Grade</th>
                                                <th>Section</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($reportRows === []): ?>
                                                <tr>
                                                    <td colspan="9" class="empty-row">No attendance logs matched the selected range.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($reportRows as $row): ?>
                                                    <tr>
                                                        <td><?php echo escape(format_report_date($row['scanned_at'], 'Y-m-d')); ?></td>
                                                        <td><?php echo escape(format_report_date($row['scanned_at'], 'h:i:s A')); ?></td>
                                                        <td><?php echo escape($row['slot_label']); ?></td>
                                                        <td><span class="table-status"><?php echo escape($row['attendance_status']); ?></span></td>
                                                        <td><?php echo escape($row['learner_number']); ?></td>
                                                        <td><?php echo escape($row['lrn']); ?></td>
                                                        <td><?php echo escape($row['learner_name']); ?></td>
                                                        <td><?php echo escape($row['grade_level']); ?></td>
                                                        <td><?php echo escape($row['section_name']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                <?php elseif ($module === 'announcements'): ?>
                    <header class="admin-page-header">
                        <div class="admin-page-title">
                            <img class="school-logo header-logo" src="<?php echo escape(school_logo_url()); ?>" alt="School logo">
                            <div class="header-copy">
                                <p class="eyebrow">Communication</p>
                                <h2>Announcements</h2>
                                <p>Create and manage announcements for the parent portal.</p>
                            </div>
                        </div>
                    </header>

                    <?php if ($announcementFlash !== null): ?>
                        <div class="alert <?php echo escape($announcementFlash['type']); ?>"><?php echo escape($announcementFlash['message']); ?></div>
                    <?php endif; ?>

                    <section>
                        <article class="admin-module-card">
                            <div class="panel-heading compact-heading">
                                <h2><?php echo $announcementForm['id'] === null ? 'Create Announcement' : 'Edit Announcement'; ?></h2>
                                <p>Published announcements will be visible on the parent portal.</p>
                            </div>

                            <form method="post" class="learner-form-grid">
                                <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token()); ?>">
                                <input type="hidden" name="form_action" value="save_announcement">
                                <input type="hidden" name="id" value="<?php echo escape((string) ($announcementForm['id'] ?? '')); ?>">

                                <div class="teacher-form-grid-full">
                                    <label for="announcement_title">Title</label>
                                    <input id="announcement_title" name="title" type="text" value="<?php echo escape($announcementForm['title']); ?>" required>
                                </div>

                                <div class="teacher-form-grid-full">
                                    <label for="announcement_content">Content</label>
                                    <textarea id="announcement_content" name="content" rows="5" required><?php echo escape($announcementForm['content']); ?></textarea>
                                </div>

                                <div class="teacher-inline-check teacher-form-grid-full">
                                    <label>
                                        <input type="checkbox" name="is_published" value="1"<?php echo !empty($announcementForm['is_published']) ? ' checked' : ''; ?>>
                                        Publish this announcement
                                    </label>
                                </div>

                                <div class="learner-form-actions">
                                    <button type="submit" class="primary-button"><?php echo $announcementForm['id'] === null ? 'Save Announcement' : 'Update Announcement'; ?></button>
                                    <?php if ($announcementForm['id'] !== null): ?>
                                        <a href="<?php echo escape(route_url('admin.php?module=announcements')); ?>" class="secondary-link">Cancel Edit</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </article>
                    </section>

                    <section class="admin-module-card">
                        <div class="panel-heading compact-heading">
                            <h2>Existing Announcements</h2>
                            <p>Review and manage all announcements.</p>
                        </div>

                        <div class="table-shell">
                            <table class="records-table learner-table admin-management-table announcements-table">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Status</th>
                                        <th>Created By</th>
                                        <th>Published On</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($announcementRows === []): ?>
                                        <tr>
                                            <td colspan="5" class="empty-row">No announcements have been created yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($announcementRows as $announcement): ?>
                                            <tr>
                                                <td><?php echo escape($announcement['title']); ?></td>
                                                <td><span class="table-status"><?php echo !empty($announcement['is_published']) ? 'Published' : 'Draft'; ?></span></td>
                                                <td><?php echo escape($announcement['username'] ?? 'N/A'); ?></td>
                                                <td><?php echo escape($announcement['published_at'] !== null ? date('M j, Y', strtotime($announcement['published_at'])) : '-'); ?></td>
                                                <td>
                                                    <div class="table-actions">
                                                        <a href="<?php echo escape(route_url('admin.php?module=announcements&edit_announcement_id=' . $announcement['id'])); ?>" class="secondary-link small-link">Edit</a>
                                                        <form method="post" class="inline-form" onsubmit="return confirm('Delete this announcement?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token()); ?>">
                                                            <input type="hidden" name="form_action" value="delete_announcement">
                                                            <input type="hidden" name="announcement_id" value="<?php echo escape((string) $announcement['id']); ?>">
                                                            <button type="submit" class="danger-button">Delete</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php elseif ($module === 'reported_issues'): ?>
                    <header class="admin-page-header admin-page-header-reported-issues">
                        <div class="admin-page-title">
                            <img class="school-logo header-logo" src="<?php echo escape(school_logo_url()); ?>" alt="School logo">
                            <div class="header-copy">
                                <p class="eyebrow">System</p>
                                <h2>Reported Issues</h2>
                                <p>Review and manage issues reported by teachers.</p>
                            </div>
                        </div>
                    </header>

                    <?php if ($issueFlash !== null): ?>
                        <div class="alert <?php echo escape($issueFlash['type']); ?>"><?php echo escape($issueFlash['message']); ?></div>
                    <?php endif; ?>

                    <article class="admin-module-card">
                        <div class="panel-heading compact-heading">
                            <h2>Reported Issues List</h2>
                            <p>All issues reported by teachers.</p>
                        </div>

                        <div class="table-shell reported-issues-table-shell">
                            <table class="records-table learner-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Subject</th>
                                        <th>Description</th>
                                        <th>Reported By</th>
                                        <th>Status</th>
                                        <th>Reported On</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($issueRows === []): ?>
                                        <tr>
                                            <td colspan="7" class="empty-row">No issues have been reported yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($issueRows as $issue): ?>
                                            <tr>
                                                <td><?php echo escape((string) $issue['id']); ?></td>
                                                <td><?php echo escape($issue['subject']); ?></td>
                                                <td><?php echo escape(substr($issue['description'], 0, 100)) . (strlen($issue['description']) > 100 ? '...' : ''); ?></td>
                                                <td><?php echo escape($issue['reporter_name'] . ' (' . $issue['username'] . ')'); ?></td>
                                                <td><span class="table-status"><?php echo escape(ucfirst($issue['status'])); ?></span></td>
                                                <td><?php echo escape(date('M j, Y H:i', strtotime($issue['created_at']))); ?></td>
                                                <td>
                                                    <div class="table-actions">
                                                        <form method="post" class="inline-form">
                                                            <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token()); ?>">
                                                            <input type="hidden" name="form_action" value="update_issue_status">
                                                            <input type="hidden" name="issue_id" value="<?php echo escape((string) $issue['id']); ?>">
                                                            <select name="status" onchange="this.form.submit()">
                                                                <option value="open"<?php echo $issue['status'] === 'open' ? ' selected' : ''; ?>>Open</option>
                                                                <option value="in_progress"<?php echo $issue['status'] === 'in_progress' ? ' selected' : ''; ?>>In Progress</option>
                                                                <option value="resolved"<?php echo $issue['status'] === 'resolved' ? ' selected' : ''; ?>>Resolved</option>
                                                                <option value="closed"<?php echo $issue['status'] === 'closed' ? ' selected' : ''; ?>>Closed</option>
                                                            </select>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>
                <?php elseif ($module === 'password_resets'): ?>
                    <header class="admin-page-header">
                        <div class="admin-page-title">
                            <img class="school-logo header-logo" src="<?php echo escape(school_logo_url()); ?>" alt="School logo">
                            <div class="header-copy">
                                <p class="eyebrow">System</p>
                                <h2>Password Resets</h2>
                                <p>Approve or deny user requests for password resets.</p>
                            </div>
                        </div>
                    </header>

                    <?php if ($passwordResetFlash !== null): ?>
                        <div class="alert <?php echo escape($passwordResetFlash['type']); ?>"><?php echo escape($passwordResetFlash['message']); ?></div>
                    <?php endif; ?>

                    <article class="admin-module-card">
                        <div class="panel-heading compact-heading">
                            <h2>Pending Requests</h2>
                            <p>Approving a request will generate a new temporary password.</p>
                        </div>

                        <div class="table-shell">
                            <table class="records-table learner-table password-reset-table">
                                <thead>
                                    <tr>
                                        <th>Requested At</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($pendingPasswordResets === []): ?>
                                        <tr>
                                            <td colspan="5" class="empty-row">No pending password reset requests.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($pendingPasswordResets as $request): ?>
                                            <tr>
                                                <td><?php echo escape(date('M j, Y H:i', strtotime($request['requested_at']))); ?></td>
                                                <td><?php echo escape($request['username']); ?></td>
                                                <td><?php echo escape($request['email']); ?></td>
                                                <td><?php echo escape(ucfirst($request['role'])); ?></td>
                                                <td>
                                                    <div class="table-actions">
                                                        <form method="post" class="inline-form" onsubmit="return confirm('Approve this request and reset the password?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token()); ?>">
                                                            <input type="hidden" name="form_action" value="approve_reset">
                                                            <input type="hidden" name="request_id" value="<?php echo escape((string) $request['id']); ?>">
                                                            <button type="submit" class="primary-button small-link">Approve</button>
                                                        </form>
                                                        <form method="post" class="inline-form" onsubmit="return confirm('Deny this password reset request?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token()); ?>">
                                                            <input type="hidden" name="form_action" value="deny_reset">
                                                            <input type="hidden" name="request_id" value="<?php echo escape((string) $request['id']); ?>">
                                                            <button type="submit" class="danger-button">Deny</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>
                <?php elseif ($module === 'settings'): ?>
                    <header class="admin-page-header">
                        <div class="admin-page-title">
                            <img class="school-logo header-logo" src="<?php echo escape(school_logo_url()); ?>" alt="School logo">
                            <div class="header-copy">
                                <p class="eyebrow">System</p>
                                <h2>Settings</h2>
                                <p>Manage system settings and view logs.</p>
                            </div>
                        </div>
                    </header>

                    <?php if ($settingsFlash !== null): ?>
                        <div class="alert <?php echo escape($settingsFlash['type']); ?>"><?php echo escape($settingsFlash['message']); ?></div>
                    <?php endif; ?>

                    <article class="admin-module-card">
                        <div class="panel-heading compact-heading">
                            <h2>Theme Customization</h2>
                            <p>Choose the color scheme used across the portal.</p>
                        </div>
                        <form method="post" class="learner-form-grid">
                            <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token()); ?>">
                            <input type="hidden" name="form_action" value="save_theme">

                            <div class="teacher-form-grid-full">
                                <label>Select Theme</label>
                                <div class="option-checklist">
                                    <?php foreach (theme_predefined_sets() as $key => $theme): ?>
                                        <label class="check-card">
                                            <input type="radio" name="theme_key" value="<?php echo escape($key); ?>"<?php echo $activeThemeKey === $key ? ' checked' : ''; ?>>
                                            <span class="theme-choice-copy">
                                                <strong><?php echo escape($theme['name']); ?></strong>
                                                <small>Accent, background, and interface colors</small>
                                                <span class="theme-swatch-row" aria-hidden="true">
                                                    <i class="theme-swatch" style="--swatch-color: <?php echo escape($theme['colors']['theme_color_accent']); ?>;"></i>
                                                    <i class="theme-swatch" style="--swatch-color: <?php echo escape($theme['colors']['theme_color_bg']); ?>;"></i>
                                                    <i class="theme-swatch" style="--swatch-color: <?php echo escape($theme['colors']['theme_color_surface_strong']); ?>;"></i>
                                                </span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="learner-form-actions">
                                <button type="submit" class="primary-button">Save Theme</button>
                            </div>
                        </form>
                    </article>

                    <article class="admin-module-card">
                        <div class="panel-heading compact-heading">
                            <h2>System Entry Logs</h2>
                            <p>Recent logins into the system, including successful and failed attempts. Total successful logins today: <?php echo escape((string) $stats['today_logins']); ?></p>
                        </div>

                        <div class="table-shell">
                            <table class="records-table admin-log-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Identity</th>
                                        <th>Name</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($systemLoginLogs === []): ?>
                                        <tr>
                                            <td colspan="6" class="empty-row">No system login logs are available yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($systemLoginLogs as $log): ?>
                                            <tr>
                                                <td><?php echo escape(date('Y-m-d', strtotime($log['logged_in_at']))); ?></td>
                                                <td><?php echo escape(date('h:i:s A', strtotime($log['logged_in_at']))); ?></td>
                                                <td><?php echo escape($log['identity_value']); ?></td>
                                                <td><?php echo escape($log['full_name_snapshot'] !== null && $log['full_name_snapshot'] !== '' ? $log['full_name_snapshot'] : ($log['username_snapshot'] ?? '-')); ?></td>
                                                <td><?php echo escape($log['role_snapshot'] !== null && $log['role_snapshot'] !== '' ? ucfirst($log['role_snapshot']) : '-'); ?></td>
                                                <td><span class="table-status"><?php echo escape(ucfirst($log['login_status'])); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>
                <?php endif; ?>
            </section>
        </section>
    </main>
    <script src="<?php echo escape(asset_url('assets/js/admin.js')); ?>"></script>
</body>
</html>
