/* =====================================================
   IMPORTAR.JS
   Handles theme toggle, file validation, drag & drop,
   and upload simulation for the import page.
   ===================================================== */

(function () {
  'use strict';

  // ── Theme Toggle ──────────────────────────────────────
  const themeToggle = document.getElementById('themeToggle');
  const html = document.documentElement;

  const savedTheme = localStorage.getItem('theme');
  if (savedTheme === 'dark') html.classList.add('dark');

  themeToggle.addEventListener('click', () => {
    html.classList.toggle('dark');
    localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
  });

  // ── Allowed MIME / extensions ─────────────────────────
  const ALLOWED_EXTENSIONS = ['.xls', '.xlsx'];
  const ALLOWED_MIMES = [
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  ];
  const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB

  // ── Utility: format bytes ─────────────────────────────
  function formatBytes(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
  }

  // ── Validate file ─────────────────────────────────────
  function isValidFile(file) {
    const name = file.name.toLowerCase();
    const hasValidExt = ALLOWED_EXTENSIONS.some((ext) => name.endsWith(ext));
    const hasValidMime = ALLOWED_MIMES.includes(file.type) || file.type === '';
    return hasValidExt && hasValidMime && file.size <= MAX_SIZE_BYTES;
  }

  function getValidationError(file) {
    const name = file.name.toLowerCase();
    const hasValidExt = ALLOWED_EXTENSIONS.some((ext) => name.endsWith(ext));
    if (!hasValidExt) return 'Tipo de arquivo inválido. Use .xls ou .xlsx.';
    if (file.size > MAX_SIZE_BYTES) return 'Arquivo muito grande. O limite é de 10 MB.';
    return null;
  }

  // ── Setup an import card ──────────────────────────────
  function setupImportCard(config) {
    const {
      dropZoneId,
      fileInputId,
      dropContentId,
      selectedId,
      fileNameId,
      fileSizeId,
      removeId,
      errorId,
      errorTextId,
      successId,
      btnId,
      btnTextId,
      spinnerId,
      importType,   // 'clientes' | 'inadimplentes'
    } = config;

    const dropZone    = document.getElementById(dropZoneId);
    const fileInput   = document.getElementById(fileInputId);
    const dropContent = document.getElementById(dropContentId);
    const selected    = document.getElementById(selectedId);
    const fileNameEl  = document.getElementById(fileNameId);
    const fileSizeEl  = document.getElementById(fileSizeId);
    const removeBtn   = document.getElementById(removeId);
    const errorEl     = document.getElementById(errorId);
    const errorTextEl = document.getElementById(errorTextId);
    const successEl   = document.getElementById(successId);
    const btn         = document.getElementById(btnId);
    const btnText     = document.getElementById(btnTextId);
    const spinner     = document.getElementById(spinnerId);

    let selectedFile = null;

    // ── Show / hide helpers ───────────────────────────
    function showError(msg) {
      errorTextEl.textContent = msg;
      errorEl.style.display   = 'flex';
      successEl.style.display = 'none';
    }

    function hideError() {
      errorEl.style.display = 'none';
    }

    function showSuccess() {
      successEl.style.display = 'flex';
      errorEl.style.display   = 'none';
    }

    function showFilePreview(file) {
      fileNameEl.textContent   = file.name;
      fileSizeEl.textContent   = formatBytes(file.size);
      dropContent.style.display = 'none';
      selected.style.display    = 'block';
      dropZone.classList.add('has-file');
      showSuccess();
      btn.disabled = false;
    }

    function clearFile() {
      selectedFile              = null;
      fileInput.value           = '';
      dropContent.style.display = 'flex';
      selected.style.display    = 'none';
      dropZone.classList.remove('has-file', 'drag-over');
      hideError();
      successEl.style.display = 'none';
      btn.disabled = true;
    }

    // ── Handle a file (from input change or drop) ─────
    function handleFile(file) {
      const error = getValidationError(file);
      if (error) {
        showError(error);
        clearFile();
        return;
      }
      selectedFile = file;
      showFilePreview(file);
    }

    // ── File input change ─────────────────────────────
    fileInput.addEventListener('change', () => {
      if (fileInput.files.length > 0) handleFile(fileInput.files[0]);
    });

    // ── Remove button ─────────────────────────────────
    removeBtn.addEventListener('click', (e) => {
      e.stopPropagation(); // prevent re-opening file picker
      clearFile();
    });

    // ── Keyboard activation on drop zone ─────────────
    dropZone.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        fileInput.click();
      }
    });

    // ── Drag & Drop ───────────────────────────────────
    dropZone.addEventListener('dragover', (e) => {
      e.preventDefault();
      dropZone.classList.add('drag-over');
    });

    dropZone.addEventListener('dragleave', (e) => {
      if (!dropZone.contains(e.relatedTarget)) {
        dropZone.classList.remove('drag-over');
      }
    });

    dropZone.addEventListener('drop', (e) => {
      e.preventDefault();
      dropZone.classList.remove('drag-over');
      const files = e.dataTransfer.files;
      if (files.length > 0) {
        // Prevent the file input from conflicting
        handleFile(files[0]);
      }
    });

    // ── Import Button ─────────────────────────────────
    btn.addEventListener('click', () => {
      if (!selectedFile) return;
      startUpload(selectedFile);
    });

    // ── Upload simulation (replace with real fetch) ───
    function startUpload(file) {
      btn.disabled   = true;
      btnText.textContent = 'Enviando…';
      spinner.style.display = 'block';
      btn.querySelector('svg').style.display = 'none';

      // -----------------------------------------------
      // BACKEND INTEGRATION POINT
      // Replace this setTimeout with a real fetch call:
      //
      // const formData = new FormData();
      // formData.append('file', file);
      // formData.append('type', importType);
      //
      // fetch('/api/import', {
      //   method: 'POST',
      //   body: formData,
      // })
      //   .then(res => res.json())
      //   .then(data => onUploadSuccess(data))
      //   .catch(err => onUploadError(err));
      // -----------------------------------------------

      setTimeout(() => {
        const success = true; // simulated result

        spinner.style.display = 'none';
        btn.querySelector('svg').style.display = '';

        if (success) {
          onUploadSuccess();
        } else {
          onUploadError(new Error('Erro de servidor simulado'));
        }
      }, 2200);
    }

    function onUploadSuccess() {
      btnText.textContent = 'Importado com Sucesso!';
      btn.disabled = false;

      // Show a persistent success indicator on the drop zone
      dropZone.classList.add('has-file');
      successEl.innerHTML = `
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span>Importação concluída! Dados processados com sucesso.</span>
      `;
      successEl.style.display = 'flex';

      // Reset the button label after a short delay
      setTimeout(() => {
        btnText.textContent = importType === 'clientes' ? 'Importar Clientes' : 'Importar Pedidos';
        btn.disabled = false;
      }, 3000);
    }

    function onUploadError(err) {
      showError('Ocorreu um erro ao enviar o arquivo. Tente novamente.');
      btnText.textContent = importType === 'clientes' ? 'Importar Clientes' : 'Importar Pedidos';
      btn.disabled = false;
      console.error('[Import error]', err);
    }
  }

  // ── Wire up both cards ────────────────────────────────
  setupImportCard({
    dropZoneId:    'dropZoneClientes',
    fileInputId:   'fileClientes',
    dropContentId: 'dropContentClientes',
    selectedId:    'selectedClientes',
    fileNameId:    'fileNameClientes',
    fileSizeId:    'fileSizeClientes',
    removeId:      'removeClientes',
    errorId:       'errorClientes',
    errorTextId:   'errorTextClientes',
    successId:     'successClientes',
    btnId:         'btnImportClientes',
    btnTextId:     'btnImportClientesText',
    spinnerId:     'spinnerClientes',
    importType:    'clientes',
  });

  setupImportCard({
    dropZoneId:    'dropZoneInadimplentes',
    fileInputId:   'fileInadimplentes',
    dropContentId: 'dropContentInadimplentes',
    selectedId:    'selectedInadimplentes',
    fileNameId:    'fileNameInadimplentes',
    fileSizeId:    'fileSizeInadimplentes',
    removeId:      'removeInadimplentes',
    errorId:       'errorInadimplentes',
    errorTextId:   'errorTextInadimplentes',
    successId:     'successInadimplentes',
    btnId:         'btnImportInadimplentes',
    btnTextId:     'btnImportInadimplentesText',
    spinnerId:     'spinnerInadimplentes',
    importType:    'inadimplentes',
  });

})();
