<?php
$pageTitle = "Cadastro indisponivel - Projeto Boleto";
$headerTitle = "Cadastro indisponivel";
$headerSubtitle = "A criacao de empresas e usuarios e feita manualmente";
$currentPage = "cadastro";
$extraCss = ["styles.css"];
include __DIR__ . '/includes/header.php';
?>

<main class="main-container">
  <section class="card" style="max-width: 680px; margin: 0 auto;">
    <div class="card-header">
      <h2 class="card-title">Cadastro publico desativado</h2>
    </div>
    <div class="card-body" style="display: grid; gap: 20px;">
      <p style="font-size: 1rem; line-height: 1.7; color: var(--foreground);">
        O cadastro de novas empresas foi removido da aplicacao. A criacao de empresas e acessos agora e feita manualmente pela administracao do sistema.
      </p>
      <p style="font-size: 0.95rem; line-height: 1.7; color: var(--muted-foreground);">
        Se voce precisa de acesso a uma empresa, solicite a criacao manual do cadastro e depois utilize a tela inicial para entrar com seu e-mail e senha.
      </p>
      <div>
        <a href="/" class="btn btn-primary">Voltar para o login</a>
      </div>
    </div>
  </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
