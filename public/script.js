const API = {
  tokenKey: "projeto_boleto_token",
  userKey: "projeto_boleto_user",

  getToken() {
    return localStorage.getItem(this.tokenKey);
  },

  getUser() {
    try {
      return JSON.parse(localStorage.getItem(this.userKey) || "null");
    } catch {
      return null;
    }
  },

  saveSession(token, user) {
    localStorage.setItem(this.tokenKey, token);
    localStorage.setItem(this.userKey, JSON.stringify(user));
  },

  clearSession() {
    localStorage.removeItem(this.tokenKey);
    localStorage.removeItem(this.userKey);
  },

  async request(path, options = {}, config = {}) {
    const headers = new Headers(options.headers || {});
    const auth = config.auth !== false;
    const isFormData = config.isFormData === true;

    if (auth && this.getToken()) {
      headers.set("Authorization", `Bearer ${this.getToken()}`);
    }

    if (!isFormData && !headers.has("Content-Type") && options.body && typeof options.body === "string") {
      headers.set("Content-Type", "application/json");
    }

    const response = await fetch(path, {
      ...options,
      headers,
    });

    const contentType = response.headers.get("content-type") || "";
    const isJson = contentType.includes("application/json");
    const data = isJson ? await response.json() : await response.text();

    if (!response.ok) {
      const detail =
        (isJson && (data.detail || data.message)) ||
        (typeof data === "string" && data) ||
        "Erro na requisição.";
      throw new Error(detail);
    }

    return data;
  },
};

function $(selector) {
  return document.querySelector(selector);
}

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function showMessage(target, text, type = "info") {
  const el = typeof target === "string" ? $(target) : target;
  if (!el) return;
  el.className = `message-box ${type}`;
  el.textContent = text;
}

function clearMessage(target) {
  const el = typeof target === "string" ? $(target) : target;
  if (!el) return;
  el.className = "message-box";
  el.textContent = "";
}

function formatMoney(value) {
  if (value === null || value === undefined || value === "") return "-";
  const number = Number(value);
  if (Number.isNaN(number)) return String(value);
  return number.toLocaleString("pt-BR", {
    style: "currency",
    currency: "BRL",
  });
}

function formatDate(value) {
  if (!value) return "-";
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) return value;
  return parsed.toLocaleDateString("pt-BR");
}

function requireAuth() {
  if (!API.getToken()) {
    window.location.href = "/";
    return false;
  }
  return true;
}

function getPageName() {
  return document.body?.dataset?.page || "";
}

function setUserBadges() {
  document.querySelectorAll("[data-user-name]").forEach((el) => {
    const user = API.getUser();
    el.textContent = user ? `${user.full_name} (${user.role})` : "Não autenticado";
  });
}

async function fetchMe() {
  const me = await API.request("/auth/me");
  const token = API.getToken();
  API.saveSession(token, me);
  setUserBadges();
  return me;
}

function logout() {
  API.clearSession();
  window.location.href = "/";
}

