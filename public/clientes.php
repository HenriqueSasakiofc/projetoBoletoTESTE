<?php
$pageTitle = "Clientes - Projeto Boleto";
$headerTitle = "Clientes";
$headerSubtitle = "Gerencie todos os clientes cadastrados";
$currentPage = "clientes";
$extraCss = ["clientes.css", "mensagem.css"];
$extraJs = ["api.js", "clientes.js", "mensagem.js"];
include __DIR__ . '/includes/header.php';
?>

<main class="main-container">
  <!-- Search Section -->
  <div class="search-section">
    <div class="search-wrapper">
      <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
      </svg>
      <input type="text" class="search-input" id="searchInput" placeholder="Buscar clientes por nome, email ou documento...">
    </div>
  </div>

  <!-- Clients Table Card -->
  <div class="card table-card">
    <div class="card-decoration"></div>
    <div class="card-decoration-2"></div>
    <div class="table-header">
      <span class="table-title">Lista de Clientes</span>
      <span class="table-count" id="clientsCount">... clientes</span>
    </div>
    <div class="table-container">
      <table>
        <thead>
          <tr>
            <th>Nome</th>
            <th>Email</th>
            <th>Telefone</th>
            <th>Status</th>
            <th>Ações</th>
          </tr>
        </thead>
        <tbody id="clientsTableBody">
          <!-- Clients will be rendered here by JavaScript -->
        </tbody>
      </table>
      <div class="empty-state" id="emptyState" style="display: none;">
        <svg class="empty-state-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" />
        </svg>
        <h3 class="empty-state-title">Nenhum cliente encontrado</h3>
        <p class="empty-state-text">Tente ajustar os termos da sua busca.</p>
      </div>
    </div>
  </div>

  <!-- Send Message Panel -->
  <div class="msg-panel" id="msgPanel" aria-live="polite">
    <div class="card msg-card">
      <div class="card-decoration"></div>
      <div class="card-decoration-2"></div>
      <div class="msg-panel-header">
        <div class="msg-panel-header-left">
          <div class="msg-panel-avatar" id="msgAvatar">—</div>
          <div>
            <h2 class="msg-panel-title">Enviar Mensagem Personalizada</h2>
            <p class="msg-panel-subtitle">Escreva uma mensagem para o cliente selecionado</p>
          </div>
        </div>
        <button class="msg-panel-close" id="msgPanelClose" aria-label="Fechar painel">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
      <div class="msg-client-meta">
        <div class="msg-meta-item">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
          </svg>
          <span id="msgClientName">—</span>
        </div>
        <div class="msg-meta-divider"></div>
        <div class="msg-meta-item">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
          </svg>
          <span id="msgClientEmail">—</span>
        </div>
      </div>
      <div class="msg-form-group">
        <label class="msg-label" for="msgTextarea">Mensagem <span class="msg-char-count" id="msgCharCount">0 / 500</span></label>
        <textarea id="msgTextarea" class="msg-textarea" placeholder="Olá, gostaria de..." maxlength="500" rows="6"></textarea>
      </div>
      <div class="msg-feedback" id="msgFeedback" style="display:none;" role="alert"></div>
      <div class="msg-actions">
        <button class="msg-btn-secondary" id="msgBtnClear" type="button">Limpar</button>
        <button class="msg-btn-primary" id="msgBtnSend" type="button">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
          </svg>
          <span id="msgBtnText">Enviar Mensagem</span>
          <div class="msg-btn-spinner" id="msgSpinner" style="display:none;"></div>
        </button>
      </div>
    </div>
  </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>