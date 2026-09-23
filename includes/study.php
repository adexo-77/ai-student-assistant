<?php
// ============================================================
//  STUDY.PHP â€“ shared Student Assistant helpers
//
//  Reused by:  dashboard.php, courses.php, course.php,
//              assignments.php, assignment.php, quizzes.php,
//              quiz.php, quiz_result.php and api/chat.php
//
//  It uses the EXISTING db.php (getPDO) and the EXISTING
//  gemini.php (askGemini) â€“ no second database or AI connection.
// ============================================================

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../gemini.php';
require_once __DIR__ . '/rag.php';        // reused by quiz generation for course-material context

// ------------------------------------------------------------
//  Courses / assignments of ONE user (ownership is part of the
//  SQL: "AND user_id = ?" â€“ a user can never load someone else's
//  course, even with a made-up id).
// ------------------------------------------------------------

/** Returns one course of this user, or false when it is not theirs. */
function findUserCourse($pdo, $courseId, $userId) {
    $courseId = (int)$courseId;
    if ($courseId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT id, user_id, name, code, description, created_at
           FROM courses
          WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$courseId, (int)$userId]);
    $row = $stmt->fetch();
    return ($row === false) ? false : $row;
}

/** Returns one assignment of this user (with its course), or false. */
function findUserAssignment($pdo, $assignmentId, $userId) {
    $assignmentId = (int)$assignmentId;
    if ($assignmentId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT a.id, a.user_id, a.course_id, a.title, a.description,
                a.due_date, a.status, a.created_at,
                c.name AS course_name, c.code AS course_code
           FROM assignments a
           JOIN courses c ON c.id = a.course_id
          WHERE a.id = ? AND a.user_id = ?'
    );
    $stmt->execute([$assignmentId, (int)$userId]);
    $row = $stmt->fetch();
    return ($row === false) ? false : $row;
}

/** All courses of this user, A-Z. */
function listUserCourses($pdo, $userId) {
    $stmt = $pdo->prepare(
        'SELECT id, name, code, description, created_at
           FROM courses
          WHERE user_id = ?
          ORDER BY name ASC'
    );
    $stmt->execute([(int)$userId]);
    return $stmt->fetchAll();
}

