(async () => {
  const user = await requireAuth();
  if (!user || !['admin', 'trainer'].includes(user.role)) {
    window.location.href = kimUrl('/public/index.html');
    return;
  }
  renderNav({ role: user.role }, kimUrl('/public/reports/index.html'));

  const exportLinks = [
    ['export-summary-csv', 'summary', 'csv'],
    ['export-summary-xml', 'summary', 'xml'],
    ['export-users-csv', 'users', 'csv'],
    ['export-users-xml', 'users', 'xml'],
    ['export-bookings-csv', 'bookings', 'csv'],
    ['export-bookings-xml', 'bookings', 'xml'],
    ['export-trainers-csv', 'trainers', 'csv'],
    ['export-trainers-xml', 'trainers', 'xml'],
    ['export-subscriptions-csv', 'subscriptions', 'csv'],
    ['export-subscriptions-xml', 'subscriptions', 'xml'],
    ['export-sessions-csv', 'sessions', 'csv'],
    ['export-sessions-xml', 'sessions', 'xml'],
  ];
  exportLinks.forEach(([id, type, format]) => {
    const el = document.getElementById(id);
    if (el) el.href = KimApi.report.exportUrl(type, format);
  });

  const dash = await KimApi.report.dashboard();
  if (!dash.success) {
    showAlert(document.getElementById('alert'), dash.error || 'Nu s-au putut incarca rapoartele', 'error');
    return;
  }

  document.getElementById('stats-grid').innerHTML = `
    <div class="card stat-card"><div class="value">${dash.active_users}</div><div class="label">Utilizatori activi</div></div>
    <div class="card stat-card"><div class="value">${dash.sessions_day}</div><div class="label">Sedinte programate azi</div></div>
    <div class="card stat-card"><div class="value">${dash.sessions_week}</div><div class="label">Sedinte programate / sapt</div></div>
    <div class="card stat-card"><div class="value">${dash.sessions_month}</div><div class="label">Sedinte programate / luna</div></div>`;

  document.getElementById('bookings-grid').innerHTML = `
    <div class="card stat-card"><div class="value">${dash.bookings_day ?? 0}</div><div class="label">Rezervari azi</div></div>
    <div class="card stat-card"><div class="value">${dash.bookings_week ?? 0}</div><div class="label">Rezervari / saptamana</div></div>
    <div class="card stat-card"><div class="value">${dash.bookings_month ?? 0}</div><div class="label">Rezervari / luna</div></div>`;

  document.getElementById('trainers-body').innerHTML = (dash.top_trainers || []).map(t =>
    `<tr><td>${escapeHtml(t.full_name)}</td><td>${t.session_count}</td></tr>`
  ).join('') || '<tr><td colspan="2">Niciun antrenor</td></tr>';

  const subs = dash.subscription_stats || {};
  document.getElementById('subs-summary-grid').innerHTML = `
    <div class="card stat-card"><div class="value">${subs.active ?? 0}</div><div class="label">Active</div></div>
    <div class="card stat-card"><div class="value">${subs.suspended ?? 0}</div><div class="label">Suspendate</div></div>
    <div class="card stat-card"><div class="value">${subs.expired ?? 0}</div><div class="label">Expirate</div></div>`;

  document.getElementById('subs-type-body').innerHTML = (subs.by_type || []).map(t =>
    `<tr><td>${escapeHtml(t.name)}</td><td>${t.cnt}</td></tr>`
  ).join('') || '<tr><td colspan="2">Niciun abonament activ</td></tr>';

  function renderChartBlock(title, paths) {
    if (!paths?.png) return '';
    const ts = Date.now();
    return `
      <div style="margin-top:1.25rem">
        <h3 style="font-size:1rem;margin-bottom:.5rem">${escapeHtml(title)}</h3>
        <img class="chart-img" src="${assetUrl(paths.png)}?t=${ts}" alt="${escapeHtml(title)} PNG">
        <img class="chart-img" src="${assetUrl(paths.webp)}?t=${ts}" alt="${escapeHtml(title)} WebP">
      </div>`;
  }

  document.getElementById('btn-charts').onclick = async () => {
    const btn = document.getElementById('btn-charts');
    btn.disabled = true;
    btn.textContent = 'Se genereaza...';
    const data = await KimApi.report.charts();
    btn.disabled = false;
    btn.textContent = 'Genereaza toate graficele';

    if (!data.success) {
      showAlert(document.getElementById('alert'), data.error || 'Eroare la generare grafice', 'error');
      return;
    }

    document.getElementById('charts').innerHTML =
      renderChartBlock('Top antrenori / terapeuti', data.trainers) +
      renderChartBlock('Rezervari sedinte (zi / sapt / luna)', data.bookings) +
      renderChartBlock('Abonamente active pe tip', data.subscriptions);

    if (!data.trainers?.generated && !data.bookings?.generated && !data.subscriptions?.generated) {
      showAlert(
        document.getElementById('alert'),
        data.trainers?.gd_available === false
          ? 'Extensia GD nu este activa in PHP. Activati extension=gd in php.ini.'
          : 'Diagramele nu au putut fi generate pe server.',
        'error'
      );
    }
  };
})();
