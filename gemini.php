<?php
// ============================================================
//  GEMINI.PHP - sends a question (and optionally a file) to the Gemini API
//
//  You normally do NOT need to change anything in this file.
//  It is used by:  api/chat.php        (the website, via AJAX)
//                  test_gemini.php     (the command-line test)
//
//  SPEED (2026-09): every HTTP call has a bounded per-attempt timeout
//  from config.php (GEMINI_REQUEST_TIMEOUT / GEMINI_CONNECT_TIMEOUT /
//  GEMINI_EMBED_TIMEOUT), so one stalled Google call can never burn the
//  whole GEMINI_TIME_BUDGET on its own. The question embedding is also
//  cached, so repeat questions (e.g. Regenerate) skip a paid API call.
// ============================================================

require_once __DIR__ . '/config.php';

/**
 * Returns the instructions prepended to every question,
 * depending on the chosen assistant mode.
 *
 *   'general' - default helpful assistant
 *   'study'   - explains topics, summarises notes, quizzes & flashcards
 *   'coding'  - programming help and debugging for students
 *   'course'  - stays on topic for one specific course
 *   'health'  - general health info with a clear "not a diagnosis" note
 */
function systemPromptForMode($mode) {
    if ($mode === 'study') {
        return 'You are a friendly Study Assistant. Explain topics clearly and simply, '
             . 'summarise notes, and create useful quizzes or flashcards when they help. '
             . 'Use plain language and short examples.';
    }
    if ($mode === 'coding') {
        return 'You are a Coding Assistant for students. Help with programming questions, '
             . 'debugging and explaining code. Show small, complete examples and say clearly '
             . 'what each part does. Point out mistakes and explain how to fix them instead of '
             . 'only giving the final code.';
    }
    if ($mode === 'course') {
        return 'You are a Course Assistant helping a student study one specific course. '
             . 'Explain concepts clearly at a student-friendly level, give definitions, '
             . 'examples and summaries, and help with exam preparation. '
             . 'Use the course details below to stay on topic.';
    }
    if ($mode === 'health') {
        return 'You are a Health Assistant that gives general health information and '
             . 'symptom guidance. ALWAYS add a short disclaimer: this is NOT a medical '
             . 'diagnosis and you are not a doctor. For serious, persistent or emergency '
             . 'concerns, recommend seeing a qualified medical professional or calling '
             . 'emergency services. Keep answers clear and cautious.';
    }
    // general chat
    return 'You are a helpful and friendly assistant. Keep answers clear and well structured.';
}

/**
 * The assistant modes shown in the chat mode selector
 * (value stored/sent to the API => label shown to the user).
 * Keep the values identical to the checks in systemPromptForMode().
 */
function assistantModes() {
    return array(
        'general' => 'General Chat',
        'study'   => 'Study Assistant',
        'coding'  => 'Coding Assistant',
        'course'  => 'Course Assistant',
        'health'  => 'Health Assistant',
    );
}


/**
 * Sends ONE question to Gemini and returns the answer as a string.
 *
 * $fileData is optional: pass an array with 'mime' and 'base64' keys
 * when the user attached a file (PDF, TXT, DOCX or an image).
 *
 * Uses the resilient call in geminiGenerateText(): the request itself
 * is exactly the same as it always was, but when Google answers
 * "model is experiencing high demand" the call is retried and the
 * backup models from config.php are tried automatically.
 *
 * Throws an Exception with a friendly message when something fails.
 */
function askGemini($question, $fileData = null) {
    return geminiGenerateText($question, false, $fileData);
}

/**
 * The models we try, in order: the configured model first, then the
 * backups from config.php (GEMINI_MODEL_BACKUPS). Duplicates removed.
 */
function geminiModelList() {
    $models = array(GEMINI_MODEL);

    if (defined('GEMINI_MODEL_BACKUPS') && is_string(GEMINI_MODEL_BACKUPS)) {
        foreach (explode(',', GEMINI_MODEL_BACKUPS) as $model) {
            $model = trim($model);
            if ($model !== '' && !in_array($model, $models, true)) {
                $models[] = $model;
            }
        }
    }

    return $models;
}

