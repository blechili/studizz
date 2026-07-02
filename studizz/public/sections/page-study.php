<!-- ═══════════════════════════════════════════════
     PAGE: STUDY
     All IDs identical to original — JS untouched.
     ═══════════════════════════════════════════════ -->
<div id="page-study" class="page">
  <section class="lnr-section">
    <div class="lnr-container">

      <div class="lnr-page-header">
        <div class="lnr-eyebrow">Study Module</div>
        <h2 class="lnr-section-title">Upload a document to begin</h2>
        <p class="lnr-section-lead" style="max-width:520px">
          Drop any document below — PDF, Word, PowerPoint, Excel, text, or image — or paste a
          link. Studizz will extract the text, summarise it, generate a quiz, and recommend
          videos — automatically.
        </p>
      </div>

      <div class="lnr-study-layout">

        <!-- Left: upload zone -->
        <div class="lnr-upload-col">
          <div class="lnr-upload-zone" id="upload-zone" role="button" tabindex="0"
               aria-label="Upload a document">
            <div class="lnr-upload-icon">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
              </svg>
            </div>
            <h3 class="lnr-upload-title">Drop your file here</h3>
            <p class="lnr-upload-sub">or click to browse from your device</p>
            <div class="lnr-upload-tags">
              <span class="lnr-tag">PDF</span>
              <span class="lnr-tag">DOCX</span>
              <span class="lnr-tag">PPT/PPTX</span>
              <span class="lnr-tag">XLSX/XLS</span>
              <span class="lnr-tag">TXT</span>
              <span class="lnr-tag">Images</span>
              <span class="lnr-tag">Max 20 MB</span>
            </div>
            <!-- id="file-input" must stay — wired in app.js.
                 No accept filter — any file type is allowed through;
                 the backend extractor decides if it can read it. -->
            <input type="file" id="file-input" />
          </div>

          <!-- Paste-a-link alternative — wired in app.js (_processUrl).
               Backend fetches server-side only (SSRF-guarded) and either
               runs it through the same extractor as a file (if it's a
               direct link to a PDF/DOCX/etc.) or pulls readable text out
               of the page. Video links are rejected with a friendly error. -->
          <div class="lnr-url-divider"><span>or paste a link</span></div>
          <div class="lnr-url-input-row">
            <input type="url" id="document-url-input" class="lnr-input"
                   placeholder="https://example.com/article-or-file" />
            <button type="button" id="process-url-btn" class="lnr-btn lnr-btn-primary lnr-btn-sm">
              Fetch
            </button>
          </div>
          <p class="lnr-upload-sub" style="margin-top:6px;font-size:0.78rem">
            Articles, PDFs, or Word/PowerPoint/Excel links — not video pages.
          </p>

          <!-- Folder selector — shown by JS when folders exist -->
          <div id="folder-assign-wrap" style="margin-top:16px;display:none">
            <div class="lnr-field">
              <label class="lnr-label" for="upload-folder-select">Save to folder (optional)</label>
              <select id="upload-folder-select" class="lnr-input lnr-select">
                <option value="">— No folder —</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Right: results panel injected by app.js -->
        <div id="study-results" class="study-results lnr-results-panel"></div>

      </div>
    </div>
  </section>
</div><!-- /page-study -->

