<?php

declare(strict_types=1);

function teacher_schedule_bootstrap(): void
{
    static $bootstrapped = false;

    if ($bootstrapped) {
        return;
    }

    database()->exec(
        'CREATE TABLE IF NOT EXISTS teacher_class_schedules (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            teacher_user_id INT UNSIGNED NOT NULL,
            school_year_id INT UNSIGNED NOT NULL,
            day_of_week ENUM(\'Monday\', \'Tuesday\', \'Wednesday\', \'Thursday\', \'Friday\') NOT NULL,
            time_start TIME NOT NULL,
            time_end TIME NOT NULL,
            subject VARCHAR(150) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_teacher_schedule_slot (teacher_user_id, school_year_id, day_of_week, time_start, time_end),
            CONSTRAINT fk_teacher_schedule_user
                FOREIGN KEY (teacher_user_id) REFERENCES users(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_teacher_schedule_school_year
                FOREIGN KEY (school_year_id) REFERENCES school_years(id)
                ON DELETE CASCADE
        )'
    );

    $bootstrapped = true;
}

function teacher_schedule_days(): array
{
    return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
}

function teacher_schedule_normalize_time(string $value): string
{
    $value = trim($value);
    $time = DateTime::createFromFormat('H:i', $value);

    return $time instanceof DateTime && $time->format('H:i') === $value ? $value . ':00' : '';
}

function teacher_schedule_save(int $teacherUserId, array $payload): void
{
    teacher_schedule_bootstrap();

    $start = teacher_schedule_normalize_time((string) ($payload['time_start'] ?? ''));
    $end = teacher_schedule_normalize_time((string) ($payload['time_end'] ?? ''));
    $subject = trim((string) ($payload['subject'] ?? ''));
    $days = $payload['days'] ?? [];
    $schoolYear = require_current_school_year();

    if ($start === '' || $end === '') {
        throw new RuntimeException('Enter a valid start and end time.');
    }

    if ($start >= $end) {
        throw new RuntimeException('The end time must be later than the start time.');
    }

    if ($subject === '' || strlen($subject) > 150) {
        throw new RuntimeException('Enter a subject up to 150 characters.');
    }

    if (!is_array($days)) {
        $days = [];
    }

    $days = array_values(array_intersect(teacher_schedule_days(), $days));
    if ($days === []) {
        throw new RuntimeException('Select at least one day from Monday to Friday.');
    }

    $statement = database()->prepare(
        'INSERT INTO teacher_class_schedules (teacher_user_id, school_year_id, day_of_week, time_start, time_end, subject)
         VALUES (:teacher_user_id, :school_year_id, :day_of_week, :time_start, :time_end, :subject)
         ON DUPLICATE KEY UPDATE subject = VALUES(subject), updated_at = CURRENT_TIMESTAMP'
    );

    foreach ($days as $day) {
        $statement->execute([
            'teacher_user_id' => $teacherUserId,
            'school_year_id' => (int) $schoolYear['id'],
            'day_of_week' => $day,
            'time_start' => $start,
            'time_end' => $end,
            'subject' => $subject,
        ]);
    }
}

function teacher_schedule_delete(int $teacherUserId, int $scheduleId): void
{
    teacher_schedule_bootstrap();

    $statement = database()->prepare(
        'DELETE FROM teacher_class_schedules
         WHERE id = :id AND teacher_user_id = :teacher_user_id'
    );
    $statement->execute([
        'id' => $scheduleId,
        'teacher_user_id' => $teacherUserId,
    ]);
}

function teacher_schedule_rows(int $teacherUserId): array
{
    teacher_schedule_bootstrap();
    $schoolYear = require_current_school_year();

    $statement = database()->prepare(
        'SELECT id, day_of_week, time_start, time_end, subject
         FROM teacher_class_schedules
         WHERE teacher_user_id = :teacher_user_id
           AND school_year_id = :school_year_id
         ORDER BY time_start ASC, time_end ASC,
            FIELD(day_of_week, \'Monday\', \'Tuesday\', \'Wednesday\', \'Thursday\', \'Friday\') ASC,
            subject ASC'
    );
    $statement->execute([
        'teacher_user_id' => $teacherUserId,
        'school_year_id' => (int) $schoolYear['id'],
    ]);

    return $statement->fetchAll();
}

function teacher_schedule_time_label(string $time): string
{
    $timestamp = strtotime($time);

    return $timestamp === false ? $time : date('g:i A', $timestamp);
}

function learner_schedule_rows(int $learnerUserId): array
{
        teacher_schedule_bootstrap();

        $statement = database()->prepare(
                'SELECT
                        tcs.day_of_week,
                        tcs.time_start,
                        tcs.time_end,
                        tcs.subject
                 FROM learners l
                 INNER JOIN learner_enrollments le
                        ON le.learner_id = l.id
                     AND le.enrollment_status = \'enrolled\'
                 INNER JOIN school_years sy
                        ON sy.id = le.school_year_id
                     AND sy.is_current = 1
                 INNER JOIN teacher_section_assignments tsa
                        ON tsa.section_id = le.section_id
                     AND tsa.school_year_id = le.school_year_id
                 INNER JOIN teacher_class_schedules tcs
                        ON tcs.teacher_user_id = tsa.teacher_user_id
                     AND tcs.school_year_id = le.school_year_id
                 WHERE l.user_id = :learner_user_id
                     AND l.current_status = \'active\'
                 ORDER BY tcs.time_start ASC, tcs.time_end ASC,
                        FIELD(tcs.day_of_week, \'Monday\', \'Tuesday\', \'Wednesday\', \'Thursday\', \'Friday\') ASC,
                        tcs.subject ASC'
        );
        $statement->execute(['learner_user_id' => $learnerUserId]);

        return $statement->fetchAll();
}

function parent_schedule_rows(int $parentUserId, int $learnerId): array
{
        teacher_schedule_bootstrap();

        $statement = database()->prepare(
                'SELECT
                        tcs.day_of_week,
                        tcs.time_start,
                        tcs.time_end,
                        tcs.subject
                 FROM parents p
                 INNER JOIN parent_learner_links pll
                        ON pll.parent_id = p.id
                     AND pll.learner_id = :learner_id
                 INNER JOIN learners l ON l.id = pll.learner_id
                 INNER JOIN learner_enrollments le
                        ON le.learner_id = l.id
                     AND le.enrollment_status = \'enrolled\'
                 INNER JOIN school_years sy
                        ON sy.id = le.school_year_id
                     AND sy.is_current = 1
                 INNER JOIN teacher_section_assignments tsa
                        ON tsa.section_id = le.section_id
                     AND tsa.school_year_id = le.school_year_id
                 INNER JOIN teacher_class_schedules tcs
                        ON tcs.teacher_user_id = tsa.teacher_user_id
                     AND tcs.school_year_id = le.school_year_id
                 WHERE p.user_id = :parent_user_id
                     AND l.current_status = \'active\'
                 ORDER BY tcs.time_start ASC, tcs.time_end ASC,
                        FIELD(tcs.day_of_week, \'Monday\', \'Tuesday\', \'Wednesday\', \'Thursday\', \'Friday\') ASC,
                        tcs.subject ASC'
        );
        $statement->execute([
                'parent_user_id' => $parentUserId,
                'learner_id' => $learnerId,
        ]);

        return $statement->fetchAll();
}

    function admin_schedule_sections(): array
    {
        teacher_schedule_bootstrap();
        $schoolYear = require_current_school_year();

        $statement = database()->prepare(
            'SELECT id, name, grade_level
             FROM sections
             WHERE school_year_id = :school_year_id
                 ORDER BY FIELD(grade_level, "Grade 7", "Grade 8", "Grade 9", "Grade 10", "Grade 11", "Grade 12"),
                     grade_level ASC, name ASC'
        );
        $statement->execute(['school_year_id' => (int) $schoolYear['id']]);

        return $statement->fetchAll();
    }

    function admin_schedule_rows_for_section(int $sectionId): array
    {
        teacher_schedule_bootstrap();
        $schoolYear = require_current_school_year();

        $statement = database()->prepare(
            'SELECT
                tcs.day_of_week,
                tcs.time_start,
                tcs.time_end,
                tcs.subject,
                adviser.username AS adviser_username,
                TRIM(CONCAT(
                    COALESCE(adviser.first_name, ""),
                    IF(COALESCE(adviser.middle_name, "") = "", "", CONCAT(" ", adviser.middle_name)),
                    IF(COALESCE(adviser.last_name, "") = "", "", CONCAT(" ", adviser.last_name))
                )) AS adviser_name
             FROM teacher_section_assignments tsa
             INNER JOIN teacher_class_schedules tcs
                ON tcs.teacher_user_id = tsa.teacher_user_id
               AND tcs.school_year_id = tsa.school_year_id
             INNER JOIN users adviser ON adviser.id = tsa.teacher_user_id
             WHERE tsa.section_id = :section_id
               AND tsa.school_year_id = :school_year_id
             ORDER BY tcs.time_start ASC, tcs.time_end ASC,
                FIELD(tcs.day_of_week, "Monday", "Tuesday", "Wednesday", "Thursday", "Friday") ASC,
                tcs.subject ASC'
        );
        $statement->execute([
            'section_id' => $sectionId,
            'school_year_id' => (int) $schoolYear['id'],
        ]);

        return $statement->fetchAll();
    }
