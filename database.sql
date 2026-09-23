-- ============================================================
--  AI CHAT – DATABASE SETUP  (import this file in phpMyAdmin)
--
--  HOW TO USE:
--    1. Start Apache and MySQL in the XAMPP Control Panel.
--    2. Open  http://localhost/phpmyadmin  in your browser.
--    3. Click "Import" on the top menu -> choose this file
--       (C:\xampp\htdocs\ai-chat\database.sql) -> click "Go".
--
--  You can import this file more than once: it will not
--  create the database or tables twice.
-- ============================================================

CREATE DATABASE IF NOT EXISTS ai_chat
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE ai_chat;

-- ------------------------------------------------------------
-- USERS – the login accounts
--   id ......... automatic number
--   username ... unique login name
--   password ... hashed password (never store plain text!)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  username   VARCHAR(50)  NOT NULL UNIQUE,
  password   VARCHAR(255) NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- MESSAGES – the conversation history
--   question .. what the user asked
--   answer .... what Gemini answered
--   user_id ... which user it belongs to
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  question   TEXT NOT NULL,
  answer     TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- DEFAULT ACCOUNT so you can log in right away:
--     username: admin
--     password: admin123
-- (The text below is a bcrypt hash of "admin123".)
-- Change the password by creating your own account, then
-- deleting this INSERT if you want.
-- ------------------------------------------------------------
INSERT INTO users (username, password)
VALUES ('admin', '$2y$10$ftEnKKbLY8OM56229Xxfv.UlIgq5ajYBKDu4K.e2MGfOeP7MUN8iG')
ON DUPLICATE KEY UPDATE username = username;