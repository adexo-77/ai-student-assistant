// ============================================================
//  CHAT.JS – powers the chat page
//  (vanilla JavaScript, no libraries)
//
//  - Attach a file (PDF / TXT / DOCX / images, max 10 MB)
//  - Choose an assistant mode (general / study / coding / course / health)
//    and, when the chat has a course context (index.php?course_id=7),
//    that course is sent to api/chat.php as well
//  - Enter = send, Shift + Enter = new line
//  - Animated "AI is thinking..." indicator while waiting
//  - Copy button on every AI answer + Regenerate button
//  - "New Chat" button clears the chat on screen
//  - AI answers are lightly formatted (bold, lists, code)
//  - Always scrolls to the newest message
//  - Talks to api/chat.php (AJAX / fetch)
// ============================================================

const chatBox           = document.getElementById('chatBox');
const chatForm          = document.getElementById('chatForm');
const questionInput     = document.getElementById('question');
const sendButton        = document.getElementById('sendBtn');
const newChatButton     = document.getElementById('newChatBtn');
const regenerateButton  = document.getElementById('regenerateBtn');
const attachButton      = document.getElementById('attachBtn');
const fileInput         = document.getElementById('fileInput');
const fileChip          = document.getElementById('fileChip');
const fileChipName      = document.getElementById('fileChipName');
const removeFileButton  = document.getElementById('removeFileBtn');
const modeSelect        = document.getElementById('modeSelect');

const userName           = chatBox.dataset.user || 'you';
// Optional course context: index.php?course_id=7 sets data-course-id.
// Empty string = normal chat (nothing about a course is sent).
const courseId           = chatBox.dataset.courseId || '';
const ALLOWED_EXTENSIONS = ['pdf', 'txt', 'docx', 'png', 'jpg', 'jpeg', 'gif', 'webp'];
const MAX_FILE_SIZE      = 10 * 1024 * 1024;             // 10 MB

let canSend     = true;      // false while waiting for Gemini
let clearUid    = 0;         // bumped by "New Chat" to ignore old replies
let activeFile  = null;      // the File object currently attached
let lastRequest = null;      // last {question, mode, file} for Regenerate
let lastBotRow  = null;      // the latest AI answer row (replaced by Regenerate)

// Small static SVG icons (our own markup, safe to set with innerHTML)
const BOT_AVATAR_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true">' +
    '<circle cx="7" cy="9" r="2.2" fill="#fff"/>' +
    '<circle cx="12" cy="9" r="2.2" fill="#fff"/>' +
    '<circle cx="17" cy="9" r="2.2" fill="#fff"/>' +
    '<path d="M5.5 14 L9.5 17.5 L7 19 Z" fill="#fff"/>' +
'</svg>';

const COPY_ICON_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true">' +
    '<rect x="5.5" y="5.5" width="13" height="13" rx="2" fill="none" stroke="currentColor" stroke-width="2"/>' +
    '<rect x="9" y="9" width="6" height="6" rx="1" fill="currentColor"/>' +
'</svg>';

// ---------- helpers: build one message --------------------------------

function makeAvatar(sender) {
    const avatar = document.createElement('div');
    if (sender === 'user') {
        avatar.className = 'avatar avatar-user';
        avatar.textContent = userName.charAt(0).toUpperCase();  // first letter
    } else {
        avatar.className = 'avatar avatar-bot';
        avatar.innerHTML = BOT_AVATAR_SVG;
    }
    return avatar;
}