/** All assignments of this user (with course name). */
function listUserAssignments($pdo, $userId) {
    $stmt = $pdo->prepare(
        'SELECT a.id, a.course_id, a.title, a.description, a.due_date, a.status,
                a.created_at, c.name AS course_name, c.code AS course_code
           FROM assignments a
           JOIN courses c ON c.id = a.course_id
          WHERE a.user_id = ?
          ORDER BY (a.status = \'completed\') ASC, a.due_date IS NULL, a.due_date ASC, a.created_at DESC'
    );
    $stmt->execute([(int)$userId]);
    return $stmt->fetchAll();
}

// ------------------------------------------------------------
//  Labels used in the forms and tables
// ------------------------------------------------------------

/** Assignment statuses: what we store => what we show. */
function assignmentStatuses() {
    return array(
        'pending'     => 'Pending',
        'in_progress' => 'In Progress',
        'completed'   => 'Completed',
    );
}

function statusLabel($status) {
    $all = assignmentStatuses();
    return isset($all[$status]) ? $all[$status] : 'Pending';
}

/** Bootstrap badge colour for a status. */
function statusBadge($status) {
    if ($status === 'completed')  { return 'success'; }
    if ($status === 'in_progress') { return 'warning'; }
    return 'secondary';
}

/** Quiz difficulties: what we store => what we show. */
function quizDifficulties() {
    return array('easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard');
}

function difficultyLabel($difficulty) {
    $all = quizDifficulties();
    return isset($all[$difficulty]) ? $all[$difficulty] : 'Medium';
}

// ------------------------------------------------------------
//  AI CONTEXT â€“ the extra instructions we add to the Gemini
//  prompt when the student chats from inside a course or an
//  assignment (spec: reuse the existing chat + Gemini code).
// ------------------------------------------------------------

/**
 * Builds the "you are helping with THIS course / assignment" part
 * of the prompt. Returns '' when there is no context at all.
 */
function studyContextInstruction($course, $assignment) {
    $lines = array();

    if ($course !== false && $course !== null) {
        $lines[] = 'The student is studying the course "' . $course['name'] . '"'
                 . ($course['code'] !== '' ? ' (course code ' . $course['code'] . ')' : '') . '.';
        if (!empty($course['description'])) {
            $lines[] = 'Course description: ' . $course['description'];
        }
        $lines[] = 'Help the student understand this course: explanations, definitions, examples, '
                 . 'study questions, summaries, coding questions, exam preparation and comparisons '
                 . 'of related concepts. Explain at a student-friendly level.';
    }

    if ($assignment !== false && $assignment !== null) {
        $lines[] = 'The student is working on the assignment "' . $assignment['title'] . '".';
        if (!empty($assignment['course_name'])) {
            $lines[] = 'It belongs to the course "' . $assignment['course_name'] . '".';
        }
        if (!empty($assignment['due_date'])) {
            $lines[] = 'The due date is ' . $assignment['due_date'] . '.';
        }
        if (!empty($assignment['description'])) {
            $lines[] = 'Assignment description: ' . $assignment['description'];
        }
        $lines[] = 'You are an educational assistant. Help the student understand and solve academic '
                 . 'problems step by step. Do not simply provide an answer when guidance and explanation '
                 . 'would better support learning. Explain the question, break the task into steps, explain '
                 . 'the concepts involved, give examples, suggest an approach, review the student\'s own '
                 . 'attempt, point out mistakes and help improve the answer.';
    }

    if (count($lines) === 0) {
        return '';
    }
    return implode("\n", $lines);
}

// ------------------------------------------------------------
//  PROGRESS â€“ simple numbers per course (no charts needed)
// ------------------------------------------------------------

/**
 * Returns array(
 *   'assignments_total', 'assignments_completed', 'assignments_pending',
 *   'assignments_in_progress', 'quizzes_taken', 'average_score'
 * )
 * average_score is 0-100 (rounded), or null when no quiz was taken yet.
 */
function courseProgress($pdo, $userId, $courseId) {
    $userId   = (int)$userId;
    $courseId = (int)$courseId;

    $progress = array(
        'assignments_total'       => 0,
        'assignments_completed'   => 0,
        'assignments_pending'     => 0,
        'assignments_in_progress' => 0,
        'quizzes_taken'           => 0,
        'average_score'           => null,
    );

    // --- assignments of this course -------------------------
    $stmt = $pdo->prepare(
        'SELECT status, COUNT(*) AS total
           FROM assignments
          WHERE user_id = ? AND course_id = ?
          GROUP BY status'
    );
    $stmt->execute([$userId, $courseId]);
    foreach ($stmt->fetchAll() as $row) {
        $count = (int)$row['total'];
        $progress['assignments_total'] += $count;
        if ($row['status'] === 'completed') {
            $progress['assignments_completed'] = $count;
        } elseif ($row['status'] === 'in_progress') {
            $progress['assignments_in_progress'] = $count;
        } else {
            $progress['assignments_pending'] = $count;
        }
    }

    // --- quiz attempts of this course -----------------------
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS attempts,
                AVG(CASE WHEN a.total_questions > 0
                         THEN a.score / a.total_questions * 100 END) AS avg_score
           FROM quiz_attempts a
           JOIN quizzes q ON q.id = a.quiz_id
          WHERE a.user_id = ? AND q.course_id = ?'
    );
    $stmt->execute([$userId, $courseId]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $progress['quizzes_taken'] = (int)$row['attempts'];
        if ($row['avg_score'] !== null) {
            $progress['average_score'] = (int)round((float)$row['avg_score']);
        }
    }

    return $progress;
}

/** Attempts of one quiz by this user, newest first. */
function listQuizAttempts($pdo, $userId, $quizId) {
    $stmt = $pdo->prepare(
        'SELECT id, score, total_questions, created_at
           FROM quiz_attempts
          WHERE user_id = ? AND quiz_id = ?
          ORDER BY created_at DESC'
    );
    $stmt->execute([(int)$userId, (int)$quizId]);
    return $stmt->fetchAll();
}

// ------------------------------------------------------------
//  QUIZZES â€“ generate with Gemini, store as JSON in MySQL
// ------------------------------------------------------------

/**
 * Turns the stored JSON text of a quiz into a PHP array.
 * Returns an empty array when the JSON is broken (never crashes).
 */
function decodeQuizQuestions($json) {
    $questions = json_decode((string)$json, true);
    return is_array($questions) ? $questions : array();
}

