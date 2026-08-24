<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/learners.php';
require_once __DIR__ . '/app/students.php';
require_once __DIR__ . '/app/theme_settings.php';

student_portal_bootstrap();

$user = require_roles(['student']);
$profile = student_profile_for_user((int) $user['id']);
$schoolYear = current_school_year();
$schoolYearId = isset($schoolYear['id']) ? (int) $schoolYear['id'] : null;
$enrollment = $profile !== null ? student_current_enrollment((int) $profile['id'], $schoolYearId) : null;

if (!student_portal_access_allowed($profile, $enrollment)) {
    logout_user();
    redirect('index.php');
}

$gradeRows = $profile !== null ? student_grade_rows((int) $profile['id'], $schoolYearId) : [];
$attendanceRows = $profile !== null ? student_attendance_rows((int) $profile['id'], $schoolYearId) : [];
$announcementRows = $profile !== null ? student_announcement_rows((int) $profile['id']) : [];

theme_settings_bootstrap();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escape(APP_NAME); ?> Student Portal</title>
    <?php echo theme_stylesheet_markup(); ?>
    <link rel="stylesheet" href="<?php echo escape(asset_url('assets/css/app.css')); ?>">
    <style>
        .student-shell { max-width: 1280px; margin: 0 auto; padding: 24px 18px 42px; }
        .student-grid { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr); gap: 18px; }
        .student-panel { background: var(--surface); border: 1px solid var(--line); border-radius: 26px; box-shadow: var(--shadow); padding: 20px; }
        .student-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; }
        .student-header h1 { margin: 0; font-size: clamp(2rem, 2vw, 2.6rem); }
        .student-subtitle { color: var(--muted); margin-top: 4px; }
        .student-summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin: 14px 0 18px; }
        .student-card { background: rgba(255,255,255,0.8); border: 1px solid rgba(31,41,51,0.06); border-radius: 18px; padding: 14px; }
        .student-card .label { font-size: 0.68rem; letter-spacing: 0.14em; text-transform: uppercase; color: var(--accent); font-weight: 800; }
        .student-card strong { display: block; margin-top: 8px; font-size: 1.35rem; }
        .student-card small { color: var(--muted); }
        .detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 18px; }
        .detail-item { display: grid; gap: 4px; }
        .detail-item dt { font-size: 0.73rem; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted); }
        .detail-item dd { margin: 0; font-weight: 700; }
        .student-table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        .student-table th, .student-table td { padding: 10px 12px; border-bottom: 1px solid rgba(31,41,51,0.08); text-align: left; font-size: 0.85rem; }
        .student-table th { background: rgba(31,41,51,0.04); text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.08em; color: var(--muted); }
        .alert { border-radius: 14px; padding: 12px 14px; margin: 12px 0; }
        .alert.neutral { background: rgba(96, 165, 250, 0.1); border: 1px solid rgba(96, 165, 250, 0.25); color: var(--ink); }
        .announcement-list { display: grid; gap: 12px; }
        .announcement-item { border: 1px solid rgba(31,41,51,0.08); border-radius: 18px; padding: 14px; background: rgba(255,255,255,0.82); }
        .announcement-item h4 { margin: 0 0 6px; }
        .announcement-meta { font-size: 0.8rem; color: var(--muted); margin-bottom: 8px; }
        @media (max-width: 900px) {
            .student-summary-grid, .student-grid, .detail-grid { grid-template-columns: 1fr; }
            .student-header { display: block; }
        }
    </style>
