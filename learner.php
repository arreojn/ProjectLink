<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/learners.php';
require_once __DIR__ . '/app/students.php';
require_once __DIR__ . '/app/learner_messages.php';
require_once __DIR__ . '/app/schedules.php';
require_once __DIR__ . '/app/theme_settings.php';

student_portal_bootstrap();

$user = require_roles(['learner']);
$profile = student_profile_for_user((int) $user['id']);
$schoolYear = current_school_year();
$schoolYearId = isset($schoolYear['id']) ? (int) $schoolYear['id'] : null;
$enrollment = $profile !== null ? student_current_enrollment((int) $profile['id'], $schoolYearId) : null;

if (!student_portal_access_allowed($profile, $enrollment)) {
    logout_user();
    redirect('index.php');
}

$messageFlash = flash_get('learner_message');
$messageErrors = [];
$adviser = learner_adviser_for_user((int) $user['id']);
$healthSummary = student_health_summary((int) $profile['id'], $schoolYearId);
$gradeRows = $profile !== null ? student_grade_rows((int) $profile['id']) : [];
$attendanceMonths = $profile !== null ? student_attendance_month_options((int) $profile['id'], $schoolYearId) : [];
$selectedAttendanceMonth = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['attendance_month'] ?? '')) === 1
    ? (string) $_GET['attendance_month']
    : ($attendanceMonths[0] ?? date('Y-m'));
$attendanceSummary = $profile !== null
    ? student_attendance_month_summary((int) $profile['id'], $schoolYearId, $selectedAttendanceMonth)
    : [];
$announcementRows = $profile !== null ? student_announcement_rows((int) $profile['id']) : [];
$messageRows = learner_messages_for_user((int) $user['id']);
$scheduleRows = learner_schedule_rows((int) $user['id']);
$scheduleGrid = [];
foreach ($scheduleRows as $scheduleRow) {
    $timeKey = (string) $scheduleRow['time_start'] . '|' . (string) $scheduleRow['time_end'];
    if (!isset($scheduleGrid[$timeKey])) {
        $scheduleGrid[$timeKey] = [
            'time_start' => (string) $scheduleRow['time_start'],
            'time_end' => (string) $scheduleRow['time_end'],
            'days' => [],
        ];
    }
    $scheduleGrid[$timeKey]['days'][(string) $scheduleRow['day_of_week']][] = (string) $scheduleRow['subject'];
}
$gradeGroups = [];
foreach ($gradeRows as $gradeRow) {
    $gradeKey = (string) ($gradeRow['grade_level'] ?? 'Unassigned');
    $gradeGroups[$gradeKey][] = $gradeRow;
}

if (is_post() && ($_POST['form_action'] ?? '') === 'send_adviser_message') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Invalid form token. Please refresh the page.');
        }

        learner_message_send(
            (int) $user['id'],
            (int) ($_POST['adviser_user_id'] ?? 0),
            (string) ($_POST['subject'] ?? ''),
            (string) ($_POST['message'] ?? '')
        );
        flash_set('learner_message', 'Your message was sent to your adviser.');
        redirect('learner.php');
    } catch (Throwable $exception) {
        $messageErrors[] = $exception->getMessage();
    }
}