/**
 * Understands every shape an AI answer can take and returns the
 * 0-based index of the correct option (or null when impossible).
 *
 * Handles: JSON booleans (true/false for True/False questions),
 * 0-based numbers, "3", a 1-based number equal to the option count
 * ("4" with 4 options -> index 3), letters ("B", "b)", "(C)"),
 * the words "true"/"false" and the full text of an option.
 */
function quizAnswerIndex($rawAnswer, $options) {
    $count = count($options);
    if ($count === 0) {
        return null;
    }

    // JSON boolean: true -> True, false -> False (true/false questions).
    if (is_bool($rawAnswer)) {
        $wanted = $rawAnswer ? 'true' : 'false';
        foreach ($options as $i => $option) {
            if (strcasecmp(trim((string)$option), $wanted) === 0) {
                return $i;
            }
        }
        return $rawAnswer ? 0 : 1;
    }

    // Numbers: 0-based, with a 1-based fallback when the number equals
    // the option count (a very common model behaviour).
    if (is_int($rawAnswer) || is_float($rawAnswer)) {
        $value = (int)$rawAnswer;
        if ($value >= 0 && $value < $count) {
            return $value;
        }
        if ($value === $count) {
            return $value - 1;
        }
        return null;
    }

    if (!is_string($rawAnswer)) {
        return null;
    }

    $answer = trim($rawAnswer);
    if ($answer === '') {
        return null;
    }

    // "3" -> index 3 ("4" with 4 options -> index 3)
    if (preg_match('/^\d+$/', $answer)) {
        $value = (int)$answer;
        if ($value >= 0 && $value < $count) {
            return $value;
        }
        if ($value === $count) {
            return $value - 1;
        }
        return null;
    }

    // "A" / "B)" / "C." / "(D)" -> index 0 / 1 / 2 / 3
    if (preg_match('/^\(?([A-Za-z])[\).\s]*$/', $answer, $m)) {
        $index = ord(strtoupper($m[1])) - 65;
        return ($index >= 0 && $index < $count) ? $index : null;
    }

    // "true" / "false" as words
    $lower = strtolower($answer);
    if ($lower === 'true' || $lower === 'false') {
        foreach ($options as $i => $option) {
            if (strcasecmp(trim((string)$option), $lower) === 0) {
                return $i;
            }
        }
    }

    // The full text of an option (with or without an "A) " prefix).
    foreach ($options as $i => $option) {
        $option = trim((string)$option);
        $plain  = preg_replace('/^\(?[A-Za-z][\).\s]+/', '', $option);
        if (strcasecmp($option, $answer) === 0 || strcasecmp($plain, $answer) === 0) {
            return $i;
        }
    }

    return null;
}

/**
 * Pulls a JSON array out of whatever the model actually sent back.
 *
 * Handles: pure JSON, ```json fences, chatty text before/after the
 * array, smart quotes, trailing commas and a missing closing bracket.
 * Returns a PHP array, or null when no JSON array could be read.
 */
