<!-- ═══════════════════════════════════════════════
     NAVIGATION BAR
     data-page attributes identical to original JS.
     ═══════════════════════════════════════════════ -->
<header class="lnr-nav" id="lnr-nav">
  <div class="lnr-nav-inner">




    <!-- CENTRE: Nav links -->
    <nav aria-label="Main navigation">
      <ul class="lnr-nav-links" id="nav-links">
        <li><a href="#" data-page="home"     class="lnr-nav-link">Home</a></li>
        <li><a href="#" data-page="features" class="lnr-nav-link">Features</a></li>
        <li><a href="#" data-page="study"    class="lnr-nav-link">Study</a></li>
        <li><a href="#" data-page="store"    class="lnr-nav-link">Store</a></li>
        <li><a href="#" data-page="chatbot"  class="lnr-nav-link">AI Chat</a></li>
      </ul>
    </nav>

    <!-- RIGHT: User chip + CTA -->
    <div class="lnr-nav-right">
      <div class="lnr-nav-user" id="nav-user-chip" role="button" tabindex="0"
           title="Click to change your username" aria-label="Change username">
        <div class="lnr-avatar" id="nav-avatar">?</div>
        <span id="nav-user" class="lnr-nav-username"></span>
      </div>
      <button class="lnr-btn lnr-btn-ghost lnr-btn-sm lnr-nav-logout" id="logout-btn"
              hidden aria-label="Sign out of Studizz">
        Sign out
      </button>
      <button class="lnr-btn lnr-btn-primary lnr-btn-sm" data-page="study">
        Start studying
      </button>
      <!-- Mobile hamburger -->
      <button class="lnr-hamburger" id="hamburger" aria-label="Toggle menu" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>
    </div>

  </div>
</header>

