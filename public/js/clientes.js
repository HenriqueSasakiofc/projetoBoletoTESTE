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
      tableBody.innerHTML = `<tr><td colspan="5" style="text-align:center">Erro ao carregar clientes: ${error.message}</td></tr>`;
    }
  }

  function renderClients(data) {
    const items = data.items || [];
    window.clients = items.map(i => ({
        id: i.id,
        name: i.full_name,
        email: i.email_billing,
        phone: i.phone || '-',
        status: i.overdue_receivables_total > 0 ? 'inactive' : 'active' // Simplified status for new design toggle
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
          <td>
            <span class="status-badge ${client.status}">${getStatusLabel(client.status)}</span>
          </td>
          <td>
            <div class="table-actions">
              <button class="btn-icon" aria-label="Enviar mensagem ao cliente" onclick="openMsgPanel(${client.id})">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
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

  searchInput.addEventListener("input", () => {
    loadClients(1);
  });

  loadClients();
}

document.addEventListener("DOMContentLoaded", initClientsPage);
