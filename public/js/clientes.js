async function initClientsPage() {
  const tableBody = document.getElementById("clientsTableBody");
  const clientsCountEl = document.getElementById("clientsCount");
  const emptyState = document.getElementById("emptyState");
  const searchInput = document.getElementById("searchInput");
  const paginationContainer = document.createElement("div");
  paginationContainer.className = "pagination-wrapper";
  $("#clientsTableBody").parentElement.insertAdjacentElement("afterend", paginationContainer);

  let currentPage = 1;
  const pageSize = 10;

  function getInitials(name) {
    if (!name) return "??";
    return name
      .split(" ")
      .map((n) => n[0])
      .slice(0, 2)
      .join("")
      .toUpperCase();
  }

  function getStatusLabel(status) {
    // API might return different status strings
    const labels = {
      pago: "Pago",
      em_aberto: "Em Aberto",
      vencendo: "Vencendo",
      inadimplente: "Inadimplente",
      active: "Ativo",
      inactive: "Inativo"
    };
    return labels[status.toLowerCase()] || status;
  }

  function formatCurrency(value) {
    return new Intl.NumberFormat("pt-BR", {
      style: "currency",
      currency: "BRL",
    }).format(Number(value || 0));
  }

  async function loadClients(page = 1) {
    currentPage = page;
    const searchTerm = searchInput.value.trim();
    
    const params = new URLSearchParams({
      page: String(currentPage),
      page_size: String(pageSize),
    });
    if (searchTerm) params.set("search", searchTerm);

    try {
      const data = await API.request(`/api/clients?${params.toString()}`);
      renderClients(data);
    } catch (error) {
      console.error("Error loading clients:", error);
      tableBody.innerHTML = `<tr><td colspan="6" style="text-align:center">Erro ao carregar clientes: ${error.message}</td></tr>`;
    }
  }

  function renderClients(data) {
    const items = data.items || [];
    window.clients = items.map(i => ({
        id: i.id,
        name: i.full_name,
        email: i.email_billing,
        phone: i.phone || '-',
        debtAmount: i.debt_amount_total || 0,
        debtLabel: i.debt_amount_total_formatted ? `R$ ${i.debt_amount_total_formatted}` : formatCurrency(i.debt_amount_total || 0),
        status: i.is_active === false ? 'inactive' : 'active'
    }));

    if (items.length === 0) {
      tableBody.innerHTML = "";
      emptyState.style.display = "block";
      clientsCountEl.textContent = "0 clientes";
      renderPagination(0);
      return;
    }

    emptyState.style.display = "none";
    clientsCountEl.textContent = `${data.total} ${data.total === 1 ? "cliente" : "clientes"}`;

    tableBody.innerHTML = window.clients
      .map(
        (client) => `
        <tr data-id="${client.id}">
          <td>
            <div class="client-info">
              <div class="client-avatar">${getInitials(client.name)}</div>
              <span class="client-name">${escapeHtml(client.name)}</span>
            </div>
          </td>
          <td class="client-email">${escapeHtml(client.email)}</td>
          <td>${escapeHtml(client.phone)}</td>
          <td><strong>${escapeHtml(client.debtLabel)}</strong></td>
          <td>
            <span class="status-badge ${client.status}">${getStatusLabel(client.status)}</span>
          </td>
          <td>
            <div class="table-actions">
              <button class="btn-icon" aria-label="Ver e editar cliente" onclick="viewClient(${client.id})">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                </svg>
              </button>
              <button class="btn-icon" aria-label="Enviar mensagem ao cliente" onclick="openMsgPanel(${client.id})">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25H4.5a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5H4.5A2.25 2.25 0 002.25 6.75m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.909A2.25 2.25 0 012.25 6.993V6.75" />
                </svg>
              </button>
              <button class="btn-icon delete" aria-label="Excluir cliente" onclick="deleteClient(${client.id})">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 7.5h12m-1.5 0-.664 9.966A2.25 2.25 0 0113.59 19.5h-3.18a2.25 2.25 0 01-2.246-2.034L7.5 7.5m3-3h3a1.5 1.5 0 011.5 1.5v1.5h-6V6a1.5 1.5 0 011.5-1.5z" />
                </svg>
              </button>
            </div>
          </td>
        </tr>
      `
      )
      .join("");

    renderPagination(data.total);
  }

  function renderPagination(total) {
    const totalPages = Math.ceil(total / pageSize);
    if (totalPages <= 1) {
        paginationContainer.innerHTML = "";
        return;
    }

    let html = `<div class="pagination">`;
    html += `<button class="btn btn-secondary" ${currentPage === 1 ? 'disabled' : ''} onclick="changePage(${currentPage - 1})">Anterior</button>`;
    html += `<span>Página ${currentPage} de ${totalPages}</span>`;
    html += `<button class="btn btn-secondary" ${currentPage === totalPages ? 'disabled' : ''} onclick="changePage(${currentPage + 1})">Próxima</button>`;
    html += `</div>`;
    paginationContainer.innerHTML = html;
  }

  window.changePage = (page) => {
    loadClients(page);
  };

  window.viewClient = (id) => {
    window.location.href = `/cliente?id=${id}`;
  };

  window.deleteClient = async (id) => {
    const client = (window.clients || []).find((item) => item.id === id);
    const clientName = client?.name || "este cliente";
    const confirmed = window.confirm(`Deseja realmente excluir ${clientName}? Essa ação remove também as cobranças vinculadas.`);

    if (!confirmed) return;

    try {
      const result = await API.request(`/api/clients/${id}`, {
        method: "DELETE",
      });
      alert(result.message || "Cliente excluído com sucesso.");
      loadClients(currentPage);
    } catch (error) {
      alert("Erro ao excluir cliente: " + error.message);
    }
  };

  searchInput.addEventListener("input", () => {
    loadClients(1);
  });

  loadClients();
}

document.addEventListener("DOMContentLoaded", initClientsPage);
