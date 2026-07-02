/**
 * STUDIZ — Frontend Application (app.js)
 * =========================================
 * Vanilla JS single-page application controller.
 * Zero API keys on the client. All AI calls proxy through
 * /backend/gateways/api_gateway.php via fetch().
 *
 * Module index:
 *  Router      — hash-free SPA page switching
 *  Toast       — notification system
 *  Api         — centralised fetch wrappers
 *  Onboarding  — first-visit modal + secure registration
 *  Study       — file upload, AI result display, quiz engine
 *  Store       — folder/summary CRUD
 *  Chatbot     — multi-turn AI chat with daily quota
 */

'use strict';

/* ─────────────────────────────────────────────────────────
   ENDPOINTS
───────────────────────────────────────────────────────── */
const ENDPOINTS = {
  gateway : '/studizz/backend/gateways/api_gateway.php',
  onboard : '/studizz/backend/gateways/onboard.php',
  store   : '/studizz/backend/gateways/store.php',
  logout  : '/studizz/backend/gateways/logout.php',
};

// Mirrors QUIZ_REGEN_MIN_QUESTIONS / QUIZ_REGEN_MAX_QUESTIONS in
// backend/config/constants.php — keep both in sync if either changes.
const QUIZ_REGEN_MIN = 3;
const QUIZ_REGEN_MAX = 25;

/* ─────────────────────────────────────────────────────────
   UTILITIES  (declared first — used by all modules)
───────────────────────────────────────────────────────── */
function escapeHtml(str) {
  if (typeof str !== 'string') return String(str ?? '');
  return str
    .replace(/&/g,  '&amp;')
    .replace(/</g,  '&lt;')
    .replace(/>/g,  '&gt;')
    .replace(/"/g,  '&quot;')
    .replace(/'/g,  '&#039;');
}

function formatDate(iso) {
  const d = new Date(iso);
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

function generateUUID() {
  if (crypto && crypto.randomUUID) return crypto.randomUUID();
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

/* ─────────────────────────────────────────────────────────
   ROUTER
───────────────────────────────────────────────────────── */
const Router = (() => {
  const VALID_PAGES = ['home', 'features', 'study', 'store', 'chatbot'];

  function navigate(page) {
    if (!VALID_PAGES.includes(page)) page = 'home';

    document.querySelectorAll('.page').forEach(el => el.classList.remove('active'));
    const target = document.getElementById(`page-${page}`);
    if (target) target.classList.add('active');

    document.querySelectorAll('[data-page]').forEach(a => {
      a.classList.toggle('active', a.dataset.page === page);
    });

    // Close mobile menu if open
    document.getElementById('nav-links')?.classList.remove('open');

    window.scrollTo({ top: 0, behavior: 'instant' });

    // Trigger module refresh when entering store/chatbot
    if (page === 'store')   Store.refresh();
    if (page === 'chatbot') Chatbot.onEnter();
  }

  function init() {
    document.addEventListener('click', e => {
      const el = e.target.closest('[data-page]');
      if (!el) return;
      e.preventDefault();
      navigate(el.dataset.page);
    });

    // Hamburger
    document.getElementById('hamburger')?.addEventListener('click', () => {
      document.getElementById('nav-links')?.classList.toggle('open');
    });

    navigate('home');
  }

  return { init, navigate };
})();

/* ─────────────────────────────────────────────────────────
   TOAST NOTIFICATIONS
───────────────────────────────────────────────────────── */
const Toast = (() => {
  const ICONS = { success: '✓', error: '✕', info: 'ℹ' };

  function show(msg, type = 'info', ms = 4000) {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.setAttribute('role', 'alert');
    el.innerHTML = `<span>${ICONS[type] ?? ICONS.info}</span><span>${escapeHtml(msg)}</span>`;
    container.appendChild(el);

    setTimeout(() => {
      el.style.transition = 'opacity 0.3s, transform 0.3s';
      el.style.opacity    = '0';
      el.style.transform  = 'translateX(30px)';
      setTimeout(() => el.remove(), 310);
    }, ms);
  }

  return { show };
})();

/* ─────────────────────────────────────────────────────────
   API HELPERS
───────────────────────────────────────────────────────── */
const Api = (() => {
  // Shared JSON parse helper. If the server returns a non-JSON body
  // (e.g. an empty 500 from PHP before it could write anything, or an
  // Apache/Nginx default HTML error page), r.json() throws a SyntaxError.
  // We catch that here and normalise it to the same {ok,error} shape so
  // callers never have to handle two different failure modes.
  async function _parseJSON(r) {
    try {
      return await r.json();
    } catch (_) {
      return { ok: false, error: 'Server returned an unexpected response. Please try again.' };
    }
  }

  async function post(url, data, signal) {
    const r = await fetch(url, {
      method : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body   : JSON.stringify(data),
      signal,
    });
    return _parseJSON(r);
  }

  async function upload(formData, signal) {
    // Do NOT set Content-Type — browser sets it with the boundary
    const r = await fetch(ENDPOINTS.gateway, { method: 'POST', body: formData, signal });
    return _parseJSON(r);
  }

  return { post, upload };
})();

/* ─────────────────────────────────────────────────────────
   ONBOARDING
───────────────────────────────────────────────────────── */
const Onboarding = (() => {
  let _user = null;   // { name, username } — no PII

  function getUserData() { return _user; }

  function setNavUser(name, username) {
    const display = name || username || '';
    const el = document.getElementById('nav-user');
    const av = document.getElementById('nav-avatar');
    if (el) el.textContent = display;
    if (av) av.textContent = display.charAt(0).toUpperCase() || '?';

    // Reveal the logout button only once a user is known to be authenticated.
    const logoutBtn = document.getElementById('logout-btn');
    if (logoutBtn) logoutBtn.hidden = false;
  }

  async function checkAlreadyRegistered() {
    try {
      // POST with empty body — if server finds valid HTTP-Only cookie it returns
      // { ok: true, already_registered: true, name: '...', username: '...' }
      const res = await Api.post(ENDPOINTS.onboard, {});
      if (res.ok && res.already_registered) {
        _user = { name: res.name, username: res.username };
        setNavUser(res.name, res.username);
        return true;
      }
    } catch (_) { /* fall through to show modal */ }
    return false;
  }

  function showModal() {
    const overlay = document.getElementById('onboarding-overlay');
    if (!overlay) return;
    overlay.classList.add('visible');
    setTimeout(() => overlay.querySelector('.form-input')?.focus(), 350);
  }

  function hideModal() {
    document.getElementById('onboarding-overlay')?.classList.remove('visible');
  }

  async function handleSubmit(e) {
    e.preventDefault();
    const form   = e.target;
    const errEl  = document.getElementById('onboard-error');
    const btn    = form.querySelector('[type=submit]');

    errEl.classList.remove('visible');
    btn.disabled    = true;
    btn.textContent = 'Creating account…';

    const payload = {
      name    : form.elements['name'].value.trim(),
      username: form.elements['username'].value.trim(),
      class   : form.elements['class'].value.trim(),
      email   : form.elements['email'].value.trim(),
      phone   : form.elements['phone'].value.trim(),
    };

    try {
      const res = await Api.post(ENDPOINTS.onboard, payload);
      if (!res.ok) {
        errEl.textContent = res.error || 'Registration failed. Please try again.';
        errEl.classList.add('visible');
        btn.disabled    = false;
        btn.textContent = 'Join Studizz';
        return;
      }
      _user = { name: res.name, username: res.username };
      setNavUser(res.name, res.username);
      hideModal();
      Toast.show(`Welcome to Studizz, ${res.name}! 🎉`, 'success', 6000);
    } catch (_) {
      errEl.textContent = 'Network error. Please check your connection and try again.';
      errEl.classList.add('visible');
      btn.disabled    = false;
      btn.textContent = 'Join Studizz';
    }
  }

  /* ── Change-username modal ──────────────────────────── */
  function showUsernameModal() {
    if (!_user) return;   // chip has nothing meaningful to edit yet
    const overlay = document.getElementById('username-modal-overlay');
    const input   = document.getElementById('username-input');
    if (!overlay) return;
    document.getElementById('username-modal-error')?.classList.remove('visible');
    if (input) input.value = _user.username || '';
    overlay.classList.add('visible');
    setTimeout(() => input?.focus(), 150);
  }

  function hideUsernameModal() {
    document.getElementById('username-modal-overlay')?.classList.remove('visible');
  }

  async function handleUsernameSubmit(e) {
    e.preventDefault();
    const form    = e.target;
    const input   = document.getElementById('username-input');
    const errEl   = document.getElementById('username-modal-error');
    const btn     = form.querySelector('[type=submit]');
    const newName = input.value.trim();

    if (!newName) return;

    errEl.classList.remove('visible');
    btn.disabled    = true;
    btn.textContent = 'Saving…';

    try {
      const res = await Api.post(ENDPOINTS.onboard, { action: 'update_username', username: newName });
      if (!res.ok) {
        errEl.textContent = res.error || 'Could not update username.';
        errEl.classList.add('visible');
        btn.disabled    = false;
        btn.textContent = 'Save';
        return;
      }
      _user.username = res.username;
      setNavUser(_user.name, res.username);
      hideUsernameModal();
      btn.disabled    = false;
      btn.textContent = 'Save';
      Toast.show('Username updated.', 'success');
    } catch (_) {
      errEl.textContent = 'Network error. Please try again.';
      errEl.classList.add('visible');
      btn.disabled    = false;
      btn.textContent = 'Save';
    }
  }

  async function handleLogout() {
    try {
      await Api.post(ENDPOINTS.logout, {});
    } catch (_) {
      // Network failure is non-fatal: the server already rotated the DB token,
      // so the old cookie is worthless. Proceed with the reload regardless.
    }
    // Hard reload clears all in-memory state and triggers a fresh auth check,
    // which will find no valid cookie and show the onboarding modal.
    window.location.reload();
  }

  async function init() {
    const registered = await checkAlreadyRegistered();
    if (!registered) showModal();
    document.getElementById('onboard-form')?.addEventListener('submit', handleSubmit);

    const chip = document.getElementById('nav-user-chip');
    chip?.addEventListener('click', showUsernameModal);
    chip?.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); showUsernameModal(); }
    });
    document.getElementById('username-form')?.addEventListener('submit', handleUsernameSubmit);
    document.getElementById('close-username-modal')?.addEventListener('click', hideUsernameModal);
    document.getElementById('username-modal-overlay')?.addEventListener('click', e => {
      if (e.target === e.currentTarget) hideUsernameModal();
    });
    document.getElementById('logout-btn')?.addEventListener('click', handleLogout);
  }

  return { init, getUserData, setNavUser };
})();