/**
 * True when an error just means "the model is busy right now" - those
 * are worth retrying (usually after a short pause) or worth trying on
 * another model. Google sends this as HTTP 429/5xx or as a message
 * about "high demand" / "overloaded" / "quota".
 */
function geminiErrorIsTemporary($httpCode, $message) {
    $temporaryCodes = array(429, 500, 502, 503, 504);
    if (in_array((int)$httpCode, $temporaryCodes, true)) {
        return true;
    }

    $message = strtolower((string)$message);
    foreach (array('high demand', 'overload', 'try again later', 'resource exhausted',
                   'rate limit', 'quota', 'temporarily unavailable') as $needle) {
        if (strpos($message, $needle) !== false) {
            return true;
        }
    }

    return false;
}


/**
 * True when Google is rejecting the request because the account has hit a hard
 * usage limit (the free-tier daily quota of 20 generate_content calls, or a
 * "resource exhausted" quota body). These errors do NOT recover in seconds and
 * MUST NOT be retried - retrying only burns the remaining quota and then
 * surfaces a raw API dump to the user.
 *
 * Contrast with geminiErrorIsTemporary(), which covers retryable 503
 * "high demand" and transient rate-limit spikes.
 */
function geminiIsQuotaExceeded($httpCode, $message) {
    $code = (int) $httpCode;
    $l    = strtolower((string) $message);

    if ($code == 429 || $code == 425 || $code == 439
        || strpos($l, 'resource exhausted') !== false
        || strpos($l, 'exceeded your current quota') !== false
        || strpos($l, 'quota exceeded') !== false
        || (strpos($l, 'quota') !== false && strpos($l, 'exceed') !== false)
    ) {
        // A generic "high demand" 429 is a transient overload, NOT a quota wall.
        if (strpos($l, 'high demand') !== false) {
            return false;
        }
        return true;
    }
    return false;
}



/**
 * API base URL. GEMINI_API_BASE (optional, NOT defined in config.php)
 * can override it to point tests at a local mock server; production
 * always uses the real Google endpoint.
 */
function geminiApiBase() {
    return defined('GEMINI_API_BASE') && GEMINI_API_BASE !== ''
        ? rtrim((string)GEMINI_API_BASE, '/') . '/'
        : 'https://generativelanguage.googleapis.com/v1beta/';
}

/**
 * Builds the JSON body of ONE Gemini generateContent request.
 * Shared by the single-call path (geminiCallModel) and the parallel
 * batch path (geminiGenerateTextBatch) so both always send exactly
 * the same request shape.
 */
function geminiBuildRequestBody($prompt, $jsonMode, $fileData = null) {
    // The question is one "part"; an attached file becomes a second part
    // using inline base64 data - exactly like the original chat code.
    $parts = array(
        array('text' => $prompt)
    );

    if ($fileData !== null) {
        $parts[] = array(
            'inlineData' => array(
                'mimeType' => $fileData['mime'],
                'data'     => $fileData['base64']
            )
        );
    }

    $request = array(
        'contents' => array(
            array('parts' => $parts)
        ),
    );

    // Only the quiz path asks for JSON mode - the chat request stays
    // exactly as it always was.
    if ($jsonMode) {
        $request['generationConfig'] = array(
            'response_mime_type' => 'application/json',   // strict JSON mode
            'temperature'        => 0.7,
            'maxOutputTokens'    => 16384,
        );
    }

    return $request;
}
/**
 * ONE call to ONE model. Never throws - returns an array:
 *   array('ok' => bool, 'text' => string, 'error' => string,
 *         'temporary' => bool, 'http' => int)
 *
 * SPEED: per-attempt curl timeouts come from config.php
 * (GEMINI_REQUEST_TIMEOUT / GEMINI_CONNECT_TIMEOUT) so one stalled
 * call can never eat the whole GEMINI_TIME_BUDGET on its own.
 */
