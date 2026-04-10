<?php
$pageTitle = "Perfil do Cliente - Projeto Boleto";
$headerTitle = "Perfil do Cliente";
$headerSubtitle = "Visualize e gerencie detalhes do cliente";
$currentPage = "cliente";
$extraCss = ["styles.css"];
$extraJs = ["api.js", "clienteDetail.js"];
include __DIR__ . '/includes/header.php';
?>

<main class="main-container">
  <div id="client-detail-message" class="message-box"></div>

  <div class="grid">
    <section class="card">
      <div class="card-header">
        <h2 class="card-title" id="client-name">Carregando...</h2>
      </div>
      <div class="card-body">
        <div class="details-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
          <div><label class="form-label">Código</label><p id="client-code">-</p></div>
          <div><label class="form-label">Documento</label><p id="client-document">-</p></div>
          <div><label class="form-label">E-mail Cobrança</label><p id="client-email-billing">-</p></div>
          <div><label class="form-label">E-mail Financeiro</label><p id="client-email-financial">-</p></div>
          <div><label class="form-label">Telefone</label><p id="client-phone">-</p></div>
          <div><label class="form-label">Outros Contatos</label><p id="client-other-contacts">-</p></div>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-header">
        <h2 class="card-title">Enviar Mensagem Manual</h2>
      </div>
      <div class="card-body">
        <form id="manual-message-form">
          <div class="form-group">
            <label class="form-label">Destinatário</label>
            <input id="manual-recipient" type="email" class="form-input" required />
          </div>
          <div class="form-group">
            <label class="form-label">Assunto</label>
            <input id="manual-subject" type="text" class="form-input" required />
          </div>
          <div class="form-group">
            <label class="form-label">Mensagem</label>
            <textarea id="manual-body" rows="4" class="form-input" required></textarea>
          </div>
          <button type="submit" class="btn btn-primary btn-full">Colocar na Fila</button>
        </form>
      </div>
    </section>
  </div>

  <section class="card" style="margin-top: 32px;">
    <div class="card-header">
        <h2 class="card-title">Cobranças Ativas</h2>
    </div>
    <div id="client-receivables" class="card-body">
        <!-- Rendered by JS -->
    </div>
  </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>