/* ─────────────────────────────────────────────────────────
   STUDY MODULE
───────────────────────────────────────────────────────── */
const Study = (() => {
  // Per-session state
  let _quiz             = [];     // current quiz question array
  let _answers          = {};     // { "0": "A", "1": "C", ... }
  let _quizSubmitted    = false;
  let _summaryId        = null;
  let _uploadController = null;   // AbortController for the in-flight upload

  /* ── Upload zone ────────────────────────────────────── */
  function initUpload() {
    const zone  = document.getElementById('upload-zone');
    const input = document.getElementById('file-input');
    if (!zone || !input) return;

    zone.addEventListener('click', () => input.click());
    zone.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
    });

    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', ()  => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', e => {
      e.preventDefault();
      zone.classList.remove('drag-over');
      const f = e.dataTransfer.files[0];
      if (f) _processFile(f);
    });

    input.addEventListener('change', () => {
      if (input.files[0]) _processFile(input.files[0]);
      input.value = '';  // reset so same file can be re-uploaded
    });

    const urlInput = document.getElementById('document-url-input');
    const urlBtn   = document.getElementById('process-url-btn');
    if (urlInput && urlBtn) {
      urlBtn.addEventListener('click', () => _processUrl(urlInput.value.trim()));
      urlInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); _processUrl(urlInput.value.trim()); }
      });
    }
  }

  /* ── File validation + upload ───────────────────────── */
  async function _processFile(file) {
    // No file-type whitelist here — any file is accepted. The backend's
    // extractor decides whether it can find usable text/numbers in it and
    // rejects with a friendly error (no AI cost spent) if it can't.
    if (file.size > 20 * 1024 * 1024) {
      Toast.show('File exceeds the 20 MB size limit.', 'error');
      return;
    }

    _showLoader();

    // Grab optional folder assignment
    const folderSelect = document.getElementById('upload-folder-select');
    const folderId     = folderSelect ? folderSelect.value : '';

    const form = new FormData();
    form.append('action',   'process_document');
    form.append('document', file);
    if (folderId) form.append('folder_id', folderId);

    _uploadController = new AbortController();

    try {
      const res = await Api.upload(form, _uploadController.signal);
      _uploadController = null;
      if (!res.ok) {
        _hideLoader();
        _resetResultsPanel();
        Toast.show(res.error || 'Processing failed. Please try again.', 'error');
        return;
      }
      if (res.duplicate) {
        Toast.show('You already uploaded this document — showing your saved summary.', 'info', 5000);
      }
      _displayRaw({
        summary           : res.summary,
        key_points        : res.key_points,
        quiz              : res.quiz,
        youtube_links     : res.youtube_links,
        summary_id        : res.summary_id,
        answered_questions: res.answered_questions,
      });
    } catch (err) {
      _uploadController = null;
      _hideLoader();
      _resetResultsPanel();
      if (err.name === 'AbortError') {
        Toast.show('Upload cancelled.', 'info');
      } else {
        Toast.show('Network error. Please try again.', 'error');
      }
    }
  }

  /* ── Link-based ingestion ───────────────────────────── */
  async function _processUrl(url) {
    if (!url) { Toast.show('Paste a link first.', 'info'); return; }
    if (!/^https?:\/\//i.test(url)) {
      Toast.show('Link must start with http:// or https://', 'error');
      return;
    }

    _showLoader();

    const folderSelect = document.getElementById('upload-folder-select');
    const folderId      = folderSelect ? folderSelect.value : '';

    _uploadController = new AbortController();

    try {
      const res = await Api.post(ENDPOINTS.gateway, {
        action   : 'process_document',
        url,
        folder_id: folderId || undefined,
      }, _uploadController.signal);
      _uploadController = null;

      if (!res.ok) {
        _hideLoader();
        _resetResultsPanel();
        Toast.show(res.error || 'Processing failed. Please try again.', 'error');
        return;
      }
      if (res.duplicate) {
        Toast.show('You already processed this link — showing your saved summary.', 'info', 5000);
      }
      _displayRaw({
        summary           : res.summary,
        key_points        : res.key_points,
        quiz              : res.quiz,
        youtube_links     : res.youtube_links,
        summary_id        : res.summary_id,
        answered_questions: res.answered_questions,
      });
      const urlInput = document.getElementById('document-url-input');
      if (urlInput) urlInput.value = '';
    } catch (err) {
      _uploadController = null;
      _hideLoader();
      _resetResultsPanel();
      if (err.name === 'AbortError') {
        Toast.show('Cancelled.', 'info');
      } else {
        Toast.show('Network error. Please try again.', 'error');
      }
    }
  }

  /* ── Cancel an in-flight upload ──────────────────────── */
  function _cancelUpload() {
    if (_uploadController) _uploadController.abort();
  }

  /* ── Revert the results panel to its pre-upload state ─ */
  function _resetResultsPanel() {
    const results = document.getElementById('study-results');
    if (!results) return;
    results.classList.remove('visible');
    results.innerHTML = '';
  }

  /* ── Loading state ──────────────────────────────────── */
  let _progressTimer = null;

  function _showLoader() {
    const zone    = document.getElementById('upload-zone');
    const results = document.getElementById('study-results');
    if (zone)    zone.style.opacity = '0.45';
    if (!results) return;

    results.classList.add('visible');
    results.innerHTML = `
      <div class="loading-state">
        <div class="spinner"></div>
        <p>Studizz AI is analysing your document…</p>
        <p class="text-muted loading-substep">
          Extracting text · Summarising · Building quiz · Fetching videos
        </p>
        <div class="progress-bar-wrap loading-progress-wrap">
          <div class="progress-bar-fill" id="ai-progress"></div>
        </div>
        <button class="lnr-btn lnr-btn-outline lnr-btn-sm loading-cancel-btn" id="cancel-upload-btn">
          Cancel
        </button>
      </div>`;

    document.getElementById('cancel-upload-btn')?.addEventListener('click', _cancelUpload);

    let pct = 4;
    _progressTimer = setInterval(() => {
      pct = Math.min(pct + (Math.random() * 7), 88);
      const bar = document.getElementById('ai-progress');
      if (bar) bar.style.width = pct + '%';
    }, 900);
  }

  function _hideLoader() {
    clearInterval(_progressTimer);
    const zone = document.getElementById('upload-zone');
    if (zone) zone.style.opacity = '1';
  }

  /* ── Core display function — PUBLIC, used by Store too ─ */
  function _displayRaw({ summary, key_points, quiz, youtube_links, summary_id, answered_questions }) {
    _hideLoader();

    // Reset quiz state for this new result
    _quiz          = quiz         || [];
    _answers       = {};
    _quizSubmitted = false;
    _summaryId     = summary_id   || null;

    const results = document.getElementById('study-results');
    if (!results) return;

    results.classList.add('visible');
    results.innerHTML = `
      <div class="result-tabs" role="tablist">
        <button class="result-tab active" role="tab" aria-selected="true"  data-tab="summary">📄 Summary</button>
        <button class="result-tab"        role="tab" aria-selected="false" data-tab="keypoints">🔑 Key Points</button>
        <button class="result-tab"        role="tab" aria-selected="false" data-tab="quiz">📝 Quiz</button>
        <button class="result-tab"        role="tab" aria-selected="false" data-tab="answers">✍️ Answers</button>
        <button class="result-tab"        role="tab" aria-selected="false" data-tab="videos">▶️ Videos</button>
      </div>

      <div id="tab-summary"   class="tab-panel active" role="tabpanel">${_buildSummaryHTML(summary, _summaryId)}</div>
      <div id="tab-keypoints" class="tab-panel"         role="tabpanel">${_buildKeyPointsHTML(key_points)}</div>
      <div id="tab-quiz"      class="tab-panel"         role="tabpanel">${_buildQuizHTML(quiz, _summaryId)}</div>
      <div id="tab-answers"   class="tab-panel"         role="tabpanel">${_buildAnswersHTML(answered_questions)}</div>
      <div id="tab-videos"    class="tab-panel"         role="tabpanel">${_buildYouTubeHTML(youtube_links)}</div>
    `;

    // Tab switching
    results.querySelectorAll('.result-tab').forEach(btn => {
      btn.addEventListener('click', () => {
        results.querySelectorAll('.result-tab').forEach(b  => { b.classList.remove('active'); b.setAttribute('aria-selected','false'); });
        results.querySelectorAll('.tab-panel').forEach(p   => p.classList.remove('active'));
        btn.classList.add('active');
        btn.setAttribute('aria-selected', 'true');
        document.getElementById(`tab-${btn.dataset.tab}`)?.classList.add('active');
      });
    });

    _bindQuizInteractivity();

    // Ask-a-question box (summary tab)
    _initAskBox();

    Router.navigate('study');
  }

  /* ── Quiz tab interactivity (re-run after every render) ─ */
  function _bindQuizInteractivity() {
    document.querySelectorAll('.quiz-option').forEach(opt => {
      opt.addEventListener('click', () => {
        if (_quizSubmitted) return;
        const qi = opt.dataset.q;
        _answers[qi] = opt.dataset.answer;
        document.querySelectorAll(`.quiz-option[data-q="${qi}"]`).forEach(o => o.classList.remove('selected'));
        opt.classList.add('selected');
      });
    });

    document.getElementById('quiz-submit-btn')?.addEventListener('click', _submitQuiz);
    document.getElementById('quiz-regen-btn')?.addEventListener('click', _regenerateQuiz);
  }

  /* ── Quiz regeneration ──────────────────────────────── */
  async function _regenerateQuiz() {
    if (!_summaryId) return;

    const hasProgress = _quizSubmitted || Object.keys(_answers).length > 0;
    if (hasProgress && !confirm('This replaces the current quiz' +
        (_quizSubmitted ? ' and your submitted score' : ' and your selected answers') +
        '. Continue?')) {
      return;
    }

    const countInput    = document.getElementById('quiz-regen-count');
    const includeOldChk = document.getElementById('quiz-regen-include-old');
    let count = countInput ? parseInt(countInput.value, 10) : 10;
    if (!Number.isFinite(count)) count = 10;
    count = Math.max(QUIZ_REGEN_MIN, Math.min(QUIZ_REGEN_MAX, count));
    const includeOld = includeOldChk ? includeOldChk.checked : false;

    const btn = document.getElementById('quiz-regen-btn');
    if (btn) { btn.disabled = true; btn.textContent = '🔄 Generating…'; }

    try {
      const res = await Api.post(ENDPOINTS.gateway, {
        action     : 'regenerate_quiz',
        summary_id : _summaryId,
        count,
        include_old: includeOld,
      });

      if (!res.ok) {
        Toast.show(res.error || 'Quiz regeneration failed. Please try again.', 'error');
        if (btn) { btn.disabled = false; btn.textContent = '🔄 Regenerate Quiz'; }
        return;
      }

      _quiz          = res.quiz || [];
      _answers       = {};
      _quizSubmitted = false;

      const quizTab = document.getElementById('tab-quiz');
      if (quizTab) {
        quizTab.innerHTML = _buildQuizHTML(_quiz, _summaryId);
        _bindQuizInteractivity();
        const newCountInput = document.getElementById('quiz-regen-count');
        if (newCountInput) newCountInput.value = String(count);
        const newIncludeOldChk = document.getElementById('quiz-regen-include-old');
        if (newIncludeOldChk) newIncludeOldChk.checked = includeOld;
      }

      Toast.show(`New ${_quiz.length}-question quiz ready!`, 'success');
    } catch (_) {
      Toast.show('Network error during quiz regeneration.', 'error');
      if (btn) { btn.disabled = false; btn.textContent = '🔄 Regenerate Quiz'; }
    }
  }

  /* ── Quiz submission & grading ──────────────────────── */
  async function _submitQuiz() {
    if (_quizSubmitted) return;

    const total    = _quiz.length;
    const answered = Object.keys(_answers).length;
    if (answered < total) {
      Toast.show(`Answer all ${total} questions first (${total - answered} remaining).`, 'info');
      return;
    }

    const btn = document.getElementById('quiz-submit-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Grading…'; }

    try {
      const res = await Api.post(ENDPOINTS.gateway, {
        action    : 'grade_quiz',
        summary_id: _summaryId,
        answers   : _answers,
      });

      if (!res.ok) {
        Toast.show(res.error || 'Grading failed. Please try again.', 'error');
        if (btn) { btn.disabled = false; btn.textContent = 'Submit Answers'; }
        return;
      }

      _quizSubmitted = true;
      if (btn) btn.style.display = 'none';

      // Mark options correct / wrong
      res.breakdown.forEach((item, i) => {
        document.querySelectorAll(`.quiz-option[data-q="${i}"]`).forEach(opt => {
          if (opt.dataset.answer === item.correct)       opt.classList.add('correct');
          else if (opt.dataset.answer === item.your_answer) opt.classList.add('wrong');
        });
      });

      _renderScorePanel(res);
    } catch (_) {
      Toast.show('Network error during grading.', 'error');
      if (btn) { btn.disabled = false; btn.textContent = 'Submit Answers'; }
    }
  }

  /* ── Score panel ────────────────────────────────────── */
  function _renderScorePanel(res) {
    const wrap = document.getElementById('score-panel-wrap');
    if (!wrap) return;
    wrap.style.display = 'block';

    const r = 60;
    const circ  = +(2 * Math.PI * r).toFixed(2);   // matches .score-ring-fill's CSS default (377)
    const offset = +(circ - (res.score / 100) * circ).toFixed(2);
    const ringClass = res.score >= 70 ? 'success' : res.score >= 40 ? '' : 'warning';

    const reviewRows = res.breakdown.map((b, i) => `
      <div class="review-row ${b.is_correct ? 'correct' : ''}">
        <div class="review-row-question">
          Q${i+1}: ${escapeHtml(b.question)}
        </div>
        <div class="review-row-answer ${b.is_correct ? 'correct' : ''}">
          Your answer: <strong>${b.your_answer || '—'}</strong>
          ${b.is_correct ? '✓' : `✕ &nbsp;·&nbsp; Correct: <strong>${b.correct}</strong>`}
        </div>
        <div class="review-row-explain">${escapeHtml(b.explain)}</div>
      </div>`).join('');

    wrap.innerHTML = `
      <div class="score-panel">
        <div class="score-ring">
          <svg viewBox="0 0 140 140" width="140" height="140" aria-hidden="true">
            <circle class="score-ring-bg"                                  cx="70" cy="70" r="${r}"/>
            <circle class="score-ring-fill ${ringClass}" id="score-arc" cx="70" cy="70" r="${r}"/>
          </svg>
          <div class="score-ring-text">
            <span class="score-pct" id="score-counter">0%</span>
            <span class="score-label">Score</span>
          </div>
        </div>
        <h3 class="score-title">${_scoreMessage(res.score)}</h3>
        <p>${res.correct} of ${res.total} correct</p>
        <details class="score-review-details">
          <summary class="score-review-summary">
            Review all answers ▾
          </summary>
          ${reviewRows}
        </details>
      </div>`;

    // Animate ring + counter
    requestAnimationFrame(() => setTimeout(() => {
      const arc = document.getElementById('score-arc');
      if (arc) { arc.style.transition = 'stroke-dashoffset 1.2s cubic-bezier(.4,0,.2,1)'; arc.style.strokeDashoffset = offset; }
      const counter = document.getElementById('score-counter');
      if (!counter) return;
      let cur = 0;
      const iv = setInterval(() => {
        cur = Math.min(cur + 2, res.score);
        counter.textContent = cur + '%';
        if (cur >= res.score) clearInterval(iv);
      }, 22);
    }, 80));
  }

  /* ── HTML builders ──────────────────────────────────── */
  function _buildSummaryHTML(text, summaryId) {
    if (!text) return '<p class="text-muted">No summary available.</p>';

    const paraList   = String(text).split(/\n{2,}/).map(p => p.trim()).filter(Boolean);
    const wordCount  = String(text).trim().split(/\s+/).filter(Boolean).length;
    const readMins   = Math.max(1, Math.round(wordCount / 200));

    const paras = paraList
      .map((p, i) => `<p${i === 0 ? ' class="lede"' : ''}>${escapeHtml(p)}</p>`)
      .join('');

    const meta = `
      <div class="summary-meta">
        <span class="summary-meta-badge">📖 ${readMins} min read</span>
        <span class="summary-meta-badge">${paraList.length} paragraph${paraList.length === 1 ? '' : 's'}</span>
      </div>`;

    const askBox = summaryId ? `
      <div class="ask-summary-box" id="ask-summary-box">
        <div class="ask-summary-header">💬 Ask a question about this document</div>
        <div class="ask-summary-feed" id="ask-summary-messages">
          <div class="ask-summary-empty">
            <p>Ask anything about this document — answers are grounded in the full extracted text, including specific figures and details.</p>
          </div>
        </div>
        <div class="ask-summary-inputbar">
          <input
            type="text"
            id="ask-summary-input"
            class="ask-summary-input"
            placeholder="e.g. What's the main argument of this document?"
            maxlength="500"
            aria-label="Ask a question about this summary"
          />
          <button id="ask-summary-send-btn" class="ask-summary-send-btn" aria-label="Send question">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <line x1="22" y1="2" x2="11" y2="13"/>
              <polygon points="22 2 15 22 11 13 2 9 22 2"/>
            </svg>
          </button>
        </div>
      </div>` : '';

    return `${meta}<div class="summary-text">${paras}</div>${askBox}`;
  }

  function _buildKeyPointsHTML(points) {
    if (!Array.isArray(points) || !points.length)
      return '<p class="text-muted">No key points extracted.</p>';
    const items = points.map(p => `<li>${escapeHtml(p)}</li>`).join('');
    return `<ul class="key-points-list">${items}</ul>`;
  }

  const ANSWER_TYPE_LABELS = {
    essay       : '✍️ Essay',
    short_answer: '📝 Short answer',
    calculation : '🧮 Calculation',
    mcq         : '🔘 Multiple choice',
  };

  function _buildAnswersHTML(items) {
    if (!Array.isArray(items) || !items.length) {
      return '<p class="text-muted">No questions or essay prompts were detected in this document — ' +
             'looks like reading material rather than an assignment to solve.</p>';
    }

    const cards = items.map((item, i) => {
      const typeBadge = ANSWER_TYPE_LABELS[item.type]
        ? `<span class="answer-type-badge">${ANSWER_TYPE_LABELS[item.type]}</span>` : '';
      return `
        <div class="ask-exchange">
          <div class="ask-question">
            <span class="ask-question-icon">❓</span>
            <span class="ask-question-text">Q${i + 1}. ${escapeHtml(item.question || '')} ${typeBadge}</span>
          </div>
          <div class="ask-answer">
            <div class="ask-answer-icon">✦</div>
            <div class="ask-answer-text">${escapeHtml(item.answer || '').replace(/\n/g, '<br>')}</div>
          </div>
        </div>`;
    }).join('');

    return `<div class="answers-container">${cards}</div>`;
  }

  function _buildQuizRegenBarHTML() {
    return `
      <div class="quiz-regen-bar">
        <div class="quiz-regen-field">
          <label class="quiz-regen-label" for="quiz-regen-count">Questions</label>
          <input type="number" id="quiz-regen-count" class="lnr-input quiz-regen-count-input"
                 min="${QUIZ_REGEN_MIN}" max="${QUIZ_REGEN_MAX}" value="10" inputmode="numeric">
        </div>
        <label class="quiz-regen-checkbox">
          <input type="checkbox" id="quiz-regen-include-old">
          <span>Include some previous questions</span>
        </label>
        <button class="lnr-btn lnr-btn-outline lnr-btn-sm" id="quiz-regen-btn" type="button">
          🔄 Regenerate Quiz
        </button>
      </div>`;
  }

  function _buildQuizHTML(quiz, summaryId) {
    // Folder-wide quizzes have no single summary_id (they span several
    // documents' raw_text), so regeneration — which is grounded in one
    // document's full text — isn't available for them.
    const regenBar = summaryId ? _buildQuizRegenBarHTML() : '';

    if (!Array.isArray(quiz) || !quiz.length)
      return regenBar + '<p class="text-muted">No quiz available.</p>';

    const cards = quiz.map((q, i) => {
      const opts = (q.options || []).map(opt => {
        const letter = opt.charAt(0);
        const text   = opt.slice(3);
        return `<div class="quiz-option" data-q="${i}" data-answer="${escapeHtml(letter)}" role="button" tabindex="0">
          <div class="quiz-option-letter">${escapeHtml(letter)}</div>
          ${escapeHtml(text)}
        </div>`;
      }).join('');

      return `
        <div class="quiz-question-card">
          <div class="quiz-q-num">Question ${i + 1} of ${quiz.length}</div>
          <div class="quiz-q-text">${escapeHtml(q.q)}</div>
          <div class="quiz-options">${opts}</div>
        </div>`;
    }).join('');

    return `
      ${regenBar}
      <div class="quiz-container">${cards}</div>
      <div class="quiz-submit-wrap">
        <button class="lnr-btn lnr-btn-primary" id="quiz-submit-btn">Submit Answers</button>
      </div>
      <div id="score-panel-wrap" class="score-panel-wrap"></div>`;
  }

  function _buildYouTubeHTML(links) {
    if (!Array.isArray(links) || !links.length)
      return '<p class="text-muted-sm">No video recommendations were found for this document.</p>';

    const cards = links.map(v => `
      <a href="https://www.youtube.com/watch?v=${encodeURIComponent(v.videoId)}"
         target="_blank" rel="noopener noreferrer" class="yt-card">
        <img class="yt-thumb" src="${escapeHtml(v.thumbnail)}"
             alt="${escapeHtml(v.title)}" loading="lazy"
             onerror="this.style.display='none'"/>
        <div class="yt-info">
          <div class="yt-title">${escapeHtml(v.title)}</div>
          <div class="yt-channel">${escapeHtml(v.channel || '')}</div>
        </div>
      </a>`).join('');

    return `
      <p class="yt-recommend-note">
        Recommended based on your document topic
      </p>
      <div class="yt-grid">${cards}</div>`;
  }

  function _scoreMessage(s) {
    if (s >= 90) return '🏆 Outstanding performance!';
    if (s >= 70) return '🌟 Great work!';
    if (s >= 50) return '📚 Decent effort — keep reviewing!';
    return '💪 Don\'t give up. Review the material and try again!';
  }

  /* ── Ask-a-question box (summary tab) ───────────────── */
  // Rendered as Q/A "exchanges" (question label + answer block) rather
  // than chat bubbles — reads better for the longer, factual answers
  // this feature returns than a messaging-app bubble does.
  let _askSending     = false;
  let _askExchangeSeq = 0;

  function _appendAskExchange(question) {
    const container = document.getElementById('ask-summary-messages');
    if (!container) return null;

    container.querySelector('.ask-summary-empty')?.remove();

    const exchangeId = `ask-exchange-${_askExchangeSeq++}`;
    const el = document.createElement('div');
    el.className = 'ask-exchange';
    el.id = exchangeId;
    el.innerHTML = `
      <div class="ask-question">
        <span class="ask-question-icon">❓</span>
        <span class="ask-question-text">${escapeHtml(question)}</span>
      </div>
      <div class="ask-answer">
        <div class="ask-answer-icon">✦</div>
        <div class="ask-answer-text ask-answer-loading">
          <span class="ask-thinking-dot"></span><span class="ask-thinking-dot"></span><span class="ask-thinking-dot"></span>
        </div>
      </div>`;

    container.appendChild(el);
    container.scrollTop = container.scrollHeight;
    return exchangeId;
  }

  function _resolveAskExchange(exchangeId, answer, isError) {
    const container = document.getElementById('ask-summary-messages');
    const answerEl   = document.getElementById(exchangeId)?.querySelector('.ask-answer-text');
    if (!answerEl) return;

    answerEl.classList.remove('ask-answer-loading');
    answerEl.classList.toggle('ask-answer-error', !!isError);
    answerEl.innerHTML = escapeHtml(answer).replace(/\n/g, '<br>');

    if (container) container.scrollTop = container.scrollHeight;
  }

  async function _sendAskQuestion() {
    if (_askSending) return;
    if (!_summaryId) {
      Toast.show('Ask a question is unavailable for this result.', 'info');
      return;
    }

    const input = document.getElementById('ask-summary-input');
    if (!input) return;
    const question = input.value.trim();
    if (!question) return;

    input.value = '';
    _askSending = true;
    const btn = document.getElementById('ask-summary-send-btn');
    if (btn) btn.disabled = true;

    const exchangeId = _appendAskExchange(question);

    try {
      const res = await Api.post(ENDPOINTS.gateway, {
        action     : 'ask_summary',
        summary_id : _summaryId,
        question,
      });

      if (!res.ok) {
        _resolveAskExchange(exchangeId, res.error || 'Something went wrong. Please try again.', true);
      } else {
        _resolveAskExchange(exchangeId, res.answer, false);
      }
    } catch (_) {
      _resolveAskExchange(exchangeId, 'Network error. Please check your connection.', true);
    }

    _askSending = false;
    if (btn) btn.disabled = false;
  }

  function _initAskBox() {
    _askExchangeSeq = 0;
    const input = document.getElementById('ask-summary-input');
    const btn   = document.getElementById('ask-summary-send-btn');

    input?.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); _sendAskQuestion(); }
    });
    btn?.addEventListener('click', _sendAskQuestion);
  }

  /* ── Folder quiz entry point (called from Store) ──── */
  function renderFolderQuiz(quiz, folderId) {
    _displayRaw({
      summary           : null,
      key_points        : [],
      quiz              : quiz,
      youtube_links     : [],
      summary_id        : null,
      answered_questions: [],
    });
    // Switch to quiz tab automatically
    setTimeout(() => {
      const quizTab = document.querySelector('.result-tab[data-tab="quiz"]');
      quizTab?.click();
    }, 80);
    Toast.show('Folder quiz ready! Answer all questions and submit.', 'info', 4000);
  }

  /* ── Stored summary entry point (called from Store) ── */
  function renderStoredSummary(data) {
    _displayRaw({
      summary           : data.summary,
      key_points        : data.key_points,
      quiz              : data.quiz_json,
      youtube_links     : data.youtube_links,
      summary_id        : data.id,
      answered_questions: data.answered_questions,
    });
  }

  /* ── Populate folder dropdown on study page ─────────── */
  const LAST_FOLDER_KEY = 'studizz_last_folder';

  function populateFolderDropdown(folders) {
    const sel  = document.getElementById('upload-folder-select');
    const wrap = document.getElementById('folder-assign-wrap');
    if (!sel || !wrap) return;

    if (!folders || !folders.length) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'block';

    sel.innerHTML = '<option value="">— No folder —</option>' +
      folders.map(f => `<option value="${f.id}">${escapeHtml(f.name)}</option>`).join('');

    // Restore the last folder the user picked — without this, every page
    // reload/navigation silently resets the dropdown to "No folder" and
    // uploads quietly stop landing in the intended folder.
    const lastFolder = localStorage.getItem(LAST_FOLDER_KEY);
    if (lastFolder && folders.some(f => String(f.id) === lastFolder)) {
      sel.value = lastFolder;
    }
  }

  function init() {
    initUpload();

    document.getElementById('upload-folder-select')?.addEventListener('change', e => {
      localStorage.setItem(LAST_FOLDER_KEY, e.target.value);
    });
  }

  return {
    init,
    renderFolderQuiz,
    renderStoredSummary,
    populateFolderDropdown,
    _displayRaw,   // exposed for cross-module use
  };
})();

