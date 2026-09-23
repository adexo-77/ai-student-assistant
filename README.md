# AI Student Assistant

A PHP + MySQL + Gemini web application that lets students study with an AI: ask questions in a chat, attach study files, ask about a specific course using your own uploaded materials (RAG), manage assignments and quizzes, and review progress on a dashboard. It runs on a local XAMPP stack — nothing is stored on a public server, and attached files are sent straight to Gemini and never persisted.

## Overview

This is a single-user, local-first AI study assistant. A student logs in and asks the Gemini model questions in a chat window, optionally attaching a file (PDF, TXT, DOCX, image) to have it summarised or discussed. The assistant provides five subject modes (General, Study, Coding, Course, Health). When working inside a course, the assistant searches that course's uploaded study materials for relevant excerpts, sends only those excerpts to Gemini, and answers from them — a lightweight, course-scoped RAG implementation backed by MySQL rather than a separate vector database.

## ⭐ Key Technical Feature: Vector-Based RAG

What sets this assistant apart from a basic Gemini chatbot is course-aware, vector-based Retrieval-Augmented Generation (**Vector-Based RAG**). It does not answer a course question from the model's general knowledge — it finds evidence in the student's own uploaded materials first, and then answers only from what it can retrieve. The workflow is:

1. A student uploads course materials (PDF, TXT, DOCX) against a course in the chat.
2. The material is processed and split into overlapping text chunks (`RAG_CHUNK_SIZE` / `RAG_CHUNK_OVERLAP`).
3. Each chunk is converted into a vector embedding **once, at upload**, using `gemini-embedding-001` (768 dimensions).
4. The embeddings are stored in the **existing MySQL database** as packed `float32` vectors in the `course_material_chunks` table — no separate vector database is used (MariaDB 10.4 has no native `VECTOR` type).
5. When the student asks a course-related question, the question itself is converted into an embedding (`RETRIEVAL_QUERY` task type).
6. The system performs a **vector similarity search using cosine similarity**, computed in PHP, over that course's chunk vectors (a two-phase scan that loads only ids + embeddings first, then chunk text only for the survivors; capped by `RAG_MAX_SCAN`).
7. The most relevant chunks are retrieved (top `RAG_TOP_K`, above the `RAG_MIN_SIMILARITY` threshold).
8. Only those chunks are passed to Gemini as context in a grounded prompt that forbids using outside knowledge.
9. Gemini generates a response grounded in the retrieved course material, and the source file name(s) are appended to the answer. If nothing relevant is found, the assistant says so rather than guessing.
10. **Ownership filtering** — every step is scoped to the student's `user_id` + `course_id` (`idx_scope` index, `AND user_id = ?` in every query), so another user's or another course's materials can never be retrieved.

The question embedding is cached on disk (`RAG_EMBED_CACHE`) so repeat questions skip a paid API call, and the time spent in each stage (embedding → vector search → Gemini → database) is reported when `RAG_DEBUG` is enabled. This is a major technical feature of the project: a compact, self-contained vector RAG pipeline built on the same LAMP-style stack as the rest of the app.

