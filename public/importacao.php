<?php
$pageTitle = "Importar Arquivos - Projeto Boleto";
$headerTitle = "Importar Arquivos";
$headerSubtitle = "Envie as planilhas para processamento";
$currentPage = "importacao";
$extraCss = ["importar.css"];
$extraJs = ["api.js", "importar.js"];
include __DIR__ . '/includes/header.php';
?>

<main class="main-container">
  <div class="page-intro">
    <div class="page-intro-icon">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
      </svg>
    </div>
    <div>
      <h2 class="page-intro-title">Importação de Lote</h2>
      <p class="page-intro-text">Para iniciar um novo lote, selecione <strong>ambos</strong> os arquivos abaixo.</p>
    </div>
  </div>

  <div class="import-grid">
    <!-- Card A: Clientes -->
    <div class="import-card" id="cardClientes">
      <h3 class="import-card-title">1. Base de Clientes</h3>
      <div class="drop-zone" id="dropZoneClientes">
        <input type="file" id="fileClientes" accept=".xls,.xlsx" class="file-input" style="display:none">
        <div class="drop-zone-content" id="dropContentClientes">
          <p class="drop-zone-text">Clique ou arraste o arquivo de <strong>Clientes</strong></p>
        </div>
        <div class="drop-zone-selected" id="selectedClientes" style="display:none;">
          <div class="file-info">
            <span class="file-name" id="fileNameClientes">—</span>
            <button type="button" class="file-remove" id="removeClientes">Remover</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Card B: Inadimplentes -->
    <div class="import-card" id="cardInadimplentes">
      <h3 class="import-card-title">2. Contas a Receber</h3>
      <div class="drop-zone" id="dropZoneInadimplentes">
        <input type="file" id="fileInadimplentes" accept=".xls,.xlsx" class="file-input" style="display:none">
        <div class="drop-zone-content" id="dropContentInadimplentes">
          <p class="drop-zone-text">Clique ou arraste o arquivo de <strong>Cobranças</strong></p>
        </div>
        <div class="drop-zone-selected" id="selectedInadimplentes" style="display:none;">
          <div class="file-info">
            <span class="file-name" id="fileNameInadimplentes">—</span>
            <button type="button" class="file-remove" id="removeInadimplentes">Remover</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="form-actions" style="margin-top: 32px; text-align: center;">
    <button class="btn btn-primary btn-large" id="btnImportJoint" disabled style="padding: 16px 32px; font-size: 1.1rem; width: 100%; max-width: 400px;">
      <span id="btnImportText">Processar Lote Conjunto</span>
      <div class="btn-spinner" id="spinnerImport" style="display:none;"></div>
    </button>
  </div>

  <!-- Result Panel (hidden until upload completes) -->
  <div id="uploadResult" style="display:none; margin-top: 28px; padding: 24px 28px; border-radius: 16px; border: 1px solid var(--border-color); background: var(--card-bg);">
    <div id="uploadResultSuccess" style="display:none;">
      <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px;">
        <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#22c55e,#16a34a);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="white" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        </div>
        <div>
          <h3 style="margin:0;font-size:1.1rem;font-weight:700;color:var(--text-primary)">Lote processado com sucesso!</h3>
          <p style="margin:4px 0 0;font-size:0.875rem;color:var(--text-secondary)">Todos os registros foram importados.</p>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px;">
        <div style="padding:16px;border-radius:12px;background:var(--bg-secondary);text-align:center;">
          <div id="resultClientes" style="font-size:2rem;font-weight:800;color:var(--primary-color)">0</div>
          <div style="font-size:0.8rem;color:var(--text-secondary);margin-top:4px;">Clientes</div>
        </div>
        <div style="padding:16px;border-radius:12px;background:var(--bg-secondary);text-align:center;">
          <div id="resultCobrancas" style="font-size:2rem;font-weight:800;color:var(--primary-color)">0</div>
          <div style="font-size:0.8rem;color:var(--text-secondary);margin-top:4px;">Cobranças</div>
        </div>
        <div style="padding:16px;border-radius:12px;background:var(--bg-secondary);text-align:center;">
          <div id="resultEmails" style="font-size:2rem;font-weight:800;color:#8b5cf6">0</div>
          <div style="font-size:0.8rem;color:var(--text-secondary);margin-top:4px;">E-mails Enviados</div>
        </div>
      </div>
      <a href="/clientes" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:8px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8z"/></svg>
        Ver Clientes
      </a>
    </div>
    <div id="uploadResultError" style="display:none;">
      <div style="display:flex;align-items:center;gap:12px;">
        <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#ef4444,#dc2626);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="white" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </div>
        <div>
          <h3 style="margin:0;font-size:1rem;font-weight:700;color:#ef4444">Erro no upload</h3>
          <p id="uploadErrorMsg" style="margin:4px 0 0;font-size:0.875rem;color:var(--text-secondary)"></p>
        </div>
      </div>
    </div>
  </div>

  <div class="info-bar" style="margin-top: 28px;">
    <p>O backend processará ambos os arquivos para garantir que as cobranças sejam vinculadas aos clientes corretos.</p>
  </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>