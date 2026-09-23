# AI Student Chat (PHP 8 + MySQL + Gemini API)

A **student AI study assistant** built with plain PHP 8 + PDO, vanilla JavaScript, Bootstrap 5, and the Google Gemini API. It lets a student ask questions in a chat, attach study files (PDF/TXT/DOCX/images, up to 10 MB), ask about a specific course using RAG over uploaded materials, track assignments, generate and take quizzes, see a dashboard with progress, browse history, search, and manage a profile. All data is stored in MySQL; uploaded files are sent straight to Gemini and **never stored on the server** (no uploads folder).

> **Tech stack:** PHP 8 (no frameworks), vanilla JS, HTML/CSS + Bootstrap 5 (CDN) + a Tailwind utility layer, Google Gemini REST API via cURL, MySQL via PDO (prepared statements only).

## Project URL

Start Apache + MySQL in the XAMPP Control Panel, then open **http://localhost/ai-chat/**. Register (or log in with the bundled `admin` / `admin123`), then use the sidebar to reach the dashboard, chat, courses, materials, assignments, quizzes, history, search and profile.

## Project layout
```
├── config.php              <- YOUR secret config (API key + DB) — NOT in Git, see below
├── config.example.php      <- safe example (copy to config.php and edit)
├── gemini.php              <- talks to the Gemini API (cURL REST), all modes + RAG
├── db.php                  <- connects to MySQL with PDO (getPDO())
├── database.sql            <- base schema (users, messages) — import once
├── student_assistant_migration.sql <- courses, materials, assignments, quizzes
├── rag_migration.sql       <- vector-chunk table + RAG indexing columns
├── test_gemini.php         <- command-line test that your API key + model work
├── index.php               <- the chat page
├── dashboard.php           <- student dashboard
├── courses.php             <- list of courses
├── course.php              <- a single course (materials, assignments, quizzes, "Ask AI")
├── materials.php           <- study materials library
├── assignments.php         <- list of assignments
├── assignment.php          <- a single assignment
├── quizzes.php             <- list of generated quizzes
├── quiz.php                <- take a quiz
├── quiz_result.php         <- quiz result / score
├── history.php             <- conversation history
├── search.php              <- global search
├── profile.php             <- user profile
├── login.php / register.php / logout.php
├── api/
│   └── chat.php            <- AJAX endpoint: Gemini + save to MySQL
├── includes/
│   ├── header.php / footer.php   <- shared HTML shell
│   ├── auth.php            <- session auth helpers
│   ├── security.php        <- CSRF tokens + flash messages
│   ├── study.php           <- course-context helpers
│   └── rag.php             <- vector/chunk retrieval (cosine similarity)
├── js/
│   └── chat.js             <- the vanilla JavaScript (chat, attachments, modes)
└── css/
    └── style.css           <- custom styles over Bootstrap 5
```

## 1. Configure your Gemini API key (important — read this)

The project reads its configuration from **`config.php`**, which is **git-ignored** (it must never be committed, because it holds your secret key).