See the dedicated [Course-Aware AI / RAG](#course-aware-ai--rag) section below for the tunable configuration values.

## Features

- **User registration and secure authentication** — register a new account, or use the seeded `admin` account. Passwords are hashed with `password_hash()` and verified with `password_verify()`.
- **AI chat** — AJAX chat (`js/chat.js` → `api/chat.php` → `gemini.php`). Every message and answer is saved to MySQL with PDO prepared statements and shown again in History.
- **Google Gemini integration** — text and file generation, with automatic model fallback and bounded retries/timeouts when the API is under load. The API key is read only from the local `config.php`, server-side, and never sent to the browser.
- **File attachments** — attach a PDF, TXT, DOCX or an image (up to 10 MB). Files are read into memory, sent straight to Gemini as base64, and **not stored on the server** (no `uploads/` directory is created).
- **Five assistant modes** — General Chat, Study Assistant, Coding Assistant, Course Assistant, Health Assistant. Each mode prepends a role-specific instruction to every request.
- **Courses** — create and manage per-user courses; the course context is shared between the dashboard, the course page, and the chat.
- **Course-specific AI assistance** — when the chat is opened from a course, the assistant is told which course is active and stays on topic.
- **Study materials** — upload study files against a course; file metadata (name, type, size, note) is stored so the course page can list what was studied with.
- **Course-material RAG / retrieval** — uploaded text is split into overlapping chunks, embedded once with `gemini-embedding-001` (`gemini.php`), and stored as packed float32 vectors in MySQL. At question time the most relevant chunks are found by cosine similarity and sent to Gemini as context.
- **Assignments** — create, list, and track assignments per course, with statuses `pending`, `in_progress`, `completed` and an optional due date. A pending-assignment counter sits in the top navigation bar.
- **Quizzes** — generate quizzes from a course's materials with Gemini, take them interactively, and review the results.
- **Quiz results** — each attempt is recorded (score, total questions, and the answers the student chose) so results can be re-viewed and compared.
- **Dashboard** — overview with statistics (courses, pending assignments, materials, quizzes done), upcoming assignments, recent activity, quiz-progress average, recent quiz scores, and AI quick-action chips.
- **Progress / activity information** — statistics and recent activity are derived directly from the database; quiz progress shows an average score across all attempts.
- **Chat history** — every question and answer is stored and browsable.
- **Search** — top-bar search that routes to a results page across courses, assignments, and quizzes.
- **User profile** — view and manage the account profile.
- **Security protections** — see the dedicated [Security](#security) section below.

## AI Assistant Modes

The mode is selected in the chat UI and determines the instructions prepended to each request (`systemPromptForMode()` in `gemini.php`). The values sent to the API are `general`, `study`, `coding`, `course`, `health`.

| Value | Label | Behaviour |
| --- | --- | --- |
| `general` | General Chat | A friendly, general-purpose assistant. |
| `study` | Study Assistant | Explains topics simply, summarises notes, and creates quizzes or flashcards when helpful. |
| `coding` | Coding Assistant | Helps with programming questions, debugging, and code explanation. |
| `course` | Course Assistant | Stays on topic for one specific course and helps with exam preparation. |
| `health` | Health Assistant | General health information with a clear disclaimer that it is not a medical diagnosis. |

## Course-Aware AI / RAG

When a chat is opened from inside a course (`index.php?course_id=...`), the Course Assistant mode is used and course-aware retrieval runs for that course (`api/chat.php` ↔ `includes/rag.php`):

1. **Index (once, at upload)** — the material's text is split into overlapping chunks (`RAG_CHUNK_SIZE` / `RAG_CHUNK_OVERLAP`), each embedded once with `gemini-embedding-001` (`askGeminiEmbeddings` in `gemini.php`), and stored as a packed float32 vector BLOB in the `course_material_chunks` table (`rag_migration.sql`). The source file is also stored so it can be re-indexed later.
2. **Question time** — the student's question is embedded with the `RETRIEVAL_QUERY` task type. The embedding is cached on disk (`RAG_EMBED_CACHE`, TTL `RAG_EMBED_CACHE_TTL`) so repeat questions skip a paid API call.
3. **Similarity** — cosine similarity is computed in PHP over at most `RAG_MAX_SCAN` chunk vectors. Retrieval is a two-phase scan: phase 1 loads only ids and embeddings (no chunk text, so the cap never drags large text through MySQL); phase 2 loads text only for the survivors.
4. **Filtering** — only rows matching this user's `user_id` and `course_id` are considered (`idx_scope`).
5. **Grounding** — the top `RAG_TOP_K` chunks above `RAG_MIN_SIMILARITY` are sent to Gemini as context in a prompt that forbids using outside knowledge. If nothing relevant is found, the assistant says so rather than guessing, and the source file names are appended to the answer.

This is a self-contained, course-scoped RAG that uses only MySQL and the Gemini embeddings API — no external vector database or service account is required.

## Technology Stack

| Layer | Technology |
| --- | --- |
| Language | PHP 8 |
| Database | MySQL / MariaDB (PDO, `utf8mb4`) |
| AI API | Google Gemini (REST over cURL) |
| Frontend | HTML, CSS (`css/style.css`), JavaScript (`js/chat.js`) |
| UI framework | Bootstrap 5 (dark theme) |
| Utility layer | Tailwind CSS (CDN, pure utility layer on top of Bootstrap) |
| Local server | Apache + MySQL via XAMPP |
| Dev tooling | Optional CLI check: `test_gemini.php` |

## Project Structure

```
ai-chat/
├── api/
│   └── chat.php                 # AJAX chat endpoint (auth + RAG + Gemini + save Q&A)
├── includes/
│   ├── auth.php                 # session start, isLoggedIn(), requireLogin()
│   ├── security.php             # CSRF tokens, flash messages, friendly errors
│   ├── rag.php                  # chunking, embedding, cosine similarity, grounding
│   ├── study.php                # courses / materials / assignments / quizzes helpers
│   ├── header.php               # Bootstrap + Tailwind <head> + app shell
│   └── footer.php               # closing tags + JS
├── js/
│   └── chat.js                  # chat UI logic (AJAX to api/chat.php)
├── css/
│   └── style.css                # application styles
├── db.php                       # getPDO() - PDO/MySQL connection (charset utf8mb4)
├── gemini.php                   # Gemini REST client, mode prompts, embeddings
├── config.php                   # YOUR local config with the API key (git-ignored)
├── config.example.php           # secret-free config template (copy this)
├── .gitignore                   # keeps config.php, logs, OS/IDE files out of git
├── test_gemini.php              # optional CLI check for the Gemini key/connection
├── database.sql                 # base schema: users, messages (+ demo admin)
├── student_assistant_migration.sql  # courses, materials, assignments, quizzes
├── rag_migration.sql            # RAG chunk table + indexing columns on materials
├── login.php / register.php / logout.php
├── index.php                    # chat page
├── dashboard.php
├── courses.php / course.php
├── materials.php
├── assignments.php / assignment.php
├── quizzes.php / quiz.php / quiz_result.php
└── history.php / search.php / profile.php
```

## Requirements

- **XAMPP** (Apache + MySQL + PHP) — recommended, since the project is built around it.
- **PHP 8** with the `pdo_mysql`, `mbstring`, and `curl` extensions enabled.
- A **Google Gemini API key** — get one free at https://aistudio.google.com/apikey
- A modern browser (Chrome, Edge, or Firefox) for the UI.

## Installation

1. **Put the project in XAMPP.** Copy the `ai-chat` folder into `C:\xampp\htdocs\ai-chat`.
2. **Start Apache and MySQL** in the XAMPP Control Panel.
3. **Open phpMyAdmin.** Go to http://localhost/phpmyadmin
4. **Import the SQL files — in this order:**
   1. `database.sql` — creates the `users` and `messages` tables and the demo `admin` account.
   2. `student_assistant_migration.sql` — adds `courses`, `course_materials`, `assignments`, `quizzes`, `quiz_attempts`, and links `messages.course_id`.
   3. `rag_migration.sql` — adds the `course_material_chunks` table and the RAG indexing columns on `course_materials`.
5. **Configure the app.**
   1. Copy `config.example.php` to `config.php`.
   2. Open `config.php` and paste your real Gemini API key into `GEMINI_API_KEY`.
   3. Update the MySQL credentials (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`) if they differ from the XAMPP defaults (`localhost` / `ai_chat` / `root` / empty).
   4. Leave `GEMINI_MODEL` and the `RAG_*` constants at their defaults — they work out of the box.
6. **Verify (optional).** From a terminal run:
   ```bat
   php C:\xampp\htdocs\ai-chat\test_gemini.php
   ```
   You should see `SUCCESS! Gemini answered:` followed by a reply. If it reports the key is missing, re-check step 5.2.
7. **Open the app.** Go to http://localhost/ai-chat/login.php and log in.
   - Demo account (created by `database.sql`): `admin` / `admin123`. Change this right away, or create your own account on the registration page.

## Configuration

All configuration lives in `config.php`, which is **local only** and excluded from version control by `.gitignore`. Copy it from `config.example.php` and set:

- `GEMINI_API_KEY` — your Google Gemini API key (the only secret).
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — MySQL credentials (XAMPP defaults: `localhost` / `ai_chat` / `root` / empty).
- `BOT_NAME` — the name shown in the navbar and on the login page.
- `GEMINI_MODEL` / `GEMINI_MODEL_BACKUPS` — model names (defaults are fine).
- `RAG_*` constants — retrieval behaviour (enable/disable, embedding model, chunk size/overlap, top-K, similarity threshold, caching). Defaults are tuned and work out of the box.

`config.example.php` is the tracked, **secret-free** template. **Never commit `config.php`.**

## Database

The database is named `ai_chat` (created automatically by `database.sql`). Import the three SQL files in this order — each is idempotent, so they are safe to re-run without duplicating or losing data:

| File | Purpose | Adds / creates |
| --- | --- | --- |
| `database.sql` | Base schema. | `users` (login accounts) and `messages` (chat history), plus a seeded demo account. |
| `student_assistant_migration.sql` | Course features. | `courses`, `course_materials`, `assignments`, `quizzes`, `quiz_attempts`; adds a nullable `messages.course_id`. |
| `rag_migration.sql` | RAG / vector search. | `course_material_chunks` table and indexing columns (`file_data`, `index_status`, `indexed_at`, ...) on `course_materials`. |

All tables use `InnoDB` and `utf8mb4`, with foreign keys that `CASCADE` on delete so each user's data stays isolated. RAG vectors are stored as packed `float32` `MEDIUMBLOB`s because MariaDB 10.4 has no native `VECTOR` type; cosine similarity is computed in `includes/rag.php`.

## Security

The application implements real, layered controls (verified in the source):

- **Password hashing** — passwords are stored with `password_hash(..., PASSWORD_DEFAULT)` and verified with `password_verify()`; plaintext is never stored (`register.php`, `login.php`).
- **Prepared statements everywhere** — all database access goes through PDO with `?` placeholders (`db.php` exposes `getPDO()`; see `login.php`, `register.php`, `study.php`, `api/chat.php`, `includes/rag.php`).
- **Session authentication** — `includes/auth.php` starts the session and `requireLogin()` guards every page and the chat API (`index.php`, `dashboard.php`, `api/chat.php`, ...).
- **CSRF protection** — `includes/security.php` generates a per-session token (`csrfToken()`), embeds it in every form via `csrfField()`, and validates it with `csrfCheck()` on every data-changing POST.
- **File validation** — `api/chat.php` (`readUploadedFile`) enforces an extension whitelist (PDF, TXT, DOCX, PNG, JPG, GIF, WEBP) and a 10 MB size cap; files are read into memory and **never written to disk**.
- **User / course ownership checks** — every query that loads a course, assignment, quiz, quiz attempt, or material filters by `user_id` (for example `findUserCourse()` in `study.php`), so a user can never read or edit another user's data, even with a made-up ID.
- **Course-scoped RAG** — retrieval is restricted to the user's `user_id` + `course_id` (`idx_scope`), so one user's chunks are never searched for another user or course.
- **Server-side API key** — `GEMINI_API_KEY` is read from the local `config.php` server-side only; the browser never receives it.
- **No stored uploads** — attached files go straight to Gemini and are discarded; no `uploads/` directory is created.
- **Generic error handling** — `friendlyError()` logs details to the Apache error log and shows the user only a generic message, so SQL or stack details are never leaked.

## Usage

A typical student session:

1. **Register** on the sign-up page (or log in with the demo `admin` / `admin123`), then open the **Dashboard**.
2. **Create a course** (for example *Database Systems*) from the courses page.
3. **Add a study material** — attach a PDF, TXT or DOCX to that course.
4. **Ask the AI** — open the chat from that course (the Course Assistant mode is selected automatically) and ask a question about the material. Only relevant excerpts from your own materials are used as context (RAG).
5. **Track work** — add assignments with due dates, and set their status to `pending`, `in_progress`, or `completed`.
6. **Create and take a quiz** — generate a quiz from the course materials, answer the questions, then open the result to see your score.
7. **Review** — check **History** for past Q&As, the quiz **Results** page for your scores, and the **Dashboard** for your overall progress.

## What Makes This System Stand Out

- **Vector-Based RAG for course-aware AI** — answers are grounded in the student's own uploaded materials, not generic knowledge.
- **Course-material vector embeddings** — materials are embedded once with `gemini-embedding-001` (768 dimensions) and reused for every later question.
- **Semantic / vector similarity search** — cosine similarity search over MySQL-stored chunk vectors finds the most relevant excerpts.
- **Grounded AI responses** — only retrieved course content is sent to Gemini as context, with a prompt that forbids hallucination.
- **Course-specific AI assistance** — the Course Assistant mode stays on topic for one specific course.
- **Gemini-powered conversational AI** — chat with file attachments, five assistant modes, and automatic model fallback.
- **AI-assisted quizzes and study tools** — quizzes generated from course materials, saved attempts, and reviewable results.
- **Student learning dashboard and progress tracking** — per-user statistics, upcoming assignments, recent activity, and quiz-progress totals/averages.

## Screenshots

Add screenshots here once captured, for example:

- `screenshots/login.png` — the login page.
- `screenshots/chat.png` — the chat window with file attachment and mode selector.
- `screenshots/dashboard.png` — the dashboard with statistics and recent activity.
- `screenshots/course.png` — a course page with materials and assignments.

No images are committed in this README; drop them into a `screenshots/` folder and update the paths above.

## GitHub Security

This repository is prepared for safe publishing:

- `.gitignore` excludes `config.php` (your real key and DB credentials), the `_ui-backup/` development-backup folder, log files, OS temp files (`.DS_Store`, `Thumbs.db`, `._*`), and IDE files (`.vscode/`, `.idea/`, `*.swp`).
- `config.example.php` is the tracked, **secret-free** template (the API key placeholder is `YOUR_GEMINI_API_KEY_HERE`).
- The real Gemini API key and DB credentials live **only** in your local `config.php`, which is never committed.

Before pushing, run these checks:

```bat
cd C:\xampp\htdocs\ai-chat
git add -A
git status --porcelain          :: config.php and _ui-backup/ must NOT appear
git status --ignored            :: confirms config.php and _ui-backup/ are ignored
git commit -m "Prepare for GitHub: .gitignore, config.example.php, README"
```

- **Never** commit `config.php`.
- **Never** paste your real Gemini API key into a tracked file.
- **Never** commit logs or the `_ui-backup/` folder.

## License

Copyright (c) 2026 AdugnaDhaba

This project is licensed under the MIT License.

See the [LICENSE](LICENSE) file for the full license text.

## Author

**AdugnaDhaba**

AI Student Assistant

A Gemini-powered AI learning system combining course management, student learning tools, and Vector-Based Retrieval-Augmented Generation (RAG).