<!-- ═══════════════════════════════════════════════
     PAGE: STORE
     All IDs identical to original — JS untouched.
     ═══════════════════════════════════════════════ -->
<div id="page-store" class="page">
  <section class="lnr-section">
    <div class="lnr-container">

      <div class="lnr-page-header lnr-page-header-row">
        <div>
          <div class="lnr-eyebrow">Store</div>
          <h2 class="lnr-section-title" style="margin-bottom:0">Your Study Archive</h2>
        </div>
        <div class="lnr-page-header-actions">
          <button class="lnr-btn lnr-btn-outline lnr-btn-sm" id="folder-quiz-btn">
            📝 Quiz this folder
          </button>
          <button class="lnr-btn lnr-btn-primary lnr-btn-sm" id="add-folder-btn">
            + New folder
          </button>
        </div>
      </div>

      <div class="lnr-store-layout">

        <!-- Sidebar: folders -->
        <aside class="lnr-sidebar">
          <div class="lnr-sidebar-head">Folders</div>
          <div class="folder-list" id="folder-list">
            <p style="font-size:0.82rem;color:var(--lnr-muted);padding:8px 0">Loading…</p>
          </div>
        </aside>

        <!-- Main: summaries grid -->
        <div class="lnr-store-main">
          <div class="summaries-grid" id="summaries-grid">
            <div class="lnr-empty" style="grid-column:1/-1">
              <div class="lnr-empty-icon">📂</div>
              <h4>No summaries yet</h4>
              <p>Upload a document in the Study section to create your first summary.</p>
              <button class="lnr-btn lnr-btn-primary lnr-btn-sm" style="margin-top:16px" data-page="study">
                Go to Study →
              </button>
            </div>
          </div>
        </div>

      </div>
    </div>
  </section>
</div><!-- /page-store -->
