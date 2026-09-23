<?php
// ============================================================
//  RAG.PHP – "ask about YOUR course materials"
//
//  Pipeline:
//    upload → extract text → chunk → embed (once, at upload)
//           → store packed float32 vectors in MySQL
//    chat  → embed question → cosine similarity search (top-K)
//           → grounded prompt with ONLY retrieved context → Gemini
//
//  Reuses: gemini.php (askGemini / askGeminiEmbeddings), db.php (getPDO),
//  the existing course_materials upload flow, PDO + prepared statements.
//  MariaDB 10.4 has no native VECTOR type, so vectors are stored as
//  packed float32 BLOBs and cosine similarity is computed in PHP.
// ============================================================

require_once __DIR__ . '/../gemini.php';

/**
 * Records the duration of one pipeline stage (in milliseconds).
 * Only active while RAG_DEBUG is switched on in config.php, so it costs
 * nothing during normal use. The collected numbers are returned with the
 * chat response as a "timings" block, which shows exactly where the time
 * went (question embedding vs vector search vs Gemini vs database).
 */
function ragTiming($label, $ms) {
    if (!defined('RAG_DEBUG') || !RAG_DEBUG) { return; }
    if (!isset($GLOBALS['rag_timings']) || !is_array($GLOBALS['rag_timings'])) {
        $GLOBALS['rag_timings'] = array();
    }
    $GLOBALS['rag_timings'][$label] = round((float)$ms, 2);
}

// ------------------------------------------------------------
// 1) TEXT EXTRACTION
// ------------------------------------------------------------

/**
 * Extracts plain text from an uploaded file (in-memory, base64).
 * Supported: TXT/CSV/HTML (direct), DOCX (ZipArchive), PDF (best-effort
 * stream parser). Images cannot be indexed as text (friendly error).
 * Throws an Exception with a user-friendly message when nothing usable
 * can be extracted.
 */