async function initDashboard() {
  const loginSection = $("#login-section");
  const appSection = $("#app-section");
  const loginForm = $("#login-form");
  const logoutBtn = $("#logout-btn");
  const templateForm = $("#template-form");
  const previewBtn = $("#preview-template-btn");
  const previewTarget = $("#template-preview");
  const dashboardMessage = $("#dashboard-message");
  const outboxList = $("#outbox-list");
  const totalClients = $("#total-clients");
  const dispatchBtn = $("#dispatch-outbox-btn");

  async function loadAuthenticatedDashboard() {
    loginSection.hidden = true;
    appSection.hidden = false;
    setUserBadges();

    try {
      const clientsData = await API.request("/api/clients?page=1&page_size=1");
      totalClients.textContent = String(clientsData.total ?? 0);
    } catch {
      totalClients.textContent = "-";
    }

    try {
      const template = await API.request("/api/message-template");
      $("#template-subject").value = template.subject || "";
      $("#template-body").value = template.body || "";
      $("#allowed-placeholders").innerHTML = (template.allowed_placeholders || [])
        .map((item) => `<span class="placeholder-chip">{{${escapeHtml(item)}}}</span>`)
        .join("");
    } catch (error) {
      showMessage(dashboardMessage, error.message, "error");
    }

    try {
      const outbox = await API.request("/api/outbox");
      renderOutbox(outbox);
    } catch (error) {
      outboxList.innerHTML = `<li class="empty-state">${escapeHtml(error.message)}</li>`;
    }
  }

  function renderOutbox(items) {
    if (!Array.isArray(items) || items.length === 0) {
      outboxList.innerHTML = `<li class="empty-state">Nenhuma mensagem na fila.</li>`;
      return;
    }

    outboxList.innerHTML = items
      .slice(0, 10)
      .map(
        (item) => `
          <li class="list-card">
            <div><strong>${escapeHtml(item.subject)}</strong></div>
            <div>Para: ${escapeHtml(item.recipient_email)}</div>
            <div>Status: ${escapeHtml(item.status)}</div>
            <div>Criado em: ${formatDate(item.created_at)}</div>
          </li>
        `
      )
      .join("");
  }

  loginForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    clearMessage(dashboardMessage);

    const email = $("#login-email").value.trim();
    const password = $("#login-password").value;

    try {
      const result = await API.request(
        "/auth/login",
        {
          method: "POST",
          body: JSON.stringify({ email, password }),
        },
        { auth: false }
      );

      API.saveSession(result.access_token, result.user);
      await loadAuthenticatedDashboard();
      showMessage(dashboardMessage, "Login realizado com sucesso.", "success");
    } catch (error) {
      showMessage(dashboardMessage, error.message, "error");
    }
  });

  logoutBtn?.addEventListener("click", logout);

  templateForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    clearMessage(dashboardMessage);

    const subject = $("#template-subject").value.trim();
    const body = $("#template-body").value.trim();

    try {
      await API.request("/api/message-template", {
        method: "PUT",
        body: JSON.stringify({ subject, body }),
      });
      showMessage(dashboardMessage, "Template salvo com sucesso.", "success");
    } catch (error) {
      showMessage(dashboardMessage, error.message, "error");
    }
  });

  previewBtn?.addEventListener("click", async () => {
    clearMessage(dashboardMessage);

    const subject = $("#template-subject").value.trim();
    const body = $("#template-body").value.trim();
    const customerId = $("#preview-customer-id").value.trim();
    const receivableId = $("#preview-receivable-id").value.trim();

    const params = new URLSearchParams();
    if (customerId) params.set("customer_id", customerId);
    if (receivableId) params.set("receivable_id", receivableId);

    try {
      const result = await API.request(`/api/message-template/preview?${params.toString()}`, {
        method: "POST",
        body: JSON.stringify({ subject, body }),
      });

      previewTarget.innerHTML = `
        <div class="preview-block">
          <h4>Assunto</h4>
          <p>${escapeHtml(result.subject)}</p>
          <h4>Corpo</h4>
          <pre>${escapeHtml(result.body)}</pre>
        </div>
      `;
    } catch (error) {
      showMessage(dashboardMessage, error.message, "error");
    }
  });

  dispatchBtn?.addEventListener("click", async () => {
    clearMessage(dashboardMessage);
    try {
      const result = await API.request("/api/outbox/dispatch?limit=20", {
        method: "POST",
      });
      showMessage(
        dashboardMessage,
        `Dispatch concluído. Enviadas: ${result.sent}. Erros: ${result.errors}.`,
        "success"
      );
      const outbox = await API.request("/api/outbox");
      renderOutbox(outbox);
    } catch (error) {
      showMessage(dashboardMessage, error.message, "error");
    }
  });

  if (API.getToken()) {
    try {
      await fetchMe();
      await loadAuthenticatedDashboard();
    } catch {
      API.clearSession();
      loginSection.hidden = false;
      appSection.hidden = true;
    }
  } else {
    loginSection.hidden = false;
    appSection.hidden = true;
  }
}

