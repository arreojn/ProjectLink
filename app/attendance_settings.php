<?php

declare(strict_types=1);

function attendance_scan_mode_options(): array
{
    return [
        'daily_scan' => [
            'label' => 'Daily Attendance Scan',
            'description' => 'Records one attendance scan per learner per day.',
        ],
    ];
}

function attendance_settings_bootstrap(): void
{
    static $bootstrapped = false;

    if ($bootstrapped) {
        return;
    }

    $pdo = database();
    $timeColumns = ['am_time_in', 'am_time_out', 'pm_time_in', 'pm_time_out'];
    foreach ($timeColumns as $column) {
        $columnStatement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = :schema_name
               AND TABLE_NAME = \'attendance_records\'
               AND COLUMN_NAME = :column_name'
        );
        $columnStatement->execute([
            'schema_name' => DB_NAME,
            'column_name' => $column,
        ]);

        if ((int) $columnStatement->fetchColumn() > 0) {
            $pdo->exec('ALTER TABLE attendance_records DROP COLUMN `' . $column . '`');
        }
    }

    $scanLogsTableStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = :schema_name
           AND TABLE_NAME = \'attendance_scan_logs\''
    );
    $scanLogsTableStatement->execute(['schema_name' => DB_NAME]);

    if ((int) $scanLogsTableStatement->fetchColumn() > 0) {
        $pdo->exec('DROP TABLE attendance_scan_logs');
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS system_settings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )'
    );

    $statement = $pdo->prepare(
        'INSERT IGNORE INTO system_settings (setting_key, setting_value)
         VALUES (:setting_key, :setting_value)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([
        'setting_key' => 'attendance_scan_mode',
        'setting_value' => 'daily_scan',
    ]);

    $bootstrapped = true;
}

function attendance_scan_mode_normalize(string $mode): string
{
    $options = attendance_scan_mode_options();

    return array_key_exists($mode, $options) ? $mode : 'daily_scan';
}

function attendance_scan_mode_details(?string $mode = null): array
{
    $options = attendance_scan_mode_options();
    $modeKey = attendance_scan_mode_normalize($mode ?? attendance_scan_mode());

    return [
        'key' => $modeKey,
        'label' => $options[$modeKey]['label'],
        'description' => $options[$modeKey]['description'],
    ];
}

function attendance_scan_mode(): string
{
    attendance_settings_bootstrap();

    $statement = database()->prepare(
        'SELECT setting_value
         FROM system_settings
         WHERE setting_key = :setting_key
         LIMIT 1'
    );
    $statement->execute(['setting_key' => 'attendance_scan_mode']);
    $row = $statement->fetch();

    return attendance_scan_mode_normalize((string) ($row['setting_value'] ?? 'daily_scan'));
}

function attendance_scan_mode_set(string $mode): array
{
    attendance_settings_bootstrap();

    $normalizedMode = attendance_scan_mode_normalize(trim($mode));

    if ($normalizedMode !== trim($mode)) {
        throw new RuntimeException('Invalid attendance scan mode.');
    }

    $statement = database()->prepare(
        'INSERT INTO system_settings (setting_key, setting_value)
         VALUES (:setting_key, :setting_value)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([
        'setting_key' => 'attendance_scan_mode',
        'setting_value' => $normalizedMode,
    ]);

    return attendance_scan_mode_details($normalizedMode);
}

function attendance_can_manage_scan_mode(array $user): bool
{
    return ($user['role'] ?? '') === 'admin';
}

function attendance_record_summary(
    ?string $attendanceDate,
    ?string $attendanceCode
): array {
    if ($attendanceCode === 'P' || $attendanceCode === 'L') {
        return [
            'code' => $attendanceCode,
            'label' => $attendanceCode === 'L' ? 'Late' : 'Present',
            'present_units' => 1.0,
            'absent_units' => 0.0,
            'is_late' => $attendanceCode === 'L',
        ];
    }

    if ($attendanceDate === null || $attendanceDate === '') {
        return [
            'code' => '', 'label' => 'No record', 'present_units' => 0.0,
            'absent_units' => 0.0, 'is_late' => false,
        ];
    }

    return [
        'code' => $attendanceCode ?: 'A',
        'label' => $attendanceCode === 'E' ? 'Excused' : 'Absent',
        'present_units' => 0.0,
        'absent_units' => 1.0,
        'is_late' => false,
    ];
}

function attendance_scan_windows(): array
{
    return [['range' => 'Any time', 'label' => 'Daily attendance scan']];
}

function attendance_strict_scan_slot_for_time(string $currentTime): ?array
{
    return ['column' => 'daily_scan', 'label' => 'Daily attendance scan'];
}

function attendance_sequence_scan_slot(string $currentTime, array $record): array
{
    return [
        'success' => true,
        'column' => 'daily_scan',
        'label' => 'Daily attendance scan',
    ];
}

function attendance_resolve_scan_slot(string $mode, string $currentTime, array $record): array
{
    $normalizedMode = attendance_scan_mode_normalize($mode);

    return attendance_strict_scan_slot_for_time($currentTime);
}
