CREATE DATABASE IF NOT EXISTS project_link
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE project_link;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(120) NOT NULL UNIQUE,
    first_name VARCHAR(100) NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'attendance', 'teacher', 'parent', 'learner', 'student', 'health', 'guidance') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_users_role_active (role, is_active),
    CONSTRAINT chk_users_is_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    contact_number VARCHAR(30) NULL,
    address TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_parents_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS learners (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL UNIQUE,
    learner_number VARCHAR(30) NOT NULL UNIQUE,
    lrn VARCHAR(30) NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    birthdate DATE NULL,
    mother_tongue VARCHAR(120) NULL,
    religion VARCHAR(120) NULL,
    address_house_number VARCHAR(120) NULL,
    address_barangay VARCHAR(120) NULL,
    address_city_municipality VARCHAR(120) NULL,
    address_province VARCHAR(120) NULL,
    has_disability TINYINT(1) NOT NULL DEFAULT 0,
    disability_basis ENUM('diagnosis', 'manifestation') NULL,
    disability_type VARCHAR(255) NULL,
    sex ENUM('male', 'female') NULL,
    current_status ENUM('active', 'inactive', 'graduated', 'transferred') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_learners_status_sex_name (current_status, sex, last_name, first_name),
    KEY idx_learners_lrn_name (lrn, last_name, first_name),
    CONSTRAINT fk_learners_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL,
    CONSTRAINT chk_learners_has_disability CHECK (has_disability IN (0, 1)),
    CONSTRAINT chk_learners_lrn_length CHECK (lrn IS NULL OR CHAR_LENGTH(lrn) = 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parent_learner_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id INT UNSIGNED NOT NULL,
    learner_id INT UNSIGNED NOT NULL,
    relationship VARCHAR(50) NOT NULL,
    is_primary_contact TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_parent_learner (parent_id, learner_id),
    KEY idx_parent_learner_links_learner (learner_id),
    CONSTRAINT fk_parent_learner_parent
        FOREIGN KEY (parent_id) REFERENCES parents(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_parent_learner_learner
        FOREIGN KEY (learner_id) REFERENCES learners(id)
        ON DELETE CASCADE,
    CONSTRAINT chk_parent_learner_primary CHECK (is_primary_contact IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS school_years (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(20) NOT NULL UNIQUE,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    current_marker TINYINT(1) GENERATED ALWAYS AS (CASE WHEN is_current = 1 THEN 1 ELSE NULL END) STORED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_school_year_current (current_marker),
    KEY idx_school_years_current_start (is_current, start_date),
    CONSTRAINT chk_school_year_current CHECK (is_current IN (0, 1)),
    CONSTRAINT chk_school_year_dates CHECK (end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    grade_level VARCHAR(20) NOT NULL,
    school_year_id INT UNSIGNED NOT NULL,
    adviser_name VARCHAR(150) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_section_year (name, grade_level, school_year_id),
    UNIQUE KEY uq_sections_id_year (id, school_year_id),
    KEY idx_sections_school_year_grade (school_year_id, grade_level, name),
    CONSTRAINT fk_sections_school_year
        FOREIGN KEY (school_year_id) REFERENCES school_years(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teacher_section_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_user_id INT UNSIGNED NOT NULL,
    section_id INT UNSIGNED NOT NULL UNIQUE,
    school_year_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_teacher_assignment_teacher_year (teacher_user_id, school_year_id),
    KEY idx_teacher_assignment_school_year (school_year_id),
    CONSTRAINT fk_teacher_assignment_user
        FOREIGN KEY (teacher_user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_teacher_assignment_section_year
        FOREIGN KEY (section_id, school_year_id) REFERENCES sections(id, school_year_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_teacher_assignment_school_year
        FOREIGN KEY (school_year_id) REFERENCES school_years(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teacher_class_schedules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_user_id INT UNSIGNED NOT NULL,
    school_year_id INT UNSIGNED NOT NULL,
    day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday') NOT NULL,
    time_start TIME NOT NULL,
    time_end TIME NOT NULL,
    subject VARCHAR(150) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_teacher_schedule_slot (teacher_user_id, school_year_id, day_of_week, time_start, time_end),
    KEY idx_teacher_schedule_teacher_year (teacher_user_id, school_year_id),
    CONSTRAINT fk_teacher_schedule_user
        FOREIGN KEY (teacher_user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_teacher_schedule_school_year
        FOREIGN KEY (school_year_id) REFERENCES school_years(id)
        ON DELETE CASCADE,
    CONSTRAINT chk_teacher_schedule_time CHECK (time_end > time_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_login_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    identity_value VARCHAR(120) NOT NULL,
    username_snapshot VARCHAR(50) NULL,
    full_name_snapshot VARCHAR(255) NULL,
    role_snapshot VARCHAR(50) NULL,
    login_status ENUM('success', 'failed') NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    logged_in_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_auth_login_status_time (login_status, logged_in_at),
    KEY idx_auth_login_user_time (user_id, logged_in_at),
    CONSTRAINT fk_auth_login_logs_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'approved', 'denied') NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by_admin_id INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    new_password_snapshot VARCHAR(255) NULL,
    KEY idx_password_resets_status_requested (status, requested_at),
    KEY idx_password_resets_user_status (user_id, status),
    CONSTRAINT fk_password_resets_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_password_resets_reviewed_by
        FOREIGN KEY (reviewed_by_admin_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    created_by_user_id INT UNSIGNED NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_announcements_published (is_published, published_at),
    KEY idx_announcements_created_by (created_by_user_id),
    CONSTRAINT fk_announcement_user
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL,
    CONSTRAINT chk_announcements_published CHECK (is_published IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reported_issues (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    subject VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('open', 'in_progress', 'resolved', 'closed') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_reported_issues_status_created (status, created_at),
    KEY idx_reported_issues_user (user_id),
    CONSTRAINT fk_reported_issues_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS learner_enrollments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    learner_id INT UNSIGNED NOT NULL,
    school_year_id INT UNSIGNED NOT NULL,
    grade_level VARCHAR(20) NOT NULL,
    section_id INT UNSIGNED NULL,
    enrollment_status ENUM('enrolled', 'completed', 'transferred_out', 'dropped') NOT NULL DEFAULT 'enrolled',
    enrolled_at DATE NOT NULL,
    completed_at DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_learner_school_year (learner_id, school_year_id),
    UNIQUE KEY uq_enrollment_id_year (id, school_year_id),
    KEY idx_enrollment_school_section_status (school_year_id, section_id, enrollment_status),
    KEY idx_enrollment_section_year (section_id, school_year_id),
    CONSTRAINT fk_enrollment_learner
        FOREIGN KEY (learner_id) REFERENCES learners(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_enrollment_school_year
        FOREIGN KEY (school_year_id) REFERENCES school_years(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_enrollment_section_year
        FOREIGN KEY (section_id, school_year_id) REFERENCES sections(id, school_year_id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_enrollment_completed_at CHECK (completed_at IS NULL OR completed_at >= enrolled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS learner_health_measurements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    learner_enrollment_id INT UNSIGNED NOT NULL UNIQUE,
    height_cm DECIMAL(5,2) NULL,
    weight_kg DECIMAL(5,2) NULL,
    recorded_on DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_health_measurement_enrollment
        FOREIGN KEY (learner_enrollment_id) REFERENCES learner_enrollments(id)
        ON DELETE CASCADE,
    CONSTRAINT chk_health_measurement_height CHECK (height_cm IS NULL OR height_cm > 0),
    CONSTRAINT chk_health_measurement_weight CHECK (weight_kg IS NULL OR weight_kg > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS learner_deworming_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    learner_enrollment_id INT UNSIGNED NOT NULL,
    dose_number TINYINT UNSIGNED NOT NULL,
    administered_on DATE NOT NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_deworming_enrollment_dose (learner_enrollment_id, dose_number),
    KEY idx_deworming_created_by (created_by_user_id),
    CONSTRAINT fk_deworming_enrollment
        FOREIGN KEY (learner_enrollment_id) REFERENCES learner_enrollments(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_deworming_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL,
    CONSTRAINT chk_deworming_dose_number CHECK (dose_number IN (1, 2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feeding_program_recipients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    learner_enrollment_id INT UNSIGNED NOT NULL UNIQUE,
    school_year_id INT UNSIGNED NOT NULL,
    enrolled_on DATE NOT NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_feeding_school_year (school_year_id),
    KEY idx_feeding_created_by (created_by_user_id),
    CONSTRAINT fk_feeding_recipient_enrollment_year
        FOREIGN KEY (learner_enrollment_id, school_year_id) REFERENCES learner_enrollments(id, school_year_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_feeding_recipient_school_year
        FOREIGN KEY (school_year_id) REFERENCES school_years(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_feeding_recipient_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS learner_subject_grades (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    learner_enrollment_id INT UNSIGNED NOT NULL,
    subject_name VARCHAR(160) NOT NULL,
    quarter_1_grade DECIMAL(5,2) NULL,
    quarter_2_grade DECIMAL(5,2) NULL,
    quarter_3_grade DECIMAL(5,2) NULL,
    quarter_4_grade DECIMAL(5,2) NULL,
    first_semester_average DECIMAL(5,2) NULL,
    second_semester_average DECIMAL(5,2) NULL,
    final_average DECIMAL(5,2) NULL,
    remarks VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_enrollment_subject (learner_enrollment_id, subject_name),
    CONSTRAINT fk_subject_grades_enrollment
        FOREIGN KEY (learner_enrollment_id) REFERENCES learner_enrollments(id)
        ON DELETE CASCADE,
    CONSTRAINT chk_subject_grades_range CHECK (
        (quarter_1_grade IS NULL OR quarter_1_grade BETWEEN 0 AND 100)
        AND (quarter_2_grade IS NULL OR quarter_2_grade BETWEEN 0 AND 100)
        AND (quarter_3_grade IS NULL OR quarter_3_grade BETWEEN 0 AND 100)
        AND (quarter_4_grade IS NULL OR quarter_4_grade BETWEEN 0 AND 100)
        AND (first_semester_average IS NULL OR first_semester_average BETWEEN 0 AND 100)
        AND (second_semester_average IS NULL OR second_semester_average BETWEEN 0 AND 100)
        AND (final_average IS NULL OR final_average BETWEEN 0 AND 100)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_legends (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL,
    color_hex CHAR(7) NULL,
    counts_as_present TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT chk_attendance_legend_present CHECK (counts_as_present IN (0, 1)),
    CONSTRAINT chk_attendance_legend_color CHECK (
        color_hex IS NULL OR (CHAR_LENGTH(color_hex) = 7 AND LEFT(color_hex, 1) = '#')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    learner_enrollment_id INT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    legend_id INT UNSIGNED NOT NULL,
    remarks VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_day (learner_enrollment_id, attendance_date),
    KEY idx_attendance_date_legend (attendance_date, legend_id),
    KEY idx_attendance_legend (legend_id),
    CONSTRAINT fk_attendance_enrollment
        FOREIGN KEY (learner_enrollment_id) REFERENCES learner_enrollments(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_attendance_legend
        FOREIGN KEY (legend_id) REFERENCES attendance_legends(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS learner_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    learner_user_id INT UNSIGNED NOT NULL,
    adviser_user_id INT UNSIGNED NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('unread', 'read', 'replied') NOT NULL DEFAULT 'unread',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_learner_messages_adviser_status (adviser_user_id, status, created_at),
    KEY idx_learner_messages_learner_created (learner_user_id, created_at),
    CONSTRAINT fk_learner_messages_learner
        FOREIGN KEY (learner_user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_learner_messages_adviser
        FOREIGN KEY (adviser_user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guidance_cases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    learner_id INT UNSIGNED NOT NULL,
    case_number VARCHAR(40) NOT NULL,
    date_opened DATE NOT NULL,
    referral_source VARCHAR(120) NULL,
    referral_reason TEXT NULL,
    counseling_type VARCHAR(80) NULL,
    counseling_date DATE NULL,
    presenting_concern TEXT NULL,
    intervention_plan TEXT NULL,
    follow_up_schedule DATE NULL,
    parent_conference VARCHAR(20) NULL,
    remarks TEXT NULL,
    case_status VARCHAR(40) NOT NULL DEFAULT 'Open',
    date_closed DATE NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_guidance_case_number (case_number),
    KEY idx_guidance_cases_learner_status (learner_id, case_status),
    KEY idx_guidance_cases_follow_up (follow_up_schedule, case_status),
    KEY idx_guidance_cases_created_by (created_by_user_id),
    CONSTRAINT fk_guidance_case_learner
        FOREIGN KEY (learner_id) REFERENCES learners(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_guidance_case_user
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL,
    CONSTRAINT chk_guidance_case_dates CHECK (date_closed IS NULL OR date_closed >= date_opened)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guidance_counseling_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    guidance_case_id INT UNSIGNED NOT NULL,
    session_date DATE NOT NULL,
    session_type VARCHAR(80) NULL,
    notes TEXT NULL,
    follow_up_required TINYINT(1) NOT NULL DEFAULT 0,
    created_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_guidance_sessions_case_date (guidance_case_id, session_date),
    KEY idx_guidance_sessions_created_by (created_by_user_id),
    CONSTRAINT fk_guidance_session_case
        FOREIGN KEY (guidance_case_id) REFERENCES guidance_cases(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_guidance_session_user
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL,
    CONSTRAINT chk_guidance_session_followup CHECK (follow_up_required IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guidance_referrals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    guidance_case_id INT UNSIGNED NOT NULL,
    source_role VARCHAR(80) NULL,
    referral_reason TEXT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'Pending',
    action_taken TEXT NULL,
    outcome_recommendation TEXT NULL,
    referred_on DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_guidance_referrals_case_status (guidance_case_id, status),
    CONSTRAINT fk_guidance_referral_case
        FOREIGN KEY (guidance_case_id) REFERENCES guidance_cases(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guidance_interventions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    guidance_case_id INT UNSIGNED NOT NULL,
    intervention_title VARCHAR(160) NOT NULL,
    intervention_type VARCHAR(80) NULL,
    scheduled_on DATE NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'Planned',
    completion_date DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_guidance_interventions_case_status (guidance_case_id, status),
    KEY idx_guidance_interventions_schedule (scheduled_on, status),
    CONSTRAINT fk_guidance_intervention_case
        FOREIGN KEY (guidance_case_id) REFERENCES guidance_cases(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO attendance_legends (code, label, color_hex, counts_as_present) VALUES
('P', 'Present', '#2F855A', 1),
('L', 'Late', '#DD6B20', 1),
('A', 'Absent', '#C53030', 0),
('E', 'Excused', '#3182CE', 0);

INSERT IGNORE INTO users (username, email, first_name, middle_name, last_name, password_hash, role, is_active) VALUES
('attendance_admin', 'attendance@projectlink.local', 'Attendance', NULL, 'Admin', '$2y$10$v7qjEmsTgoPzJGUOGr0aL.YGT1PAB6j/yuqMcg6evfkLSqrDwaDLC', 'admin', 1),
('portal_admin', 'admin@projectlink.local', 'Portal', NULL, 'Admin', '$2y$10$8vrYlwt9a/sRLnGWs01UDO5UYQ1iisGoy3m2LiOtne99.IuOR4n7G', 'admin', 1),
('attendance_user', 'attendance-user@projectlink.local', 'Attendance', NULL, 'User', '$2y$10$v7qjEmsTgoPzJGUOGr0aL.YGT1PAB6j/yuqMcg6evfkLSqrDwaDLC', 'attendance', 1),
('health_coordinator', 'health@projectlink.local', 'Health', NULL, 'Coordinator', '$2y$10$cLV/PRK6X6TVzrXWbGsRQe40bsHF6HXj./M8DLmLgIvln/.yDUHoS', 'health', 1),
('guidance_counselor', 'guidance@projectlink.local', 'Guidance', NULL, 'Counselor', '$2y$10$1m0SQotwjOPioWYBvqnl2uSlZLwG4c2rek28cVQPiqyI97.1h/xQK', 'guidance', 1),
('teacher_mabini', 'teacher.mabini@projectlink.local', 'Mabini', 'Demo', 'Teacher', '$2y$10$MowOCypAlH70pG7wAMix3.cddt8d.B66dIBvCfhptP958vYLiu5bi', 'teacher', 1),
('demo_parent', 'parent@projectlink.local', 'Ana', 'Santos', 'Dela Cruz', '$2y$10$bRKpueTjVab73zPzrBUyBe.3iRircjMowF66LfB1UuA/QZv4Vw9T.', 'parent', 1);

INSERT IGNORE INTO school_years (label, start_date, end_date, is_current) VALUES
('2026-2027', '2026-06-01', '2027-03-31', 1);

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
('attendance_scan_mode', 'daily_scan');

INSERT IGNORE INTO sections (name, grade_level, school_year_id, adviser_name)
SELECT 'Mabini', 'Grade 7', sy.id, 'Adviser Demo'
FROM school_years sy
WHERE sy.label = '2026-2027';

INSERT IGNORE INTO teacher_section_assignments (
    teacher_user_id,
    section_id,
    school_year_id
)
SELECT
    u.id,
    s.id,
    sy.id
FROM users u
INNER JOIN school_years sy ON sy.label = '2026-2027'
INNER JOIN sections s ON s.name = 'Mabini' AND s.school_year_id = sy.id
WHERE u.username = 'teacher_mabini';

INSERT IGNORE INTO learners (learner_number, lrn, first_name, middle_name, last_name, current_status) VALUES
('LP-0001', '123456789012', 'Juan', 'Santos', 'Dela Cruz', 'active'),
('LP-0002', '987654321098', 'Maria', 'Reyes', 'Lopez', 'active');

INSERT IGNORE INTO parents (
    user_id,
    first_name,
    middle_name,
    last_name,
    contact_number,
    address
)
SELECT
    u.id,
    'Ana',
    'Santos',
    'Dela Cruz',
    '09171234567',
    'ProjectLink Demo Household'
FROM users u
WHERE u.username = 'demo_parent';

INSERT IGNORE INTO parent_learner_links (
    parent_id,
    learner_id,
    relationship,
    is_primary_contact
)
SELECT
    p.id,
    l.id,
    'Mother',
    CASE WHEN l.lrn = '123456789012' THEN 1 ELSE 0 END
FROM parents p
INNER JOIN learners l ON l.lrn IN ('123456789012', '987654321098')
INNER JOIN users u ON u.id = p.user_id
WHERE u.username = 'demo_parent';

INSERT IGNORE INTO learner_enrollments (
    learner_id,
    school_year_id,
    grade_level,
    section_id,
    enrollment_status,
    enrolled_at
)
SELECT
    l.id,
    sy.id,
    'Grade 7',
    s.id,
    'enrolled',
    '2026-06-15'
FROM learners l
INNER JOIN school_years sy ON sy.label = '2026-2027'
LEFT JOIN sections s ON s.name = 'Mabini' AND s.school_year_id = sy.id
WHERE l.lrn IN ('123456789012', '987654321098');

INSERT IGNORE INTO learner_health_measurements (
    learner_enrollment_id,
    height_cm,
    weight_kg,
    recorded_on
)
SELECT
    le.id,
    CASE WHEN l.lrn = '123456789012' THEN 142.00 ELSE 138.00 END,
    CASE WHEN l.lrn = '123456789012' THEN 36.50 ELSE 34.25 END,
    '2026-07-01'
FROM learner_enrollments le
INNER JOIN learners l ON l.id = le.learner_id
INNER JOIN school_years sy ON sy.id = le.school_year_id
WHERE sy.label = '2026-2027'
  AND l.lrn IN ('123456789012', '987654321098');

INSERT IGNORE INTO learner_deworming_records (
    learner_enrollment_id,
    dose_number,
    administered_on,
    created_by_user_id
)
SELECT
    le.id,
    1,
    '2026-07-10',
    u.id
FROM learner_enrollments le
INNER JOIN learners l ON l.id = le.learner_id
INNER JOIN school_years sy ON sy.id = le.school_year_id
INNER JOIN users u ON u.username = 'health_coordinator'
WHERE sy.label = '2026-2027'
  AND l.lrn IN ('123456789012', '987654321098');

INSERT IGNORE INTO feeding_program_recipients (
    learner_enrollment_id,
    school_year_id,
    enrolled_on,
    created_by_user_id
)
SELECT
    le.id,
    le.school_year_id,
    '2026-07-12',
    u.id
FROM learner_enrollments le
INNER JOIN learners l ON l.id = le.learner_id
INNER JOIN school_years sy ON sy.id = le.school_year_id
INNER JOIN users u ON u.username = 'health_coordinator'
WHERE sy.label = '2026-2027'
  AND l.lrn = '123456789012';

INSERT IGNORE INTO attendance_records (
    learner_enrollment_id,
    attendance_date,
    legend_id,
    remarks
)
SELECT
    le.id,
    seeded.attendance_date,
    al.id,
    'Demo attendance seed'
FROM learner_enrollments le
INNER JOIN learners l ON l.id = le.learner_id
INNER JOIN (
    SELECT '2026-06-18' AS attendance_date, 'P' AS legend_code
    UNION ALL
    SELECT '2026-06-19', 'L'
    UNION ALL
    SELECT '2026-06-20', 'P'
    UNION ALL
    SELECT '2026-06-21', 'E'
    UNION ALL
    SELECT '2026-06-22', 'P'
) AS seeded
INNER JOIN attendance_legends al ON al.code = seeded.legend_code
WHERE l.lrn = '123456789012';