function extractQuizJsonArray($raw) {
    $text = (string)$raw;

    // Byte-order mark and non-breaking spaces.
    $text = str_replace(array("\xEF\xBB\xBF", "\xC2\xA0"), array('', ' '), $text);

    // Smart quotes -> plain quotes so json_decode() can work.
    $text = str_replace(
        array("\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x98", "\xE2\x80\x99"),
        array('"', '"', "'", "'"),
        $text
    );

    // 1) Already valid JSON.
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    // 2) Cut away everything around the array (fences, chatty text).
    $start = strpos($text, '[');
    if ($start === false) {
        return null;
    }
    $candidate = substr($text, $start);

    $end = strrpos($candidate, ']');
    if ($end !== false) {
        $slice   = substr($candidate, 0, $end + 1);
        $decoded = json_decode($slice, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 3) Repair the common mistakes and try again.
        $repaired = preg_replace('/,\s*([\]}])/', '$1', $slice);   // trailing commas
        $decoded  = json_decode($repaired, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    // 4) The array was cut off (usually the "]" is missing): close it.
    $repaired = rtrim(preg_replace('/,\s*$/', '', $candidate));
    $decoded  = json_decode($repaired . ']', true);
    if (is_array($decoded)) {
        return $decoded;
    }

    return null;
}/**
 * Makes the JSON Gemini returns usable for our quiz page.
 *
 * It accepts the many key names models use in practice
 * (question/text/prompt, options/choices, answer/correct/
 * correct_answer/answer_index) and normalises every kept question to
 *   array('question' => ..., 'type' => 'mc'|'tf',
 *         'options' => array(...), 'answer' => 0-based index,
 *         'explanation' => ...)
 */
function normaliseQuizQuestions($decoded, $wantedCount) {
    $clean = array();

    if (!is_array($decoded)) {
        return $clean;
    }

    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }

        // ---- the question text -------------------------------
        $text = '';
        foreach (array('question', 'text', 'prompt', 'title') as $key) {
            if (isset($item[$key]) && is_string($item[$key]) && trim($item[$key]) !== '') {
                $text = trim($item[$key]);
                break;
            }
        }
        if ($text === '') {
            continue;
        }

        // ---- the correct answer (read first: a boolean means tf) ----
        $rawAnswer = null;
        foreach (array('answer', 'correct', 'correct_answer', 'correctAnswer',
                       'answer_index', 'correct_index', 'correct_option') as $key) {
            if (array_key_exists($key, $item)) {
                $rawAnswer = $item[$key];
                break;
            }
        }

        // ---- true/false or multiple choice --------------------
        $rawType     = isset($item['type']) ? strtolower(trim((string)$item['type'])) : '';
        $answerLower = is_string($rawAnswer) ? strtolower(trim($rawAnswer)) : '';
        $isTrueFalse = (strpos($rawType, 'tf') !== false
                        || strpos($rawType, 'true') !== false
                        || strpos($rawType, 'false') !== false
                        || strpos($rawType, 'boolean') !== false
                        || is_bool($rawAnswer)
                        || $answerLower === 'true'
                        || $answerLower === 'false');

        $options = array();
        if ($isTrueFalse) {
            $options = array('True', 'False');
        } else {
            foreach (array('options', 'choices', 'answers') as $key) {
                if (!isset($item[$key]) || !is_array($item[$key])) {
                    continue;
                }
                $collected = array();
                foreach ($item[$key] as $option) {
                    $option = trim((string)$option);
                    if ($option !== '') {
                        $collected[] = $option;
                    }
                }
                if (count($collected) >= 2) {
                    $options = $collected;
                    break;
                }
            }
        }

        if (count($options) < 2) {
            continue;                                    // unusable question
        }

        $answerIndex = quizAnswerIndex($rawAnswer, $options);
        if ($answerIndex === null) {
            continue;                                    // we cannot grade it
        }

        // ---- the explanation (optional) ----------------------
        $explanation = '';
        foreach (array('explanation', 'reason', 'why', 'rationale') as $key) {
            if (isset($item[$key]) && is_string($item[$key])) {
                $explanation = trim($item[$key]);
                break;
            }
        }

        $clean[] = array(
            'question'    => $text,
            'type'        => ($isTrueFalse ? 'tf' : 'mc'),
            'options'     => $options,
            'answer'      => $answerIndex,
            'explanation' => $explanation,
        );

        if (count($clean) >= (int)$wantedCount) {
            break;
        }
    }

    return $clean;
}/** Builds the strict-JSON prompt for ONE batch of quiz questions. */
/** Returns a short, length-capped string of retrieved material excerpts for quiz prompts. */
function ragQuizExcerpt(array $rag) {
    if (empty($rag['hits'])) {
        return '';
    }
    $cap  = defined('RAG_MAX_CONTEXT_CHARS') ? (int)RAG_MAX_CONTEXT_CHARS : 4500;
    $out  = '';
    $size = 0;
    foreach ($rag['hits'] as $hit) {
        $src = isset($hit['file_name']) ? (string)$hit['file_name'] : 'material';
        $txt = isset($hit['content']) ? trim((string)$hit['content']) : '';
        if ($txt === '') {
            continue;
        }
        $block = '[' . $src . '] ' . $txt;
        $len   = strlen($block);
        if ($size + $len > $cap) {
            $out .= substr($block, 0, max(1, $cap - $size));
            break;
        }
        $out  .= ($out === '' ? '' : "\n---\n") . $block;
        $size += $len;
    }
    return trim($out);
}

