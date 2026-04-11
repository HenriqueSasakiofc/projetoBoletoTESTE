const outboxState = {
  items: [],
  loading: false,
};

function outboxStatusClass(status) {
  if (status === "sent") return "outbox-status outbox-status--sent";
  if (status === "error") return "outbox-status outbox-status--error";
  return "outbox-status outbox-status--pending";
}

function outboxErrorPreview(message) {
  if (!message) return "-";
  return message.length > 90 ? `${message.slice(0, 90)}...` : message;
}

function outboxQueryParams() {
  const params = new URLSearchParams();
  params.set("status", $("#outboxStatusFilter")?.value || "all");
  params.set("kind", $("#outboxKindFilter")?.value || "all");
  params.set("limit", $("#outboxLimitFilter")?.value || "100");
  return params.toString();
}

function renderOutboxSummary(summary = {}) {
  $("#outboxTotal").textContent = summary.total ?? 0;
  $("#outboxPending").textContent = summary.pending ?? 0;
  $("#outboxSent").textContent = summary.sent ?? 0;
  $("#outboxError").textContent = summary.error ?? 0;
}

function renderOutboxTable(items = []) {
  const tbody = $("#outboxTableBody");
  const emptyState = $("#outboxEmptyState");
  const count = $("#outboxCount");

  if (!tbody) return;

  count.textContent = `${items.length} ${items.length === 1 ? "item" : "itens"}`;
  emptyState.style.display = items.length ? "none" : "block";

  tbody.innerHTML = items
    .map((item) => {
      const customer = item.customer_name || `Cliente #${item.customer_id || "-"}`;
      const receivable = item.receivable_number || (item.receivable_id ? `#${item.receivable_id}` : "-");
      const error = outboxErrorPreview(item.error_message);

      return `
        <tr>
          <td class="outbox-id">#${item.id}</td>
          <td>${escapeHtml(item.created_at_formatted || "-")}</td>
          <td><span class="outbox-pill">${escapeHtml(item.message_kind_label)}</span></td>
          <td>${escapeHtml(item.notification_event_label || "-")}</td>
          <td>${escapeHtml(customer)}</td>
          <td>${escapeHtml(receivable)}</td>
          <td class="outbox-email">${escapeHtml(item.recipient_email)}</td>
          <td class="outbox-subject">${escapeHtml(item.subject)}</td>
          <td><span class="${outboxStatusClass(item.status)}">${escapeHtml(item.status_label)}</span></td>
          <td class="outbox-error" title="${escapeHtml(item.error_message || "")}">${escapeHtml(error)}</td>
        </tr>
      `;
    })
    .join("");
}

function setOutboxLoading(isLoading) {
  outboxState.loading = isLoading;
  const refreshBtn = $("#outboxRefreshBtn");
  const scheduleBtn = $("#outboxScheduleBtn");
  const dispatchBtn = $("#outboxDispatchBtn");

  if (refreshBtn) {
    refreshBtn.disabled = isLoading;
    refreshBtn.textContent = isLoading ? "Atualizando..." : "Atualizar";
  }

  if (dispatchBtn) {
    dispatchBtn.disabled = isLoading;
  }

  if (scheduleBtn) {
    scheduleBtn.disabled = isLoading;
    scheduleBtn.textContent = isLoading ? "Processando..." : "Gerar cobrancas de hoje";
  }
}

async function loadOutbox() {
  if (!checkAuth()) return;

  setOutboxLoading(true);
  try {
    const data = await API.request(`/api/outbox?${outboxQueryParams()}`);
    outboxState.items = data.items || [];
    renderOutboxSummary(data.summary || {});
    renderOutboxTable(outboxState.items);
  } catch (error) {
    AppUI?.notify({
      type: "error",
      title: "Erro ao carregar outbox",
      message: error.message || "Nao foi possivel buscar os dados da fila.",
    });
  } finally {
    setOutboxLoading(false);
  }
}

async function dispatchOutbox() {
  if (!checkAuth() || outboxState.loading) return;

  const kind = $("#outboxKindFilter")?.value || "all";
  const confirmed = await AppUI.confirm({
    title: "Disparar e-mails?",
    message: "O sistema vai tentar enviar mensagens pendentes e com erro da outbox conforme o filtro de tipo atual.",
    confirmText: "Disparar agora",
    cancelText: "Cancelar",
  });

  if (!confirmed) return;

  setOutboxLoading(true);
  try {
    const result = await API.request("/api/outbox/dispatch", {
      method: "POST",
      body: JSON.stringify({
        limit: 100,
        kind,
      }),
    });

    AppUI.notify({
      type: result.errors > 0 ? "warning" : "success",
      title: "Processamento concluido",
      message: `${result.sent || 0} enviados, ${result.errors || 0} com erro.`,
    });

    await loadOutbox();
  } catch (error) {
    AppUI?.notify({
      type: "error",
      title: "Erro no disparo",
      message: error.message || "Nao foi possivel processar a fila.",
    });
  } finally {
    setOutboxLoading(false);
  }
}

async function scheduleAutomaticOutbox() {
  if (!checkAuth() || outboxState.loading) return;

  const confirmed = await AppUI.confirm({
    title: "Gerar cobrancas automaticas?",
    message: "O sistema vai avaliar os titulos em aberto pela data de vencimento e colocar na outbox os e-mails automaticos aplicaveis para hoje.",
    confirmText: "Gerar agora",
    cancelText: "Cancelar",
  });

  if (!confirmed) return;

  setOutboxLoading(true);
  try {
    const result = await API.request("/api/outbox/schedule-automatic", {
      method: "POST",
      body: JSON.stringify({}),
    });

    const summary = result.summary || {};
    AppUI.notify({
      type: summary.scheduled > 0 ? "success" : "info",
      title: "Cobrancas avaliadas",
      message: `${summary.scheduled || 0} mensagens automaticas foram adicionadas na outbox. ${summary.skipped_duplicate || 0} ja existiam.`,
    });

    await loadOutbox();
  } catch (error) {
    AppUI?.notify({
      type: "error",
      title: "Erro ao gerar cobrancas",
      message: error.message || "Nao foi possivel avaliar as cobrancas automaticas.",
    });
  } finally {
    setOutboxLoading(false);
  }
}

document.addEventListener("DOMContentLoaded", () => {
  $("#outboxRefreshBtn")?.addEventListener("click", loadOutbox);
  $("#outboxScheduleBtn")?.addEventListener("click", scheduleAutomaticOutbox);
  $("#outboxDispatchBtn")?.addEventListener("click", dispatchOutbox);
  $("#outboxStatusFilter")?.addEventListener("change", loadOutbox);
  $("#outboxKindFilter")?.addEventListener("change", loadOutbox);
  $("#outboxLimitFilter")?.addEventListener("change", loadOutbox);

  loadOutbox();
});
