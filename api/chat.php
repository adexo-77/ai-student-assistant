<?php
// ============================================================
//  API/CHAT.PHP – this is what js/chat.js calls (AJAX)
//
//  1. reads the question + mode (+ an optional attached file)
//  2. sends it to Gemini  (gemini.php)
//  3. saves question + answer in MySQL
//  4. sends the answer back to the page (JSON)
// ============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../gemini.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/study.php';   // findUserCourse(), studyContextInstruction()
require_once __DIR__ . '/../includes/rag.php';     // RAG: material retrieval + grounding

// Must be logged in to use the chat.
if (!isLoggedIn()) {
    echo json_encode(array('error' => 'Not logged in. Please log in again.'));
    exit;
}

// ------------------------------------------------------------
// 1) READ THE REQUEST
//    - With a file:    multipart/form-data  (browser sends the raw file)
//    - Without a file: JSON body            ({"message":"...","mode":"..."})
//
//    Optional: "course_id" – when the student asked from inside a
//    course (index.php?course_id=5 or the course page).
// ------------------------------------------------------------
$question = '';
$mode     = 'general';
$courseId = 0;
$fileData = null;       // becomes array('name','mime','size','base64') when a file is attached

if (count($_FILES) > 0) {
    // ---- multipart request (a file was attached) ----
    $question = trim((string)$_POST['message']);
    $mode     = trim((string)$_POST['mode']);
    $courseId = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
    if ($mode === '') { $mode = 'general'; }

    $upload = $_FILES['file'];
    if ($upload !== null && (int)$upload['error'] !== UPLOAD_ERR_NO_FILE) {
        if ((int)$upload['error'] === UPLOAD_ERR_OK) {
            try {
                $fileData = readUploadedFile($upload);
            } catch (Exception $e) {
                echo json_encode(array('error' => $e->getMessage()));
                exit;
            }
        } elseif ((int)$upload['error'] === UPLOAD_ERR_INI_SIZE) {
            echo json_encode(array('error' => 'The file is too large (the server limit was reached).'));
            exit;
        } else {
            echo json_encode(array('error' => 'There was a problem reading the uploaded file.'));
            exit;
        }
    }
} else {
    // ---- JSON request ----
    $input = json_decode((string)file_get_contents('php://input'), true);
    $input = $input ?? array();               // if the JSON was empty/invalid
    $question = trim((string)$input['message']);
    $mode     = (string)($input['mode'] ?? 'general');
    $courseId = (int)($input['course_id'] ?? 0);
}

// ------------------------------------------------------------
// 2) BASIC CHECKS
// ------------------------------------------------------------
if ($question === '' && $fileData === null) {
    echo json_encode(array('error' => 'Please write a question first.'));
    exit;
}
// A file alone is fine: turn it into a "summarise this file" request.
if ($question === '' && $fileData !== null) {
    $question = 'Please summarise this file for me.';
}

// Is the API key still missing? Tell the user exactly what to do.
if (GEMINI_API_KEY === '' || GEMINI_API_KEY === 'PASTE_YOUR_GEMINI_API_KEY_HERE') {
    echo json_encode(array('error' => 'Your Gemini API key is not set yet. Open config.php and paste your key there.'));
    exit;
}

