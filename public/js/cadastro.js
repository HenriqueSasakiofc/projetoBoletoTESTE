async function initRegisterPage() {
  const form = $("#register-form");
  const messageEl = $("#register-message");

  form?.addEventListener("submit", async (event) => {
    event.preventDefault();
    messageEl.textContent = "";
    messageEl.className = "message-box";

    const payload = {
      company_name: $("#register-company-name").value.trim(),
      admin_full_name: $("#register-admin-name").value.trim(),
      admin_email: $("#register-admin-email").value.trim(),
      admin_password: $("#register-admin-password").value,
      admin_password_confirm: $("#register-admin-password-confirm").value,
      terms_accepted: $("#register-terms").checked,
      website: $("#register-website").value.trim(),
    };

    try {
      const result = await API.request("/api/auth/register-company", {
        method: "POST",
        body: JSON.stringify(payload),
      }, { auth: false });

      API.saveSession(result.access_token, result.user);
      messageEl.textContent = "Conta criada com sucesso. Redirecionando...";
      messageEl.className = "message-box success";

      setTimeout(() => {
        window.location.href = "/";
      }, 700);
    } catch (error) {
      messageEl.textContent = error.message;
      messageEl.className = "message-box error";
    }
  });
}

document.addEventListener("DOMContentLoaded", initRegisterPage);
