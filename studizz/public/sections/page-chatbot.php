<!-- ═══════════════════════════════════════════════
     PAGE: CHATBOT
     All IDs identical to original — JS untouched.
     ═══════════════════════════════════════════════ -->
<div id="page-chatbot" class="page">
  <section class="lnr-section-sm">
    <div class="lnr-container">

      <div class="lnr-page-header lnr-page-header-row">
        <div>
          <div class="lnr-eyebrow">AI Assistant</div>
          <h2 class="lnr-section-title" style="margin-bottom:0">Studizz AI Chatbot</h2>
        </div>
        <!-- Quota counter — JS updates this -->
        <div class="lnr-quota-badge" id="chat-quota">
          <span>15</span> / 15 queries today
        </div>
      </div>

      <div class="lnr-chat-shell">

        <!-- Chat top bar -->
        <div class="lnr-chat-topbar">
          <div class="lnr-chat-identity">
            <div class="lnr-chat-avatar">🤖</div>
            <div>
              <div class="lnr-chat-name">Studizz AI</div>
              <div class="lnr-chat-status">
                <span class="lnr-status-dot"></span>Online
              </div>
            </div>
          </div>
          <span class="lnr-chat-hint">Ask anything about your studies</span>
        </div>

        <!-- Message feed — JS populates this -->
        <div class="chat-messages lnr-chat-feed" id="chat-messages">
          <div class="chat-empty lnr-chat-empty">
            <div class="chat-empty-icon">💬</div>
            <h4>Start a conversation</h4>
            <p>Ask me to explain a concept, summarise a topic, or guide you through using Studizz.</p>
          </div>
        </div>

        <!-- Input bar -->
        <div class="lnr-chat-inputbar">
          <textarea
            id="chat-input"
            class="chat-input lnr-chat-input"
            placeholder="Ask Studizz AI anything…"
            rows="1"
            maxlength="2000"
            aria-label="Chat message"
          ></textarea>
          <button id="chat-send-btn" class="chat-send-btn lnr-send-btn" aria-label="Send">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <line x1="22" y1="2" x2="11" y2="13"/>
              <polygon points="22 2 15 22 11 13 2 9 22 2"/>
            </svg>
          </button>
        </div>

      </div>
    </div>
  </section>
</div><!-- /page-chatbot -->

