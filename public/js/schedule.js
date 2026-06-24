let currentUser = null;
let allowedTypes = [];
let currentTrainerId = null;
let listMeta = {};

function sessionCanModify(session, meta = listMeta) {
  if (session.can_modify === true) return true;
  if (session.can_modify === false) return false;
  const role = meta.current_user_role || currentUser?.role;
  if (role === 'admin') return true;
  if (role === 'trainer') {
    const trainerId = meta.current_trainer_id ?? currentTrainerId ?? currentUser?.id;
    return Number(session.trainer_id) === Number(trainerId);
  }
  return false;
}

function renderSlot(slot) {
  const status = slot.status || (slot.is_full ? 'occupied' : 'free');
  const cls = `slot-item slot-${status}`;
  let inner = `<span class="slot-label">${escapeHtml(slot.time)}</span>`;

  if (['trainer', 'admin'].includes(currentUser.role) && slot.booked_by) {
    inner += `<span class="slot-booked-by">Rezervat de: ${escapeHtml(slot.booked_by)}</span>`;
  } else if (status === 'mine') {
    inner += `<span class="slot-booked-by">Rezervat de tine</span>`;
    if (currentUser.role === 'member') {
      inner += `<button type="button" class="btn btn-sm btn-danger slot-btn" onclick="unbookSession(${slot.session_id})">Anuleaza</button>`;
    }
  } else if (status === 'occupied') {
    inner += `<span class="slot-booked-by">${currentUser.role === 'member' ? 'Ocupat' : (slot.booked_count + '/' + slot.capacity)}</span>`;
  } else if (status === 'free' && currentUser.role === 'member') {
    inner += `<button type="button" class="btn btn-sm btn-success slot-btn" onclick="bookSlot(${slot.session_id})">Rezerva</button>`;
  } else if (['trainer', 'admin'].includes(currentUser.role)) {
    inner += `<span class="slot-booked-by">Liber (${slot.booked_count}/${slot.capacity})</span>`;
  }

  return `<div class="${cls}">${inner}</div>`;
}

async function updateSessionsRemainingUI() {
  const banner = document.getElementById('subscription-banner');
  if (!banner || currentUser.role !== 'member') return;
  const data = await refreshSessionsRemaining('sessions-remaining-fitness', 'sessions-remaining-kineto');
  if (data.success) {
    banner.classList.remove('hidden');
    if (data.current_trainer_id != null) {
      currentTrainerId = data.current_trainer_id;
    }
    const typesText = (data.allowed_types || []).join(', ') || 'niciun tip';
    const extra = document.getElementById('allowed-types-text');
    if (extra) extra.textContent = typesText;
    if (!data.has_active) {
      const fitnessEl = document.getElementById('sessions-remaining-fitness');
      const kinetoEl = document.getElementById('sessions-remaining-kineto');
      if (fitnessEl) fitnessEl.textContent = '0';
      if (kinetoEl) kinetoEl.textContent = '0';
    }
  }
}

function getIntervalTypeFilter() {
  const value = document.getElementById('filter-type')?.value || '';
  return value || 'all';
}

async function loadIntervals() {
  const container = document.getElementById('intervals-container');
  const msgEl = document.getElementById('intervals-message');
  const typeFilter = getIntervalTypeFilter();
  const params = typeFilter !== 'all' ? { type: typeFilter } : {};
  const data = await KimApi.schedule.getIntervals(params);

  if (!data.success) {
    container.innerHTML = '<p>Eroare la incarcarea intervalelor.</p>';
    return;
  }

  allowedTypes = data.allowed_types || [];

  if (data.message) {
    msgEl.textContent = data.message;
    msgEl.classList.remove('hidden');
  } else {
    msgEl.classList.add('hidden');
  }

  if (!data.days || data.days.length === 0) {
    container.innerHTML = '<p>Nu exista intervale disponibile pentru abonamentul tau.</p>';
    return;
  }

  container.innerHTML = data.days.map(day => `
    <div class="day-schedule card" style="margin-bottom:1rem">
      <h3>${escapeHtml(day.day_name)} — ${escapeHtml(day.date)}</h3>
      ${day.intervals.map(interval => `
        <div class="interval-block">
          <p class="interval-meta">
            <span class="badge badge-${interval.type}">${escapeHtml(interval.type)}</span>
            ${escapeHtml(interval.trainer_name)} |
            ${escapeHtml(interval.start_time)}–${escapeHtml(interval.end_time)}
          </p>
          <div class="slot-grid">
            ${interval.slots.map(slot => renderSlot(slot)).join('')}
          </div>
        </div>
      `).join('')}
    </div>
  `).join('');
}

