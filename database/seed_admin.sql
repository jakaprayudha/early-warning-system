-- Local development account only. Sign in with admin@example.test / Admin123!
-- Remove this account or replace the password before deploying outside local development.
INSERT INTO users (name, email, password_hash, role, status, created_at)
VALUES (
    'Admin Dummy',
    'admin@example.test',
    '$2y$12$PmALU7FImctGlmPnIEYZyOiyK6/1crvQzQFk2ivpZgEyyXKagyucK',
    'system_admin',
    'active',
    CAST(strftime('%s', 'now') AS INTEGER)
);
