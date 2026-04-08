<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Pendências - Projeto Boleto</title>
  <link rel="stylesheet" href="/static/style.css" />
</head>
<body data-page="pendencias">
  <header class="topbar">
    <div>
      <h1>Pendências de vínculo</h1>
      <p>Resolver cobranças sem vínculo seguro com cliente</p>
    </div>
    <div class="topbar-actions">
      <a class="button-link" href="/">Dashboard</a>
      <a class="button-link" href="/importacao">Importação</a>
      <span class="user-badge" data-user-name>Não autenticado</span>
      <button id="logout-btn" data-logout type="button">Sair</button>
    </div>
  </header>

  <main class="page-container">
    <div id="pendings-message" class="message-box"></div>

    <section class="card">
      <div class="form-inline">
        <input id="pendings-batch-id" type="number" placeholder="Informe o batch_id" />
        <button id="load-pendings-btn" type="button">Carregar pendências</button>
      </div>
    </section>

    <section id="pendings-list" class="content-list"></section>
  </main>

  <script src="/static/script.js"></script>
</body>
</html>