<?php
// ====================================================================
//  CONFIG.EXAMPLE.PHP  -- copy this file to config.php and edit it
//
//  This is the example configuration. It contains NO real secrets.
//  Copy / rename it to "config.php" (which is git-ignored) and fill
//  in YOUR OWN values, then the rest of the project just works.
// ====================================================================

// ---------------------------------------------------------------
// 1) GEMINI API KEY
//
//    Get a free key here:  https://aistudio.google.com/apikey
//    It looks like:        AIzaSyYourLongKeyHere
//
//    NEVER commit your real key. config.php (the edited copy) is
//    listed in .gitignore and will NOT be pushed to GitHub.
// ---------------------------------------------------------------
define('GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY_HERE');

// Gemini model to use (free text models).
// Current free options: 'gemini-2.5-flash'  or  'gemini-3.6-flash'
// gemini-2.0-flash is shut down, so do NOT use it.
define('GEMINI_MODEL', 'gemini-3.6-flash');

// Backup models tried automatically when the main model above answers
// "This model is currently experiencing high demand" (HTTP 503) or is
// rate-limited. Comma-separated, in the order they should be tried.
define('GEMINI_MODEL_BACKUPS', 'gemini-flash-latest,gemini-3.5-flash,gemini-2.5-flash,gemini-flash-lite-latest');

// ---------------------------------------------------------------
// 3b) RAG -- course-material search ("ask about YOUR materials")
//
// When the student chats inside a course, uploaded materials are
// split into chunks, embedded once at upload and stored in MySQL.
// At question time the relevant chunks are found with cosine
// similarity and ONLY they are sent to Gemini as context.
// ---------------------------------------------------------------
if (!defined('RAG_ENABLED')) { define('RAG_ENABLED', true); }
define('RAG_EMBED_MODEL', 'gemini-embedding-001');  // embedding model (confirmed available)
define('RAG_EMBED_DIM', 768);                        // output dimensionality per embedding
define('RAG_CHUNK_SIZE', 900);                       // target chunk size in characters
define('RAG_CHUNK_OVERLAP', 150);                    // characters carried over between chunks
define('RAG_TOP_K', 3);                              // max chunks retrieved per question (small = fast + focused)
define('RAG_MIN_SIMILARITY', 0.60);                  // cosine threshold for "relevant" (0..1)
define('RAG_MAX_CONTEXT_CHARS', 4500);               // total context size cap sent to Gemini (smaller = faster)

// ---- RAG + Gemini SPEED / RELIABILITY tuning ----
// Hard cap on how many chunk vectors may be scored for ONE question.
// Keeps a question fast even when a course holds thousands of chunks.
// Phase 1 of retrieval selects ONLY id + embedding (no chunk text),
// so the capped scan never drags large text fields through MySQL.
define('RAG_MAX_SCAN', 2000);
// Reuse the embedding of a question that was asked before. This removes
// a whole Gemini round-trip on repeat questions (e.g. "Regenerate").
define('RAG_EMBED_CACHE', true);
define('RAG_EMBED_CACHE_TTL', 3600);                 // seconds the question embedding is kept
// Total seconds allowed for ONE answer, and the maximum number of
// Gemini attempts (main model + backups) before giving up. This bounds
// the worst case during a "high demand" spike. The curl timeouts are
// deliberately smaller so one slow attempt cannot eat the whole budget.
define('GEMINI_TIME_BUDGET', 40);
define('GEMINI_MAX_CALLS', 3);
define('GEMINI_CONNECT_TIMEOUT', 8);   // seconds to connect to Google
define('GEMINI_REQUEST_TIMEOUT', 25);  // seconds for one Gemini answer call
define('GEMINI_EMBED_TIMEOUT', 20);    // seconds for one embedding batch call
// Parallel quiz batches may ask for up to 25 questions in ONE JSON
// answer, which takes longer than a chat reply. This cap applies
// ONLY to the concurrent quiz-batch calls (geminiGenerateTextBatch).
define('GEMINI_QUIZ_REQUEST_TIMEOUT', 60);
// Max concurrent quiz-batch requests sent to Gemini at once. Bounded to 2-3
// so that big quizzes stay fast without overwhelming the API or tripping
// rate limits. Hard-capped to 3 inside geminiGenerateTextBatch() regardless.
define('GEMINI_QUIZ_MAX_PARALLEL', 3);
// Set to true to add a "timings" block to the chat JSON response, which
// shows where the time went (retrieval vs Gemini vs database).
define('RAG_DEBUG', false);

// ---------------------------------------------------------------
// 2) MYSQL DATABASE
//    These are the XAMPP default values. XAMPP's MySQL "root" user
//    has an EMPTY password by default, so you can leave DB_PASS
//    as '' unless you set a password.
// ---------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'ai_chat');
define('DB_USER', 'root');
define('DB_PASS', 'YOUR_DATABASE_PASSWORD_HERE');

// ---------------------------------------------------------------
// 3) Site name shown in the navbar and on the login page
// ---------------------------------------------------------------
define('BOT_NAME', 'AI Chat');
?>
