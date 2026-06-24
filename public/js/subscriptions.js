let currentUser = null;

async function loadTypes() {
  const data = await KimApi.subscription.types();
  const grid = document.getElementById('types-grid');
  grid.innerHTML = (data.types || []).map(t => `
    <div class="card">
      <h3>${escapeHtml(t.name)}</h3>
      <p>${escapeHtml(t.description || '')}</p>
      <p><strong>${t.price} RON</strong> / ${t.duration_days} zile</p>
      <p><strong>${t.sessions_included ?? 4}</strong> sedinte incluse</p>
      <button class="btn btn-success btn-sm" onclick="activateSub(${t.id})">Activeaza</button>
    </div>
  `).join('');
}

window.activateSub = async (typeId) => {
  const data = await KimApi.subscription.activate({ type_id: typeId });
  const el = document.getElementById('alert');
  if (data.success) {
    const types = (data.allowed_types || []).join(', ');
    showAlert(
      el,
      `Abonament activat! +${data.sessions_remaining} sedinte. Total ramase: ${data.total_remaining ?? data.sessions_remaining}. Tipuri: ${types}. Poti activa si un al doilea abonament (ex: Basic + Kineto).`,
      'success'
    );
    const remEl = document.getElementById('active-sessions-remaining');
    if (remEl) remEl.textContent = data.total_remaining ?? data.sessions_remaining;
    const typesEl = document.getElementById('active-types-list');
    if (typesEl) typesEl.textContent = types || '-';
  } else {
    showAlert(el, data.error || 'Eroare activare', 'error');
  }
  loadHistory();
};

async function loadHistory() {
  const data = await KimApi.subscription.history();
  document.getElementById('history-body').innerHTML = (data.subscriptions || []).map(s =>
    `<tr>
      <td>${escapeHtml(s.type_name)}</td>
      <td>${escapeHtml(s.status)}</td>
      <td>${s.sessions_remaining ?? '-'}</td>
      <td>${escapeHtml(s.start_date)}</td>
      <td>${escapeHtml(s.end_date)}</td>
      <td>${s.price} RON</td>
    </tr>`
  ).join('') || '<tr><td colspan="6">Niciun abonament</td></tr>';

  const active = (data.subscriptions || []).find(s => s.status === 'active');
  const remEl = document.getElementById('active-sessions-remaining');
  if (remEl && active) {
    remEl.textContent = active.sessions_remaining ?? 0;
  }
}

document.getElementById('type-form')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const data = await KimApi.subscription.typeCreate({
    name: document.getElementById('type_name').value,
    price: parseFloat(document.getElementById('type_price').value),
    duration_days: parseInt(document.getElementById('type_days').value),
    sessions_included: parseInt(document.getElementById('type_sessions').value) || 4,
    description: document.getElementById('type_desc').value,
  });
  const el = document.getElementById('alert');
  showAlert(el, data.success ? 'Tip adaugat' : data.error, data.success ? 'success' : 'error');
  if (data.success) {
    e.target.reset();
    loadTypes();
  }
});

(async () => {
  currentUser = await requireAuth();
  if (!currentUser) return;
  renderNav({ role: currentUser.role }, kimUrl('/public/subscriptions/index.html'));
  if (currentUser.role === 'admin') {
    document.getElementById('admin-types').classList.remove('hidden');
  }
  const rem = await KimApi.subscription.getRemaining();
  const remEl = document.getElementById('active-sessions-remaining');
  const typesEl = document.getElementById('active-types-list');
  if (remEl && rem.success) {
    remEl.textContent = rem.total_remaining ?? rem.sessions_remaining;
  }
  if (typesEl && rem.success) {
    typesEl.textContent = (rem.allowed_types || []).join(', ') || '-';
  }
  loadTypes();
  loadHistory();
})();