function buildQuizBatchPrompt($course, $want, $difficulty, $batchNo, $totalBatches, $materialContext = '') {
    $labels = quizDifficulties();
    $lines  = array();

    $lines[] = 'You are an exam writer creating a ' . $labels[$difficulty] . '-difficulty quiz for a university student.';
    $lines[] = '';
    $lines[] = 'Course name: ' . $course['name'];
    if (isset($course['code']) && $course['code'] !== '') {
        $lines[] = 'Course code: ' . $course['code'];
    }
    if (!empty($course['description'])) {
        $lines[] = 'Course description: ' . $course['description'];
    }
    $lines[] = '';
    if ($materialContext !== '') {
        $lines[] = 'FACTUAL BASE: the excerpts below are from the student\'s own uploaded';
        $lines[] = 'course materials for this course. Base EVERY question strictly on the';
        $lines[] = 'course above AND these excerpts. If an excerpt is empty, ignore it.';
        $lines[] = 'Do not invent facts from general AI knowledge.';
        $lines[] = '';
        $lines[] = '<material context>';
        $lines[] = $materialContext;
        $lines[] = '</material context>';
        $lines[] = '';
    }
    $lines[] = 'Write exactly ' . $want . ' questions (part ' . $batchNo . ' of ' . $totalBatches . ').';
    $lines[] = 'Every question must be about this course and must be clearly different from the other questions.';
    $lines[] = '';
    $lines[] = 'Allowed question types:';
    $lines[] = '1. "mc" = multiple choice with exactly 4 options.';
    $lines[] = '2. "tf" = true/false, with options exactly ["True","False"].';
    $lines[] = '';
    $lines[] = 'Reply with ONLY a JSON array. No text before or after it, no markdown, no code fences.';
    $lines[] = 'Each element must have exactly these keys:';
    $lines[] = '{"question":"...","type":"mc","options":["...","...","...","..."],"answer":0,"explanation":"..."}';
    $lines[] = '"answer" is the 0-based index of the correct option.';
    $lines[] = 'For "tf" questions use options ["True","False"] and answer 0 for True or 1 for False.';
    $lines[] = 'Always include a short "explanation" telling the student WHY the answer is correct.';

    return implode("\n", $lines);
}

/**
 * Asks Gemini for a quiz about one course and returns EXACTLY $count
 * normalised questions (1-100 supported - nothing is hard-coded).
 *
 * SPEED (2026-09): large quizzes use bigger batches (25 questions per
 * API call) and ALL batch prompts are sent CONCURRENTLY (curl_multi in
  * geminiGenerateTextBatch). A 100-question quiz now runs as ceil(4/3) = 2
 * waves of at most 3 concurrent calls instead of 10 sequential ones, and
 * each batch prompt is grounded in the course's own material context.
 * Any batch that fails or returns
 * too few usable questions is topped up through the ORIGINAL sequential
 * path (askGeminiJson), so the existing retry / backup-model fallback
 * for temporary Gemini errors is fully preserved.
 * Nothing is ever invented locally: when Gemini fails, a friendly
 * Exception is thrown instead of saving fake questions.
 */
