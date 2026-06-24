async function loadUsers() {
  const tbody = document.getElementById('users-body');
  const data = await KimApi.adminUsers.list();
  if (!data.success) {
    tbody.innerHTML = `<tr><td colspan="4">${escapeHtml(data.message || data.error || 'Eroare incarcare')}</td></tr>`;
    return;
  }

  tbody.innerHTML = (data.users || []).map(u => {
    const isAdmin = u.role === 'admin';
    const checked = u.is_trainer ? 'checked' : '';
    const disabled = isAdmin ? 'disabled' : '';
    const title = isAdmin ? 'title="Administrator — nu poate fi modificat"' : '';
    return `<tr>
      <td>${escapeHtml(u.last_name || '-')}</td>
      <td>${escapeHtml(u.first_name || '-')}</td>
      <td>${escapeHtml(u.email)}${isAdmin ? ' <span class="badge">admin</span>' : ''}</td>
      <td>
        <input type="checkbox" ${checked} ${disabled} ${title}
               data-user-id="${u.id}"
               onchange="toggleTrainer(this, ${u.id})">
      </td>
    </tr>`;
  }).join('') || '<tr><td colspan="4">Niciun utilizator</td></tr>';
}

window.toggleTrainer = async (checkbox, userId) => {
  const el = document.getElementById('alert');
  const isTrainer = checkbox.checked ? 1 : 0;
  const data = await KimApi.adminUsers.updateRole({ user_id: userId, is_trainer: isTrainer });
  if (data.success) {
    showAlert(el, 'Rol actualizat.', 'success');
  } else {
    checkbox.checked = !checkbox.checked;
    showAlert(el, data.message || data.error || 'Nu ai permisiunea să modifici rolurile.', 'error');
  }
};

(async () => {
  const user = await requireAuth();
  if (!user || user.role !== 'admin') {
    window.location.href = kimUrl('/public/index.html');
    return;
  }
  renderNav(user, kimUrl('/public/admin/users.html'));
  await loadUsers();
})();
