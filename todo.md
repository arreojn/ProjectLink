# Student Portal TODO

## Planning

- [ ] Confirm student portal scope and required screens
- [ ] Define student data visibility and privacy rules
- [ ] Confirm how student accounts will be created and linked to learners

## Authentication and Roles

- [ ] Add the `student` role to role validation and dashboard routing
- [ ] Create student login and logout flow
- [ ] Link each student account to one active learner record
- [ ] Prevent students from viewing other learners' records
- [ ] Add password change and account recovery support

## Student Portal

- [ ] Create `student.php` portal entry point
- [ ] Build a student dashboard with current school year context
- [ ] Add personal profile view
- [ ] Add read-only grades view
- [ ] Add read-only attendance view
- [ ] Add announcements view
- [ ] Add responsive navigation and mobile layout

## Data and Security

- [ ] Add learner-scoped query helpers for grades
- [ ] Add learner-scoped query helpers for attendance
- [ ] Restrict health, disability, and guidance data by policy
- [ ] Apply CSRF protection to all student forms
- [ ] Escape all displayed learner and school data
- [ ] Verify inactive, transferred, and unenrolled student access behavior

## Testing

- [ ] Test student login with valid and invalid credentials
- [ ] Test access isolation between student accounts
- [ ] Test current and historical school-year data handling
- [ ] Test grades and attendance with empty data states
- [ ] Test announcements on desktop and mobile layouts
- [ ] Run PHP syntax validation for all changed files
- [ ] Test logout and session expiration

## Rollout

- [ ] Add student account management for administrators
- [ ] Add student portal link to appropriate dashboards
- [ ] Document student account setup and privacy rules
- [ ] Verify production database backups before enabling the role