function generateQuizQuestions($course, $count, $difficulty) {
    $count = (int)$count;
    if ($count < 1)   { $count = 5; }
    if ($count > 100) { $count = 100; }

        if (!isset(quizDifficulties()[$difficulty])) {
        $difficulty = 'medium';
    }

    // RAG: pull the student's OWN course-material context ONCE and reuse it
    // across every batch prompt (single retrieval, no per-batch DB queries
    // and no repeated embedding call). Failure here is non-fatal: we simply
    // fall back to the course name/code/description already in the prompt.
    // This never fabricates questions - it only enriches the prompt with
    // real, ownership-scoped material excerpts.
    $materialContext = '';
    try {
        if (defined('RAG_ENABLED') && RAG_ENABLED
            && function_exists('ragRetrieveContext') && function_exists('getPDO')
        ) {
            $ragPdo = getPDO();
            if ($ragPdo) {
                $ragQuery = $course['name'];
                if (!empty($course['code'])) {
                    $ragQuery .= ' ' . $course['code'];
                }
                $rag = ragRetrieveContext($ragPdo, (int)$course['user_id'], (int)$course['id'], $ragQuery);
                $materialContext = ragQuizExcerpt($rag);
            }
        }
    } catch (Throwable $e) {
        $materialContext = '';
    }

    $questions = array();
    $seen      = array();                              // fingerprints of kept questions
    $lastError = '';

    $batchSize = 25;                                   // questions per API call (parallel phase)
    $batches   = (int)ceil($count / $batchSize);

    if ($batches === 1) {
        // Small quiz (up to 25 questions): ONE call through the original
        // resilient path - same retries and quality as before, but the
        // single prompt asks for all questions at once.
        $prompt = buildQuizBatchPrompt($course, $count, $difficulty, 1, 1, $materialContext);
        for ($attempt = 1; $attempt <= 2 && count($questions) < $count; $attempt++) {
            try {
                $err = quizCollectBatch(askGeminiJson($prompt), $count, $questions, $seen, $count);
                if ($err === '') {
                    break;                             // batch done
                }
                $lastError = $err;
            } catch (Exception $e) {
                $lastError = $e->getMessage();
            }
        }
    } else {
        // SPEED: fire ALL batch prompts at the same time and wait once,
        // instead of waiting for each batch sequentially.
        $prompts = array();
        $quotaExhausted = false;
        for ($batch = 1; $batch <= $batches; $batch++) {
            $want      = min($batchSize, $count - ($batch - 1) * $batchSize);
            $prompts[] = buildQuizBatchPrompt($course, $want, $difficulty, $batch, $batches, $materialContext);
        }

        $quizTimeout = defined('GEMINI_QUIZ_REQUEST_TIMEOUT')
            ? max(15, (int)GEMINI_QUIZ_REQUEST_TIMEOUT)
            : (defined('GEMINI_REQUEST_TIMEOUT') ? max(15, (int)GEMINI_REQUEST_TIMEOUT) : 60);

        $batchResults = geminiGenerateTextBatch($prompts, true, $quizTimeout);
        foreach ($batchResults as $res) {
            if (count($questions) >= $count) {
                break;                                 // enough already
            }
            if (empty($res['ok'])) {
                $lastError = $res['error'];
                // A hard quota error means the original top-up loop below cannot
                // help (it hits the same dead quota and wastes more calls), so
                // flag it and skip straight to the friendly failure message.
                if (geminiIsQuotaExceeded(isset($res['http']) ? $res['http'] : 0, $res['error'])) {
                    $quotaExhausted = true;
                    $lastError = "You've reached Gemini's free-tier request limit. "
                        . "The free tier allows only 20 quiz-generating calls per day, "
                        . "so this quiz can't be generated right now. Please try again "
                        . "tomorrow, or check your Google API plan and billing - a paid "
                        . "tier removes the daily limit.";
                }
                continue;                              // failed batch -> top-up below
            }
            $err = quizCollectBatch($res['text'], $batchSize, $questions, $seen, $count);
            if ($err !== '') {
                $lastError = $err;
            }
        }
    }

    // ---- top-up: missing questions through the ORIGINAL sequential ----
    // ---- resilient path (same fallback/retry system as always)    ----
    $rounds    = 0;
    $maxRounds = 4;                                    // bounded: never loop forever
    while (count($questions) < $count && $rounds < $maxRounds && !$quotaExhausted) {
        $rounds++;
        $missing = $count - count($questions);
        $want    = min(10, $missing);
        $prompt  = buildQuizBatchPrompt($course, $want, $difficulty, $rounds, 1, $materialContext);

        for ($attempt = 1; $attempt <= 2 && count($questions) < $count; $attempt++) {
            try {
                $err = quizCollectBatch(askGeminiJson($prompt), $want, $questions, $seen, $count);
                if ($err === '') {
                    break;                             // this top-up batch is done
                }
                $lastError = $err;
            } catch (Exception $e) {
                $lastError = $e->getMessage();
            }
        }
    }

    if (count($questions) < $count) {
        if (count($questions) === 0) {
            throw new Exception('The AI could not create this quiz right now. '
                . ($lastError !== '' ? $lastError . ' ' : '')
                . 'Please try again in a moment.');
        }
        throw new Exception('The AI returned ' . count($questions) . ' of ' . $count
            . ' questions. Please try again - a smaller number of questions is more reliable.');
    }

    // Never save more than the student asked for.
    return array_slice($questions, 0, $count);
}

/**
 * SPEED helper shared by the parallel and sequential paths: turns ONE
 * raw Gemini answer into normalised questions and appends the usable,
 * non-duplicate ones (up to $count in total). Returns '' on success or
 * a friendly error message for the caller's $lastError.
 */