// ------------------------------------------------------------
// 3) ASK GEMINI + SAVE TO MYSQL
// ------------------------------------------------------------
try {
    $pdo = getPDO();

    // --- optional course context -----------------------------------------
    // The course must belong to the logged-in user, otherwise it is
    // ignored (a made-up course_id can never reach someone else's data).
    $course = false;
    if ($courseId > 0) {
        $course = findUserCourse($pdo, $courseId, (int)$_SESSION['user_id']);
    }

        // ------------------------------------------------------------
        // RAG: search the student's OWN course-material chunks for
        // relevant context (only when no file is attached - an attached
        // file already IS the context and is sent inline as before).
        // ------------------------------------------------------------
        $rag = null;
        $ragSources = array();
        if ($fileData === null && $course !== false && RAG_ENABLED) {
            try {
                $rag = ragRetrieveContext($pdo, (int)$_SESSION['user_id'], (int)$course['id'], $question);
            } catch (Exception $ragErr) {
                $rag = null;   // retrieval trouble must never break normal chat
            }

            if ($rag !== null && count($rag['hits']) === 0 && $rag['materialsReady'] > 0) {
                // Materials exist but nothing relevant was found:
                // refuse to answer from general knowledge (grounding rule).
                $noInfo = ragNoInfoMessage();
                $stmt = $pdo->prepare(
                    'INSERT INTO messages (user_id, question, answer, course_id) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute(array(
                    $_SESSION['user_id'],
                    $question,
                    $noInfo,
                    (int)$course['id'],
                ));
                echo json_encode(array('answer' => $noInfo, 'sources' => array()));
                exit;
            }
        }

        // Prepends the instructions for the chosen mode
        // (general / study / coding / course / health).
        $finalQuestion = systemPromptForMode($mode) . "\n\n" . $question;

        if ($rag !== null && count($rag['hits']) > 0) {
            // Grounded answer: ONLY the retrieved chunks are the context.
            $finalQuestion = ragBuildGroundedPrompt($question, $rag, $course);
            foreach ($rag['hits'] as $hit) {
                if (!in_array($hit['file_name'], $ragSources, true)) { $ragSources[] = $hit['file_name']; }
            }
        } else {
            // When the student is inside a course, add the course instructions
            // (previous behaviour - unchanged for general chat).
            $context = studyContextInstruction($course, false);
            if ($context !== '') {
                $finalQuestion = $context . "\n\n" . $finalQuestion;
            }
        }

    // 1) Ask Gemini (the file is sent along when one was attached)
    $answer = askGemini($finalQuestion, $fileData);

    // 2) Save question + answer in MySQL (prepared statement = safe).
    //    When a file was used we add its name so the history makes sense.
    $storedQuestion = $question;
    if ($fileData !== null) {
        $storedQuestion .= '  [file: ' . $fileData['name'] . ']';
    }

    $stmt = $pdo->prepare(
        'INSERT INTO messages (user_id, question, answer, course_id) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $_SESSION['user_id'],
        $storedQuestion,
        $answer,
        ($course === false ? null : (int)$course['id']),
    ]);

    // Remember WHICH material was studied with the AI so the course page
    // can list it. Only the metadata is stored – the file itself is still
    // sent straight to Gemini and never saved on the server.
    if ($fileData !== null && $course !== false) {
        $stmt = $pdo->prepare(
            'INSERT INTO course_materials (user_id, course_id, file_name, file_type, file_size, note, file_data)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $_SESSION['user_id'],
            (int)$course['id'],
            $fileData['name'],
            $fileData['mime'],
            (int)$fileData['size'],
            'Studied with the AI',
            base64_decode($fileData['base64']),
        ]);
        $materialId = (int)$pdo->lastInsertId();

        // RAG indexing: extract -> chunk -> embed ONCE, now. The chat
        // later searches these vectors without ever re-reading the file.
        if (RAG_ENABLED) {
            ragIndexMaterial($pdo, (int)$_SESSION['user_id'], $materialId, $fileData);
        }
    }

    // 3) Send the answer back to the page - when the answer was grounded
    //    in course materials, list the source file(s) that were used.
    if (count($ragSources) > 0) {
        // TIMINGS (2026-09): when RAG_DEBUG is on, the response also carries
        if (defined('RAG_DEBUG') && RAG_DEBUG
            && isset($GLOBALS['rag_timings']) && is_array($GLOBALS['rag_timings'])) {
            $response['timings'] = $GLOBALS['rag_timings'];
        }
        // where the time went (embed / DB / Gemini) so the real bottleneck
        // is visible instead of hidden behind the spinner.
        $answer .= "\n\n" . ragSourcesLine($rag['hits']);
    }
    $response = array('answer' => $answer);
    if (count($ragSources) > 0) { $response['sources'] = $ragSources; }
    echo json_encode($response);
} catch (Exception $e) {
    // Something failed (network, API key, database...) – show it nicely.
    echo json_encode(array('error' => $e->getMessage()));
}

// ------------------------------------------------------------
//  HEALTHY UPLOAD: checks extension + size, reads the file into
//  memory as base64 and returns it. THE FILE IS NEVER SAVED ON
//  THE SERVER – it is sent straight to Gemini and discarded.
// ------------------------------------------------------------
function readUploadedFile($upload) {

    if ((int)$upload['size'] === 0) {
        throw new Exception('The file is empty.');
    }

    // 10 MB keeps us safely below Gemini's inline-file limit (20 MB).
    if ((int)$upload['size'] > 10 * 1024 * 1024) {
        throw new Exception('File is too large (maximum 10 MB).');
    }

    // Allowed types: PDF, TXT, DOCX and common image formats.
    $mimeTypes = array(
        'pdf'  => 'application/pdf',
        'txt'  => 'text/plain',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp'
    );

    $ext = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));

    if (!isset($mimeTypes[$ext])) {
        throw new Exception('This file type is not allowed. Use PDF, TXT, DOCX, PNG, JPG, GIF or WEBP.');
    }

    // Read the temporary upload into memory and base64-encode it for the API.
    $content = file_get_contents((string)$upload['tmp_name']);

    return array(
        'name'   => (string)$upload['name'],
        'mime'   => $mimeTypes[$ext],
        'size'   => (int)$upload['size'],
        'base64' => base64_encode($content)
    );
}