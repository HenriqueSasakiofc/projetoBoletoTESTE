async function initClientDetailPage() {
  const messageEl = $("#client-detail-message");
  const params = new URLSearchParams(window.location.search);
  const customerId = params.get("id");

  if (!customerId) {
    messageEl.textContent = "ID do cliente não informado.";
    messageEl.className = "message-box error";
    return;
  }

  async function loadClient() {
    try {
      const data = await API.request(`/api/clients/${customerId}`);
      renderClient(data);
    } catch (error) {
      console.error("Error loading client details:", error);
      messageEl.textContent = "Erro: " + error.message;
      messageEl.className = "message-box error";
    }
  }

  function renderClient(data) {
    $("#client-name").textContent = data.full_name || "-";
    $("#client-code").textContent = data.external_code || "-";
    $("#client-email-billing").textContent = data.email_billing_masked || data.email_billing || "-";
    $("#client-email-financial").textContent = data.email_financial_masked || data.email_financial || "-";
    $("#client-phone").textContent = data.phone_masked || data.phone || "-";
    $("#client-document").textContent = data.document_number_masked || data.document_number || "-";
    $("#client-other-contacts").textContent = data.other_contacts || "-";

    $("#manual-recipient").value = data.email_billing || "";
    renderReceivables(data.receivables || []);
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
          <div class="card" style="margin-bottom: 16px;">
            <div class="card-body">
                <h3>Título ${escapeHtml(item.receivable_number || item.nosso_numero || "#")}</h3>
                <p>Status: ${escapeHtml(item.status || "-")}</p>
                <p>Vencimento: ${escapeHtml(item.due_date_formatted || item.due_date || "-")}</p>
                <p>Valor: R$ ${escapeHtml(item.amount_total_formatted || item.amount_total || "0,00")}</p>
                <button class="btn btn-secondary btn-sm" onclick="queueMessage(${item.id})">Colocar Mensagem Padrão na Fila</button>
            </div>
          </div>
        `
      )
      .join("");
  }

  window.queueMessage = async (receivableId) => {
    try {
      const result = await API.request(`/api/receivables/${receivableId}/queue-standard-message`, {
        method: "POST",
      });
      alert(result.message || "Mensagem colocada na fila!");
    } catch (error) {
      alert("Erro: " + error.message);
    }
  };

  $("#manual-message-form")?.addEventListener("submit", async (e) => {
    e.preventDefault();
    const payload = {
        recipient_email: $("#manual-recipient").value.trim(),
        subject: $("#manual-subject").value.trim(),
        body: $("#manual-body").value.trim(),
    };

    try {
        const result = await API.request(`/api/customers/${customerId}/send-manual-message`, {
            method: "POST",
            body: JSON.stringify(payload)
        });
        alert(result.message || "Mensagem manual colocada na fila com sucesso!");
        $("#manual-message-form").reset();
    } catch (error) {
        alert("Erro: " + error.message);
    }
  });

  loadClient();
}

document.addEventListener("DOMContentLoaded", initClientDetailPage);