function geminiCallModel($model, $prompt, $jsonMode, $fileData = null) {
    $url = geminiApiBase() . 'models/'
         . $model
         . ':generateContent?key=' . GEMINI_API_KEY;

    // Same request shape as always - now shared with the parallel batch helper.
    $request = geminiBuildRequestBody($prompt, $jsonMode, $fileData);

    // Bounded per-attempt timeouts (config.php). Defaults keep the
    // original behaviour when the constants are not defined.
    $reqTimeout = defined('GEMINI_REQUEST_TIMEOUT') ? max(5, (int)GEMINI_REQUEST_TIMEOUT) : 120;
    $conTimeout = defined('GEMINI_CONNECT_TIMEOUT') ? max(1, (int)GEMINI_CONNECT_TIMEOUT) : 10;

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($request),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_TIMEOUT        => $reqTimeout,
        // Fail fast when the network is down instead of hanging for
        // the whole generation timeout.
        CURLOPT_CONNECTTIMEOUT => $conTimeout,
    ));

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        return array('ok' => false, 'text' => '',
            'error' => 'Could not reach the Gemini API (network error: ' . $curlError . ')',
            'temporary' => true, 'http' => $httpCode);
    }

    $result  = json_decode($response, true);
    $message = isset($result['error']['message']) ? (string)$result['error']['message'] : '';

    if ($httpCode !== 200) {
        if ($message === '') {
            $message = 'HTTP code ' . $httpCode . ' - ' . substr((string)$response, 0, 200);
        }
        return array('ok' => false, 'text' => '',
            'error' => 'Gemini API error: ' . $message,
            'temporary' => geminiErrorIsTemporary($httpCode, $message), 'http' => $httpCode);
    }

    // Collect the text of EVERY part (some models split the answer up).
    $answer = '';
    if (isset($result['candidates'][0]['content']['parts'])
        && is_array($result['candidates'][0]['content']['parts'])) {
        foreach ($result['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                $answer .= $part['text'];
            }
        }
    }

    if (trim($answer) === '') {
        return array('ok' => false, 'text' => '',
            'error' => 'Gemini returned an empty answer (the request may have been blocked by safety filters).',
            'temporary' => true, 'http' => $httpCode);
    }

    return array('ok' => true, 'text' => $answer, 'error' => '', 'temporary' => false, 'http' => $httpCode);
}


/**
 * Resilient Gemini call used by BOTH the chat and the quiz generator.
 *
 * Google's free models regularly answer with "This model is currently
 * experiencing high demand" (HTTP 503) - so instead of failing we now
 *   1. retry the same model once after a short pause, and
 *   2. automatically try the backup models from config.php.
 *
 * SPEED (2026-09): bounded retry - at most GEMINI_MAX_CALLS calls inside
 * a total GEMINI_TIME_BUDGET, so a "high demand" spike can never stall
 * the chat for long.
 *
 * Returns the answer text, or throws Exception with a friendly message.
 */
function geminiGenerateText($prompt, $jsonMode = false, $fileData = null) {
    $models    = geminiModelList();
    $lastError = '';
    $attempts  = 0;
    $maxCalls  = defined('GEMINI_MAX_CALLS') ? max(1, (int)GEMINI_MAX_CALLS) : 8;
    $deadline  = time() + (defined('GEMINI_TIME_BUDGET') ? max(5, (int)GEMINI_TIME_BUDGET) : 150);

    foreach ($models as $model) {
        for ($try = 1; $try <= 2 && $attempts < $maxCalls; $try++) {
            if (time() > $deadline) {
                break 2;                                  // out of time - report the last error
            }

            $attempts++;
            $call = geminiCallModel($model, $prompt, $jsonMode, $fileData);

            if ($call['ok']) {
                return $call['text'];
            }

            $lastError = $call['error'];

            // Quota exhausted (free-tier daily limit) is NOT recoverable by
            // retrying - it won't reset in seconds. Stop the retry/backoff
            // loop now and report a clean message, so we don't burn the
            // account's remaining calls on a dead quota bucket.
            if (geminiIsQuotaExceeded($call['http'], $call['error'])) {
                $lastError = "You've reached Gemini's free-tier request limit. "
                    . "Please try again in a few minutes, or check your Google API "
                    . "plan and billing - a paid tier removes the daily limit.";
                break 2;
            }

            if (!$call['temporary']) {
                break;                                    // e.g. bad request - next model
            }
            if ($try < 2 && $attempts < $maxCalls) {
                sleep(2);                                 // the spike is usually short
            }
        }
    }

    throw new Exception('The AI service is busy right now. ' . $lastError
        . ' Please try again in a moment.');
}