/* ─────────────────────────────────────────────────────────
   STORE MODULE
───────────────────────────────────────────────────────── */
const Store = (() => {
  let _folders       = [];
  let _activeFolderId = null;  // null = All Summaries

  /* ── Bootstrap ──────────────────────────────────────── */
  async function refresh() {
    await loadFolders();
    await loadSummaries();
  }

  /* ── Folders ────────────────────────────────────────── */
  async function loadFolders() {
    try {
      const res = await Api.post(ENDPOINTS.store, { action: 'list_folders' });
      if (!res.ok) return;
      _folders = res.folders || [];
      _renderSidebar();
      Study.populateFolderDropdown(_folders);
    } catch (_) { Toast.show('Could not load folders.', 'error'); }
  }

  function _renderSidebar() {
    const list = document.getElementById('folder-list');
    if (!list) return;

    const totalCount = _folders.reduce((n, f) => n + parseInt(f.summary_count || 0, 10), 0);

    const allRow = `
      <div class="folder-item ${_activeFolderId === null ? 'active' : ''}"
           data-folder-id="__all__" role="button" tabindex="0">
        <span class="folder-dot folder-dot-all"></span>
        All Summaries
        <span class="folder-count">${totalCount}</span>
      </div>`;

    const folderRows = _folders.map(f => `
      <div class="folder-item ${_activeFolderId === f.id ? 'active' : ''}"
           data-folder-id="${f.id}" role="button" tabindex="0">
        <span class="folder-dot" style="background:${escapeHtml(f.color)}"></span>
        ${escapeHtml(f.name)}
        <span class="folder-count">${f.summary_count || 0}</span>
      </div>`).join('');

    list.innerHTML = allRow + folderRows;

    list.querySelectorAll('.folder-item').forEach(item => {
      const activate = () => {
        const raw = item.dataset.folderId;
        _activeFolderId = raw === '__all__' ? null : parseInt(raw, 10);
        _renderSidebar();
        loadSummaries();
      };
      item.addEventListener('click', activate);
      item.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') activate(); });
    });
  }

  /* ── Summaries ──────────────────────────────────────── */
  async function loadSummaries() {
    const payload = { action: 'list_summaries' };
    if (_activeFolderId !== null) payload.folder_id = _activeFolderId;

    try {
      const res = await Api.post(ENDPOINTS.store, payload);
      if (res.ok) _renderSummaries(res.summaries || []);
    } catch (_) { Toast.show('Could not load summaries.', 'error'); }
  }

  function _renderSummaries(list) {
    const grid = document.getElementById('summaries-grid');
    if (!grid) return;

    if (!list.length) {
      grid.innerHTML = `
        <div class="lnr-empty lnr-empty-span">
          <div class="lnr-empty-icon">📂</div>
          <h4>No summaries here yet</h4>
          <p>Upload a document in the Study section to create your first summary.</p>
          <button class="lnr-btn lnr-btn-primary lnr-btn-sm mt-16" data-page="study">
            Go to Study →
          </button>
        </div>`;
      return;
    }

    grid.innerHTML = list.map(s => `
      <div class="summary-card" data-id="${s.id}">
        <div class="summary-card-name">📄 ${escapeHtml(s.original_name)}</div>
        <div class="summary-card-preview">${escapeHtml(s.preview || '')}…</div>
        <div class="summary-card-footer">
          <span>${formatDate(s.created_at)}</span>
          <div class="summary-card-actions">
            <select class="summary-folder-select" data-id="${s.id}" title="Move to folder">
              ${_folderOptionsHTML(s.folder_id)}
            </select>
            <button class="lnr-btn lnr-btn-ghost lnr-btn-sm" data-action="view"   data-id="${s.id}" title="View summary">👁</button>
            <button class="lnr-btn lnr-btn-ghost lnr-btn-sm" data-action="delete" data-id="${s.id}" title="Delete summary">🗑</button>
          </div>
        </div>
      </div>`).join('');

    grid.querySelectorAll('[data-action]').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const id = parseInt(btn.dataset.id, 10);
        if (btn.dataset.action === 'view')   _viewSummary(id);
        if (btn.dataset.action === 'delete') _deleteSummary(id);
      });
    });

    // Folder-move dropdown per card
    grid.querySelectorAll('.summary-folder-select').forEach(sel => {
      sel.addEventListener('click', e => e.stopPropagation());
      sel.addEventListener('change', () => {
        const id       = parseInt(sel.dataset.id, 10);
        const folderId = sel.value ? parseInt(sel.value, 10) : null;
        _moveSummary(id, folderId);
      });
    });

    // Card click also views
    grid.querySelectorAll('.summary-card').forEach(card => {
      card.addEventListener('click', () => _viewSummary(parseInt(card.dataset.id, 10)));
    });
  }

  function _folderOptionsHTML(currentFolderId) {
    const noFolder = `<option value="" ${!currentFolderId ? 'selected' : ''}>— No folder —</option>`;
    const rest = _folders.map(f =>
      `<option value="${f.id}" ${String(f.id) === String(currentFolderId) ? 'selected' : ''}>${escapeHtml(f.name)}</option>`
    ).join('');
    return noFolder + rest;
  }

  async function _moveSummary(id, folderId) {
    try {
      const res = await Api.post(ENDPOINTS.store, { action: 'move_summary', id, folder_id: folderId });
      if (res.ok) {
        Toast.show('Summary moved.', 'success', 2500);
        await refresh();
      } else {
        Toast.show(res.error || 'Could not move summary.', 'error');
      }
    } catch (_) { Toast.show('Network error while moving summary.', 'error'); }
  }

  async function _viewSummary(id) {
    Toast.show('Loading summary…', 'info', 2000);
    try {
      const res = await Api.post(ENDPOINTS.store, { action: 'get_summary', id });
      if (!res.ok) { Toast.show(res.error || 'Could not load summary.', 'error'); return; }
      Study.renderStoredSummary(res.summary);
    } catch (_) { Toast.show('Could not load summary.', 'error'); }
  }

  async function _deleteSummary(id) {
    if (!confirm('Permanently delete this summary? This cannot be undone.')) return;
    try {
      const res = await Api.post(ENDPOINTS.store, { action: 'delete_summary', id });
      if (res.ok) { Toast.show('Summary deleted.', 'success'); await refresh(); }
      else Toast.show(res.error || 'Delete failed. Please try again.', 'error');
    } catch (_) { Toast.show('Delete failed.', 'error'); }
  }

  /* ── Folder CRUD modal ──────────────────────────────── */
  function _openFolderModal() {
    const overlay = document.getElementById('folder-modal-overlay');
    if (!overlay) return;
    overlay.classList.add('visible');
    document.getElementById('folder-name-input')?.focus();
  }

  function _closeFolderModal() {
    document.getElementById('folder-modal-overlay')?.classList.remove('visible');
    document.getElementById('folder-name-input').value = '';
  }

  async function _createFolder(name, color) {
    try {
      const res = await Api.post(ENDPOINTS.store, { action: 'create_folder', name, color });
      if (res.ok) {
        Toast.show(`Folder "${name}" created.`, 'success');
        _closeFolderModal();
        // refresh (not just loadFolders) so existing cards' folder-move
        // dropdowns are re-rendered with the new folder as an option
        await refresh();
      } else Toast.show(res.error || 'Could not create folder. Please try again.', 'error');
    } catch (_) { Toast.show('Could not create folder.', 'error'); }
  }

  /* ── Folder-wide quiz ───────────────────────────────── */
  async function _launchFolderQuiz() {
    if (_activeFolderId === null) {
      Toast.show('Select a specific folder first to generate a folder quiz.', 'info');
      return;
    }
    Toast.show('Generating folder quiz — this may take a moment…', 'info', 5000);
    try {
      const res = await Api.post(ENDPOINTS.gateway, {
        action   : 'folder_quiz',
        folder_id: _activeFolderId,
      });
      if (!res.ok) { Toast.show(res.error || 'Quiz generation failed. Please try again.', 'error'); return; }
      Study.renderFolderQuiz(res.quiz, _activeFolderId);
    } catch (_) { Toast.show('Quiz generation failed. Please try again.', 'error'); }
  }

  function init() {
    // Folder modal open/close
    document.getElementById('add-folder-btn')?.addEventListener('click', _openFolderModal);
    document.getElementById('close-folder-modal')?.addEventListener('click', _closeFolderModal);

    // Close modal on overlay background click
    document.getElementById('folder-modal-overlay')?.addEventListener('click', e => {
      if (e.target === e.currentTarget) _closeFolderModal();
    });

    // Folder form submission
    document.getElementById('folder-form')?.addEventListener('submit', e => {
      e.preventDefault();
      const name  = document.getElementById('folder-name-input')?.value.trim();
      const color = document.getElementById('folder-color-input')?.value || '#6C63FF';
      if (name) _createFolder(name, color);
    });

    // Folder quiz
    document.getElementById('folder-quiz-btn')?.addEventListener('click', _launchFolderQuiz);

    // Initial load
    refresh();
  }

  return { init, refresh, loadFolders };
})();

