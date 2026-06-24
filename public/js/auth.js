const loginForm = document.getElementById('login-form');
const registerForm = document.getElementById('register-form');
const alertEl = document.getElementById('alert');

if (loginForm) {
  loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = loginForm.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;
    try {
      const data = await KimApi.user.login({
        email: document.getElementById('email').value.trim(),
        password: document.getElementById('password').value,
      });
      if (data.success) {
        window.location.href = kimUrl('/public/index.html');
      } else {
        showAlert(alertEl, data.error || 'Eroare login');
      }
    } catch (err) {
      showAlert(alertEl, err.message || 'Eroare de retea');
    } finally {
      if (btn) btn.disabled = false;
    }
  });
}

if (registerForm) {
  registerForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = await KimApi.user.register({
      full_name: document.getElementById('full_name').value,
      email: document.getElementById('email').value,
      phone: document.getElementById('phone').value,
      password: document.getElementById('password').value,
    });
    if (data.success) {
      showAlert(alertEl, 'Cont creat! Autentificati-va.', 'success');
      setTimeout(() => { window.location.href = 'login.html'; }, 1500);
    } else {
      showAlert(alertEl, data.error || 'Eroare inregistrare');
    }
  });
}
