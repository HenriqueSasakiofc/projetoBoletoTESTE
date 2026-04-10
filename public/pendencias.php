<?php
$pageTitle = "Pendências - Projeto Boleto";
$headerTitle = "Pendências de Vínculo";
$headerSubtitle = "Resolva inconsistências nas importações";
$currentPage = "pendencias";
$extraCss = ["styles.css"];
$extraJs = ["api.js", "pendencias.js"];
include __DIR__ . '/includes/header.php';
?>

<main class="main-container">
  <div id="pendings-message" class="message-box"></div>

  <section class="card" style="margin-bottom: 32px;">
    <div class="card-body">
      <div style="display: flex; gap: 12px; align-items: flex-end;">
        <div style="flex: 1;">
          <label class="form-label" for="pendings-batch-id">ID do Lote</label>
          <input id="pendings-batch-id" type="number" class="form-input" placeholder="Ex: 42" />
        </div>
        <button id="load-pendings-btn" class="btn btn-secondary" type="button">Carregar Pendências</button>
      </div>
    </div>
  </section>

  <section id="pendings-list">
    <!-- Rendered by JS -->
  </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>