async function initRegisterPage() {
  const messageEl = $("#register-message");
  const form = $("#register-form");

  if (API.getToken()) {
    window.location.href = "/";
    return;
  }

  form?.addEventListener("submit", async (event) => {
    event.preventDefault();
    clearMessage(messageEl);

    const payload = {
      company_name: $("#register-company-name").value.trim(),
      admin_full_name: $("#register-admin-name").value.trim(),
      admin_email: $("#register-admin-email").value.trim(),
      admin_password: $("#register-admin-password").value,
      admin_password_confirm: $("#register-admin-password-confirm").value,
      terms_accepted: $("#register-terms").checked,
      website: $("#register-website").value.trim(),
    };

    try {
      const result = await API.request(
        "/auth/register-company",
        {
          method: "POST",
          body: JSON.stringify(payload),
        },
        { auth: false }
      );

      API.saveSession(result.access_token, result.user);
      showMessage(messageEl, "Conta criada com sucesso. Redirecionando...", "success");

      setTimeout(() => {
        window.location.href = "/";
      }, 700);
    } catch (error) {
      showMessage(messageEl, error.message, "error");
    }
  });
}

async function initClientsPage() {
  if (!requireAuth()) return;

  const messageEl = $("#clients-message");
  const listEl = $("#clients-list");
  const paginationEl = $("#clients-pagination");
  const form = $("#clients-filter-form");
  const pageInfo = {
    page: 1,
    pageSize: 20,
  };

  async function loadClients() {
    clearMessage(messageEl);
    listEl.innerHTML = `<div class="empty-state">Carregando clientes...</div>`;

    const search = $("#client-search").value.trim();
    const status = $("#client-status").value.trim();

    const params = new URLSearchParams({
      page: String(pageInfo.page),
      page_size: String(pageInfo.pageSize),
    });

    if (search) params.set("search", search);
    if (status) params.set("status_filter", status);

    try {
      const data = await API.request(`/api/clients?${params.toString()}`);
      renderClients(data);
    } catch (error) {
      showMessage(messageEl, error.message, "error");
      listEl.innerHTML = `<div class="empty-state">Não foi possível carregar os clientes.</div>`;
    }
  }

  function renderClients(data) {
    if (!data.items || data.items.length === 0) {
      listEl.innerHTML = `<div class="empty-state">Nenhum cliente encontrado.</div>`;
    } else {
      listEl.innerHTML = data.items
        .map(
          (item) => `
            <article class="list-card">
              <div class="card-row-between">
                <div>
                  <h3>${escapeHtml(item.full_name)}</h3>
                  <p>Código: ${escapeHtml(item.external_code || "-")}</p>
                  <p>E-mail cobrança: ${escapeHtml(item.email_billing_masked || "-")}</p>
                  <p>Documento: ${escapeHtml(item.document_number_masked || "-")}</p>
                </div>
                <div class="stats-column">
                  <span>Total títulos: ${item.receivables_total}</span>
                  <span>Em aberto: ${item.open_receivables_total}</span>
                  <span>Inadimplentes: ${item.overdue_receivables_total}</span>
                  <a class="button-link" href="/cliente?id=${item.id}">Ver perfil</a>
                </div>
              </div>
            </article>
          `
        )
        .join("");
    }

    const totalPages = Math.max(1, Math.ceil((data.total || 0) / (data.page_size || 20)));
    paginationEl.innerHTML = `
      <button type="button" id="prev-page" ${pageInfo.page <= 1 ? "disabled" : ""}>Anterior</button>
      <span>Página ${data.page} de ${totalPages}</span>
      <button type="button" id="next-page" ${pageInfo.page >= totalPages ? "disabled" : ""}>Próxima</button>
    `;

    $("#prev-page")?.addEventListener("click", () => {
      if (pageInfo.page > 1) {
        pageInfo.page -= 1;
        loadClients();
      }
    });

    $("#next-page")?.addEventListener("click", () => {
      if (pageInfo.page < totalPages) {
        pageInfo.page += 1;
        loadClients();
      }
    });
  }

  form?.addEventListener("submit", (event) => {
    event.preventDefault();
    pageInfo.page = 1;
    loadClients();
  });

  $("#logout-btn")?.addEventListener("click", logout);

  await fetchMe();
  await loadClients();
}