</head>
<body class="dashboard-body">
    <main class="dashboard-shell student-shell">
        <header class="topbar">
            <div class="header-title-block">
                <img class="school-logo" src="<?php echo escape(school_logo_url()); ?>" alt="School logo">
                <div class="header-copy">
                    <p class="eyebrow">Student Portal</p>
                    <h1>My Dashboard</h1>
                </div>
            </div>
            <div class="topbar-actions">
                <p class="signed-in-as">Signed in as <?php echo escape($user['username']); ?></p>
                <a href="<?php echo escape(route_url('change_password.php')); ?>" class="secondary-link">Change Password</a>
                <a href="<?php echo escape(route_url('logout.php')); ?>" class="secondary-link">Logout</a>
            </div>
        </header>

        <?php if ($profile === null): ?>
            <div class="alert neutral">This account is not linked to a learner record yet. Please contact the school administrator.</div>
        <?php else: ?>
            <section class="student-summary-grid">
                <div class="student-card">
                    <span class="label">School Year</span>
                    <strong><?php echo escape($schoolYear['label'] ?? 'No active school year'); ?></strong>
                    <small>Current context</small>
                </div>
                <div class="student-card">
                    <span class="label">Grade Level</span>
                    <strong><?php echo escape($enrollment['grade_level'] ?? 'Unassigned'); ?></strong>
                    <small>Current enrollment</small>
                </div>
                <div class="student-card">
                    <span class="label">Section</span>
                    <strong><?php echo escape($enrollment['section_name'] ?? 'Unassigned'); ?></strong>
                    <small>Advisory section</small>
                </div>
                <div class="student-card">
                    <span class="label">Status</span>
                    <strong><?php echo escape($profile['current_status'] ?? 'active'); ?></strong>
                    <small>Account status</small>
                </div>
            </section>

            <section class="student-grid">
                <article class="student-panel">
                    <div class="student-header">
                        <div>
                            <h1><?php echo escape(trim(($profile['first_name'] ?? '') . ' ' . ($profile['middle_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''))); ?></h1>
                            <div class="student-subtitle">Student Profile</div>
                        </div>
                    </div>
                    <dl class="detail-grid">
                        <div class="detail-item">
                            <dt>LRN</dt>
                            <dd><?php echo escape((string) ($profile['lrn'] ?? '-')); ?></dd>
                        </div>
                        <div class="detail-item">
                            <dt>Learner Number</dt>
                            <dd><?php echo escape((string) ($profile['learner_number'] ?? '-')); ?></dd>
                        </div>
                        <div class="detail-item">
                            <dt>Birthdate</dt>
                            <dd><?php echo escape(student_portal_format_date($profile['birthdate'] ?? null)); ?></dd>
                        </div>
                        <div class="detail-item">
                            <dt>Sex</dt>
                            <dd><?php echo escape((string) ($profile['sex'] ?? '-')); ?></dd>
                        </div>
                        <div class="detail-item">
                            <dt>Mother Tongue</dt>
                            <dd><?php echo escape((string) ($profile['mother_tongue'] ?? '-')); ?></dd>
                        </div>
                        <div class="detail-item">
                            <dt>Religion</dt>
                            <dd><?php echo escape((string) ($profile['religion'] ?? '-')); ?></dd>
                        </div>
                        <div class="detail-item">
                            <dt>Address</dt>
                            <dd><?php echo escape(trim(implode(', ', array_filter([
                                $profile['address_house_number'] ?? '',
                                $profile['address_barangay'] ?? '',
                                $profile['address_city_municipality'] ?? '',
                                $profile['address_province'] ?? '',
                            ], static fn ($value): bool => trim((string) $value) !== '')))); ?></dd>
                        </div>
                    </dl>
                </article>

                <article class="student-panel">
                    <div class="student-header">
                        <div>
                            <h2>Announcements</h2>
                            <div class="student-subtitle">Latest school updates</div>
                        </div>
                    </div>
                    <?php if ($announcementRows === []): ?>
                        <div class="alert neutral">No published announcements are available for your section yet.</div>
                    <?php else: ?>
                        <div class="announcement-list">
                            <?php foreach ($announcementRows as $announcement): ?>
                                <article class="announcement-item">
                                    <h4><?php echo escape((string) ($announcement['title'] ?? 'Announcement')); ?></h4>
                                    <div class="announcement-meta">
                                        <?php echo escape((string) ($announcement['role'] ?? 'teacher')); ?> •
                                        <?php echo escape(student_portal_format_date($announcement['published_at'] ?? $announcement['created_at'] ?? null)); ?>
                                    </div>
                                    <div><?php echo nl2br(escape((string) ($announcement['content'] ?? ''))); ?></div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            </section>

            <section class="student-grid" style="margin-top: 18px;">
                <article class="student-panel">
                    <div class="student-header">
                        <div>
                            <h2>Grades</h2>
                            <div class="student-subtitle">Current school year record</div>
                        </div>
                    </div>
                    <?php if ($gradeRows === []): ?>
                        <div class="alert neutral">No grade records are available yet for this school year.</div>
                    <?php else: ?>
                        <div class="table-shell">
                            <table class="student-table">
                                <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Q1</th>
                                    <th>Q2</th>
                                    <th>Q3</th>
                                    <th>Q4</th>
                                    <th>Final</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($gradeRows as $gradeRow): ?>
                                    <tr>
                                        <td><?php echo escape((string) ($gradeRow['subject_name'] ?? '-')); ?></td>
                                        <td><?php echo escape((string) ($gradeRow['quarter_1_grade'] ?? '-')); ?></td>
                                        <td><?php echo escape((string) ($gradeRow['quarter_2_grade'] ?? '-')); ?></td>
                                        <td><?php echo escape((string) ($gradeRow['quarter_3_grade'] ?? '-')); ?></td>
                                        <td><?php echo escape((string) ($gradeRow['quarter_4_grade'] ?? '-')); ?></td>
                                        <td><?php echo escape((string) ($gradeRow['final_average'] ?? '-')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="student-panel">
                    <div class="student-header">
                        <div>
                            <h2>Attendance</h2>
                            <div class="student-subtitle">Latest recorded attendance</div>
                        </div>
                    </div>
                    <?php if ($attendanceRows === []): ?>
                        <div class="alert neutral">No attendance entries are available yet.</div>
                    <?php else: ?>
                        <div class="table-shell">
                            <table class="student-table">
                                <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>AM</th>
                                    <th>PM</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($attendanceRows as $attendance): ?>
                                    <tr>
                                        <td><?php echo escape(student_portal_format_date($attendance['attendance_date'] ?? null, 'M d, Y')); ?></td>
                                        <td><?php echo escape((string) ($attendance['attendance_status'] ?? '-')); ?></td>
                                        <td><?php echo escape((string) ($attendance['am_time_in'] ?? '-') . ' / ' . (string) ($attendance['am_time_out'] ?? '-')); ?></td>
                                        <td><?php echo escape((string) ($attendance['pm_time_in'] ?? '-') . ' / ' . (string) ($attendance['pm_time_out'] ?? '-')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
