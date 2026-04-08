<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Projeto Boleto</title>
  <link rel="stylesheet" href="/static/style.css" />
</head>
<body data-page="dashboard">
  <header class="topbar">
    <div>
      <h1>Projeto Boleto</h1>
      <p>Dashboard de cobrança e notificações</p>
    </div>
    <div class="topbar-actions">
      <span class="user-badge" data-user-name>Não autenticado</span>
      <button id="logout-btn" type="button">Sair</button>
    </div>
  </header>

  <main class="page-container">
    <div id="dashboard-message" class="message-box"></div>

    <section id="login-section" class="card">
      <h2>Entrar</h2>
      <form id="login-form" class="form-grid">
        <input id="login-email" type="email" placeholder="E-mail" required />
        <input id="login-password" type="password" placeholder="Senha" required />
        <button type="submit">Entrar</button>
      </form>

      <div style="margin-top:14px;display:flex;gap:12px;flex-wrap:wrap;">
        <a class="button-link" href="/cadastro">Criar conta da empresa</a>
      </div>

      <p class="hint-text" style="margin-top:12px;">
        Use o login existente ou crie uma nova empresa.
      </p>
    </section>

    <section id="app-section" hidden>
      <div class="dashboard-grid">
        <article class="card stat-card">
          <h3>Total de clientes</h3>
          <strong id="total-clients">-</strong>
        </article>

        <article class="card quick-links">
          <h3>Navegação rápida</h3>
          <div class="button-group">
            <a class="button-link" href="/clientes">Clientes</a>
            <a class="button-link" href="/importacao">Importação</a>
            <a class="button-link" href="/pendencias">Pendências</a>
          </div>
        </article>
      </div>

      <section class="card">
        <h2>Mensagem padrão da empresa</h2>

        <div id="allowed-placeholders" class="placeholder-list"></div>

        <form id="template-form" class="form-grid">
          <input id="template-subject" type="text" placeholder="Assunto" required />
          <textarea id="template-body" rows="10" placeholder="Corpo da mensagem" required></textarea>
          <button type="submit">Salvar template</button>
        </form>

        <div class="preview-controls">
          <input id="preview-customer-id" type="number" placeholder="customer_id para preview (opcional)" />
          <input id="preview-receivable-id" type="number" placeholder="receivable_id para preview (opcional)" />
          <button id="preview-template-btn" type="button">Gerar preview</button>
        </div>

        <div id="template-preview" class="card-section"></div>
      </section>

      <section class="card">
        <div class="card-row-between">
          <h2>Outbox</h2>
          <button id="dispatch-outbox-btn" type="button">Disparar fila</button>
        </div>
        <ul id="outbox-list" class="stack-list"></ul>
      </section>
    </section>
  </main>

  <script src="/static/script.js"></script>
</body>
</html>