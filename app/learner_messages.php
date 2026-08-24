<?php

declare(strict_types=1);

function learner_messages_bootstrap(): void
{
    static $bootstrapped = false;

    if ($bootstrapped) {
        return;
    }

    database()->exec(
        'CREATE TABLE IF NOT EXISTS learner_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            learner_user_id INT UNSIGNED NOT NULL,
            adviser_user_id INT UNSIGNED NOT NULL,
            subject VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            status ENUM(\'unread\', \'read\', \'replied\') NOT NULL DEFAULT \'unread\',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_learner_messages_learner
                FOREIGN KEY (learner_user_id) REFERENCES users(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_learner_messages_adviser
                FOREIGN KEY (adviser_user_id) REFERENCES users(id)
                ON DELETE CASCADE
        )'
    );

    $bootstrapped = true;
}

function learner_adviser_for_user(int $userId): ?array
{
    $statement = database()->prepare(
        'SELECT
            adviser.id AS adviser_user_id,
            adviser.username AS adviser_username,
            adviser.email AS adviser_email,
            TRIM(CONCAT(
                COALESCE(adviser.first_name, \'\'),
                IF(COALESCE(adviser.middle_name, \'\') = \'\', \'\', CONCAT(\' \', adviser.middle_name)),
                IF(COALESCE(adviser.last_name, \'\') = \'\', \'\', CONCAT(\' \', adviser.last_name))
            )) AS adviser_name,
            s.name AS section_name,
            le.grade_level
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
         INNER JOIN users adviser
            ON adviser.id = tsa.teacher_user_id
           AND adviser.role = \'teacher\'
           AND adviser.is_active = 1
         LEFT JOIN sections s ON s.id = le.section_id
         WHERE l.user_id = :user_id
           AND l.current_status = \'active\'
         LIMIT 1'
    );
    $statement->execute(['user_id' => $userId]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

function learner_message_send(int $learnerUserId, int $adviserUserId, string $subject, string $message): void
{
    learner_messages_bootstrap();

    $subject = trim($subject);
    $message = trim($message);

    if ($subject === '' || strlen($subject) > 255) {
        throw new RuntimeException('Enter a message subject up to 255 characters.');
    }

    if ($message === '') {
        throw new RuntimeException('Enter a message to send to your adviser.');
    }

    $access = database()->prepare(
        'SELECT 1
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
         WHERE l.user_id = :learner_user_id
           AND tsa.teacher_user_id = :adviser_user_id
           AND l.current_status = \'active\'
         LIMIT 1'
    );
    $access->execute([
        'learner_user_id' => $learnerUserId,
        'adviser_user_id' => $adviserUserId,
    ]);

    if ($access->fetchColumn() === false) {
        throw new RuntimeException('The selected adviser is not assigned to your current section.');
    }

    $statement = database()->prepare(
        'INSERT INTO learner_messages (learner_user_id, adviser_user_id, subject, message)
         VALUES (:learner_user_id, :adviser_user_id, :subject, :message)'
    );
    $statement->execute([
        'learner_user_id' => $learnerUserId,
        'adviser_user_id' => $adviserUserId,
        'subject' => $subject,
        'message' => $message,
    ]);
}

function learner_messages_for_adviser(int $adviserUserId): array
{
    learner_messages_bootstrap();

    $statement = database()->prepare(
        'SELECT
            lm.id,
            lm.subject,
            lm.message,
            lm.status,
            lm.created_at,
            TRIM(CONCAT(
                COALESCE(learner.first_name, ""),
                IF(COALESCE(learner.middle_name, "") = "", "", CONCAT(" ", learner.middle_name)),
                IF(COALESCE(learner.last_name, "") = "", "", CONCAT(" ", learner.last_name))
            )) AS learner_name,
            learner.lrn
         FROM learner_messages lm
         INNER JOIN users learner_user ON learner_user.id = lm.learner_user_id
         INNER JOIN learners learner ON learner.user_id = learner_user.id
         WHERE lm.adviser_user_id = :adviser_user_id
         ORDER BY lm.created_at DESC, lm.id DESC'
    );
    $statement->execute(['adviser_user_id' => $adviserUserId]);

    return $statement->fetchAll();
}

function learner_messages_for_user(int $learnerUserId): array
{
    learner_messages_bootstrap();

    $statement = database()->prepare(
        'SELECT
            lm.subject,
            lm.message,
            lm.status,
            lm.created_at,
            TRIM(CONCAT(
                COALESCE(adviser.first_name, ""),
                IF(COALESCE(adviser.middle_name, "") = "", "", CONCAT(" ", adviser.middle_name)),
                IF(COALESCE(adviser.last_name, "") = "", "", CONCAT(" ", adviser.last_name))
            )) AS adviser_name
         FROM learner_messages lm
         INNER JOIN users adviser ON adviser.id = lm.adviser_user_id
         WHERE lm.learner_user_id = :learner_user_id
         ORDER BY lm.created_at DESC, lm.id DESC'
    );
    $statement->execute(['learner_user_id' => $learnerUserId]);

    return $statement->fetchAll();
}
