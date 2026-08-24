<?php

declare(strict_types=1);

function student_portal_bootstrap(): void
{
    static $bootstrapped = false;

    if ($bootstrapped) {
        return;
    }

    auth_bootstrap();

    if (!auth_table_exists('users')) {
        $bootstrapped = true;
        return;
    }

    auth_ensure_user_role('learner');
    auth_ensure_user_role('student');

    $bootstrapped = true;
}

function student_profile_for_user(int $userId): ?array
{
    $statement = database()->prepare(
        'SELECT
            l.id,
            l.user_id,
            l.learner_number,
            l.lrn,
            l.first_name,
            l.middle_name,
            l.last_name,
            l.birthdate,
            l.mother_tongue,
            l.religion,
            l.address_house_number,
            l.address_barangay,
            l.address_city_municipality,
            l.address_province,
            l.sex,
            l.current_status,
            CONCAT(
                l.last_name,
                ", ",
                l.first_name,
                IF(l.middle_name IS NULL OR l.middle_name = "", "", CONCAT(" ", l.middle_name))
            ) AS full_name
         FROM learners l
         WHERE l.user_id = :user_id
         LIMIT 1'
    );
    $statement->execute(['user_id' => $userId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

function student_portal_access_allowed(?array $profile, ?array $enrollment): bool
{
    return $profile !== null
        && ($profile['current_status'] ?? '') === 'active'
        && $enrollment !== null
        && ($enrollment['enrollment_status'] ?? '') === 'enrolled';
}

function student_current_enrollment(int $learnerId, ?int $schoolYearId = null): ?array
{
    $schoolYear = $schoolYearId !== null ? ['id' => $schoolYearId] : current_school_year();

    if ($schoolYear === null) {
        return null;
    }

    $statement = database()->prepare(
        'SELECT
            le.id AS learner_enrollment_id,
            le.learner_id,
            le.school_year_id,
            sy.label AS school_year_label,
            le.grade_level,
            COALESCE(s.name, "Unassigned") AS section_name,
            le.enrollment_status,
                le.enrolled_at,
                adviser.id AS adviser_user_id,
                TRIM(CONCAT(
                     COALESCE(adviser.first_name, ""),
                     IF(COALESCE(adviser.middle_name, "") = "", "", CONCAT(" ", adviser.middle_name)),
                     IF(COALESCE(adviser.last_name, "") = "", "", CONCAT(" ", adviser.last_name))
                )) AS adviser_name,
                adviser.email AS adviser_email
         FROM learner_enrollments le
         INNER JOIN school_years sy ON sy.id = le.school_year_id
         LEFT JOIN sections s ON s.id = le.section_id
            LEFT JOIN teacher_section_assignments tsa
                ON tsa.section_id = le.section_id
              AND tsa.school_year_id = le.school_year_id
            LEFT JOIN users adviser
                ON adviser.id = tsa.teacher_user_id
              AND adviser.role = "teacher"
              AND adviser.is_active = 1
         WHERE le.learner_id = :learner_id
           AND le.school_year_id = :school_year_id
                     AND le.enrollment_status = "enrolled"
         ORDER BY le.enrolled_at DESC, le.id DESC
         LIMIT 1'
    );
    $statement->execute([
        'learner_id' => $learnerId,
        'school_year_id' => (int) $schoolYear['id'],
    ]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

function student_grade_rows(int $learnerId, ?int $schoolYearId = null): array
{
    $sql = 'SELECT
            le.school_year_id,
            sy.label AS school_year_label,
            le.grade_level,
            COALESCE(s.name, "Unassigned") AS section_name,
            lsg.subject_name,
            lsg.quarter_1_grade,
            lsg.quarter_2_grade,
            lsg.quarter_3_grade,
            lsg.quarter_4_grade,
            lsg.first_semester_average,
            lsg.second_semester_average,
            lsg.final_average,
            lsg.remarks
         FROM learner_subject_grades lsg
         INNER JOIN learner_enrollments le ON le.id = lsg.learner_enrollment_id
         INNER JOIN school_years sy ON sy.id = le.school_year_id
         LEFT JOIN sections s ON s.id = le.section_id
         WHERE le.learner_id = :learner_id';

    $params = ['learner_id' => $learnerId];

    if ($schoolYearId !== null) {
        $sql .= ' AND le.school_year_id = :school_year_id';
        $params['school_year_id'] = $schoolYearId;
    }

    $sql .= ' ORDER BY sy.start_date DESC, lsg.subject_name ASC';

    $statement = database()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function student_attendance_rows(int $learnerId, ?int $schoolYearId = null): array
{
    $sql = 'SELECT
            ar.attendance_date,
            COALESCE(al.code, "") AS attendance_code,
            COALESCE(al.label, "No record") AS attendance_status,
            COALESCE(al.counts_as_present, 0) AS counts_as_present
         FROM learner_enrollments le
         LEFT JOIN attendance_records ar ON ar.learner_enrollment_id = le.id
         LEFT JOIN attendance_legends al ON al.id = ar.legend_id
         WHERE le.learner_id = :learner_id';

    $params = ['learner_id' => $learnerId];

    if ($schoolYearId !== null) {
        $sql .= ' AND le.school_year_id = :school_year_id';
        $params['school_year_id'] = $schoolYearId;
    }

    $sql .= ' ORDER BY ar.attendance_date DESC, ar.id DESC LIMIT 30';

    $statement = database()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function student_attendance_month_options(int $learnerId, ?int $schoolYearId = null): array
{
    $sql = 'SELECT DISTINCT DATE_FORMAT(ar.attendance_date, "%Y-%m") AS attendance_month
            FROM learner_enrollments le
            INNER JOIN attendance_records ar ON ar.learner_enrollment_id = le.id
            WHERE le.learner_id = :learner_id';
    $params = ['learner_id' => $learnerId];

    if ($schoolYearId !== null) {
        $sql .= ' AND le.school_year_id = :school_year_id';
        $params['school_year_id'] = $schoolYearId;
    }

    $sql .= ' ORDER BY attendance_month DESC';
    $statement = database()->prepare($sql);
    $statement->execute($params);

    return array_values(array_filter(array_map(
        static fn (array $row): string => (string) ($row['attendance_month'] ?? ''),
        $statement->fetchAll()
    ), static fn (string $month): bool => $month !== ''));
}

function student_attendance_month_summary(int $learnerId, ?int $schoolYearId, string $month): array
{
    $month = preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : date('Y-m');
    $sql = 'SELECT
                COALESCE(al.label, "No record") AS attendance_status,
                COUNT(ar.id) AS total_days
            FROM learner_enrollments le
            LEFT JOIN attendance_records ar ON ar.learner_enrollment_id = le.id
            LEFT JOIN attendance_legends al ON al.id = ar.legend_id
            WHERE le.learner_id = :learner_id
              AND DATE_FORMAT(ar.attendance_date, "%Y-%m") = :month';
    $params = ['learner_id' => $learnerId, 'month' => $month];

    if ($schoolYearId !== null) {
        $sql .= ' AND le.school_year_id = :school_year_id';
        $params['school_year_id'] = $schoolYearId;
    }

    $sql .= ' GROUP BY al.id, al.label ORDER BY al.label ASC';
    $statement = database()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function student_health_summary(int $learnerId, ?int $schoolYearId = null): ?array
{
    $schoolYear = $schoolYearId !== null ? ['id' => $schoolYearId] : current_school_year();

    if ($schoolYear === null) {
        return null;
    }

    $statement = database()->prepare(
        'SELECT
            hm.height_cm,
            hm.weight_kg,
            hm.recorded_on,
            COUNT(DISTINCT CASE WHEN dr.dose_number = 1 THEN dr.id END) AS first_dose_recorded,
            COUNT(DISTINCT CASE WHEN dr.dose_number = 2 THEN dr.id END) AS second_dose_recorded,
            CASE WHEN fpr.id IS NULL THEN 0 ELSE 1 END AS feeding_program_recipient
         FROM learner_enrollments le
         LEFT JOIN learner_health_measurements hm ON hm.learner_enrollment_id = le.id
         LEFT JOIN learner_deworming_records dr ON dr.learner_enrollment_id = le.id
         LEFT JOIN feeding_program_recipients fpr ON fpr.learner_enrollment_id = le.id
         WHERE le.learner_id = :learner_id
           AND le.school_year_id = :school_year_id
           AND le.enrollment_status = "enrolled"
         GROUP BY le.id, hm.id, hm.height_cm, hm.weight_kg, hm.recorded_on, fpr.id
         LIMIT 1'
    );
    $statement->execute([
        'learner_id' => $learnerId,
        'school_year_id' => (int) $schoolYear['id'],
    ]);
    $row = $statement->fetch();

    if ($row === false) {
        return null;
    }

    $height = (float) ($row['height_cm'] ?? 0);
    $weight = (float) ($row['weight_kg'] ?? 0);
    $heightMeters = $height / 100;
    $row['bmi'] = $heightMeters > 0 && $weight > 0 ? round($weight / ($heightMeters * $heightMeters), 2) : null;

    return $row;
}

function student_announcement_rows(int $learnerId): array
{
    $statement = database()->prepare(
        'SELECT DISTINCT
            a.*,
            u.username,
            u.role
         FROM announcements a
         INNER JOIN users u ON u.id = a.created_by_user_id
         INNER JOIN teacher_section_assignments tsa ON tsa.teacher_user_id = u.id
         INNER JOIN learner_enrollments le
            ON le.section_id = tsa.section_id
           AND le.school_year_id = tsa.school_year_id
         WHERE le.learner_id = :learner_id
           AND a.is_published = 1
           AND u.role = "teacher"
         ORDER BY a.published_at DESC, a.created_at DESC'
    );
    $statement->execute(['learner_id' => $learnerId]);

    return $statement->fetchAll();
}

function student_portal_format_date(?string $value, string $format = 'D, M j, Y'): string
{
    if ($value === null || trim((string) $value) === '') {
        return '-';
    }

    $timestamp = strtotime((string) $value);

    return $timestamp === false ? (string) $value : date($format, $timestamp);
}

function student_portal_format_time(?string $value): string
{
    if ($value === null || trim((string) $value) === '') {
        return '-';
    }

    $timestamp = strtotime((string) $value);

    return $timestamp === false ? (string) $value : date('h:i A', $timestamp);
}