async function initClientDetailPage() {
  if (!requireAuth()) return;

  const messageEl = $("#client-detail-message");
  const params = new URLSearchParams(window.location.search);
  const customerId = params.get("id");

  if (!customerId) {
    showMessage(messageEl, "ID do cliente não informado na URL.", "error");
    return;
  }

  async function loadClient() {
    clearMessage(messageEl);

    try {
      const data = await API.request(`/api/clients/${customerId}`);

      $("#client-name").textContent = data.full_name || "-";
      $("#client-code").textContent = data.external_code || "-";
      $("#client-email-billing").textContent = data.email_billing_masked || "-";
      $("#client-email-financial").textContent = data.email_financial_masked || "-";
      $("#client-phone").textContent = data.phone_masked || "-";
      $("#client-document").textContent = data.document_number_masked || "-";
      $("#client-other-contacts").textContent = data.other_contacts || "-";

      $("#manual-recipient").value = data.email_billing || "";
      renderReceivables(data.receivables || []);
      renderHistory(data.history || []);
      renderMessages(data.messages || []);
    } catch (error) {
      showMessage(messageEl, error.message, "error");
    }
  }

  function renderReceivables(items) {
    const target = $("#client-receivables");
    if (!items.length) {
      target.innerHTML = `<div class="empty-state">Nenhuma cobrança encontrada.</div>`;
      return;
    }

    target.innerHTML = items
      .map(
        (item) => `
          <article class="list-card">
            <div class="card-row-between">
              <div>
                <h3>Título ${escapeHtml(item.receivable_number || item.nosso_numero || "#")}</h3>
                <p>Nosso número: ${escapeHtml(item.nosso_numero || "-")}</p>
                <p>Vencimento: ${formatDate(item.due_date)}</p>
                <p>Valor: ${formatMoney(item.amount_total)}</p>
                <p>Saldo: ${formatMoney(item.balance_amount)}</p>
                <p>Status: ${escapeHtml(item.status)}</p>
              </div>
              <div class="stats-column">
                <button type="button" class="queue-standard-btn" data-receivable-id="${item.id}">
                  Colocar mensagem padrão na fila
                </button>
              </div>
            </div>
          </article>
        `
      )
      .join("");

    document.querySelectorAll(".queue-standard-btn").forEach((btn) => {
      btn.addEventListener("click", async () => {
        clearMessage(messageEl);
        try {
          await API.request(`/api/receivables/${btn.dataset.receivableId}/queue-standard-message`, {
            method: "POST",
          });
          showMessage(messageEl, "Mensagem padrão colocada na fila.", "success");
          await loadClient();
        } catch (error) {
          showMessage(messageEl, error.message, "error");
        }
      });
    });
  }

  function renderHistory(items) {
    const target = $("#client-history");
    if (!items.length) {
      target.innerHTML = `<div class="empty-state">Sem histórico de cobranças.</div>`;
      return;
    }

    target.innerHTML = items
      .map(
        (item) => `
          <article class="list-card">
            <p><strong>${escapeHtml(item.old_status || "-")}</strong> → <strong>${escapeHtml(item.new_status || "-")}</strong></p>
            <p>${escapeHtml(item.note || "-")}</p>
            <p>${formatDate(item.created_at)}</p>
          </article>
        `
      )
      .join("");
  }

  function renderMessages(items) {
    const target = $("#client-messages");
    if (!items.length) {
      target.innerHTML = `<div class="empty-state">Sem histórico de mensagens.</div>`;
      return;
    }

    target.innerHTML = items
      .map(
        (item) => `
          <article class="list-card">
            <p><strong>${escapeHtml(item.subject)}</strong></p>
            <p>Para: ${escapeHtml(item.recipient_email)}</p>
            <p>Status: ${escapeHtml(item.status)}</p>
            <p>Criado em: ${formatDate(item.created_at)}</p>
          </article>
        `
      )
      .join("");
  }

  $("#manual-message-form")?.addEventListener("submit", async (event) => {
    event.preventDefault();
    clearMessage(messageEl);

    const payload = {
      recipient_email: $("#manual-recipient").value.trim(),
      subject: $("#manual-subject").value.trim(),
      body: $("#manual-body").value.trim(),
    };

    try {
      await API.request(`/api/customers/${customerId}/send-manual-message`, {
        method: "POST",
        body: JSON.stringify(payload),
      });
      showMessage(messageEl, "Mensagem manual colocada na fila.", "success");
      $("#manual-subject").value = "";
      $("#manual-body").value = "";
      await loadClient();
    } catch (error) {
      showMessage(messageEl, error.message, "error");
    }
  });

  $("#logout-btn")?.addEventListener("click", logout);

  await fetchMe();
  await loadClient();
}

