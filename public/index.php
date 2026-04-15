<?php
$pageTitle = "PayReminder";
$headerTitle = "PayReminder";
$headerSubtitle = "Gerencie seus clientes e mensagens";
$currentPage = "dashboard";
$extraJs = ["api.js", "dashboardTemplates.js"];
include __DIR__ . '/includes/header.php';
?>

<main class="main-container">
  <!-- Login Section -->
  <section id="login-section" class="card" style="max-width: 450px; margin: 40px auto; display: block;">
    <div class="card-header">
      <h2 class="card-title">Entrar no Sistema</h2>
    </div>
    <div class="card-body">
      <form id="login-form">
        <div class="form-group">
          <label class="form-label" for="login-email">E-mail</label>
          <input id="login-email" type="email" class="form-input" placeholder="admin@empresa.com" required />
        </div>
        <div class="form-group">
          <label class="form-label" for="login-password">Senha</label>
          <input id="login-password" type="password" class="form-input" required />
        </div>
        <button type="submit" class="btn btn-primary btn-full" id="login-submit-btn" style="margin-top: 24px;">Entrar</button>
      </form>
      <div id="login-error" role="alert" style="display:none; margin-top: 16px; padding: 12px 16px; border-radius: 10px; background-color: #fef2f2; border: 1px solid #fecaca; color: #dc2626; font-size: 14px; font-weight: 500;"></div>
      <div style="margin-top: 24px; text-align: center;">
        <p style="color: var(--muted-foreground); font-size: 14px; line-height: 1.5;">
          O cadastro de empresas e acessos é feito manualmente pela administração do sistema.
        </p>
      </div>
    </div>
  </section>

  <!-- Dashboard App Section -->
  <div id="app-section" style="display: none;">
    <section class="dashboard-workspace">
      <div class="dashboard-status-panel">
        <div>
          <span class="dashboard-status-label">Clientes sincronizados</span>
          <strong class="dashboard-status-value" id="totalClients">...</strong>
        </div>
        <p>
          Use as abas acima como entrada principal para cada funcao. Cada area fica isolada, facilitando suporte e correcao de erros.
        </p>
      </div>
    </section>

    <div class="grid">
    <!-- Total Clients Card -->
    <div class="card">
      <div class="card-decoration"></div>
      <div class="card-decoration-2"></div>
      <div class="card-header">
        <span class="card-title">Total de Clientes</span>
        <div class="card-icon">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" />
          </svg>
        </div>
      </div>
      <div class="total-clients-value" id="totalClients">...</div>
      <span class="growth-badge">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18" />
        </svg>
        Sincronizado
      </span>
      <div class="card-footer">
        <a href="/clientes" class="btn btn-secondary btn-full">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
          </svg>
          Ver Todos os Clientes
        </a>
      </div>
    </div>

    <!-- Import Spreadsheet Card -->
    <div class="card import-card">
      <div class="card-decoration"></div>
      <div class="card-decoration-2"></div>
      <div class="card-icon">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
        </svg>
      </div>
      <h3 class="import-card-title">Importar Planilha</h3>
      <p class="import-card-description">
        Importe seus clientes e cobranças a partir de arquivos Excel
      </p>
      <a href="/importacao" class="btn btn-primary btn-full">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </svg>
        Ir para Importação
      </a>
    </div>

    <!-- Outbox Card -->
    <div class="card import-card">
      <div class="card-decoration"></div>
      <div class="card-decoration-2"></div>
      <div class="card-icon">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8m-18 8.5A2.5 2.5 0 005.5 19h13a2.5 2.5 0 002.5-2.5v-9A2.5 2.5 0 0018.5 5h-13A2.5 2.5 0 003 7.5v9z" />
        </svg>
      </div>
      <h3 class="import-card-title">Outbox de E-mails</h3>
      <p class="import-card-description">
        Veja mensagens pendentes, enviadas e com erro, e dispare a fila manualmente
      </p>
      <a href="/outbox" class="btn btn-secondary btn-full">
        Abrir Outbox
      </a>
    </div>
  </div>

  <!-- Default Messages Section -->
  <div class="messages-section" id="templates-section">
    <div class="card messages-card">
      <div class="messages-header">
        <div>
          <h2 class="messages-title">Configurar e-mails automaticos</h2>
          <p class="messages-subtitle">Crie uma mensagem para cada momento da cobranca</p>
        </div>
        <span class="messages-count" id="messagesCount">3 eventos</span>
      </div>
      <div class="messages-body">
        <div class="template-event-tabs" id="templateEventTabs">
          <!-- Rendered by JS -->
        </div>

        <div class="template-helper-panel">
          <div>
            <h3 class="template-helper-title">Caixinhas de informacao</h3>
            <p class="template-helper-text">
              Clique em uma caixinha para inserir o campo no assunto ou na mensagem. O sistema troca automaticamente pelo dado real do cliente e da fatura.
            </p>
          </div>
          <div class="template-placeholder-grid" id="templatePlaceholderGrid">
            <!-- Rendered by JS -->
          </div>
        </div>

        <!-- Form -->
        <form id="messageForm">
          <input type="hidden" id="templateEventCode" />
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="messageTitle">Assunto</label>
              <input type="text" id="messageTitle" class="form-input template-editable" placeholder="Ex: Seu titulo vence em 7 dias" required />
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" for="messageContent">Corpo da Mensagem</label>
            <textarea id="messageContent" class="form-input template-editable" placeholder="Digite o conteudo da mensagem..." rows="8" required></textarea>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
              </svg>
              Salvar Template
            </button>
          </div>
        </form>

        <!-- Messages List -->
        <div class="messages-list">
          <h3 class="messages-list-header">Resumo dos 3 envios configurados</h3>
          <div id="messagesList">
            <!-- Rendered by JS -->
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
