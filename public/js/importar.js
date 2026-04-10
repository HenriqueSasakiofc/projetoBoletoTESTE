async function initImportPage() {
  const dropZoneClientes = document.getElementById("dropZoneClientes");
  const fileInputClientes = document.getElementById("fileClientes");
  const dropContentClientes = document.getElementById("dropContentClientes");
  const selectedClientes = document.getElementById("selectedClientes");
  const fileNameClientes = document.getElementById("fileNameClientes");
  const removeClientes = document.getElementById("removeClientes");

  const dropZoneReceivables = document.getElementById("dropZoneInadimplentes");
  const fileInputReceivables = document.getElementById("fileInadimplentes");
  const dropContentReceivables = document.getElementById("dropContentInadimplentes");
  const selectedReceivables = document.getElementById("selectedInadimplentes");
  const fileNameReceivables = document.getElementById("fileNameInadimplentes");
  const removeReceivables = document.getElementById("removeInadimplentes");

  const btnImport = document.getElementById("btnImportJoint");
  const btnText = document.getElementById("btnImportText");
  const spinner = document.getElementById("spinnerImport");
  const btnReprocess = document.getElementById("btnReprocessBatch");

  const uploadResult = document.getElementById("uploadResult");
  const uploadSuccess = document.getElementById("uploadResultSuccess");
  const uploadError = document.getElementById("uploadResultError");
  const uploadErrorMsg = document.getElementById("uploadErrorMsg");
  const uploadSuccessTitle = document.getElementById("uploadSuccessTitle");
  const uploadSuccessText = document.getElementById("uploadSuccessText");
  const resultClientes = document.getElementById("resultClientes");
  const resultCobrancas = document.getElementById("resultCobrancas");
  const resultEmails = document.getElementById("resultEmails");

  const latestBatchTitle = document.getElementById("latestBatchTitle");
  const latestBatchSubtitle = document.getElementById("latestBatchSubtitle");
  const latestBatchBadge = document.getElementById("latestBatchBadge");
  const latestBatchCustomers = document.getElementById("latestBatchCustomers");
  const latestBatchReceivables = document.getElementById("latestBatchReceivables");
  const latestBatchInvalid = document.getElementById("latestBatchInvalid");
  const latestBatchFiles = document.getElementById("latestBatchFiles");
  const latestBatchError = document.getElementById("latestBatchError");
  const latestBatchHint = document.getElementById("latestBatchHint");

  let customersFile = null;
  let receivablesFile = null;
  let latestBatch = null;

  function handleFile(file, type) {
    if (type === "clientes") {
      customersFile = file;
      fileNameClientes.textContent = file.name;
      dropContentClientes.style.display = "none";
      selectedClientes.style.display = "block";
      dropZoneClientes.classList.add("has-file");
    } else {
      receivablesFile = file;
      fileNameReceivables.textContent = file.name;
      dropContentReceivables.style.display = "none";
      selectedReceivables.style.display = "block";
      dropZoneReceivables.classList.add("has-file");
    }
    checkReady();
  }

  function checkReady() {
    btnImport.disabled = !(customersFile && receivablesFile);
  }

  function notify(type, title, message) {
    window.AppUI?.notify({ type, title, message });
  }

  function formatDateTime(value) {
    if (!value) return "-";

    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return value;

    return parsed.toLocaleString("pt-BR", {
      dateStyle: "short",
      timeStyle: "short",
    });
  }

  function badgeClassForStatus(status) {
    switch (status) {
      case "completed":
        return "batch-ops-badge batch-ops-badge--success";
      case "error":
        return "batch-ops-badge batch-ops-badge--error";
      case "processing":
        return "batch-ops-badge batch-ops-badge--warning";
      default:
        return "batch-ops-badge batch-ops-badge--idle";
    }
  }

  function resetImportButton() {
    checkReady();
    btnText.textContent = "Processar lote";
    spinner.style.display = "none";
  }

  function clearResultPanels() {
    if (uploadResult) uploadResult.style.display = "none";
    if (uploadSuccess) uploadSuccess.style.display = "none";
    if (uploadError) uploadError.style.display = "none";
  }

  function showResultSuccess(result, options = {}) {
    if (uploadSuccessTitle) {
      uploadSuccessTitle.textContent = options.title || "Lote processado com sucesso!";
    }

    if (uploadSuccessText) {
      uploadSuccessText.textContent =
        options.description || "Os registros foram importados e o lote ficou salvo para reprocessamento.";
    }

    if (resultClientes) resultClientes.textContent = result.customers_parsed ?? 0;
    if (resultCobrancas) resultCobrancas.textContent = result.receivables_parsed ?? 0;
    if (resultEmails) resultEmails.textContent = result.emails_sent_now ?? result.emails_queued ?? 0;
    if (uploadError) uploadError.style.display = "none";
    if (uploadSuccess) uploadSuccess.style.display = "block";
    if (uploadResult) {
      uploadResult.style.display = "block";
      uploadResult.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }

  function showResultError(message) {
    if (uploadErrorMsg) uploadErrorMsg.textContent = message;
    if (uploadSuccess) uploadSuccess.style.display = "none";
    if (uploadError) uploadError.style.display = "block";
    if (uploadResult) {
      uploadResult.style.display = "block";
      uploadResult.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }

  function renderLatestBatch(batch) {
    latestBatch = batch;

    if (!batch) {
      latestBatchTitle.textContent = "Nenhum lote disponivel";
      latestBatchSubtitle.textContent =
        "Assim que um lote for enviado, voce podera acompanhar o status e reprocessar sem pedir os arquivos novamente.";
      latestBatchBadge.className = "batch-ops-badge batch-ops-badge--idle";
      latestBatchBadge.textContent = "Sem lote";
      latestBatchCustomers.textContent = "0";
      latestBatchReceivables.textContent = "0";
      latestBatchInvalid.textContent = "0";
      latestBatchFiles.textContent = "Nenhum arquivo enviado ainda.";
      latestBatchHint.textContent =
        "Os novos uploads ficam guardados automaticamente para permitir reprocessamento.";
      latestBatchError.style.display = "none";
      btnReprocess.disabled = true;
      btnReprocess.dataset.batchId = "";
      return;
    }

    const invalidRows =
      (batch.preview_invalid_customers || 0) + (batch.preview_invalid_receivables || 0);
    const customersTotal = batch.preview_customers_total || batch.merged_customers_count || 0;
    const receivablesTotal = batch.preview_receivables_total || batch.merged_receivables_count || 0;

    latestBatchTitle.textContent = `Lote #${batch.id}`;
    latestBatchSubtitle.textContent = `${batch.status_label} em ${batch.updated_at_formatted || formatDateTime(batch.updated_at)}`;
    latestBatchBadge.className = badgeClassForStatus(batch.status);
    latestBatchBadge.textContent = batch.status_label || batch.status;
    latestBatchCustomers.textContent = customersTotal;
    latestBatchReceivables.textContent = receivablesTotal;
    latestBatchInvalid.textContent = invalidRows;
    latestBatchFiles.textContent = `Clientes: ${batch.customers_filename} | Cobrancas: ${batch.receivables_filename}`;
    latestBatchHint.textContent = batch.reprocess_hint || "";

    if (batch.error_message) {
      latestBatchError.textContent = batch.error_message;
      latestBatchError.style.display = "block";
    } else {
      latestBatchError.style.display = "none";
      latestBatchError.textContent = "";
    }

    btnReprocess.disabled = !batch.can_reprocess;
    btnReprocess.dataset.batchId = String(batch.id);
  }

  async function loadLatestBatch() {
    try {
      const batch = await API.request("/api/upload-batches/latest");
      renderLatestBatch(batch);
    } catch (error) {
      renderLatestBatch(null);
      console.error(error);
    }
  }

  fileInputClientes.addEventListener("change", (e) => {
    if (e.target.files.length > 0) handleFile(e.target.files[0], "clientes");
  });

  fileInputReceivables.addEventListener("change", (e) => {
    if (e.target.files.length > 0) handleFile(e.target.files[0], "receivables");
  });

  removeClientes.addEventListener("click", (e) => {
    e.stopPropagation();
    customersFile = null;
    fileInputClientes.value = "";
    dropContentClientes.style.display = "flex";
    selectedClientes.style.display = "none";
    dropZoneClientes.classList.remove("has-file");
    checkReady();
  });

  removeReceivables.addEventListener("click", (e) => {
    e.stopPropagation();
    receivablesFile = null;
    fileInputReceivables.value = "";
    dropContentReceivables.style.display = "flex";
    selectedReceivables.style.display = "none";
    dropZoneReceivables.classList.remove("has-file");
    checkReady();
  });

  // Drag & Drop
  [dropZoneClientes, dropZoneReceivables].forEach((dz, idx) => {
    dz.addEventListener("dragover", (e) => {
      e.preventDefault();
      dz.classList.add("drag-over");
    });
    dz.addEventListener("dragleave", (e) => {
      dz.classList.remove("drag-over");
    });
    dz.addEventListener("drop", (e) => {
      e.preventDefault();
      dz.classList.remove("drag-over");
      const files = e.dataTransfer.files;
      if (files.length > 0) {
        handleFile(files[0], idx === 0 ? "clientes" : "receivables");
      }
    });
    dz.addEventListener("click", () => {
        (idx === 0 ? fileInputClientes : fileInputReceivables).click();
    });
  });

  btnImport.addEventListener("click", async () => {
    if (!customersFile || !receivablesFile) return;

    btnImport.disabled = true;
    btnText.textContent = "Enviando lote...";
    spinner.style.display = "block";

    clearResultPanels();

    const formData = new FormData();
    formData.append("customers_upload", customersFile);
    formData.append("receivables_upload", receivablesFile);

    try {
      const result = await API.request(
        "/api/imports/upload",
        {
          method: "POST",
          body: formData,
        },
        { isFormData: true }
      );

      showResultSuccess(result);
      notify("success", "Lote importado", "Os arquivos foram processados e ficaram salvos para reprocessamento.");
      await loadLatestBatch();

      btnText.textContent = "✓ Lote Enviado";
    } catch (error) {
      const isDuplicate = error.status === 409;
      const message = isDuplicate
        ? `${error.message} Use o card do ultimo lote para reprocessar sem pedir novo envio ao cliente.`
        : error.message;

      showResultError(message);
      notify(
        isDuplicate ? "warning" : "error",
        isDuplicate ? "Lote ja importado" : "Falha na importacao",
        message
      );
      await loadLatestBatch();
    } finally {
      resetImportButton();
    }
  });

  btnReprocess?.addEventListener("click", async () => {
    if (!latestBatch || !latestBatch.id) return;

    const confirmed = await window.AppUI?.confirm({
      title: "Reprocessar lote?",
      message:
        "O sistema vai limpar os dados gerados por esse par de planilhas e rodar a importacao novamente usando os arquivos salvos no servidor.",
      confirmText: "Reprocessar agora",
      cancelText: "Cancelar",
      tone: "danger",
    });

    if (!confirmed) return;

    const originalText = btnReprocess.textContent;
    btnReprocess.disabled = true;
    btnReprocess.textContent = "Reprocessando...";
    clearResultPanels();

    try {
      const result = await API.request(`/api/upload-batches/${latestBatch.id}/reprocess`, {
        method: "POST",
      });

      showResultSuccess(result, {
        title: "Lote reprocessado com sucesso!",
        description: "A base foi limpa e as planilhas salvas foram processadas novamente.",
      });
      notify("success", "Lote reprocessado", "A limpeza e o novo processamento foram concluidos.");
      await loadLatestBatch();
    } catch (error) {
      showResultError(error.message);
      notify("error", "Nao foi possivel reprocessar", error.message);
      await loadLatestBatch();
    } finally {
      btnReprocess.textContent = originalText;
      if (latestBatch) {
        btnReprocess.disabled = !latestBatch.can_reprocess;
      }
    }
  });

  resetImportButton();
  await loadLatestBatch();
}

document.addEventListener("DOMContentLoaded", initImportPage);
