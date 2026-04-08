<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Criar conta da empresa - Projeto Boleto</title>
  <link rel="stylesheet" href="/static/style.css" />
</head>
<body data-page="cadastro">
  <header class="topbar">
    <div>
      <h1>Criar conta da empresa</h1>
      <p>Cadastre sua empresa e o primeiro administrador do ambiente</p>
    </div>
    <div class="topbar-actions">
      <a class="button-link" href="/">Voltar para login</a>
    </div>
  </header>

  <main class="page-container">
    <div id="register-message" class="message-box"></div>

    <section class="card">
      <h2>Cadastro inicial</h2>

      <form id="register-form" class="form-grid">
        <input id="register-company-name" type="text" placeholder="Nome da empresa" required />
        <input id="register-admin-name" type="text" placeholder="Seu nome" required />
        <input id="register-admin-email" type="email" placeholder="Seu e-mail" required />
        <input id="register-admin-password" type="password" placeholder="Senha" minlength="8" required />
        <input id="register-admin-password-confirm" type="password" placeholder="Confirmar senha" minlength="8" required />

        <input
          id="register-website"
          type="text"
          name="website"
          autocomplete="off"
          tabindex="-1"
          style="position:absolute;left:-9999px;opacity:0;pointer-events:none;"
        />

        <label style="display:flex;gap:10px;align-items:flex-start;">
          <input id="register-terms" type="checkbox" style="width:auto;margin-top:3px;" />
          <span>Li e aceito os termos de uso da plataforma.</span>
        </label>

        <button type="submit">Criar conta</button>
      </form>

      <p class="hint-text" style="margin-top:12px;">
        Esse cadastro cria uma empresa nova e o primeiro usuário administrador dela.
      </p>
    </section>
  </main>

  <script src="/static/script.js"></script>
</body>
</html>