const TEMPLATE_EVENTS = [
  {
    code: "lembrete_7_dias",
    title: "1 semana antes",
    description: "Enviado exatamente 7 dias antes do vencimento.",
  },
  {
    code: "vencimento_hoje",
    title: "Dia do vencimento",
    description: "Enviado no dia em que a fatura vence.",
  },
  {
    code: "vencido",
    title: "Fatura vencida",
    description: "Enviado uma vez depois que a cobranca venceu.",
  },
];

const DEFAULT_PLACEHOLDERS = [
  { key: "customer_name", label: "Nome do cliente" },
  { key: "invoice_id", label: "ID da fatura" },
  { key: "issue_date", label: "Data de lancamento" },
  { key: "due_date", label: "Data de vencimento" },
  { key: "amount_total", label: "Valor original" },
  { key: "balance_amount", label: "Saldo atual" },
  { key: "days_overdue", label: "Dias em atraso" },
  { key: "customer_email", label: "E-mail do cliente" },
];

async function initDashboard() {
  const loginSection = $("#login-section");
  const appSection = $("#app-section");
  const loginForm = $("#login-form");
  const totalClientsEl = $("#totalClients");
  const messagesList = $("#messagesList");
  const messageForm = $("#messageForm");
  const messagesCount = $("#messagesCount");
  const eventTabs = $("#templateEventTabs");
  const placeholderGrid = $("#templatePlaceholderGrid");
  const eventCodeInput = $("#templateEventCode");
  const subjectInput = $("#messageTitle");
  const bodyInput = $("#messageContent");

  let templatesByEvent = {};
  let activeEventCode = TEMPLATE_EVENTS[0].code;
  let lastFocusedTemplateField = bodyInput;

  async function loadDashboardData() {
    if (loginSection) loginSection.style.display = "none";
    if (appSection) appSection.style.display = "block";
    checkAuth();

    try {
      const clientsData = await API.request("/api/clients?page=1&page_size=1");
      totalClientsEl.textContent = clientsData.total || 0;
    } catch {
      totalClientsEl.textContent = "-";
    }

    await loadTemplates();
  }

  function renderEventTabs() {
    if (!eventTabs) return;

    eventTabs.innerHTML = TEMPLATE_EVENTS.map((event) => {
      const isActive = event.code === activeEventCode;
      return `
        <button type="button" class="template-event-tab ${isActive ? "is-active" : ""}" data-event-code="${event.code}">
          <strong>${escapeHtml(event.title)}</strong>
          <span>${escapeHtml(event.description)}</span>
        </button>
      `;
    }).join("");

    eventTabs.querySelectorAll("[data-event-code]").forEach((button) => {
      button.addEventListener("click", () => {
        saveCurrentFormInMemory();
        activeEventCode = button.dataset.eventCode;
        fillFormFromActiveTemplate();
        renderEventTabs();
      });
    });
  }

  function renderPlaceholders(placeholders = DEFAULT_PLACEHOLDERS) {
    if (!placeholderGrid) return;

    placeholderGrid.innerHTML = placeholders.map((placeholder) => `
      <button type="button" class="template-placeholder-chip" data-placeholder="${placeholder.key}">
        <span>${escapeHtml(placeholder.label)}</span>
        <code>{{${escapeHtml(placeholder.key)}}}</code>
      </button>
    `).join("");

    placeholderGrid.querySelectorAll("[data-placeholder]").forEach((button) => {
      button.addEventListener("click", () => insertPlaceholder(button.dataset.placeholder));
    });
  }

  function fillFormFromActiveTemplate() {
    const template = templatesByEvent[activeEventCode] || {};
    if (eventCodeInput) eventCodeInput.value = activeEventCode;
    if (subjectInput) subjectInput.value = template.subject || "";
    if (bodyInput) bodyInput.value = template.body || "";
  }

  function saveCurrentFormInMemory() {
    if (!activeEventCode) return;

    templatesByEvent[activeEventCode] = {
      ...(templatesByEvent[activeEventCode] || {}),
      event_code: activeEventCode,
      subject: subjectInput?.value || "",
      body: bodyInput?.value || "",
      is_active: true,
    };
  }

  function renderTemplatesSummary() {
    const templates = TEMPLATE_EVENTS.map((event) => ({
      ...event,
      ...(templatesByEvent[event.code] || {}),
    }));

    messagesCount.textContent = `${templates.length} eventos`;
    messagesList.innerHTML = templates.map((tpl, index) => `
      <div class="message-item">
        <div class="message-number">${index + 1}</div>
        <div class="message-content">
          <div class="message-title">${escapeHtml(tpl.title)}: ${escapeHtml(tpl.subject || "Sem assunto")}</div>
          <div class="message-text">${escapeHtml(tpl.body || "Mensagem ainda nao configurada.")}</div>
        </div>
        <div class="message-actions">
          <button class="btn-icon" type="button" aria-label="Editar ${escapeHtml(tpl.title)}" data-edit-template="${tpl.code}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
            </svg>
          </button>
        </div>
      </div>
    `).join("");

    messagesList.querySelectorAll("[data-edit-template]").forEach((button) => {
      button.addEventListener("click", () => {
        saveCurrentFormInMemory();
        activeEventCode = button.dataset.editTemplate;
        fillFormFromActiveTemplate();
        renderEventTabs();
        subjectInput?.focus();
      });
    });
  }

  async function loadTemplates() {
    try {
      const response = await API.request("/api/notification-templates");
      templatesByEvent = {};
      (response.templates || []).forEach((template) => {
        templatesByEvent[template.event_code] = template;
      });
      renderEventTabs();
      renderPlaceholders(response.placeholders || DEFAULT_PLACEHOLDERS);
      fillFormFromActiveTemplate();
      renderTemplatesSummary();
    } catch (error) {
      AppUI?.notify({
        type: "error",
        title: "Erro ao carregar templates",
        message: error.message || "Nao foi possivel carregar os templates automaticos.",
      });
    }
  }

  function insertPlaceholder(key) {
    const field = lastFocusedTemplateField || bodyInput || subjectInput;
    if (!field) return;

    const token = `{{${key}}}`;
    const start = field.selectionStart ?? field.value.length;
    const end = field.selectionEnd ?? field.value.length;
    const before = field.value.slice(0, start);
    const after = field.value.slice(end);
    const needsLeadingSpace = before.length > 0 && !/\s$/.test(before);
    const needsTrailingSpace = after.length > 0 && !/^\s/.test(after);
    const insert = `${needsLeadingSpace ? " " : ""}${token}${needsTrailingSpace ? " " : ""}`;

    field.value = before + insert + after;
    const cursor = before.length + insert.length;
    field.focus();
    field.setSelectionRange(cursor, cursor);
    saveCurrentFormInMemory();
  }

  const loginErrorEl = $("#login-error");
  const loginSubmitBtn = $("#login-submit-btn");

  loginForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const email = $("#login-email").value.trim();
    const password = $("#login-password").value;

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
        loginErrorEl.textContent = error.message || "E-mail ou senha invalidos. Tente novamente.";
        loginErrorEl.style.display = "block";
      }
      if (loginSubmitBtn) loginSubmitBtn.textContent = "Entrar";
    }
  });

  [subjectInput, bodyInput].forEach((field) => {
    field?.addEventListener("focus", () => {
      lastFocusedTemplateField = field;
    });
    field?.addEventListener("input", saveCurrentFormInMemory);
  });

  messageForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    saveCurrentFormInMemory();

    const payload = {
      templates: TEMPLATE_EVENTS.map((event) => ({
        event_code: event.code,
        subject: (templatesByEvent[event.code]?.subject || "").trim(),
        body: (templatesByEvent[event.code]?.body || "").trim(),
        is_active: true,
      })),
    };

    try {
      await API.request("/api/notification-templates", {
        method: "PUT",
        body: JSON.stringify(payload),
      });
      AppUI?.notify({
        type: "success",
        title: "Templates salvos",
        message: "Os 3 e-mails automaticos foram atualizados.",
      });
      await loadTemplates();
    } catch (error) {
      AppUI?.notify({
        type: "error",
        title: "Erro ao salvar",
        message: error.message || "Nao foi possivel salvar os templates.",
      });
    }
  });

  if (API.getToken()) {
    loadDashboardData();
  } else {
    if (loginSection) loginSection.style.display = "block";
    if (appSection) appSection.style.display = "none";
  }
}

document.addEventListener("DOMContentLoaded", initDashboard);