/* ─────────────────────────────────────────────────────────
   CHATBOT MODULE
───────────────────────────────────────────────────────── */
const Chatbot = (() => {
  const SESSION_STORAGE_KEY = 'studizz_chat_sid';
  let _sessionId  = null;
  let _history    = [];   // [{role, content}] last 20 turns kept
  let _remaining  = 15;
  let _isSending  = false;
  let _initialized = false;

  function onEnter() {
    // Only load status once per page load
    if (!_initialized) { _initialized = true; _loadStatus(); }
  }

  async function _loadStatus() {
    try {
      const res = await Api.post(ENDPOINTS.gateway, { action: 'get_chat_status' });
      if (res.ok) _updateQuota(res.remaining, res.used);
    } catch (_) { /* non-critical */ }
  }

  function _updateQuota(remaining, used) {
    _remaining = remaining;
    const el = document.getElementById('chat-quota');
    if (el) el.innerHTML = `<span>${remaining}</span> / 15 queries today`;

    if (remaining <= 0) {
      const input = document.getElementById('chat-input');
      const btn   = document.getElementById('chat-send-btn');
      if (input) {
        input.disabled     = true;
        input.placeholder  = 'Daily limit reached. Come back tomorrow!';
      }
      if (btn) btn.disabled = true;
    }
  }

  function _appendMessage(role, content) {
    const container = document.getElementById('chat-messages');
    if (!container) return;

    // Remove empty state placeholder
    container.querySelector('.chat-empty')?.remove();

    const user     = Onboarding.getUserData();
    const initials = (user?.name || user?.username || 'U').charAt(0).toUpperCase();
    const msgEl    = document.createElement('div');
    msgEl.className = `chat-message ${role}`;

    const botAvatar  = `<div class="chat-message-avatar">🤖</div>`;
    const userAvatar = `<div class="chat-message-avatar">${escapeHtml(initials)}</div>`;
    const formatted  = escapeHtml(content).replace(/\n/g, '<br>');

    msgEl.innerHTML = `
      ${role === 'bot' ? botAvatar : ''}
      <div class="chat-bubble">${formatted}</div>
      ${role === 'user' ? userAvatar : ''}`;

    container.appendChild(msgEl);
    container.scrollTop = container.scrollHeight;
  }

  function _showTyping() {
    const container = document.getElementById('chat-messages');
    if (!container) return;
    const el    = document.createElement('div');
    el.id       = 'chat-typing-indicator';
    el.className = 'chat-message bot';
    el.innerHTML = `
      <div class="chat-message-avatar">🤖</div>
      <div class="chat-typing visible">
        <div class="typing-dot"></div>
        <div class="typing-dot"></div>
        <div class="typing-dot"></div>
      </div>`;
    container.appendChild(el);
    container.scrollTop = container.scrollHeight;
  }

  function _hideTyping() {
    document.getElementById('chat-typing-indicator')?.remove();
  }

  async function _send() {
    if (_isSending || _remaining <= 0) return;

    const input = document.getElementById('chat-input');
    if (!input) return;
    const message = input.value.trim();
    if (!message) return;

    input.value      = '';
    input.style.height = 'auto';
    _isSending       = true;

    _appendMessage('user', message);
    _showTyping();

    _history.push({ role: 'user', content: message });
    if (_history.length > 20) _history = _history.slice(-20);

    try {
      const res = await Api.post(ENDPOINTS.gateway, {
        action    : 'chat',
        message,
        session_id: _sessionId,
        history   : _history.slice(-10),
      });

      _hideTyping();

      if (!res.ok) {
        _appendMessage('bot', `⚠️ ${res.error || 'Something went wrong. Please try again.'}`);
        if (res.remaining !== undefined) _updateQuota(res.remaining, res.used);
      } else {
        _appendMessage('bot', res.reply);
        _history.push({ role: 'assistant', content: res.reply });
        _updateQuota(res.remaining, res.used);
      }
    } catch (_) {
      _hideTyping();
      _appendMessage('bot', '⚠️ Network error. Please check your connection.');
    }

    _isSending = false;
  }

  function init() {
    // Persist session ID across page switches but not across browser sessions
    _sessionId = sessionStorage.getItem(SESSION_STORAGE_KEY);
    if (!_sessionId) {
      _sessionId = generateUUID();
      sessionStorage.setItem(SESSION_STORAGE_KEY, _sessionId);
    }

    const input = document.getElementById('chat-input');
    const btn   = document.getElementById('chat-send-btn');

    input?.addEventListener('input', () => {
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    });

    input?.addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); _send(); }
    });

    btn?.addEventListener('click', _send);
  }

  return { init, onEnter };
})();

/* ─────────────────────────────────────────────────────────
   BOOT — initialise all modules after DOM is ready
───────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  Router.init();       // sets up nav + shows home page
  Onboarding.init();   // checks cookie → shows modal if new user
  Study.init();        // wires upload zone
  Store.init();        // loads folders + summaries
  Chatbot.init();      // wires input + send button

  // Set footer year
  const fy = document.getElementById('footer-year');
  if (fy) fy.textContent = new Date().getFullYear();
});