/**
 * SPEED (2026-09): runs SEVERAL independent prompts CONCURRENTLY against
 * the primary model using curl_multi (one HTTP connection per prompt).
 *
 * Used by the quiz generator so a 50/100-question quiz needs 2/4
 * parallel API calls instead of 5/10 sequential ones.
 *
 * Returns an array ALIGNED WITH $prompts; each element is:
 *   array('ok' => bool, 'text' => string, 'error' => string, 'http' => int)
 *
 * Reliability is unchanged: batches that fail here fall back to the
 * original sequential retry path (askGeminiJson) in the caller, so the
 * backup-model system for temporary Gemini errors still applies.
 */
function geminiGenerateTextBatch(array $prompts, $jsonMode = false, $timeout = null, $maxParallel = null) {
    $results = array();
    if (count($prompts) === 0) {
        return $results;
    }

    // Bound concurrent Gemini calls so big quizzes stay fast without
    // hitting rate limits. Defaults to GEMINI_QUIZ_MAX_PARALLEL and is
    // hard-capped to 3 for API safety.
    if ($maxParallel === null) {
        $maxParallel = defined('GEMINI_QUIZ_MAX_PARALLEL') ? (int)GEMINI_QUIZ_MAX_PARALLEL : 3;
    }
    $maxParallel = max(1, min((int)$maxParallel, 3));

    $models  = geminiModelList();
    $primary = $models[0];
    if ($timeout === null) {
        $timeout = defined('GEMINI_REQUEST_TIMEOUT') ? max(5, (int)GEMINI_REQUEST_TIMEOUT) : 120;
    }
    $timeout    = max(5, (int)$timeout);
    $conTimeout = defined('GEMINI_CONNECT_TIMEOUT') ? max(1, (int)GEMINI_CONNECT_TIMEOUT) : 10;

    $url = geminiApiBase() . 'models/'
         . $primary . ':generateContent?key=' . GEMINI_API_KEY;

    $mh      = curl_multi_init();
    $handles = array();           // i => ch
    // Create every handle up front, but ADD only $maxParallel of them to
    // the multi handle at a time (sliding window). This caps concurrent
    // Gemini calls at $maxParallel, so a 100-question quiz runs as
    // ceil(4 / 3) = 2 waves instead of 4 simultaneous calls.
    foreach (array_keys($prompts) as $i) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(geminiBuildRequestBody($prompts[$i], $jsonMode)),
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $conTimeout,
        ));
        $handles[$i] = $ch;
    }

    $decode = function ($ch) {
        $response  = curl_multi_getcontent($ch);
        $curlError = curl_error($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($response === false || $curlError !== '') {
            return array('ok' => false, 'text' => '',
                'error' => 'Could not reach the Gemini API (network error: ' . $curlError . ')',
                'http' => $httpCode);
        }

        $result  = json_decode((string)$response, true);
        $message = isset($result['error']['message']) ? (string)$result['error']['message'] : '';

        if ($httpCode !== 200) {
            if ($message === '') {
                $message = 'HTTP code ' . $httpCode . ' - ' . substr((string)$response, 0, 200);
            }
            return array('ok' => false, 'text' => '',
                'error' => 'Gemini API error: ' . $message, 'http' => $httpCode);
        }

        // Collect the text of EVERY part (some models split the answer up).
        $answer = '';
        if (isset($result['candidates'][0]['content']['parts'])
            && is_array($result['candidates'][0]['content']['parts'])) {
            foreach ($result['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['text']) && is_string($part['text'])) {
                    $answer .= $part['text'];
                }
            }
        }

        if (trim($answer) === '') {
            return array('ok' => false, 'text' => '',
                'error' => 'Gemini returned an empty answer (the request may have been blocked by safety filters).',
                'http' => $httpCode);
        }

        return array('ok' => true, 'text' => $answer, 'error' => '', 'http' => $httpCode);
    };

    // Run the handles in waves of at most $maxParallel: start a wave, drive
    // it until every handle in that wave is done, then collect and start the
    // next wave. No per-handle id mapping is needed, so the code is robust
    // across PHP/curl variants (resource vs CurlHandle objects).
    $queued = 0;                          // next handle to schedule
    $total  = count($handles);
    $keys   = array_keys($handles);

    while ($queued < $total) {
        // Gather up to $maxParallel handles into one wave.
        $wave = array();                  // i => ch for this wave only
        while ($queued < $total && count($wave) < $maxParallel) {
            $i        = $keys[$queued];
            $wave[$i] = $handles[$i];
            curl_multi_add_handle($mh, $handles[$i]);
            $queued++;
        }

        // Drive the whole wave until no handle is still active.
        $active = null;
        do {
            $status = curl_multi_exec($mh, $active);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        while ($active > 0) {
            curl_multi_select($mh, 1.0);
            do {
                $status = curl_multi_exec($mh, $active);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
        }

        // Every handle in this wave is done: collect, then clean up.
        foreach ($wave as $i => $ch) {
            $results[$i] = $decode($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
    }

    curl_multi_close($mh);
    ksort($results);
    return $results;
}

/**
 * Strict-JSON variant used by the quiz generator: same resilient call,
 * but asks Gemini for JSON mode so the answer is pure JSON.
 * Returns the RAW JSON text (still parsed/validated by the caller).
 */
function askGeminiJson($prompt) {
    return geminiGenerateText($prompt, true);
}


// ============================================================
//  EMBEDDINGS (RAG - "ask about YOUR course materials")
// ============================================================

/**
 * Calls the embedding endpoint for a batch of texts and returns the
 * vectors IN THE SAME ORDER as the input:
 *
 *      askGeminiEmbeddings(array $texts, $taskType)  =>  array of float[][]
 *
 * $taskType: 'RETRIEVAL_DOCUMENT' (chunks) or 'RETRIEVAL_QUERY' (questions).
 * Model + dimensionality come from config.php (RAG_EMBED_MODEL/RAG_EMBED_DIM).
 * Throws an Exception with a friendly message when the API keeps failing.
 *
 * SPEED (2026-09): for a single question embedding, the vector is cached
 * in a temp-dir file (RAG_EMBED_CACHE / RAG_EMBED_CACHE_TTL) so repeat
 * questions (e.g. the Regenerate button) skip the paid API round-trip.
 */
function askGeminiEmbeddings(array $texts, $taskType = 'RETRIEVAL_DOCUMENT') {
    if (count($texts) === 0) { return array(); }

    // SPEED PATCH: reuse the embedding of a recently asked question.
    // Only for single-text queries (RETRIEVAL_QUERY) - never for the
    // document chunks that get indexed once per material.
    $cacheFile = '';
    if (count($texts) === 1 && $taskType === 'RETRIEVAL_QUERY'
        && (!defined('RAG_EMBED_CACHE') || RAG_EMBED_CACHE)) {
        $ttl = defined('RAG_EMBED_CACHE_TTL') ? (int)RAG_EMBED_CACHE_TTL : 3600;
        if ($ttl > 0) {
            $cacheDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ai_chat_embed_cache';
            if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0700, true); }
            if (is_dir($cacheDir) && is_writable($cacheDir)) {
                $cacheFile = $cacheDir . DIRECTORY_SEPARATOR
                    . sha1('q|' . RAG_EMBED_MODEL . '|' . (int)RAG_EMBED_DIM . '|' . $texts[0]) . '.json';
                if (is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) < $ttl) {
                    $cached = json_decode((string)@file_get_contents($cacheFile), true);
                    if (is_array($cached) && count($cached) > 0) {
                        return array(array_map('floatval', $cached));
                    }
                }
            }
        }
    }

    $key   = GEMINI_API_KEY;
    $model = RAG_EMBED_MODEL;
    $url   = geminiApiBase() . 'models/'
           . $model . ':batchEmbedContents?key=' . urlencode($key);

    // SPEED PATCH (2026-09): hard per-attempt ceiling so one slow call
    // cannot burn the whole GEMINI_TIME_BUDGET during a demand spike.
    $embTimeout    = defined('GEMINI_EMBED_TIMEOUT')   ? max(5, (int)GEMINI_EMBED_TIMEOUT)   : 120;
    $embConTimeout = defined('GEMINI_CONNECT_TIMEOUT') ? max(1, (int)GEMINI_CONNECT_TIMEOUT) : 10;

    $vectors   = array();
    $batchSize = 32;                       // requests per API call (limit is 100)
    $attempts  = 0;
    $maxCalls  = 6;

    foreach (array_chunk($texts, $batchSize) as $batch) {
        $requests = array();
        foreach ($batch as $text) {
            $requests[] = array(
                'model'                 => 'models/' . $model,
                'taskType'              => $taskType,
                'title'                 => '',
                'outputDimensionality'  => (int)RAG_EMBED_DIM,
                'content'               => array(
                    'parts' => array(array('text' => (string)$text)),
                ),
            );
        }

        $ok     = false;
        $err    = '';
        $tries  = 0;


        while ($tries < 3 && $attempts < $maxCalls) {
            $tries++;
            $attempts++;

            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
                CURLOPT_POSTFIELDS     => json_encode(array('requests' => $requests)),
                CURLOPT_TIMEOUT        => $embTimeout,
                CURLOPT_CONNECTTIMEOUT => $embConTimeout,
            ));
            $response = curl_exec($ch);
            $curlErr  = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($response === false) {
                $err = 'network error (' . $curlErr . ')';
                if ($tries < 3) { sleep(2); continue; }
                break;
            }

            $result = json_decode((string)$response, true);
            $apiMsg = isset($result['error']['message']) ? (string)$result['error']['message'] : '';

            if ($httpCode !== 200) {
                $err = $apiMsg !== '' ? $apiMsg : ('HTTP ' . $httpCode);
                if (geminiErrorIsTemporary($httpCode, $apiMsg) && $tries < 3) {
                    sleep(3);                       // demand spike - wait and retry
                    continue;
                }
                break;
            }

            if (isset($result['embeddings']) && is_array($result['embeddings'])) {
                foreach ($result['embeddings'] as $emb) {
                    $values = isset($emb['values']) && is_array($emb['values'])
                        ? array_map('floatval', $emb['values']) : array();
                    $vectors[] = $values;
                }
                $ok = true;
                break;
            }

            $err = 'unexpected embedding response';
            break;
        }

        if (!$ok) {
            throw new Exception('Could not create the material embeddings. ' . $err
                . ' Please try again in a moment.');
        }
    }

    // SPEED PATCH: remember a fresh single-question embedding for repeats.
    if ($cacheFile !== '' && count($texts) === 1
        && isset($vectors[0]) && is_array($vectors[0]) && count($vectors[0]) > 0) {
        @file_put_contents($cacheFile, json_encode($vectors[0]), LOCK_EX);
    }

    return $vectors;
}

/** Single-text convenience wrapper (used for the student's question). */
function askGeminiEmbedding($text, $taskType = 'RETRIEVAL_QUERY') {
    $vecs = askGeminiEmbeddings(array($text), $taskType);
    return (count($vecs) > 0) ? $vecs[0] : null;
}