function quizCollectBatch($raw, $want, &$questions, &$seen, $count) {
    $decoded = extractQuizJsonArray($raw);
    if (!is_array($decoded)) {
        return 'Gemini returned invalid JSON.';
    }

    $batchQuestions = normaliseQuizQuestions($decoded, $want);
    if (count($batchQuestions) === 0) {
        return 'Gemini returned questions in an unsupported format.';
    }

    foreach ($batchQuestions as $question) {
        $fingerprint = strtolower(preg_replace('/\s+/', ' ', $question['question']));
        if (isset($seen[$fingerprint])) {
            continue;                                  // duplicate across batches
        }
        $seen[$fingerprint] = true;
        $questions[]        = $question;

        if (count($questions) >= $count) {
            break;
        }
    }

    return '';
}

// ------------------------------------------------------------
//  DISPLAY HELPER shared by the Student Assistant pages
// ------------------------------------------------------------

// history.php already defines formatAiText(). The function_exists()
// guard means a page could include both files without a fatal error,
// and the existing history page keeps working unchanged.
if (!function_exists('formatAiText')) {
    /**
     * Formats an AI answer for the screen. ALL HTML is escaped first
     * (so the text can never inject markup), then simple markdown
     * (**bold**, `code`, # headers, bullet lists) becomes styled HTML.
     * Identical behaviour to the existing history.php.
     */
    function formatAiText($text) {
        $t = htmlspecialchars((string)$text, ENT_QUOTES);
        $t = str_replace("\r\n", "\n", $t);
        $t = str_replace("\r", "\n", $t);

        $t = preg_replace('/^### (.*)$/m', '<h5 class="ai-h">$1</h5>', $t);
        $t = preg_replace('/^## (.*)$/m', '<h4 class="ai-h">$1</h4>', $t);
        $t = preg_replace('/^# (.*)$/m', '<h4 class="ai-h">$1</h4>', $t);
        $t = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $t);
        $t = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $t);
        $t = preg_replace('/^[*-] (.+)$/m', '&bull; $1', $t);

        return nl2br($t);
    }
}

// ------------------------------------------------------------
//  Lists used by course.php / assignment.php / quizzes.php
// ------------------------------------------------------------

/** Material notes of one course (metadata only â€“ files are not stored). */
function listCourseMaterials($pdo, $userId, $courseId) {
    $stmt = $pdo->prepare(
        'SELECT id, file_name, file_type, file_size, note, created_at, index_status, index_note, chunk_count, indexed_at
           FROM course_materials
          WHERE user_id = ? AND course_id = ?
          ORDER BY created_at DESC'
    );
    $stmt->execute([(int)$userId, (int)$courseId]);
    return $stmt->fetchAll();
}
/** Deletes one material of this user (its RAG chunks vanish via ON DELETE CASCADE). */
function deleteUserMaterial($pdo, $userId, $materialId) {
    $stmt = $pdo->prepare('DELETE FROM course_materials WHERE id = ? AND user_id = ?');
    $stmt->execute([(int)$materialId, (int)$userId]);
    return $stmt->rowCount() > 0;
}

/** Label + Bootstrap badge colour for a material's RAG index status. */
function materialStatusLabel($status) {
    $status = strtolower(trim((string)$status));
    if ($status === 'ready') { return 'Ready'; }
    if ($status === 'processing') { return 'Processing'; }
    if ($status === 'failed') { return 'Failed'; }
    return 'Pending';
}
function materialStatusBadge($status) {
    $status = strtolower(trim((string)$status));
    if ($status === 'ready') { return 'success'; }
    if ($status === 'processing') { return 'warning'; }
    if ($status === 'failed') { return 'danger'; }
    return 'secondary';
}


