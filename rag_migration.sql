-- ============================================================
--  RAG_MIGRATION.SQL – adds course-material vector search (RAG)
--  SAFE / IDEMPOTENT: you can run it any number of times.
--    * creates ONE new table (course_material_chunks)
--    * adds index-status columns to course_materials (IF NOT EXISTS)
--    * existing users, messages, courses, assignments, quizzes,
--      quiz_attempts and their rows are NEVER touched or deleted.
--  MariaDB 10.4 has no native VECTOR type, so embeddings are
--  stored as packed float32 BLOBs and cosine similarity is done
--  in PHP (includes/rag.php).
-- ============================================================

CREATE TABLE IF NOT EXISTS course_material_chunks (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    material_id  INT             NOT NULL,
    user_id      INT             NOT NULL,          -- denormalised for ownership-scoped search
    course_id    INT             NOT NULL,          -- denormalised for course-scoped search
    chunk_index  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    content      MEDIUMTEXT      NOT NULL,
    embedding    MEDIUMBLOB      NOT NULL,          -- packed float32 vector
    dim          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    model        VARCHAR(64)     NOT NULL DEFAULT '',
    created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_material_chunk (material_id, chunk_index),
    KEY idx_scope (user_id, course_id),
    CONSTRAINT fk_chunk_material FOREIGN KEY (material_id)
        REFERENCES course_materials (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Store the uploaded file itself so materials can be (re-)indexed later
-- and re-sent to Gemini. Old rows simply keep NULL (never indexed).
ALTER TABLE course_materials
    ADD COLUMN IF NOT EXISTS file_data     LONGBLOB     NULL DEFAULT NULL AFTER note,
    ADD COLUMN IF NOT EXISTS index_status  VARCHAR(12)  NOT NULL DEFAULT 'pending' AFTER file_data,
    ADD COLUMN IF NOT EXISTS index_note    VARCHAR(255) NULL DEFAULT NULL AFTER index_status,
    ADD COLUMN IF NOT EXISTS indexed_at    DATETIME     NULL DEFAULT NULL AFTER index_note,
    ADD COLUMN IF NOT EXISTS chunk_count   INT UNSIGNED NOT NULL DEFAULT 0 AFTER indexed_at;

-- Legacy materials uploaded before RAG existed have no stored file,
-- so they can never be indexed automatically; mark them honestly.
UPDATE course_materials
SET index_status = 'pending',
    index_note   = 'Uploaded before indexing existed - attach this file again in the AI chat to index it.'
WHERE file_data IS NULL AND index_status = 'processing';
