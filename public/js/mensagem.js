/* =====================================================
   MENSAGEM.JS
   Handles the "Enviar Mensagem Personalizada" panel:
   - Open / close with smooth animation
   - Populate client data from the existing clients array
   - Character counter
   - Validation + visual feedback
   - Send simulation (ready for backend integration)
   ===================================================== */

(function () {
  'use strict';

  /* ── DOM refs ──────────────────────────────────────── */
  const panel        = document.getElementById('msgPanel');
  const closeBtn     = document.getElementById('msgPanelClose');
  const avatar       = document.getElementById('msgAvatar');
  const nameEl       = document.getElementById('msgClientName');
  const emailEl      = document.getElementById('msgClientEmail');
  const phoneEl      = document.getElementById('msgClientPhone');
  const textarea     = document.getElementById('msgTextarea');
  const charCount    = document.getElementById('msgCharCount');
  const feedback     = document.getElementById('msgFeedback');
  const btnSend      = document.getElementById('msgBtnSend');
  const btnText      = document.getElementById('msgBtnText');
  const spinner      = document.getElementById('msgSpinner');
  const btnClear     = document.getElementById('msgBtnClear');

  const MAX_CHARS = 500;

  /* ── Currently selected client ─────────────────────── */
  let activeClient = null;

  /* ── Utilities ─────────────────────────────────────── */
  function getInitials(name) {
    return name.split(' ').map(n => n[0]).slice(0, 2).join('').toUpperCase();
  }

  function hideFeedback() {
    feedback.style.display = 'none';
    feedback.className = 'msg-feedback';
    feedback.innerHTML = '';
  }

  function showFeedback(type, message) {
    const icon = type === 'success'
      ? `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
           <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
         </svg>`
      : `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
           <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
         </svg>`;

    feedback.innerHTML   = icon + `<span>${message}</span>`;
    feedback.className   = `msg-feedback ${type}`;
    feedback.style.display = 'flex';

    // Auto-hide success after 5 s
    if (type === 'success') {
      setTimeout(hideFeedback, 5000);
    }
  }

  function updateCharCount() {
    const len = textarea.value.length;
    charCount.textContent = `${len} / ${MAX_CHARS}`;
    charCount.classList.remove('near-limit', 'at-limit');
    if (len >= MAX_CHARS)        charCount.classList.add('at-limit');
    else if (len >= MAX_CHARS * 0.85) charCount.classList.add('near-limit');
  }

  function resetForm() {
    textarea.value = '';
    updateCharCount();
    hideFeedback();
    textarea.focus();
  }

  /* ── Open panel ─────────────────────────────────────── */
  // Called from the inline onclick in the rendered table row.
  // The global `clients` array lives in the existing inline <script>.
  window.openMsgPanel = function (clientId) {
    // `clients` is defined in the page's inline <script>
    const client = (window.clients || []).find(c => c.id === clientId);
    if (!client) return;

    activeClient = client;

    // Populate header
    avatar.textContent    = getInitials(client.name);
    nameEl.textContent    = client.name;
    emailEl.textContent   = client.email;
    if (phoneEl) phoneEl.textContent = client.phone;

    // Pre-fill textarea with a personalised greeting
    textarea.value = `Olá ${client.name.split(' ')[0]}, `;
    updateCharCount();
    hideFeedback();

    // Show panel with animation
    panel.classList.add('visible');

    // Smooth scroll to panel
    setTimeout(() => {
      panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      textarea.focus();
      // Position cursor at end
      const len = textarea.value.length;
      textarea.setSelectionRange(len, len);
    }, 80);
  };

  /* ── Close panel ─────────────────────────────────────── */
  function closePanel() {
    panel.classList.remove('visible');
    activeClient = null;
    resetForm();
  }

  closeBtn.addEventListener('click', closePanel);

  // Close on Escape key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && panel.classList.contains('visible')) {
      closePanel();
    }
  });

  /* ── Character counter ───────────────────────────────── */
  textarea.addEventListener('input', () => {
    updateCharCount();
    if (feedback.classList.contains('error')) hideFeedback();
  });

  /* ── Clear button ────────────────────────────────────── */
  btnClear.addEventListener('click', () => {
    resetForm();
  });

  /* ── Send ────────────────────────────────────────────── */
  btnSend.addEventListener('click', () => {
    const message = textarea.value.trim();

    // Validation
    if (!message) {
      showFeedback('error', 'A mensagem não pode estar vazia.');
      textarea.focus();
      return;
    }

    if (!activeClient) {
      showFeedback('error', 'Nenhum cliente selecionado.');
      return;
    }

    startSend(message);
  });

  function startSend(message) {
    btnSend.disabled        = true;
    btnText.textContent     = 'Enviando…';
    spinner.style.display   = 'block';
    btnSend.querySelector('svg').style.display = 'none';
    hideFeedback();

    const payload = {
      recipient_email: activeClient.email,
      subject: "Mensagem Manual",
      body: message,
    };

    API.request(`/api/customers/${activeClient.id}/send-manual-message`, {
      method: "POST",
      body: JSON.stringify(payload),
    })
      .then((response) => onSendSuccess(response))
      .catch((err) => onSendError(err));
  }

  function onSendSuccess(response) {
    resetSendBtn();
    showFeedback(
      'success',
      response?.message || `Mensagem colocada na fila para ${activeClient.name} (${activeClient.email}).`
    );
    textarea.value = '';
    updateCharCount();
  }

  function onSendError(err) {
    resetSendBtn();
    showFeedback('error', 'Ocorreu um erro ao enviar a mensagem. Tente novamente.');
    console.error('[Mensagem] Send error:', err);
  }

  function resetSendBtn() {
    btnSend.disabled = false;
    btnText.textContent = 'Enviar Mensagem';
    spinner.style.display = 'none';
    btnSend.querySelector('svg').style.display = '';
  }

})();