async function initImportPage() {
  if (!requireAuth()) return;

  const messageEl = $("#import-message");
  const batchDetailsEl = $("#batch-details");
  const batchInput = $("#batch-id-input");
  const approveBtn = $("#approve-merge-btn");

  function renderBatch(batch) {
    batchDetailsEl.innerHTML = `
      <article class="list-card">
        <h3>Lote #${batch.id}</h3>
        <p>Status: ${escapeHtml(batch.status)}</p>
        <p>Arquivo clientes: ${escapeHtml(batch.customers_filename)}</p>
        <p>Arquivo contas a receber: ${escapeHtml(batch.receivables_filename)}</p>
        <p>Total clientes em preview: ${batch.preview_customers_total}</p>
        <p>Total cobranças em preview: ${batch.preview_receivables_total}</p>
        <p>Clientes inválidos: ${batch.preview_invalid_customers}</p>
        <p>Cobranças inválidas: ${batch.preview_invalid_receivables}</p>
        <p>Pendências: ${batch.preview_pending_links}</p>
        <p>Clientes mesclados: ${batch.merged_customers_count}</p>
        <p>Cobranças mescladas: ${batch.merged_receivables_count}</p>
        <p><a class="button-link" href="/pendencias?batch_id=${batch.id}">Abrir pendências deste lote</a></p>
      </article>
    `;

    batchInput.value = batch.id;
  }

  async function loadBatch(batchId) {
    clearMessage(messageEl);
    try {
      const batch = await API.request(`/api/upload-batches/${batchId}`);
      renderBatch(batch);
    } catch (error) {
      showMessage(messageEl, error.message, "error");
    }
  }

  $("#upload-form")?.addEventListener("submit", async (event) => {
    event.preventDefault();
    clearMessage(messageEl);

    const customersFile = $("#customers-file").files[0];
    const receivablesFile = $("#receivables-file").files[0];

    if (!customersFile || !receivablesFile) {
      showMessage(messageEl, "Selecione as duas planilhas antes de enviar.", "error");
      return;
    }

    const formData = new FormData();
    formData.append("customers_file", customersFile);
    formData.append("receivables_file", receivablesFile);

    try {
      const batch = await API.request(
        "/api/upload-batches",
        { method: "POST", body: formData },
        { isFormData: true }
      );
      renderBatch(batch);
      showMessage(messageEl, `Lote ${batch.id} enviado com sucesso.`, "success");
      history.replaceState({}, "", `/importacao?batch_id=${batch.id}`);
    } catch (error) {
      showMessage(messageEl, error.message, "error");
    }
  });

  $("#load-batch-btn")?.addEventListener("click", async () => {
    const batchId = batchInput.value.trim();
    if (!batchId) {
      showMessage(messageEl, "Informe um batch_id para consultar.", "error");
      return;
    }
    await loadBatch(batchId);
  });

  approveBtn?.addEventListener("click", async () => {
    clearMessage(messageEl);
    const batchId = batchInput.value.trim();

    if (!batchId) {
      showMessage(messageEl, "Carregue um lote antes de aprovar o merge.", "error");
      return;
    }

    try {
      const batch = await API.request(`/api/upload-batches/${batchId}/approve-merge`, {
        method: "POST",
      });
      renderBatch(batch);
      showMessage(messageEl, "Merge aprovado com sucesso.", "success");
    } catch (error) {
      showMessage(messageEl, error.message, "error");
    }
  });

  $("#logout-btn")?.addEventListener("click", logout);

  await fetchMe();

  const params = new URLSearchParams(window.location.search);
  const batchId = params.get("batch_id");
  if (batchId) {
    await loadBatch(batchId);
  }
}

