async function initDashboard() {
  const loginSection = $("#login-section");
  const appSection = $("#app-section");
  const loginForm = $("#login-form");
  const totalClientsEl = $("#totalClients");
  const messagesList = $("#messagesList");
  const messageForm = $("#messageForm");
  const messagesCount = $("#messagesCount");


  async function loadDashboardData() {
    if (loginSection) loginSection.style.display = "none";
    if (appSection) appSection.style.display = "block";
    checkAuth(); // Update header

    // Load Total Clients
    try {
      const clientsData = await API.request("/api/clients?page=1&page_size=1");
      totalClientsEl.textContent = clientsData.total || 0;
    } catch (error) {
      totalClientsEl.textContent = "-";
    }

    // Load Message Template
    loadTemplate();
  }

  async function loadTemplate() {
    try {
      const template = await API.request("/api/message-template");
      renderTemplates([template]);
    } catch (error) {
      console.error("Error loading template:", error);
    }
  }

  function renderTemplates(templates) {
    if (!templates || templates.length === 0 || !templates[0].subject) {
      messagesList.innerHTML = '<div class="empty-state">Nenhum template configurado.</div>';
      messagesCount.textContent = "0 mensagens";
      return;
    }

    messagesCount.textContent = `${templates.length} ${templates.length === 1 ? 'mensagem' : 'mensagens'}`;
    messagesList.innerHTML = templates
      .map(
        (tpl, index) => `
        <div class="message-item">
            <div class="message-number">${index + 1}</div>
            <div class="message-content">
                <div class="message-title">${escapeHtml(tpl.subject)}</div>
                <div class="message-text">${escapeHtml(tpl.body)}</div>
            </div>
            <div class="message-actions">
                <button class="btn-icon" aria-label="Editar" onclick="editTemplate()">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                    </svg>
                </button>
            </div>
        </div>
    `
      )
      .join("");
  }

  const loginErrorEl = $("#login-error");
  const loginSubmitBtn = $("#login-submit-btn");

  loginForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const email = $("#login-email").value.trim();
    const password = $("#login-password").value;

    // Show loading state
    if (loginSubmitBtn) loginSubmitBtn.textContent = "Entrando...";
    if (loginErrorEl) loginErrorEl.style.display = "none";

    try {
      const result = await API.request(
        "/api/auth/login",
        {
          method: "POST",
          body: JSON.stringify({ email, password }),
        },
        { auth: false }
      );

      API.saveSession(result.access_token, result.user);
      loadDashboardData();
    } catch (error) {
      if (loginErrorEl) {
        loginErrorEl.textContent = error.message || "E-mail ou senha inválidos. Tente novamente.";
        loginErrorEl.style.display = "block";
      }
      if (loginSubmitBtn) loginSubmitBtn.textContent = "Entrar";
    }
  });

  messageForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    const subject = $("#messageTitle").value.trim();
    const body = $("#messageContent").value.trim();

    try {
      await API.request("/api/message-template", {
        method: "PUT",
        body: JSON.stringify({ subject, body }),
      });
      loadTemplate();
      messageForm.reset();
    } catch (error) {
      alert("Erro ao salvar template: " + error.message);
    }
  });

  window.editTemplate = () => {
    API.request("/api/message-template").then((template) => {
      $("#messageTitle").value = template.subject || "";
      $("#messageContent").value = template.body || "";
      $("#messageTitle").focus();
    });
  };

  if (API.getToken()) {
    loadDashboardData();
  } else {
    if (loginSection) loginSection.style.display = "block";
    if (appSection) appSection.style.display = "none";
  }
}

document.addEventListener("DOMContentLoaded", initDashboard);
