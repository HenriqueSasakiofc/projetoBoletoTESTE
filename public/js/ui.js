(function () {
  let toastStack = null;
  let dialogBackdrop = null;
  let dialogTitle = null;
  let dialogMessage = null;
  let dialogCancel = null;
  let dialogConfirm = null;
  let dialogResolve = null;
  let dialogController = null;

  function ensureUiShell() {
    if (toastStack && dialogBackdrop) {
      return;
    }

    toastStack = document.createElement("div");
    toastStack.className = "app-toast-stack";
    document.body.appendChild(toastStack);

    dialogBackdrop = document.createElement("div");
    dialogBackdrop.className = "app-dialog-backdrop";
    dialogBackdrop.hidden = true;
    dialogBackdrop.innerHTML = `
      <div class="app-dialog" role="dialog" aria-modal="true" aria-labelledby="appDialogTitle">
        <div class="app-dialog-header">
          <h3 class="app-dialog-title" id="appDialogTitle"></h3>
        </div>
        <p class="app-dialog-message"></p>
        <div class="app-dialog-actions">
          <button type="button" class="btn btn-secondary app-dialog-cancel">Cancelar</button>
          <button type="button" class="btn btn-primary app-dialog-confirm">Confirmar</button>
        </div>
      </div>
    `;

    document.body.appendChild(dialogBackdrop);

    dialogTitle = dialogBackdrop.querySelector(".app-dialog-title");
    dialogMessage = dialogBackdrop.querySelector(".app-dialog-message");
    dialogCancel = dialogBackdrop.querySelector(".app-dialog-cancel");
    dialogConfirm = dialogBackdrop.querySelector(".app-dialog-confirm");

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && dialogResolve) {
        closeDialog(false);
      }
    });
  }

  function closeDialog(result) {
    if (!dialogBackdrop || !dialogResolve) {
      return;
    }

    const resolve = dialogResolve;
    dialogResolve = null;
    if (dialogController) {
      dialogController.abort();
      dialogController = null;
    }
    dialogBackdrop.hidden = true;
    document.body.classList.remove("app-dialog-open");
    resolve(result);
  }

  function buildToastIcon(type) {
    const icons = {
      success:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>',
      error:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86l-7.5 13A2 2 0 004.5 20h15a2 2 0 001.71-3.14l-7.5-13a2 2 0 00-3.42 0z"/></svg>',
      warning:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v5m0 4h.01M10.29 3.86l-7.5 13A2 2 0 004.5 20h15a2 2 0 001.71-3.14l-7.5-13a2 2 0 00-3.42 0z"/></svg>',
      info:
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16v-4m0-4h.01M22 12A10 10 0 1112 2a10 10 0 0110 10z"/></svg>',
    };

    return icons[type] || icons.info;
  }

  function notify(options = {}) {
    ensureUiShell();

    const type = options.type || "info";
    const title = options.title || "Aviso";
    const message = options.message || "";
    const duration = options.duration === 0 ? 0 : options.duration || 4800;

    const toast = document.createElement("div");
    toast.className = `app-toast app-toast--${type}`;
    toast.innerHTML = `
      <div class="app-toast-icon">${buildToastIcon(type)}</div>
      <div class="app-toast-body">
        <strong class="app-toast-title"></strong>
        <p class="app-toast-message"></p>
      </div>
      <button type="button" class="app-toast-close" aria-label="Fechar mensagem">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/>
        </svg>
      </button>
    `;

    toast.querySelector(".app-toast-title").textContent = title;
    toast.querySelector(".app-toast-message").textContent = message;

    const close = () => {
      toast.classList.add("is-leaving");
      window.setTimeout(() => toast.remove(), 180);
    };

    toast.addEventListener("click", (event) => {
      event.stopPropagation();
    });

    toast.querySelector(".app-toast-close").addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();
      close();
    });

    toastStack.appendChild(toast);

    if (duration > 0) {
      window.setTimeout(close, duration);
    }

    return toast;
  }

  function confirm(options = {}) {
    ensureUiShell();

    if (dialogResolve) {
      closeDialog(false);
    }

    dialogTitle.textContent = options.title || "Confirmar acao";
    dialogMessage.textContent = options.message || "Deseja continuar?";
    dialogConfirm.textContent = options.confirmText || "Confirmar";
    dialogCancel.textContent = options.cancelText || "Cancelar";
    dialogConfirm.classList.remove("btn-primary", "btn-danger");
    dialogConfirm.classList.add(options.tone === "danger" ? "btn-danger" : "btn-primary");

    dialogBackdrop.hidden = false;
    document.body.classList.add("app-dialog-open");

    dialogController = new AbortController();
    const { signal } = dialogController;

    dialogBackdrop.addEventListener(
      "click",
      (event) => {
        if (event.target === dialogBackdrop) {
          event.preventDefault();
          closeDialog(false);
        }
      },
      { signal }
    );

    dialogCancel.addEventListener(
      "click",
      (event) => {
        event.preventDefault();
        event.stopPropagation();
        closeDialog(false);
      },
      { signal }
    );

    dialogConfirm.addEventListener(
      "click",
      (event) => {
        event.preventDefault();
        event.stopPropagation();
        closeDialog(true);
      },
      { signal }
    );

    return new Promise((resolve) => {
      dialogResolve = resolve;
      window.requestAnimationFrame(() => {
        dialogConfirm.focus();
      });
    });
  }

  window.AppUI = {
    notify,
    confirm,
  };
})();