async function loadSessions() {
  const type = document.getElementById('filter-type').value;
  const data = await KimApi.schedule.list(type ? { type } : {});
  const tbody = document.getElementById('sessions-body');
  if (!data.success) {
    tbody.innerHTML = '<tr><td colspan="8">Eroare incarcare</td></tr>';
    return;
  }

  listMeta = data;
  if (data.current_trainer_id != null) {
    currentTrainerId = data.current_trainer_id;
  }

  let sessions = data.sessions || [];
  if (currentUser.role === 'member' && allowedTypes.length > 0) {
    sessions = sessions.filter(s => allowedTypes.includes(s.type));
  } else if (currentUser.role === 'member' && allowedTypes.length === 0) {
    sessions = [];
  }

  const isTrainer = ['trainer', 'admin'].includes(currentUser.role);
  const colSpan = isTrainer ? 8 : 7;

  tbody.innerHTML = sessions.map(s => {
    const spots = `${s.booked_count}/${s.max_participants}`;
    let actions = '';
    if (currentUser.role === 'member') {
      actions = `<button class="btn btn-sm btn-success" onclick="bookSession(${s.id})">Rezerva</button>
                 <button class="btn btn-sm btn-danger" onclick="unbookSession(${s.id})">Anuleaza</button>`;
    }
    if (isTrainer && sessionCanModify(s, data)) {
      actions += `<button class="btn btn-sm btn-primary" onclick="editSession(${s.id})">Edit</button>
                  <button class="btn btn-sm btn-danger" onclick="cancelSession(${s.id})">Sterge</button>`;
    }
    const membersCell = isTrainer
      ? `<td id="members-${s.id}"><a href="#" onclick="showSessionMembers(${s.id});return false;">Vezi</a></td>`
      : '';
    return `<tr>
      <td>${escapeHtml(s.title)}</td>
      <td><span class="badge badge-${s.type}">${escapeHtml(s.type)}</span></td>
      <td>${escapeHtml(s.trainer_name)}</td>
      <td>${escapeHtml(s.room_name)}</td>
      <td>${escapeHtml(s.start_time)}</td>
      <td>${spots}</td>
      ${membersCell}
      <td>${actions}</td>
    </tr>`;
  }).join('') || `<tr><td colspan="${colSpan}">Nicio sedinta</td></tr>`;
}

window.showSessionMembers = async (sessionId) => {
  const data = await KimApi.schedule.get(sessionId);
  if (!data.success) return;
  const names = (data.bookings || [])
    .filter(b => b.status === 'active' || b.status === 'confirmed')
    .map(b => b.booked_by_name || b.full_name || b.email)
    .join(', ') || 'Nimeni';
  const cell = document.getElementById(`members-${sessionId}`);
  if (cell) cell.textContent = names;
};

async function loadFormOptions() {
  const [trainers, rooms] = await Promise.all([
    KimApi.resources.list('trainer_users'),
    KimApi.resources.list('rooms'),
  ]);
  document.getElementById('trainer_id').innerHTML = (trainers.data || []).map(t =>
    `<option value="${t.id}">${escapeHtml(t.full_name)}</option>`
  ).join('');
  document.getElementById('room_id').innerHTML = (rooms.data || []).map(r =>
    `<option value="${r.id}">${escapeHtml(r.name)}</option>`
  ).join('');
}

async function handleBookSuccess(data) {
  const el = document.getElementById('alert');
  if (data.success) {
    const breakdown = `Fitness+Forta: ${data.fitness_forta ?? '-'}, Kineto: ${data.kineto ?? '-'}`;
    const msg = data.message
      ? `${data.message} ${breakdown}`
      : `Rezervare confirmata. ${breakdown}`;
    showAlert(el, msg, 'success');
    await updateSessionsRemainingUI();
  } else {
    const err = data.error || 'Eroare rezervare';
    showAlert(el, err, 'error');
  }
  await loadIntervals();
  loadSessions();
}

window.bookSession = async (id) => {
  const data = await KimApi.schedule.bookWithSubscription(id);
  await handleBookSuccess(data);
};

window.bookSlot = async (sessionId) => {
  const data = await KimApi.schedule.bookWithSubscription(sessionId);
  await handleBookSuccess(data);
};

