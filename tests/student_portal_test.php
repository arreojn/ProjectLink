<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/students.php';

function test_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$initialPasswordHash = password_hash('123456789012', PASSWORD_DEFAULT);
$changedPasswordHash = password_hash('new-password', PASSWORD_DEFAULT);

test_expect(
    auth_requires_password_change([
        'role' => 'learner',
        'username' => '123456789012',
        'password_hash' => $initialPasswordHash,
    ]),
    'Learner using the LRN password must be required to change it.'
);

test_expect(
    !auth_requires_password_change([
        'role' => 'learner',
        'username' => '123456789012',
        'password_hash' => $changedPasswordHash,
    ]),
    'Learner with a changed password must not be forced to change it again.'
);

test_expect(
    !auth_requires_password_change([
        'role' => 'teacher',
        'username' => '123456789012',
        'password_hash' => $initialPasswordHash,
    ]),
    'Non-learner accounts must not inherit learner first-login enforcement.'
);

test_expect(
    student_portal_access_allowed(
        ['current_status' => 'active'],
        ['enrollment_status' => 'enrolled']
    ),
    'Active learners with enrolled current records must be allowed into the portal.'
);

test_expect(
    !student_portal_access_allowed(
        ['current_status' => 'transferred'],
        ['enrollment_status' => 'enrolled']
    ),
    'Transferred learners must not be allowed into the portal.'
);

test_expect(
    !student_portal_access_allowed(
        ['current_status' => 'inactive'],
        ['enrollment_status' => 'enrolled']
    ),
    'Inactive learners must not be allowed into the portal.'
);

test_expect(
    !student_portal_access_allowed(
        ['current_status' => 'active'],
        ['enrollment_status' => 'completed']
    ),
    'Learners without an enrolled current record must not be allowed into the portal.'
);

test_expect(
    !student_portal_access_allowed(null, null),
    'Unlinked accounts must not be allowed into the learner portal.'
);

echo "Student portal tests passed.\n";
