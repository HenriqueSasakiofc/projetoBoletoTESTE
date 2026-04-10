const API = {
  tokenKey: "projeto_boleto_token",
  userKey: "projeto_boleto_user",

  getToken() {
    return localStorage.getItem(this.tokenKey);
  },

  getUser() {
    try {
      return JSON.parse(localStorage.getItem(this.userKey) || "null");
    } catch {
      return null;
    }
  },

  saveSession(token, user) {
    localStorage.setItem(this.tokenKey, token);
    localStorage.setItem(this.userKey, JSON.stringify(user));
  },

  clearSession() {
    localStorage.removeItem(this.tokenKey);
    localStorage.removeItem(this.userKey);
  },

  async request(path, options = {}, config = {}) {
    const headers = new Headers(options.headers || {});
    const auth = config.auth !== false;
    const isFormData = config.isFormData === true;

    if (auth && this.getToken()) {
      headers.set("Authorization", `Bearer ${this.getToken()}`);
    }

    if (!isFormData && !headers.has("Content-Type") && options.body && typeof options.body === "string") {
      headers.set("Content-Type", "application/json");
    }

    const response = await fetch(path, {
      ...options,
      headers,
    });

    const contentType = response.headers.get("content-type") || "";
    const isJson = contentType.includes("application/json");
    const data = isJson ? await response.json() : await response.text();

    if (!response.ok) {
      const detail =
        (isJson && (data.error || data.detail || data.message)) ||
        (typeof data === "string" && data) ||
        `Erro ${response.status} na requisição.`;
      const error = new Error(detail);
      error.status = response.status;
      error.data = data;
      throw error;
    }

    return data;
  },
};

function $(selector) {
  return document.querySelector(selector);
}

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function checkAuth() {
    const token = API.getToken();
    const user = API.getUser();
    const path = window.location.pathname;

    // Allowed pages without token
    const isPublicPage = path === "/" || path === "/index.php" || path === "/cadastro" || path === "/cadastro.php";

    // Only block navigation if there is no token at all
    if (!token) {
        if (!isPublicPage) {
            window.location.href = "/";
        }
        return false;
    }

    // Show user info in header if available
    const userInfo = $("#user-info");
    const userBadgeName = $("#user-badge-name");
    if (userInfo) userInfo.style.display = "flex";
    if (userBadgeName && user) userBadgeName.textContent = user.full_name || user.email || "Usuário";

    $("#logout-btn")?.addEventListener("click", () => {
        API.clearSession();
        window.location.href = "/";
    });

    return true;
}

document.addEventListener("DOMContentLoaded", checkAuth);