window.unbookSession = async (id) => {
  const data = await KimApi.schedule.unbook(id);
  const el = document.getElementById('alert');
  if (!data.success) {
    showAlert(el, data.message || data.error || 'Eroare la anulare.', 'error');
    return;
  }
  loadSessions();
  loadIntervals();
};

window.cancelSession = async (id) => {
  if (!confirm('Anulati sedinta?')) return;
  const data = await KimApi.schedule.cancel(id);
  const el = document.getElementById('alert');
  if (!data.success) {
    showAlert(el, data.message || data.error || 'Nu ai permisiunea să modifici această sesiune.', 'error');
    return;
  }
  loadSessions();
  loadIntervals();
};

window.editSession = async (id) => {
  const data = await KimApi.schedule.get(id);
  if (!data.success) return;
  if (!sessionCanModify(data.session, data)) {
    const el = document.getElementById('alert');
    showAlert(el, 'Nu ai permisiunea să modifici această sesiune.', 'error');
    return;
  }
  const s = data.session;
  document.getElementById('session-id').value = s.id;
  document.getElementById('title').value = s.title;
  document.getElementById('type').value = s.type;
  document.getElementById('trainer_id').value = s.trainer_id;
  document.getElementById('room_id').value = s.room_id;
  document.getElementById('start_time').value = s.start_time.replace(' ', 'T').slice(0, 16);
  document.getElementById('end_time').value = s.end_time.replace(' ', 'T').slice(0, 16);
  document.getElementById('max_participants').value = s.max_participants;
  document.getElementById('form-card').classList.remove('hidden');
  document.getElementById('form-title').textContent = 'Editare sedinta';
};

document.getElementById('session-form')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const body = {
    title: document.getElementById('title').value,
    type: document.getElementById('type').value,
    trainer_id: parseInt(document.getElementById('trainer_id').value),
    room_id: parseInt(document.getElementById('room_id').value),
    start_time: document.getElementById('start_time').value.replace('T', ' ') + ':00',
    end_time: document.getElementById('end_time').value.replace('T', ' ') + ':00',
    max_participants: parseInt(document.getElementById('max_participants').value),
  };
  const id = document.getElementById('session-id').value;
  const data = id
    ? await KimApi.schedule.update(id, body)
    : await KimApi.schedule.create(body);
  const el = document.getElementById('alert');
  showAlert(el, data.success ? 'Salvat' : (data.message || data.error || 'Eroare'), data.success ? 'success' : 'error');
  if (data.success) {
    document.getElementById('form-card').classList.add('hidden');
    loadSessions();
    loadIntervals();
  }
});

(async () => {
  currentUser = await requireAuth();
  if (!currentUser) return;
  renderNav(currentUser, kimUrl('/public/schedule/index.html'));

  const thead = document.querySelector('#sessions-table-head');
  if (thead && ['trainer', 'admin'].includes(currentUser.role)) {
    thead.innerHTML = '<tr><th>Titlu</th><th>Tip</th><th>Antrenor</th><th>Sala</th><th>Start</th><th>Locuri</th><th>Membri</th><th>Actiuni</th></tr>';
  }

  if (['trainer', 'admin'].includes(currentUser.role)) {
    const intervalsBtn = document.getElementById('btn-intervals');
    if (intervalsBtn) {
      intervalsBtn.classList.remove('hidden');
      intervalsBtn.href = kimUrl('/public/trainer/intervals.html');
    }
    const bookingsBtn = document.getElementById('btn-bookings');
    if (bookingsBtn) {
      bookingsBtn.classList.remove('hidden');
      bookingsBtn.href = kimUrl('/public/trainer/bookings.html');
    }
  }
  if (currentUser.role === 'member') {
    await updateSessionsRemainingUI();
  }
  if (['trainer', 'admin'].includes(currentUser.role)) {
    document.getElementById('btn-new').classList.remove('hidden');
    await loadFormOptions();
    if (currentUser.role === 'trainer') {
      currentTrainerId = currentUser.id;
    }
    document.getElementById('btn-new').onclick = () => {
      document.getElementById('session-form').reset();
      document.getElementById('session-id').value = '';
      document.getElementById('form-card').classList.remove('hidden');
      document.getElementById('form-title').textContent = 'Sedinta noua';
    };
  }
  document.getElementById('btn-refresh').onclick = () => {
    loadIntervals();
    loadSessions();
  };
  document.getElementById('filter-type').onchange = () => {
    loadIntervals();
    loadSessions();
  };
  document.getElementById('btn-cancel-form').onclick = () =>
    document.getElementById('form-card').classList.add('hidden');
  await loadIntervals();
  loadSessions();
})();
