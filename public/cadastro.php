<?php
$pageTitle = "Criar conta da empresa - Projeto Boleto";
$headerTitle = "Cadastro de Empresa";
$headerSubtitle = "Crie sua conta para começar a gerenciar seus boletos";
$currentPage = "cadastro";
$extraCss = ["styles.css"];
$extraJs = ["api.js", "cadastro.js"];
include __DIR__ . '/includes/header.php';
?>

<main class="main-container">
  <div id="register-message" class="message-box"></div>

  <section class="card" style="max-width: 600px; margin: 0 auto;">
    <div class="card-header">
      <h2 class="card-title">Cadastro Inicial</h2>
    </div>
    <div class="card-body">
      <form id="register-form">
        <div class="form-group">
          <label class="form-label" for="register-company-name">Nome da Empresa</label>
          <input id="register-company-name" type="text" class="form-input" placeholder="Minha Empresa LTDA" required />
        </div>
        <div class="form-group">
          <label class="form-label" for="register-admin-name">Seu Nome Completo</label>
          <input id="register-admin-name" type="text" class="form-input" placeholder="João Silva" required />
        </div>
        <div class="form-group">
          <label class="form-label" for="register-admin-email">E-mail Administrativo</label>
          <input id="register-admin-email" type="email" class="form-input" placeholder="admin@empresa.com" required />
        </div>
        <div class="grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
          <div class="form-group">
            <label class="form-label" for="register-admin-password">Senha</label>
            <input id="register-admin-password" type="password" class="form-input" minlength="8" required />
          </div>
          <div class="form-group">
            <label class="form-label" for="register-admin-password-confirm">Confirmar Senha</label>
            <input id="register-admin-password-confirm" type="password" class="form-input" minlength="8" required />
          </div>
        </div>

        <input id="register-website" type="text" name="website" autocomplete="off" tabindex="-1" style="display:none" />

        <div class="form-group" style="margin-top: 16px;">
          <label style="display:flex; gap:10px; align-items:center; cursor:pointer;">
            <input id="register-terms" type="checkbox" style="width:20px; height:20px;" required />
            <span>Li e aceito os termos de uso.</span>
          </label>
        </div>

        <button type="submit" class="btn btn-primary btn-full" style="margin-top: 24px;">Criar Conta</button>
      </form>
    </div>
  </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>