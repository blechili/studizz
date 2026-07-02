<!-- ═══════════════════════════════════════════════
     ONBOARDING MODAL
     All form IDs and name attributes are unchanged.
     Backend wiring in onboard.php is untouched.
     ═══════════════════════════════════════════════ -->
<div id="onboarding-overlay" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="onboard-title">
  <div class="lnr-modal">

    <div class="lnr-modal-brand">
      <div class="lnr-modal-icon">
        <svg width="22" height="22" viewBox="0 0 22 22" fill="none">
          <circle cx="11" cy="11" r="10" stroke="url(#mg)" stroke-width="1.5"/>
          <circle cx="11" cy="11" r="4" fill="url(#mg2)"/>
          <defs>
            <linearGradient id="mg" x1="0" y1="0" x2="22" y2="22" gradientUnits="userSpaceOnUse">
              <stop stop-color="#818cf8"/><stop offset="1" stop-color="#6366f1"/>
            </linearGradient>
            <linearGradient id="mg2" x1="7" y1="7" x2="15" y2="15" gradientUnits="userSpaceOnUse">
              <stop stop-color="#a5b4fc"/><stop offset="1" stop-color="#6366f1"/>
            </linearGradient>
          </defs>
        </svg>
      </div>
      <span class="lnr-modal-wordmark">Studizz</span>
    </div>

    <h2 id="onboard-title" class="lnr-modal-title">Create your account</h2>
    <p class="lnr-modal-sub">Your AI study partner. Takes 30 seconds.</p>

    <div id="onboard-error" class="lnr-error" role="alert"></div>

    <!-- IDs, names, and types identical to original — backend untouched -->
    <form id="onboard-form" class="lnr-form" novalidate>
      <div class="lnr-field-row">
        <div class="lnr-field">
          <label class="lnr-label" for="ob-name">Full Name</label>
          <input id="ob-name" name="name" type="text" class="lnr-input"
                 placeholder="Tise Oladoye" required autocomplete="name" />
        </div>
        <div class="lnr-field">
          <label class="lnr-label" for="ob-username">Username</label>
          <input id="ob-username" name="username" type="text" class="lnr-input"
                 placeholder="tise_studies" required autocomplete="username" />
        </div>
      </div>
      <div class="lnr-field">
        <label class="lnr-label" for="ob-class">Class / Year</label>
        <input id="ob-class" name="class" type="text" class="lnr-input"
               placeholder="SS3 / Year 12 / 300L" required />
      </div>
      <div class="lnr-field">
        <label class="lnr-label" for="ob-email">Email Address</label>
        <input id="ob-email" name="email" type="email" class="lnr-input"
               placeholder="you@email.com" required autocomplete="email" />
      </div>
      <div class="lnr-field">
        <label class="lnr-label" for="ob-phone">Phone Number</label>
        <input id="ob-phone" name="phone" type="tel" class="lnr-input"
               placeholder="+234 800 000 0000" required autocomplete="tel" />
      </div>
      <button type="submit" class="lnr-btn lnr-btn-primary lnr-btn-full">
        Get started <span class="lnr-btn-arrow">→</span>
      </button>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════
     FOLDER CREATION MODAL
     ═══════════════════════════════════════════════ -->
<div id="folder-modal-overlay" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="folder-modal-title">
  <div class="lnr-modal lnr-modal-sm">
    <h3 id="folder-modal-title" class="lnr-modal-title" style="font-size:1.1rem">New Folder</h3>
    <form id="folder-form" class="lnr-form" novalidate>
      <div class="lnr-field">
        <label class="lnr-label" for="folder-name-input">Folder name</label>
        <input id="folder-name-input" type="text" class="lnr-input"
               placeholder="e.g. Biology Notes" required maxlength="120" />
      </div>
      <div class="lnr-field">
        <label class="lnr-label" for="folder-color-input">Colour</label>
        <input id="folder-color-input" type="color" value="#6366f1" class="lnr-color-input" />
      </div>
      <div class="lnr-modal-actions">
        <button type="submit" class="lnr-btn lnr-btn-primary lnr-btn-sm">Create folder</button>
        <button type="button" id="close-folder-modal" class="lnr-btn lnr-btn-ghost lnr-btn-sm">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════
     CHANGE USERNAME MODAL
     ═══════════════════════════════════════════════ -->
<div id="username-modal-overlay" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="username-modal-title">
  <div class="lnr-modal lnr-modal-sm">
    <h3 id="username-modal-title" class="lnr-modal-title" style="font-size:1.1rem">Change Username</h3>
    <div id="username-modal-error" class="lnr-error" role="alert"></div>
    <form id="username-form" class="lnr-form" novalidate>
      <div class="lnr-field">
        <label class="lnr-label" for="username-input">New username</label>
        <input id="username-input" type="text" class="lnr-input"
               placeholder="tise_studies" required maxlength="60" autocomplete="username" />
      </div>
      <div class="lnr-modal-actions">
        <button type="submit" class="lnr-btn lnr-btn-primary lnr-btn-sm">Save</button>
        <button type="button" id="close-username-modal" class="lnr-btn lnr-btn-ghost lnr-btn-sm">Cancel</button>
      </div>
    </form>
  </div>
</div>