1. Copy the safe template: `copy config.example.php config.php`
2. Open `config.php` and replace the placeholders:
   - `YOUR_GEMINI_API_KEY_HERE` -> your Gemini key (free at https://aistudio.google.com/apikey)
   - `YOUR_DATABASE_PASSWORD_HERE` -> your MySQL password (empty `''` is fine for the XAMPP `root` default)
3. `config.php` is in `.gitignore`, so it will not be added to Git.

The key stays in PHP only — it is never sent to the browser (JS/HTML never see it).

## 2. Create / import the database

1. Start **MySQL** in the XAMPP Control Panel.
2. Open http://localhost/phpmyadmin, click **Import**, choose **`database.sql`** -> **Go**.
3. Then import **`student_assistant_migration.sql`** (courses, materials, assignments, quizzes).
4. Then import **`rag_migration.sql`** (vector-chunk table + RAG indexing columns for materials).

All three migrations are idempotent/safe — they use `IF NOT EXISTS` and `ADD COLUMN IF NOT EXISTS` where supported, and explicitly preserve existing rows; existing data is never deleted.

That creates the `ai_chat` database and a default login: admin / admin123 (a bcrypt hash in the SQL; change it by registering your own account, then deleting that row if you want).

## 3. Test the AI connection

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\ai-chat\test_gemini.php
```

If it prints `SUCCESS!` your API key + model work. Then open http://localhost/ai-chat/, log in, and start chatting.

## 4. Features

### Chat + file attachments
- Attach button (paperclip) next to the input. Supported: PDF, TXT, DOCX, PNG, JPG, GIF, WEBP, max 10 MB. The file is read in memory, base64-encoded and sent inline to Gemini, and never saved on the server. Server-side extension whitelist + size check; filenames are HTML-escaped wherever displayed.
- Assistant modes chosen from a selector above the input: **General Chat**, **Study Assistant** (summaries, quizzes, flashcards), **Coding Assistant**, **Course Assistant** (scoped to one course's materials), and **Health Assistant** (clearly states it is not a medical diagnosis). Mode instructions are prepended to every request server-side (`systemPromptForMode()` in `gemini.php`).

### Conversations & history
- Enter = send, Shift + Enter = new line (textarea auto-grows); animated "AI is thinking..." indicator; Copy button under every answer; Regenerate re-asks the last question/mode/file and replaces the answer. Every Q&A is saved in MySQL and shown on the History page with light formatting (HTML escaped first — safe).

### Courses & materials (with RAG)
- Create courses; upload study materials (PDF/TXT/DOCX/images) against a course. When the chat has a course context, the relevant chunks of your materials are found by cosine similarity (`includes/rag.php`) and sent to Gemini as context — so answers only use material you actually uploaded. Materials are split into overlapping chunks, embedded once with `gemini-embedding-001` (stored as packed float32 vectors in MySQL — no external vector DB), and cached so repeat questions are fast.

### Assignments
- Add assignments to a course with a title, description, due date, and status (`pending` / `in_progress` / `completed`). The dashboard highlights upcoming / open assignments.

### Quizzes
- Generate quizzes from a course's materials — Gemini creates the questions as JSON, stored on the `quizzes` table. Take a quiz, get a score, and revisit your attempt + chosen answers on the result page. Bulk quiz generation runs with bounded concurrency/limits to avoid rate limits.

### Dashboard & search & profile
- The dashboard shows counts of courses, open assignments, your materials, completed quizzes, course progress bars, recent quiz scores, and upcoming assignments. A global search across your data, and a profile page.

## How it works (short version)
1. `js/chat.js` posts your question (and optional attached file + mode + course id) to `api/chat.php` via AJAX (multipart when a file is attached, JSON otherwise).
2. `api/chat.php` optionally runs RAG retrieval over your course's material chunks, then calls `askGemini()` in `gemini.php` (cURL -> Google REST API).
3. The question + answer are saved with PDO prepared statements; the answer (plus RAG timing debug info, if enabled) is returned to the page as JSON.
4. `chat.js` renders the answer as a formatted bubble.

## Configuration flags (in config.php)
`GEMINI_API_KEY`, `GEMINI_MODEL`, `GEMINI_MODEL_BACKUPS`; `RAG_ENABLED`, `RAG_EMBED_MODEL`, `RAG_EMBED_DIM`, `RAG_CHUNK_SIZE`, `RAG_CHUNK_OVERLAP`, `RAG_TOP_K`, `RAG_MIN_SIMILARITY`, `RAG_MAX_CONTEXT_CHARS`; `RAG_MAX_SCAN`, `RAG_EMBED_CACHE`, `RAG_EMBED_CACHE_TTL`; `GEMINI_TIME_BUDGET`, `GEMINI_MAX_CALLS`, the `*_TIMEOUT*` constants, `GEMINI_QUIZ_MAX_PARALLEL`; `RAG_DEBUG` (adds a `timings` block to the chat JSON response when on); `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`; `BOT_NAME`.

## Troubleshooting
| Problem | Fix |
|---|---|
| "Gemini API error: 400" / invalid key | Check the key in `config.php`; make sure you copied `config.example.php` to `config.php` first |
| `Unknown database 'ai_chat'` | Import `database.sql` in phpMyAdmin (section 2) |
| Page does not load at all | Start Apache in the XAMPP Control Panel |
| Attach button does nothing | Hard-refresh (Ctrl+F5) once after editing `chat.js`; the script is cache-busted via `?v=<filemtime>` |
| Attachment rejected as "not allowed" | Only PDF/TXT/DOCX/PNG/JPG/GIF/WEBP under 10 MB are accepted |
| Quizzes / materials / RAG not present | Run `rag_migration.sql` and `student_assistant_migration.sql` as well |
| "Network error" from Gemini | Check your internet connection; the API needs internet |

> Note: this project uses the classic Gemini `generateContent` REST endpoint via cURL — it remains fully supported and is the easiest way to get started. The endpoint + model are easy to see/change in `gemini.php` / `config.php`.

## Security notes for contributors
- **Never commit `config.php`.** It is in `.gitignore`. Use `config.example.php` for the tracked, secret-free template.
- The Gemini API key and database password are read from PHP constants only — they are never sent to the browser.
- CSRF protection is applied to all POST forms via `csrfCheck()` (`includes/security.php`).
- Passwords are stored with `password_hash()` (bcrypt) and verified with `password_verify()`.
