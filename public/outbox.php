<?php
$pageTitle = "Outbox - Projeto Boleto";
$headerTitle = "Outbox";
$headerSubtitle = "Acompanhe a fila e o historico de envio de e-mails";
$currentPage = "outbox";
$extraCss = ["outbox.css"];
$extraJs = ["api.js", "outbox.js"];
include __DIR__ . '/includes/header.php';
?>

<main class="main-container outbox-page">
  <section class="outbox-hero card">
    <div>
      <span class="outbox-eyebrow">Controle operacional</span>
      <h2 class="outbox-title">Fila de e-mails</h2>
      <p class="outbox-description">
        Visualize mensagens pendentes, enviadas e com erro. Use o disparo manual para processar a fila quando precisar.
      </p>
    </div>
    <div class="outbox-actions">
      <button type="button" class="btn btn-secondary" id="outboxRefreshBtn">
        Atualizar
      </button>
      <button type="button" class="btn btn-secondary" id="outboxScheduleBtn">
        Gerar cobrancas de hoje
      </button>
      <button type="button" class="btn btn-primary" id="outboxDispatchBtn">
        Disparar e-mails
      </button>
    </div>
  </section>

  <section class="outbox-stats">
    <div class="outbox-stat card">
      <span class="outbox-stat-label">Total</span>
      <strong id="outboxTotal">0</strong>
    </div>
    <div class="outbox-stat card">
      <span class="outbox-stat-label">Pendentes</span>
      <strong id="outboxPending">0</strong>
    </div>
    <div class="outbox-stat card">
      <span class="outbox-stat-label">Enviados</span>
      <strong id="outboxSent">0</strong>
    </div>
    <div class="outbox-stat card">
      <span class="outbox-stat-label">Erros</span>
      <strong id="outboxError">0</strong>
    </div>
  </section>

  <section class="outbox-filters card">
    <label class="outbox-filter">
      <span>Status</span>
      <select id="outboxStatusFilter" class="form-input">
        <option value="all">Todos</option>
        <option value="pending">Pendentes</option>
        <option value="error">Com erro</option>
        <option value="sent">Enviados</option>
      </select>
    </label>
    <label class="outbox-filter">
      <span>Tipo</span>
      <select id="outboxKindFilter" class="form-input">
        <option value="automatic" selected>Automaticos</option>
        <option value="all">Todos</option>
        <option value="manual">Manuais</option>
        <option value="standard">Padrao</option>
      </select>
    </label>
    <label class="outbox-filter">
      <span>Limite</span>
      <select id="outboxLimitFilter" class="form-input">
        <option value="50">50 mensagens</option>
        <option value="100" selected>100 mensagens</option>
        <option value="200">200 mensagens</option>
        <option value="300">300 mensagens</option>
      </select>
    </label>
  </section>

  <section class="card outbox-table-card">
    <div class="table-header">
      <span class="table-title">Mensagens</span>
      <span class="table-count" id="outboxCount">0 itens</span>
    </div>
    <div class="outbox-table-wrap">
      <table class="outbox-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Criado em</th>
            <th>Tipo</th>
            <th>Evento</th>
            <th>Cliente</th>
            <th>Cobranca</th>
            <th>Destinatario</th>
            <th>Assunto</th>
            <th>Status</th>
            <th>Erro</th>
          </tr>
        </thead>
        <tbody id="outboxTableBody"></tbody>
      </table>
      <div class="empty-state" id="outboxEmptyState" style="display:none;">
        <h3 class="empty-state-title">Nenhuma mensagem encontrada</h3>
        <p class="empty-state-text">Ajuste os filtros ou processe uma importacao/agendamento para gerar itens na fila.</p>
      </div>
    </div>
  </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