function addBubble(text, sender, formatted) {
    const row = document.createElement('div');
    row.className = 'message ' + (sender === 'user' ? 'user-message' : 'bot-message');

    const wrap = document.createElement('div');
    wrap.className = 'bubble-wrap';

    const label = document.createElement('div');
    label.className = 'msg-sender';
    label.textContent = (sender === 'user' ? 'You' : 'AI Assistant');
    wrap.appendChild(label);

    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    if (sender === 'bot' && formatted) {
        bubble.innerHTML = formatAiText(text);      // AI answers get light formatting
    } else {
        bubble.textContent = text;                  // everything else stays plain text
    }
    wrap.appendChild(bubble);

    if (sender === 'bot') {
        wrap.appendChild(makeCopyButton(text));     // copy button for AI answers
        lastBotRow = row;                           // remember for Regenerate
    }

    row.appendChild(makeAvatar(sender));
    row.appendChild(wrap);

    chatBox.appendChild(row);
    scrollToBottom(true);
    return row;
}

// ---------- copy button -------------------------------------------------

function makeCopyButton(text) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'copy-btn';
    btn.title = 'Copy answer';
    btn.innerHTML = COPY_ICON_SVG + '<span>Copy</span>';

    btn.addEventListener('click', function () { copyText(text, btn); });
    return btn;
}

function copyText(text, btn) {
    // Flexible textarea method – works everywhere (also plain http://localhost)
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none;';
    document.body.appendChild(ta);
    ta.select();
    ta.setSelectionRange(0, text.length);

    let ok = false;
    try {
        ok = document.execCommand('copy');
    } catch (err) {
        ok = false;
    }
    ta.remove();

    // Fallback: modern clipboard API
    if (!ok && navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(
            function () { showCopyFeedback(btn, true); },
            function () { showCopyFeedback(btn, false); }
        );
        return;
    }

    showCopyFeedback(btn, ok);
}

function showCopyFeedback(btn, ok) {
    const label = btn.querySelector('span');
    if (!label) return;
    label.textContent = ok ? 'Copied!' : 'Could not copy';
    btn.classList.add('copied');

    window.setTimeout(function () {
        label.textContent = 'Copy';
        btn.classList.remove('copied');
    }, 1600);
}

// ---------- thinking indicator ------------------------------------------

function showThinking() {
    const row = document.createElement('div');
    row.className = 'message bot-message';
    row.id = 'thinking-row';

    const wrap = document.createElement('div');
    wrap.className = 'bubble-wrap';

    const label = document.createElement('div');
    label.className = 'msg-sender';
    label.textContent = 'AI Assistant';
    wrap.appendChild(label);

    const bubble = document.createElement('div');
    bubble.className = 'bubble thinking-bubble';
    bubble.textContent = 'AI is thinking';
    for (let i = 0; i < 3; i++) {
        const dot = document.createElement('span');
        dot.className = 'dot';
        bubble.appendChild(dot);
    }
    wrap.appendChild(bubble);

    row.appendChild(makeAvatar('bot'));
    row.appendChild(wrap);

    chatBox.appendChild(row);
    scrollToBottom(true);
}

function hideThinking() {
    const row = document.getElementById('thinking-row');
    if (row) row.remove();
}

// ---------- scrolling ---------------------------------------------------

