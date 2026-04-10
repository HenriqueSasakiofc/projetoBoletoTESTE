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

  let customersFile = null;
  let receivablesFile = null;

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

  const uploadResult     = document.getElementById("uploadResult");
  const uploadSuccess    = document.getElementById("uploadResultSuccess");
  const uploadError      = document.getElementById("uploadResultError");
  const uploadErrorMsg   = document.getElementById("uploadErrorMsg");
  const resultClientes   = document.getElementById("resultClientes");
  const resultCobrancas  = document.getElementById("resultCobrancas");
  const resultEmails     = document.getElementById("resultEmails");

  btnImport.addEventListener("click", async () => {
    if (!customersFile || !receivablesFile) return;

    btnImport.disabled = true;
    btnText.textContent = "Enviando lote...";
    spinner.style.display = "block";

    // Hide previous result
    if (uploadResult)  uploadResult.style.display  = "none";
    if (uploadSuccess) uploadSuccess.style.display  = "none";
    if (uploadError)   uploadError.style.display    = "none";

    const formData = new FormData();
    formData.append("customers_upload", customersFile);
    formData.append("receivables_upload", receivablesFile);

    try {
      const result = await API.request("/api/imports/upload", {
        method: "POST",
        body: formData,
      }, { isFormData: true });

      // Show success panel
      if (resultClientes)  resultClientes.textContent  = result.customers_parsed  ?? 0;
      if (resultCobrancas) resultCobrancas.textContent = result.receivables_parsed ?? 0;
      if (resultEmails)    resultEmails.textContent    = result.emails_sent_now    ?? 0;
      if (uploadResult)  { uploadResult.style.display  = "block"; uploadResult.scrollIntoView({ behavior: "smooth" }); }
      if (uploadSuccess)   uploadSuccess.style.display  = "block";

      btnText.textContent = "✓ Lote Enviado";
    } catch (error) {
      // Show error panel
      if (uploadErrorMsg)  uploadErrorMsg.textContent  = error.message;
      if (uploadResult)  { uploadResult.style.display  = "block"; uploadResult.scrollIntoView({ behavior: "smooth" }); }
      if (uploadError)     uploadError.style.display   = "block";

      btnImport.disabled = false;
      btnText.textContent = "Tentar novamente";
      spinner.style.display = "none";
    }
  });
}

document.addEventListener("DOMContentLoaded", initImportPage);
