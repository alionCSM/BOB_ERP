-- Per-username lockout for login rate-limiter (AuthController).
-- Prior code had a runtime ALTER TABLE; this migration replaces it.

ALTER TABLE bb_login_attempts
    ADD COLUMN username VARCHAR(150) DEFAULT NULL;

ALTER TABLE bb_login_attempts
    ADD INDEX idx_username_time (username, attempted_at);