async function initPendingsPage() {
  if (!requireAuth()) return;

  const messageEl = $("#pendings-message");
  const listEl = $("#pendings-list");
  const batchIdInput = $("#pendings-batch-id");

  async function loadPendings(batchId) {
    clearMessage(messageEl);
    listEl.innerHTML = `<div class="empty-state">Carregando pendências...</div>`;

    try {
      const items = await API.request(`/api/upload-batches/${batchId}/pendings`);
      renderPendings(items);
    } catch (error) {
      showMessage(messageEl, error.message, "error");
      listEl.innerHTML = `<div class="empty-state">Não foi possível carregar as pendências.</div>`;
    }
  }

  function renderPendings(items) {
    if (!items.length) {
      listEl.innerHTML = `<div class="empty-state">Nenhuma pendência encontrada para este lote.</div>`;
      return;
    }

    listEl.innerHTML = items
      .map(
        (item) => `
          <article class="list-card pending-card" id="pending-card-${item.id}">
            <h3>Pendência #${item.id}</h3>
            <p>Status: ${escapeHtml(item.status)}</p>
            <p>Cliente da cobrança: ${escapeHtml(item.customer_name)}</p>
            <p>Documento: ${escapeHtml(item.customer_document_number || "-")}</p>
            <p>Título: ${escapeHtml(item.receivable_number || "-")}</p>
            <p>Nosso número: ${escapeHtml(item.nosso_numero || "-")}</p>
            <p>Vencimento: ${formatDate(item.due_date)}</p>
            <p>Valor: ${formatMoney(item.amount_total)}</p>
            <p>Sugestão de cliente existente: ${escapeHtml(item.suggested_customer_id || "-")}</p>

            <div class="pending-actions">
              <div class="inline-form">
                <input type="text" id="search-customer-${item.id}" placeholder="Buscar cliente existente por nome" />
                <button type="button" class="search-customer-btn" data-pending-id="${item.id}" data-customer-name="${escapeHtml(item.customer_name)}">
                  Buscar
                </button>
              </div>
              <div class="search-results" id="search-results-${item.id}"></div>

              <details class="details-block">
                <summary>Criar cliente manualmente</summary>
                <form class="create-customer-form" data-pending-id="${item.id}">
                  <input type="text" name="full_name" placeholder="Nome do cliente" value="${escapeHtml(item.customer_name)}" required />
                  <input type="text" name="document_number" placeholder="CPF/CNPJ" />
                  <input type="email" name="email_billing" placeholder="E-mail de cobrança" />
                  <input type="email" name="email_financial" placeholder="E-mail financeiro" />
                  <input type="text" name="phone" placeholder="Telefone" />
                  <textarea name="other_contacts" placeholder="Outros contatos"></textarea>
                  <button type="submit">Criar cliente e resolver</button>
                </form>
              </details>
            </div>
          </article>
        `
      )
      .join("");

    bindPendingActions();
  }

  function bindPendingActions() {
    document.querySelectorAll(".search-customer-btn").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const pendingId = btn.dataset.pendingId;
        const fallbackName = btn.dataset.customerName || "";
        const input = $(`#search-customer-${pendingId}`);
        const resultBox = $(`#search-results-${pendingId}`);
        const term = (input.value.trim() || fallbackName).trim();

        if (!term) {
          resultBox.innerHTML = `<div class="empty-state">Digite um nome para buscar.</div>`;
          return;
        }

        resultBox.innerHTML = `<div class="empty-state">Buscando clientes...</div>`;

        try {
          const data = await API.request(`/api/clients?search=${encodeURIComponent(term)}&page=1&page_size=10`);
          if (!data.items.length) {
            resultBox.innerHTML = `<div class="empty-state">Nenhum cliente encontrado.</div>`;
            return;
          }

          resultBox.innerHTML = data.items
            .map(
              (item) => `
                <div class="search-result-row">
                  <span>#${item.id} - ${escapeHtml(item.full_name)}</span>
                  <button type="button" class="link-existing-btn" data-pending-id="${pendingId}" data-customer-id="${item.id}">
                    Vincular
                  </button>
                </div>
              `
            )
            .join("");

          document.querySelectorAll(".link-existing-btn").forEach((linkBtn) => {
            linkBtn.addEventListener("click", async () => {
              clearMessage(messageEl);
              try {
                await API.request(`/api/pendings/${linkBtn.dataset.pendingId}/link-existing`, {
                  method: "POST",
                  body: JSON.stringify({ customer_id: Number(linkBtn.dataset.customerId) }),
                });
                showMessage(messageEl, "Pendência vinculada com sucesso.", "success");
                const batchId = batchIdInput.value.trim();
                if (batchId) await loadPendings(batchId);
              } catch (error) {
                showMessage(messageEl, error.message, "error");
              }
            });
          });
        } catch (error) {
          resultBox.innerHTML = `<div class="empty-state">${escapeHtml(error.message)}</div>`;
        }
      });
    });

    document.querySelectorAll(".create-customer-form").forEach((form) => {
      form.addEventListener("submit", async (event) => {
        event.preventDefault();
        clearMessage(messageEl);

        const pendingId = form.dataset.pendingId;
        const formData = new FormData(form);

        const payload = {
          full_name: String(formData.get("full_name") || "").trim(),
          document_number: String(formData.get("document_number") || "").trim() || null,
          email_billing: String(formData.get("email_billing") || "").trim() || null,
          email_financial: String(formData.get("email_financial") || "").trim() || null,
          phone: String(formData.get("phone") || "").trim() || null,
          other_contacts: String(formData.get("other_contacts") || "").trim() || null,
        };

        try {
          await API.request(`/api/pendings/${pendingId}/create-customer`, {
            method: "POST",
            body: JSON.stringify(payload),
          });
          showMessage(messageEl, "Cliente criado e pendência resolvida.", "success");
          const batchId = batchIdInput.value.trim();
          if (batchId) await loadPendings(batchId);
        } catch (error) {
          showMessage(messageEl, error.message, "error");
        }
      });
    });
  }

  $("#load-pendings-btn")?.addEventListener("click", async () => {
    const batchId = batchIdInput.value.trim();
    if (!batchId) {
      showMessage(messageEl, "Informe o batch_id.", "error");
      return;
    }
    await loadPendings(batchId);
  });

  $("#logout-btn")?.addEventListener("click", logout);

  await fetchMe();

  const params = new URLSearchParams(window.location.search);
  const batchId = params.get("batch_id");
  if (batchId) {
    batchIdInput.value = batchId;
    await loadPendings(batchId);
  }
}

function initCommonNav() {
  setUserBadges();
  document.querySelectorAll("[data-logout]").forEach((btn) => {
    btn.addEventListener("click", logout);
  });
}

document.addEventListener("DOMContentLoaded", async () => {
  initCommonNav();

  const page = getPageName();

  try {
    if (page === "dashboard") {
      await initDashboard();
    } else if (page === "cadastro") {
      await initRegisterPage();
    } else if (page === "clientes") {
      await initClientsPage();
    } else if (page === "cliente") {
      await initClientDetailPage();
    } else if (page === "importacao") {
      await initImportPage();
    } else if (page === "pendencias") {
      await initPendingsPage();
    }
  } catch (error) {
    console.error(error);
  }
});