function ragExtractText($mime, $base64) {
    $raw = base64_decode((string)$base64, true);
    if ($raw === false || $raw === '') {
        throw new Exception('The file could not be read.');
    }

    $text = '';

    if ($mime === 'text/plain' || $mime === 'text/csv') {
        $text = ragToUtf8($raw);
    } elseif (strpos($mime, 'html') !== false) {
        $text = trim(strip_tags(ragToUtf8($raw)));
    } elseif (strpos($mime, 'officedocument.wordprocessingml') !== false) {
        $text = ragExtractDocx($raw);
    } elseif ($mime === 'application/pdf') {
        $text = ragExtractPdf($raw);
    } elseif (strpos($mime, 'image/') === 0) {
        throw new Exception('Images cannot be indexed as searchable text. They are still '
            . 'sent to Gemini when you attach them in the chat.');
    } else {
        throw new Exception('This file type cannot be indexed as text.');
    }

    // Normalise whitespace and drop obvious noise
    $text = str_replace(array("\r\n", "\r"), "\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);

    if (mb_strlen(trim($text)) < 40) {
        throw new Exception('No readable text could be extracted from this file. '
            . 'Scanned PDFs and images are not searchable - try a TXT or DOCX export.');
    }

    return trim($text);
}

/** Best-effort charset conversion to UTF-8. */
function ragToUtf8($raw) {
    if (mb_check_encoding($raw, 'UTF-8')) {
        return $raw;
    }
    $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    return $converted !== false ? $converted : $raw;
}

/** DOCX = ZIP with word/document.xml inside. */
function ragExtractDocx($raw) {
    if (!class_exists('ZipArchive')) {
        throw new Exception('DOCX text extraction is not available on this server - try a TXT export.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($tmp, $raw);
    $zip = new ZipArchive();
    $text = '';
    if ($zip->open($tmp) === true) {
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml !== false) {
            $text = str_replace('</w:p>', "\n", $xml);          // paragraph breaks
            $text = preg_replace('/<[^>]+>/', ' ', $text);      // strip all XML tags
            $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }
    @unlink($tmp);
    if (trim($text) === '') {
        throw new Exception('No text was found inside this DOCX file.');
    }
    return $text;
}

/**
 * Best-effort PDF text extraction without any library:
 * inflate the content streams (FlateDecode) and pull the literal
 * strings from Tj / TJ text operators. Works for most text-based
 * PDFs; scanned PDFs have no text layer and fail with a clear message.
 */
function ragExtractPdf($raw) {
    $text = '';

    if (preg_match_all('/stream\r?\n(.*?)endstream/s', $raw, $streams)) {
        foreach ($streams[1] as $stream) {
            $data = @gzuncompress($stream);
            if ($data === false) { $data = @gzinflate($stream); }
            if ($data === false || (strpos($data, 'Tj') === false && strpos($data, 'TJ') === false)) {
                continue;
            }
            // " (Hello World) Tj "  and  " [ (Hello) -2 (World) ] TJ "
            if (preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)\s*T[jJ]/', $data, $m)) {
                $text .= implode('', $m[1]) . "\n";
            }
            if (preg_match_all('/\[((?:[^\[\]\\\\]|\\\\.)*)\]\s*TJ/', $data, $m)) {
                foreach ($m[1] as $array) {
                    if (preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)/', $array, $s)) {
                        $text .= implode('', $s[1]) . "\n";
                    }
                }
            }
        }
    }

    $text = str_replace(array('\(', '\)', '\\\\'), array('(', ')', '\\'), $text);

    if (mb_strlen(trim($text)) < 40) {
        throw new Exception('This PDF has no readable text layer (it may be scanned). '
            . 'For reliable indexing attach a TXT or DOCX version.');
    }
    return $text;
}

// ------------------------------------------------------------
// 2) CHUNKING
// ------------------------------------------------------------

/**
 * Splits extracted text into overlapping chunks of ~RAG_CHUNK_SIZE
 * characters, cutting on sentence boundaries when possible.
 * Returns an array of non-empty chunk strings (deduplicated).
 */
function ragChunkText($text) {
    $text = trim((string)$text);
    if ($text === '') {
        return array();                       // genuinely empty: nothing to index
    }
    $size    = (int)RAG_CHUNK_SIZE;
    $overlap = (int)RAG_CHUNK_OVERLAP;

    // short / single-sentence / boundary-free document: keep as ONE chunk
    // so it stays searchable (never silently drop real content).
    if (mb_strlen($text) <= $size) {
        return array($text);
    }
    // split into sentences / paragraphs, keeping the separators out
    $parts = preg_split('/(?<=[.!?])\s+|\n{2,}/', $text);
    $chunks   = array();
    $current  = '';

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') { continue; }

        // a single sentence longer than a chunk: hard-split it on words
        while (mb_strlen($part) > $size) {
            $piece = mb_substr($part, 0, $size);
            $cut   = mb_strrpos($piece, ' ');
            if ($cut !== false && $cut > $size * 0.5) { $piece = mb_substr($piece, 0, $cut); }
            if (trim($current) !== '') { $chunks[] = trim($current . ' ' . $piece); }
            else { $chunks[] = $piece; }
            $part = mb_substr($part, mb_strlen($piece));
            if (trim($part) === '') { break; }
        }

        if (trim($part) === '') { continue; }

        if ($current !== '' && mb_strlen($current) + mb_strlen($part) + 1 > $size) {
            $chunks[] = trim($current);
            // start the next chunk with a tail of the previous one (overlap)
            $tail = mb_strlen($current) > $overlap ? mb_substr($current, -$overlap) : $current;
            $current = trim($tail . ' ' . $part);
        } else {
            $current = trim($current . ' ' . $part);
        }
    }
    if (trim($current) !== '') { $chunks[] = trim($current); }

    // keep chunks that actually say something; drop duplicates
    $out = array();
    $seen = array();
    foreach ($chunks as $chunk) {
        $chunk = trim(preg_replace('/\s+/', ' ', $chunk));
        if (mb_strlen($chunk) < 40) { continue; }
        $hash = md5($chunk);
        if (isset($seen[$hash])) { continue; }
        $seen[$hash] = true;
        $out[] = $chunk;
        if (count($out) >= 500) { break; }          // hard safety cap per document
    }
    return $out;
}