/** Assignments of one course (open ones first). */
function listCourseAssignments($pdo, $userId, $courseId) {
    $stmt = $pdo->prepare(
        'SELECT id, course_id, title, description, due_date, status, created_at
           FROM assignments
          WHERE user_id = ? AND course_id = ?
          ORDER BY (status = \'completed\') ASC, due_date IS NULL, due_date ASC, created_at DESC'
    );
    $stmt->execute([(int)$userId, (int)$courseId]);
    return $stmt->fetchAll();
}

/** Quizzes of one course, each with attempt count and best score. */
function listCourseQuizzes($pdo, $userId, $courseId) {
    $stmt = $pdo->prepare(
        'SELECT q.id, q.title, q.difficulty, q.questions, q.created_at,
                COUNT(a.id) AS attempts,
                MAX(CASE WHEN a.total_questions > 0
                         THEN a.score / a.total_questions * 100 END) AS best_score
           FROM quizzes q
           LEFT JOIN quiz_attempts a ON a.quiz_id = q.id AND a.user_id = q.user_id
          WHERE q.user_id = ? AND q.course_id = ?
          GROUP BY q.id, q.title, q.difficulty, q.questions, q.created_at
          ORDER BY q.created_at DESC'
    );
    $stmt->execute([(int)$userId, (int)$courseId]);
    return $stmt->fetchAll();
}

/** One quiz of this user (or false when it is not theirs). */
function findUserQuiz($pdo, $quizId, $userId) {
    $quizId = (int)$quizId;
    if ($quizId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT q.id, q.user_id, q.course_id, q.title, q.difficulty, q.questions,
                q.created_at, c.name AS course_name, c.code AS course_code
           FROM quizzes q
           JOIN courses c ON c.id = q.course_id
          WHERE q.id = ? AND q.user_id = ?'
    );
    $stmt->execute([$quizId, (int)$userId]);
    $row = $stmt->fetch();
    return ($row === false) ? false : $row;
}

/** Small helper: "245 KB" instead of "250880". */
function formatFileSize($bytes) {
    $bytes = (int)$bytes;
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

/**
 * Saves a brand-new quiz (the JSON question list is already normalised)
 * and returns the new quiz id. Ownership is enforced by user_id.
 */
function saveQuiz($pdo, $userId, $courseId, $title, $difficulty, $questions) {
    if (!isset(quizDifficulties()[$difficulty])) {
        $difficulty = 'medium';
    }
    $stmt = $pdo->prepare(
        'INSERT INTO quizzes (user_id, course_id, title, difficulty, questions)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$userId,
        (int)$courseId,
        (string)$title,
        $difficulty,
        json_encode($questions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Records one finished quiz attempt (for reporting + review).
 * $answers is the array of indexes the student picked (0-based), or null.
 * Returns the new attempt id.
 */
function saveQuizAttempt($pdo, $userId, $quizId, $score, $totalQuestions, $answers) {
    $stmt = $pdo->prepare(
        'INSERT INTO quiz_attempts (user_id, quiz_id, score, total_questions, answers)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$userId,
        (int)$quizId,
        (int)$score,
        (int)$totalQuestions,
        ($answers === null) ? null : json_encode($answers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    return (int)$pdo->lastInsertId();
}

/** All quizzes of this user (newest first), with their course name. */
function listUserQuizzes($pdo, $userId) {
    $stmt = $pdo->prepare(
        'SELECT q.id, q.course_id, q.title, q.difficulty, q.questions, q.created_at,
                c.name AS course_name, c.code AS course_code,
                COUNT(a.id) AS attempts,
                MAX(CASE WHEN a.total_questions > 0
                         THEN a.score / a.total_questions * 100 END) AS best_score
           FROM quizzes q
           JOIN courses c ON c.id = q.course_id
           LEFT JOIN quiz_attempts a ON a.quiz_id = q.id AND a.user_id = q.user_id
          WHERE q.user_id = ?
       GROUP BY q.id, q.course_id, q.title, q.difficulty, q.questions, q.created_at, c.name, c.code
          ORDER BY q.created_at DESC'
    );
    $stmt->execute([(int)$userId]);
    return $stmt->fetchAll();
}

/** One quiz attempt of this user (or false), with the quiz title + course for display. */
function findUserQuizAttempt($pdo, $attemptId, $userId) {
    $attemptId = (int)$attemptId;
    if ($attemptId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT a.id, a.quiz_id, a.score, a.total_questions, a.answers, a.created_at,
               q.title AS quiz_title, q.questions AS quiz_questions,
               c.name AS course_name
          FROM quiz_attempts a
          JOIN quizzes q ON q.id = a.quiz_id
          JOIN courses c ON c.id = q.course_id
         WHERE a.id = ? AND a.user_id = ?'
    );
    $stmt->execute([$attemptId, (int)$userId]);
    return $stmt->fetch();
}