theme_settings_bootstrap();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escape(APP_NAME); ?> Learner Portal</title>
    <?php echo theme_stylesheet_markup(); ?>
    <link rel="stylesheet" href="<?php echo escape(asset_url('assets/css/app.css')); ?>">
    <style>
        .student-shell { max-width: 1280px; margin: 0 auto; padding: 24px 18px 42px; }
        .student-grid { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr); gap: 18px; }
        .student-grid.single-panel, .student-grid.records-grid { grid-template-columns: minmax(0, 1fr); }
        .student-panel { background: var(--surface); border: 1px solid var(--line); border-radius: 26px; box-shadow: var(--shadow); padding: 20px; }
        .student-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; }
        .student-header h1 { margin: 0; font-size: clamp(2rem, 2vw, 2.6rem); }
        .student-subtitle { color: var(--muted); margin-top: 4px; }
        .student-summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin: 14px 0 18px; }
        .student-card { background: rgba(255,255,255,0.8); border: 1px solid rgba(31,41,51,0.06); border-radius: 18px; padding: 14px; }
        .student-card .label { font-size: 0.68rem; letter-spacing: 0.14em; text-transform: uppercase; color: var(--accent); font-weight: 800; }
        .student-card strong { display: block; margin-top: 8px; font-size: 1.35rem; }
        .student-card small { color: var(--muted); }
        .learner-profile-photo { width: 112px; height: 112px; object-fit: cover; border-radius: 18px; border: 3px solid var(--accent); }
        .profile-identity { display: flex; align-items: center; gap: 16px; }
        .health-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
        .health-stat { padding: 12px; background: rgba(255,255,255,0.72); border: 1px solid var(--line); border-radius: 12px; }
        .health-stat strong { display: block; margin-top: 5px; }
        .message-form { display: grid; gap: 10px; }
        .message-log { display: grid; gap: 10px; }
        .message-log-item { padding: 12px; border: 1px solid var(--line); border-radius: 12px; background: rgba(255,255,255,0.72); }
        .message-log-item strong { display: block; }
        .grade-group { margin-top: 18px; }
        .grade-group h3 { margin: 0; padding: 10px 12px; border-left: 4px solid var(--accent); background: rgba(180, 83, 9, 0.08); }
        .attendance-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
        .attendance-stat { padding: 14px; border: 1px solid var(--line); border-radius: 12px; text-align: center; }
        .attendance-stat strong { display: block; margin-top: 6px; font-size: 1.5rem; }
        .announcement-modal { position: fixed; inset: 0; z-index: 20; display: grid; place-items: center; padding: 20px; background: rgba(15, 23, 42, 0.55); }
        .announcement-modal[hidden] { display: none; }
        .announcement-dialog { width: min(680px, 100%); max-height: min(80vh, 700px); overflow: auto; background: var(--surface); border: 1px solid var(--line); border-radius: 18px; padding: 20px; box-shadow: var(--shadow); }
        .announcement-dialog-header { display: flex; justify-content: space-between; gap: 12px; align-items: center; }
        .announcement-close { border: 1px solid var(--line); background: transparent; color: var(--ink); border-radius: 8px; padding: 7px 10px; cursor: pointer; }
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
            .student-summary-grid, .student-grid, .detail-grid, .health-summary, .attendance-summary { grid-template-columns: 1fr; }
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
                    <p class="eyebrow">Learner Portal</p>
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
            <div class="alert neutral">This learner account is not linked to a learner record yet. Please contact the teacher or administrator.</div>
        <?php else: ?>
            <?php if ($messageFlash !== null): ?><div class="alert success"><?php echo escape($messageFlash['message']); ?></div><?php endif; ?>
            <?php foreach ($messageErrors as $messageError): ?><div class="alert error"><?php echo escape($messageError); ?></div><?php endforeach; ?>
            <?php if ($announcementRows !== []): ?>
                <section id="announcement-modal" class="announcement-modal" role="dialog" aria-modal="true" aria-labelledby="announcement-modal-title">
                    <div class="announcement-dialog">
                        <div class="announcement-dialog-header">
                            <h2 id="announcement-modal-title">Announcements</h2>
                            <button type="button" class="announcement-close" data-close-announcements>Close</button>
                        </div>
                        <p class="student-subtitle">Updates for your current section</p>
                    <div class="announcement-list">
                        <?php foreach ($announcementRows as $announcement): ?>
                            <article class="announcement-item">
                                <h4><?php echo escape((string) ($announcement['title'] ?? 'Announcement')); ?></h4>
                                <div class="announcement-meta"><?php echo escape(student_portal_format_date($announcement['published_at'] ?? $announcement['created_at'] ?? null)); ?></div>
                                <div><?php echo nl2br(escape((string) ($announcement['content'] ?? ''))); ?></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    </div>
                </section>
            <?php endif; ?>
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

            <section class="student-grid single-panel">
                <article class="student-panel">
                    <div class="student-header">
                        <div class="profile-identity">
                            <img class="learner-profile-photo" src="<?php echo escape(learner_photo_url($profile['lrn'] ?? null)); ?>" alt="Learner profile photo">
                            <div>
                            <h1><?php echo escape(trim(($profile['first_name'] ?? '') . ' ' . ($profile['middle_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''))); ?></h1>
                            <div class="student-subtitle">Student Profile</div>
                            </div>
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

            </section>

            <section class="student-grid" style="margin-top: 18px;">
                <article class="student-panel">
                    <div class="student-header"><div><h2>Health Summary</h2><div class="student-subtitle">Current school year wellness records</div></div></div>
                    <div class="health-summary">
                        <div class="health-stat"><span>Height</span><strong><?php echo escape($healthSummary !== null && $healthSummary['height_cm'] !== null ? $healthSummary['height_cm'] . ' cm' : 'Not recorded'); ?></strong></div>
                        <div class="health-stat"><span>Weight</span><strong><?php echo escape($healthSummary !== null && $healthSummary['weight_kg'] !== null ? $healthSummary['weight_kg'] . ' kg' : 'Not recorded'); ?></strong></div>
                        <div class="health-stat"><span>BMI</span><strong><?php echo escape($healthSummary !== null && $healthSummary['bmi'] !== null ? (string) $healthSummary['bmi'] : 'Not measured'); ?></strong></div>
                        <div class="health-stat"><span>Deworming</span><strong><?php echo escape($healthSummary !== null && (int) $healthSummary['first_dose_recorded'] > 0 ? '1st dose recorded' : 'No 1st dose record'); ?></strong></div>
                        <div class="health-stat"><span>Second dose</span><strong><?php echo escape($healthSummary !== null && (int) $healthSummary['second_dose_recorded'] > 0 ? 'Recorded' : 'Not recorded'); ?></strong></div>
                        <div class="health-stat"><span>Feeding program</span><strong><?php echo escape($healthSummary !== null && (int) $healthSummary['feeding_program_recipient'] === 1 ? 'Participant' : 'Not enrolled'); ?></strong></div>
                    </div>
                </article>

                <article class="student-panel">
                    <div class="student-header"><div><h2>My Adviser</h2><div class="student-subtitle">Your current section adviser</div></div></div>
                    <?php if ($adviser === null): ?>
                        <div class="alert neutral">No adviser is assigned to your current section.</div>
                    <?php else: ?>
                        <strong><?php echo escape($adviser['adviser_name'] !== '' ? $adviser['adviser_name'] : $adviser['adviser_username']); ?></strong>
                        <span><?php echo escape($adviser['adviser_email'] ?? ''); ?></span>
                        <form method="post" class="message-form">
                            <input type="hidden" name="csrf_token" value="<?php echo escape(csrf_token()); ?>">
                            <input type="hidden" name="form_action" value="send_adviser_message">
                            <input type="hidden" name="adviser_user_id" value="<?php echo escape((string) $adviser['adviser_user_id']); ?>">
                            <label for="adviser_subject">Subject</label>
                            <input id="adviser_subject" name="subject" type="text" maxlength="255" required>
                            <label for="adviser_message">Message</label>
                            <textarea id="adviser_message" name="message" rows="4" required></textarea>
                            <button type="submit" class="primary-button">Contact Adviser</button>
                        </form>
                    <?php endif; ?>
                </article>
            </section>

            <section class="student-grid single-panel" style="margin-top: 18px;">
                <article class="student-panel">
                    <div class="student-header"><div><h2>Message Log</h2><div class="student-subtitle">Messages sent to your adviser</div></div></div>
                    <?php if ($messageRows === []): ?>
                        <div class="alert neutral">No messages sent yet.</div>
                    <?php else: ?>
                        <div class="message-log">
                            <?php foreach ($messageRows as $messageRow): ?>
                                <div class="message-log-item">
                                    <strong><?php echo escape($messageRow['subject']); ?></strong>
                                    <span><?php echo escape($messageRow['adviser_name'] !== '' ? $messageRow['adviser_name'] : 'Adviser'); ?> · <?php echo escape(student_portal_format_date($messageRow['created_at'], 'M d, Y h:i A')); ?> · <?php echo escape(ucfirst($messageRow['status'])); ?></span>
                                    <div><?php echo nl2br(escape($messageRow['message'])); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            </section>

            <section class="student-grid single-panel" style="margin-top: 18px;">
                <article class="student-panel">
                    <div class="student-header">
                        <div>
                            <h2>My Class Schedule</h2>
                            <div class="student-subtitle">Read-only schedule for your current section</div>
                        </div>
                    </div>
                    <div class="table-shell schedule-table-shell">
                        <table class="student-table schedule-table">
                            <thead>
                            <tr>
                                <th>Day/Time</th>
                                <?php foreach (teacher_schedule_days() as $scheduleDay): ?>
                                    <th><?php echo escape($scheduleDay); ?></th>
                                <?php endforeach; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($scheduleGrid === []): ?>
                                <tr><td colspan="6" class="empty-row">No class schedule has been posted yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($scheduleGrid as $scheduleSlot): ?>
                                    <tr>
                                        <th><?php echo escape(teacher_schedule_time_label($scheduleSlot['time_start']) . ' - ' . teacher_schedule_time_label($scheduleSlot['time_end'])); ?></th>
                                        <?php foreach (teacher_schedule_days() as $scheduleDay): ?>
                                            <td>
                                                <?php foreach ($scheduleSlot['days'][$scheduleDay] ?? [] as $scheduleSubject): ?>
                                                    <div class="schedule-cell-entry">
                                                        <div class="schedule-subject"><?php echo escape($scheduleSubject); ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <section class="student-grid records-grid" style="margin-top: 18px;">
                <article class="student-panel attendance-panel">
                    <div class="student-header">
                        <div>
                            <h2>Attendance Summary</h2>
                            <div class="student-subtitle">Select a month to view your attendance totals</div>
                        </div>
                        <form method="get">
                            <label for="attendance_month">Month</label>
                            <select id="attendance_month" name="attendance_month" onchange="this.form.submit()">
                                <?php if ($attendanceMonths === []): ?><option value="<?php echo escape($selectedAttendanceMonth); ?>">No recorded months</option><?php endif; ?>
                                <?php foreach ($attendanceMonths as $attendanceMonth): ?>
                                    <option value="<?php echo escape($attendanceMonth); ?>"<?php echo $selectedAttendanceMonth === $attendanceMonth ? ' selected' : ''; ?>><?php echo escape(date('F Y', strtotime($attendanceMonth . '-01'))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <?php if ($attendanceSummary === []): ?>
                        <div class="alert neutral">No attendance records are available for this month.</div>
                    <?php else: ?>
                        <div class="attendance-summary">
                            <?php foreach ($attendanceSummary as $attendanceStat): ?>
                                <div class="attendance-stat">
                                    <span><?php echo escape($attendanceStat['attendance_status']); ?></span>
                                    <strong><?php echo escape((string) $attendanceStat['total_days']); ?></strong>
                                    <small>day(s)</small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="student-panel grades-panel">
                    <div class="student-header">
                        <div>
                            <h2>Grades</h2>
                            <div class="student-subtitle">Organized by grade level and school year</div>
                        </div>
                    </div>
                    <?php if ($gradeRows === []): ?>
                        <div class="alert neutral">No grade records are available yet for this school year.</div>
                    <?php else: ?>
                        <?php foreach ($gradeGroups as $gradeLevel => $gradeGroupRows): ?>
                            <section class="grade-group">
                                <?php $gradeLabel = preg_match('/^grade\s+/i', (string) $gradeLevel) === 1 ? (string) $gradeLevel : 'Grade ' . (string) $gradeLevel; ?>
                                <?php $gradeSchoolYears = array_values(array_unique(array_filter(array_map(static fn (array $row): string => trim((string) ($row['school_year_label'] ?? '')), $gradeGroupRows)))); ?>
                                <h3><?php echo escape($gradeLabel . ($gradeSchoolYears !== [] ? ' - ' . implode(', ', $gradeSchoolYears) : '')); ?></h3>
                                <div class="table-shell">
                                    <table class="student-table">
                                        <thead><tr><th>Subject</th><th>Q1</th><th>Q2</th><th>Q3</th><th>Q4</th><th>Final</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($gradeGroupRows as $gradeRow): ?>
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
                            </section>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </article>
            </section>
        <?php endif; ?>
    </main>
</body>
<?php if ($announcementRows !== []): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('announcement-modal');
    const closeButton = document.querySelector('[data-close-announcements]');

    if (!modal || !closeButton) {
        return;
    }

    closeButton.addEventListener('click', function () {
        modal.hidden = true;
    });

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            modal.hidden = true;
        }
    });

    closeButton.focus();
});
</script>
<?php endif; ?>
</html>