// ------------------------------------------------------------
// 3) VECTOR HELPERS (pack / unpack / cosine)
// ------------------------------------------------------------

/** float[] → packed float32 BLOB (stored in MySQL). */
function ragPackVector(array $vec) {
    return pack('f*', ...$vec);
}

/** BLOB → float[]. */
function ragUnpackVector($blob) {
    $vals = unpack('f*', (string)$blob);
    return $vals === false ? array() : array_values($vals);
}

/** Cosine similarity of two vectors. */
function ragCosine(array $a, array $b) {
    $n = min(count($a), count($b));
    if ($n === 0) { return -1.0; }
    $dot = 0.0; $na = 0.0; $nb = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dot += $a[$i] * $b[$i];
        $na  += $a[$i] * $a[$i];
        $nb  += $b[$i] * $b[$i];
    }
    if ($na == 0.0 || $nb == 0.0) { return -1.0; }
    return $dot / (sqrt($na) * sqrt($nb));
}

// ------------------------------------------------------------
// 4) INDEXING  (runs ONCE per material – never during chat)
// ------------------------------------------------------------

/** Loads one of the logged-in user's materials (ownership check). */
function ragFindMaterial($pdo, $userId, $materialId) {
    $stmt = $pdo->prepare(
        'SELECT id, user_id, course_id, file_name, file_type, file_size, note,
                file_data, index_status, index_note, indexed_at, chunk_count
         FROM course_materials WHERE id = ? AND user_id = ?'
    );
    $stmt->execute(array((int)$materialId, (int)$userId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** Writes the index status (+optional note / chunk count) of a material. */
function ragSetStatus($pdo, $userId, $materialId, $status, $note = null, $chunkCount = null) {
    $sql = 'UPDATE course_materials SET index_status = ?, index_note = ?, indexed_at = '
         . ($status === 'ready' ? 'NOW()' : 'indexed_at')
         . ($chunkCount !== null ? ', chunk_count = ?' : '')
         . ' WHERE id = ? AND user_id = ?';
    $params = array($status, $note);
    if ($chunkCount !== null) { $params[] = (int)$chunkCount; }
    $params[] = (int)$materialId;
    $params[] = (int)$userId;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

/**
 * Full index run for one material:
 *   extract → chunk → embed (batched Gemini call) → store vectors → Ready
 * Any failure marks the material Failed with a friendly reason – the
 * chat itself is never affected.
 */
function ragIndexMaterial($pdo, $userId, $materialId, $fileData = null) {
    $mat = ragFindMaterial($pdo, $userId, $materialId);
    if ($mat === null) { return 'failed'; }            // not found / not owned

    // Already indexed? Reuse the vectors that are there and never pay for
    // the same embeddings twice. A freshly attached file ($fileData) always
    // wins, because its content may have changed.
    if ($fileData === null
        && (string)$mat['index_status'] === 'ready'
        && (int)$mat['chunk_count'] > 0) {
        return 'ready';
    }

    ragSetStatus($pdo, $userId, $materialId, 'processing');

    try {
        $mime   = ($fileData !== null && isset($fileData['mime'])) ? $fileData['mime'] : $mat['file_type'];
        $base64 = null;
        if ($fileData !== null && isset($fileData['base64'])) {
            $base64 = $fileData['base64'];
        } elseif (!empty($mat['file_data'])) {
            $base64 = base64_encode($mat['file_data']);
        }

        $text   = ragExtractText($mime, $base64);
        $chunks = ragChunkText($text);
        if (count($chunks) === 0) {
            throw new Exception('The text was too short to split into study chunks.');
        }

        $vectors = askGeminiEmbeddings($chunks, 'RETRIEVAL_DOCUMENT');
        if (count($vectors) !== count($chunks)) {
            throw new Exception('The AI returned the wrong number of embeddings.');
        }

        // replace any previous indexing of this material atomically
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM course_material_chunks WHERE material_id = ?')
                ->execute(array((int)$materialId));

            $insert = $pdo->prepare(
                'INSERT INTO course_material_chunks
                    (material_id, user_id, course_id, chunk_index, content, embedding, dim, model)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($chunks as $i => $chunk) {
                $insert->execute(array(
                    (int)$materialId, (int)$userId, (int)$mat['course_id'], $i,
                    $chunk, ragPackVector($vectors[$i]), count($vectors[$i]), RAG_EMBED_MODEL,
                ));
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        ragSetStatus($pdo, $userId, $materialId, 'ready', null, count($chunks));
        return 'ready';

    } catch (Exception $e) {
        ragSetStatus($pdo, $userId, $materialId, 'failed',
            mb_substr('Indexing failed: ' . $e->getMessage(), 0, 240));
        return 'failed';
    }
}

/** Deletes a material AND all of its vectors/chunks (ownership-checked). */
function ragDeleteMaterial($pdo, $userId, $materialId) {
    $mat = ragFindMaterial($pdo, $userId, $materialId);
    if ($mat === null) { return false; }
    // explicit first (belt), FK ON DELETE CASCADE (braces)
    $pdo->prepare('DELETE FROM course_material_chunks WHERE material_id = ? AND user_id = ?')
        ->execute(array((int)$materialId, (int)$userId));
    $pdo->prepare('DELETE FROM course_materials WHERE id = ? AND user_id = ?')
        ->execute(array((int)$materialId, (int)$userId));
    return true;
}

// ------------------------------------------------------------
// 5) RETRIEVAL  (used by the chat – chunk embeddings are NOT
//    recomputed here; only the question itself is embedded)
// ------------------------------------------------------------

/**
 * Core vector search. Given a PRE-COMPUTED question embedding ($qvec),
 * returns the most relevant Ready chunks for ONE user's course.
 *
 *   $excludeMaterialIds – chunks whose material is being deleted /
 *                         re-indexed are skipped so a half-done index
 *                         never leaks a partial answer.
 *
 * Returns: array('hits' => [{content, file_name, material_id, score}, ...],
 *                  'materialsReady' => int)
 */
function ragRetrieve($pdo, $userId, $courseId, array $qvec, array $excludeMaterialIds = array(), $readyCount = null) {
    // How many Ready materials does this course have (excluding any skipped)?
    // The caller already counted them, so this query only runs when the
    // number was not supplied - that removes one identical database
    // round-trip from every single question.
    if ($readyCount === null) {
        $countSql = "SELECT COUNT(*) FROM course_materials
                     WHERE user_id = ? AND course_id = ? AND index_status = 'ready'";
        $countParams = array((int)$userId, (int)$courseId);
        if (count($excludeMaterialIds) > 0) {
            $ph = implode(',', array_fill(0, count($excludeMaterialIds), '?'));
            $countSql .= " AND id NOT IN ($ph)";
            foreach ($excludeMaterialIds as $ex) { $countParams[] = (int)$ex; }
        }
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($countParams);
        $ready = (int)$stmt->fetchColumn();
    } else {
        $ready = (int)$readyCount;
    }

    if ($ready === 0) {
        return array('hits' => array(), 'materialsReady' => 0);
    }

    // ---- PHASE 1: score the vectors (NO chunk text is transferred) ----
    // Ownership + course scoping stay in SQL, so security is unchanged.
    // The model and dimension filters moved into SQL as well, so stale
    // vectors from an older embedding model are never read at all. The
    // LIMIT is a hard speed guard; only the winners get their text, in
    // phase 2 below.
    $sql = "SELECT c.id, c.material_id, c.embedding
             FROM course_material_chunks c
             INNER JOIN course_materials m
                 ON m.id = c.material_id
                AND m.user_id = ?
                AND m.course_id = ?
                AND m.index_status = ?
             WHERE c.user_id = ? AND c.course_id = ?
               AND c.model = ? AND c.dim = ?";
    $params = array((int)$userId, (int)$courseId, 'ready', (int)$userId, (int)$courseId,
                    RAG_EMBED_MODEL, count($qvec));

    if (count($excludeMaterialIds) > 0) {
        $ph = implode(',', array_fill(0, count($excludeMaterialIds), '?'));
        $sql .= " AND c.material_id NOT IN ($ph)";
        foreach ($excludeMaterialIds as $ex) { $params[] = (int)$ex; }
    }

    // The ORDER BY + LIMIT always stay LAST so the exclusion clause above
    // is still valid SQL.
    $sql .= " ORDER BY c.id LIMIT " . (defined('RAG_MAX_SCAN') ? (int)RAG_MAX_SCAN : 5000);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $scored = array();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $vec = ragUnpackVector($row['embedding']);
        if (count($vec) !== count($qvec)) { continue; }   // dimension mismatch guard
        $score = ragCosine($qvec, $vec);
        if ($score >= (float)RAG_MIN_SIMILARITY) {
            $scored[] = array(
                'chunk_id'    => (int)$row['id'],
                'material_id' => (int)$row['material_id'],
                'score'       => $score,
            );
        }
    }

    $best = array_slice($scored, 0, (int)RAG_TOP_K);

    // ---- PHASE 2: fetch the text of the winners ONLY ----------------
    $ids = array();
    foreach ($best as $b) { $ids[] = (int)$b['chunk_id']; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT c.id, c.content, m.file_name
           FROM course_material_chunks c
           INNER JOIN course_materials m ON m.id = c.material_id
          WHERE c.id IN ($ph)"
    );
    $textById = array();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $textById[(int)$row['id']] = array(
            'content'   => (string)$row['content'],
            'file_name' => (string)$row['file_name'],
        );
    }

    // keep the similarity order, then cap the total context size for Gemini
    $total = 0; $kept = array();
    foreach ($best as $b) {
        $cidKey = (int)$b['chunk_id'];
        if (!isset($textById[$cidKey])) { continue; }
        $content = $textById[$cidKey]['content'];
        $len = mb_strlen($content);
        if ($total + $len > (int)RAG_MAX_CONTEXT_CHARS) { break; }
        $total += $len;
        $kept[] = array(
            'content'     => $content,
            'file_name'   => $textById[$cidKey]['file_name'],
            'material_id' => (int)$b['material_id'],
            'score'       => (float)$b['score'],
        );
    }

    return array('hits' => $kept, 'materialsReady' => $ready);
}

/**
 * Question-based entry point for the chat: it first checks whether the
 * course has any Ready material (so courses without materials NEVER trigger
 * a needless embedding call), then embeds the QUESTION only and delegates
 * to ragRetrieve(). Returns the same shape as ragRetrieve().
 */
function ragRetrieveContext($pdo, $userId, $courseId, $question) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM course_materials
         WHERE user_id = ? AND course_id = ? AND index_status = 'ready'"
    );
    $stmt->execute(array((int)$userId, (int)$courseId));
    $ready = (int)$stmt->fetchColumn();

    if ($ready === 0) {
        return array('hits' => array(), 'materialsReady' => 0);
    }

    $t = microtime(true);
    $qvec = ragEmbedQuestion((string)$question);
    ragTiming('embed_question_ms', (microtime(true) - $t) * 1000);

    if ($qvec === null || count($qvec) === 0) {
        throw new Exception('Could not analyse the question.');
    }

    // $ready is passed in so ragRetrieve() does not repeat the same COUNT.
    $t = microtime(true);
    $result = ragRetrieve($pdo, $userId, $courseId, $qvec, array(), $ready);
    ragTiming('vector_search_ms', (microtime(true) - $t) * 1000);

    return $result;
}

/**
 * Embeds the student's question, reusing a cached vector when the exact
 * same question was asked before. The cache is a plain file in the system
 * temp folder. It is entirely optional: if anything at all goes wrong we
 * simply call the API, so the chat can never break because of it.
 */
function ragEmbedQuestion($question) {
    $question = (string)$question;
    $useCache = defined('RAG_EMBED_CACHE') ? (bool)RAG_EMBED_CACHE : false;
    $ttl      = defined('RAG_EMBED_CACHE_TTL') ? (int)RAG_EMBED_CACHE_TTL : 3600;

    $file = '';
    if ($useCache && $ttl > 0 && $question !== '') {
        $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ai_chat_embed_cache';
        if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
        if (is_dir($dir) && is_writable($dir)) {
            $file = $dir . DIRECTORY_SEPARATOR
                  . sha1('q|' . RAG_EMBED_MODEL . '|' . (int)RAG_EMBED_DIM . '|' . $question)
                  . '.json';

            if (is_file($file) && (time() - (int)@filemtime($file)) < $ttl) {
                $cached = json_decode((string)@file_get_contents($file), true);
                if (is_array($cached) && count($cached) > 0) {
                    return array_map('floatval', $cached);
                }
            }
        }
    }

    $vec = askGeminiEmbedding($question, 'RETRIEVAL_QUERY');

    if ($file !== '' && is_array($vec) && count($vec) > 0) {
        @file_put_contents($file, json_encode($vec), LOCK_EX);
    }

    return $vec;
}

/** Backwards-compatible alias (same signature, calls ragRetrieve()). */
function ragSearch($pdo, $userId, $courseId, array $qvec, array $excludeMaterialIds = array()) {
    return ragRetrieve($pdo, $userId, $courseId, $qvec, $excludeMaterialIds);
}


/** The exact "not found" answer (never invent from general knowledge). */
function ragNoInfoMessage() {
    return "I couldn't find enough information in your course materials to answer this.\n\n"
         . "Tip: attach a PDF, TXT or DOCX file in this chat to index it - "
         . "I can then answer questions from your own materials.";
}

// ------------------------------------------------------------
// 6) GROUNDED PROMPT  (retrieved context + question -> Gemini)
// ------------------------------------------------------------

/** Builds the context-only prompt that is sent to askGemini(). */
function ragBuildGroundedPrompt($question, array $rag, $course) {
    $blocks = '';
    foreach ($rag['hits'] as $i => $hit) {
        $blocks .= "\n[" . ($i + 1) . "] Source: " . $hit['file_name'] . "\n"
                .  $hit['content'] . "\n";
    }

    $courseLine = '';
    if (is_array($course)) {
        $courseLine = 'Course: ' . $course['name']
                    . (trim((string)$course['code']) !== '' ? ' (' . $course['code'] . ')' : '') . "\n";
    }

    return "You are a grounded study assistant. Answer the student's question using ONLY the "
         . "numbered excerpts below. They come from the student's own course materials.\n"
         . $courseLine
         . "STRICT RULES:\n"
         . "- Use only the excerpts. Never use your own general knowledge to fill gaps.\n"
         . "- If the excerpts do not contain enough information, answer exactly: "
         . "\"I couldn't find enough information in your course materials to answer this.\"\n"
         . "- Explain clearly, at a student-friendly level.\n"
         . "- Mention which source file(s) the answer came from.\n"
         . "\nSTUDY MATERIAL EXCERPTS:" . $blocks
         . "\nQUESTION: " . $question;
}

/** "Sources:" footer appended to grounded answers. */
function ragSourcesLine(array $hits) {
    $names = array();
    foreach ($hits as $hit) {
        if (!in_array($hit['file_name'], $names, true)) { $names[] = $hit['file_name']; }
    }
    return '**Sources:** ' . implode(', ', $names);
}