function scrollToBottom(smooth) {
    if (!chatBox) return;
    try {
        chatBox.scrollTo({ top: chatBox.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    } catch (err) {
        chatBox.scrollTop = chatBox.scrollHeight;
    }
}

// ---------- light markdown formatter for AI answers ---------------------

function escapeHtml(text) {
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// Turns Gemini's markdown-ish answer into safe, simple HTML.
// Order matters: escape -> protect code -> blocks -> inline -> <br>
function formatAiText(text) {
    // 1) escape ALL HTML first (this keeps everything safe)
    let html = escapeHtml(text);

    // 2) keep ```code``` blocks intact (their line breaks stay literal)
    const codeBlocks = [];
    html = html.replace(/```([\s\S]*?)```/g, function (m, code) {
        codeBlocks.push(code);
        return '\x01CODE' + (codeBlocks.length - 1) + '\x01';
    });

    // 3) walk line by line for headers, lists and horizontal rules
    const lines = html.split('\n');
    const out = [];
    let openList = null;                 // 'ul', 'ol' or null

    function closeList() {
        if (openList) { out.push('</' + openList + '>'); openList = null; }
    }

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const m = line.match(/^(#{1,3})\s+(.*)/);
        if (m) {
            closeList();
            const tag = m[1].length >= 3 ? 'h5' : 'h4';
            out.push('<' + tag + ' class="ai-h">' + m[2] + '</' + tag + '>');
            continue;
        }
        if (/^-\s+/.test(line)) {
            if (openList !== 'ul') { closeList(); out.push('<ul>'); openList = 'ul'; }
            out.push('<li>' + line.replace(/^-\s+/, '') + '</li>');
            continue;
        }
        if (/^\d+\.\s+/.test(line)) {
            if (openList !== 'ol') { closeList(); out.push('<ol>'); openList = 'ol'; }
            out.push('<li>' + line.replace(/^\d+\.\s+/, '') + '</li>');
            continue;
        }
        if (line.trim() === '---' || line.trim() === '***') {
            closeList();
            out.push('<hr class="ai-hr">');
            continue;
        }
        closeList();
        out.push(line);
    }
    closeList();
    html = out.join('\n');

    // 4) inline formatting (only after escaping)
    html = html.replace(/`([^`\n]+)`/g, '<code>$1</code>');
    html = html.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/(^|\s)\*([^*\n]+)\*(\s|$)/g, '$1<em>$2</em>$3');

    // 5) remaining line breaks
    html = html.replace(/\n/g, '<br>');

    // 6) put the code blocks back as <pre>
    html = html.replace(/\x01CODE(\d+)\x01/g, function (m, i) {
        return '<pre class="ai-code">' + codeBlocks[parseInt(i, 10)] + '</pre>';
    });

    return html;
}

// ---------- sending -----------------------------------------------------

function makeFormData(question, mode, file, course) {
    const fd = new FormData();
    fd.append('message', question);
    fd.append('mode', mode);
    if (course) fd.append('course_id', course);
    fd.append('file', file, file.name);
    return fd;
}

function sendQuestion(regenerate, previous) {
    let question = '';
    let mode = modeSelect.value;
    let file = null;
    let course = courseId;

    if (regenerate && previous) {
        question = previous.question;
        mode = previous.mode;
        file = previous.file;
        course = previous.course;
        if (lastBotRow) lastBotRow.remove();   // replace the old answer
        lastBotRow = null;
    } else {
        question = questionInput.value.trim();
        file = activeFile;
        if (question === '' && file === null) return;
        if (question === '' && file !== null) question = 'Please summarise this file for me.';

        addBubble(question + (file ? '\n\ud83d\udcce ' + file.name : ''), 'user');
        questionInput.value = '';
        autoGrow();
        lastRequest = { question: question, mode: mode, file: file, course: course };
    }

    if (!canSend) return;
    canSend = false;
    sendButton.disabled = true;
    showThinking();
    const uid = clearUid;

    const hasFile = file !== null;
    const body = hasFile ? makeFormData(question, mode, file, course)
                         : JSON.stringify({ message: question, mode: mode, course_id: course });
    const headers = hasFile ? {} : { 'Content-Type': 'application/json' };

    // talk to api/chat.php (it asks Gemini and saves to MySQL)
    fetch('api/chat.php', {
        method: 'POST',
        headers: headers,
        body: body,
        credentials: 'same-origin'
    })
    .then(function (response) { return response.json(); })
    .then(function (data) {
        if (uid !== clearUid) return;             // chat was cleared while waiting
        hideThinking();
        if (data.error) {
            addBubble('Error: ' + data.error, 'bot', false);
        } else {
            addBubble(data.answer, 'bot', true);  // formatted AI answer
            // TIMINGS (2026-09): with RAG_DEBUG on, the API also reports where
            // the time went — logged to the console, never shown in the chat.
            if (data.timings && window.console && console.debug) { console.debug('[chat timings]', data.timings); }
        }
    })
    .catch(function (err) {
        if (uid !== clearUid) return;
        hideThinking();
        addBubble('Could not reach the server. Is XAMPP running?', 'bot', false);
    })
    .finally(function () {
        canSend = true;
        sendButton.disabled = false;
        updateRegenerateState();
        questionInput.focus();
    });
}

// ---------- Regenerate --------------------------------------------------

function regenerate() {
    if (!canSend || !lastRequest) return;
    sendQuestion(true, lastRequest);
}

function updateRegenerateState() {
    regenerateButton.disabled = !(lastRequest !== null && canSend);
}

// ---------- New Chat / Clear Chat --------------------------------------

function clearChat() {
    chatBox.innerHTML = '';
    clearUid++;                                  // ignore old replies
    canSend = true;
    sendButton.disabled = false;
    lastRequest = null;
    lastBotRow = null;

    activeFile = null;
    fileInput.value = '';
    fileChip.classList.add('d-none');

    addBubble('Hi! I am your AI assistant. Pick a mode, attach a file, or just type a question below.', 'bot', false);
    questionInput.value = '';
    autoGrow();
    updateRegenerateState();
    questionInput.focus();
}

// ---------- little niceties ---------------------------------------------

function autoGrow() {
    questionInput.style.height = 'auto';
    questionInput.style.height = Math.min(questionInput.scrollHeight + 4, 150) + 'px';
}

// ---------- events: file attachment -------------------------------------

attachButton.addEventListener('click', function () {
    fileInput.click();
});

fileInput.addEventListener('change', function () {
    const f = fileInput.files[0];
    if (!f) return;

    const ext = f.name.split('.').pop().toLowerCase();
    if (ALLOWED_EXTENSIONS.indexOf(ext) === -1) {
        addBubble('Error: "' + f.name + '" is not allowed. Use PDF, TXT, DOCX, PNG, JPG, GIF or WEBP.', 'bot', false);
        fileInput.value = '';
        return;
    }
    if (f.size > MAX_FILE_SIZE) {
        addBubble('Error: The file is too large. Maximum size is 10 MB.', 'bot', false);
        fileInput.value = '';
        return;
    }

    activeFile = f;
    fileChipName.textContent = f.name;
    fileChip.classList.remove('d-none');
    questionInput.focus();
});

removeFileButton.addEventListener('click', function () {
    activeFile = null;
    fileInput.value = '';
    fileChip.classList.add('d-none');
    questionInput.focus();
});

// ---------- events: modes & chat ----------------------------------------

regenerateButton.addEventListener('click', regenerate);

newChatButton.addEventListener('click', function () {
    if (window.confirm('Start a new chat?\n\nThis only clears the chat on screen - all saved messages stay in your history.')) {
        clearChat();
    }
});

// Enter = send, Shift + Enter = new line
questionInput.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();                        // no new line here
        if (chatForm.requestSubmit) {
            chatForm.requestSubmit();                  // fires "submit"
        } else {
            chatForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        }
    }
    autoGrow();
});

chatForm.addEventListener('submit', function (event) {
    event.preventDefault();                            // do NOT reload the page
    sendQuestion(false, null);
});

// Welcome message on page load
clearChat();

// Prefill from the dashboard AI quick actions
// (index.php passes ?mode=study&prompt=... via data attributes).
// Runs AFTER clearChat() so the prefill survives the welcome message.
(function () {
    var prefill = chatBox.dataset.prefill || '';
    var mode    = chatBox.dataset.mode || '';

    if (mode !== '' && modeSelect) {
        for (var i = 0; i < modeSelect.options.length; i++) {
            if (modeSelect.options[i].value === mode) {
                modeSelect.selectedIndex = i;
                break;
            }
        }
    }

    if (prefill !== '') {
        questionInput.value = prefill;
        autoGrow();
        questionInput.focus();
    }
})();