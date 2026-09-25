-- World Explorer — PostgreSQL schema
-- Target: Neon project cold-pond-78659280 / branch production
--
-- Replaces the previous MySQL `globe` database.
-- Safe to re-run: every object is created IF NOT EXISTS.

BEGIN;

-- ---------------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------------
-- id             surrogate key, replaces MySQL AUTO_INCREMENT INT
-- email          lower-cased by the application (config.php normalize_email)
--                so that uniqueness matches MySQL's case-insensitive collation
-- verified       BOOLEAN replaces TINYINT(1); read via db_to_bool()
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                 SERIAL       PRIMARY KEY,
    name               VARCHAR(100) NOT NULL,
    email              VARCHAR(254) NOT NULL,
    password           TEXT         NOT NULL,
    verified           BOOLEAN      NOT NULL DEFAULT FALSE,
    verification_token TEXT,
    created_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- Case-insensitive uniqueness. MySQL's default utf8mb4_general_ci collation
-- treated 'Bob@x.com' and 'bob@x.com' as the same account; a plain UNIQUE on
-- email would not, so enforce it explicitly on lower(email).
CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_key ON users (lower(email));

-- ---------------------------------------------------------------------------
-- user_settings
-- ---------------------------------------------------------------------------
-- Previously created at runtime by settings.php with MySQL DDL. It is now
-- migrated into a managed migration file so the schema is in one place.
-- notifications moves from TINYINT(1) to BOOLEAN.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_settings (
    user_id       INT          PRIMARY KEY,
    theme         VARCHAR(16)  NOT NULL DEFAULT 'dark',
    language      VARCHAR(8)   NOT NULL DEFAULT 'en',
    notifications BOOLEAN      NOT NULL DEFAULT TRUE,
    CONSTRAINT user_settings_user_id_fkey
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

-- ---------------------------------------------------------------------------
-- wishlist / visited_countries
-- ---------------------------------------------------------------------------
-- Re-keyed from a free-text `user_name` column onto users.id. The old shape let
-- two users sharing a name collide on the same rows, and orphaned a user's
-- saved countries whenever their name changed.
--
-- UNIQUE (user_id, country) both enforces the "one row per country" rule the
-- application used to check with SELECT COUNT(*) and provides the btree index
-- that serves the WHERE + ORDER BY country queries.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wishlist (
    id         SERIAL       PRIMARY KEY,
    user_id    INT          NOT NULL,
    country    VARCHAR(100) NOT NULL,
    created_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    CONSTRAINT wishlist_user_id_fkey
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT wishlist_user_country_key UNIQUE (user_id, country)
);

CREATE TABLE IF NOT EXISTS visited_countries (
    id         SERIAL       PRIMARY KEY,
    user_id    INT          NOT NULL,
    country    VARCHAR(100) NOT NULL,
    created_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    CONSTRAINT visited_countries_user_id_fkey
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT visited_countries_user_country_key UNIQUE (user_id, country)
);

COMMIT;
