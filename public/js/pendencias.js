async function initPendenciesPage() {
    const listEl = $("#pendings-list");
    const batchIdInput = $("#pendings-batch-id");
    const loadBtn = $("#load-pendings-btn");
    const messageEl = $("#pendings-message");

    const params = new URLSearchParams(window.location.search);
    const batchId = params.get("batch_id");
    if (batchId) {
        batchIdInput.value = batchId;
        loadPendencies(batchId);
    }

    loadBtn?.addEventListener("click", () => {
        const id = batchIdInput.value.trim();
        if (id) loadPendencies(id);
    });

    async function loadPendencies(id) {
        listEl.innerHTML = '<div class="empty-state">Carregando pendências...</div>';
        try {
            const data = await API.request(`/api/upload-batches/${id}/pendings`);
            renderPendencies(data);
        } catch (error) {
            listEl.innerHTML = `<div class="empty-state">Erro: ${error.message}</div>`;
        }
    }

    function renderPendencies(items) {
        if (!items || items.length === 0) {
            listEl.innerHTML = '<div class="empty-state">Nenhuma pendência encontrada para este lote.</div>';
            return;
        }

        listEl.innerHTML = items.map(item => `
            <div class="card" style="margin-bottom: 24px;">
                <div class="card-header">
                    <span class="card-title">Pendência #${item.id}</span>
                </div>
                <div class="card-body">
                    <p><strong>Cliente da cobrança:</strong> ${escapeHtml(item.customer_name)}</p>
                    <p><strong>Documento:</strong> ${escapeHtml(item.customer_document_number || '-')}</p>
                    <p><strong>Título:</strong> ${escapeHtml(item.receivable_number || '-')}</p>
                    <p><strong>Valor:</strong> ${item.amount_total}</p>
                    <p><strong>Vencimento:</strong> ${item.due_date}</p>
                    
                    <div class="pending-actions" style="margin-top: 16px; display: flex; gap: 12px; flex-wrap: wrap;">
                        <button class="btn btn-primary btn-sm" onclick="resolvePending(${item.id}, 'create')">Criar Novo Cliente</button>
                        <button class="btn btn-secondary btn-sm" onclick="showLinkOptions(${item.id})">Vincular a Existente</button>
                    </div>
                </div>
            </div>
        `).join('');
    }

    window.resolvePending = async (id, action) => {
        // Implement resolution logic here or call existing API
        alert("Ação '" + action + "' para pendência " + id + " (A ser implementado)");
    };
}

document.addEventListener("DOMContentLoaded", initPendenciesPage);
