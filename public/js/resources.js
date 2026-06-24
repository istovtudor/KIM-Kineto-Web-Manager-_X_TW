let entity = 'trainers';
let isAdmin = false;

const STATUS_LABELS = {
  available: 'Bun',
  maintenance: 'In mentenanta',
  retired: 'Defect',
  bun: 'Bun',
  defect: 'Defect',
  mentenanta: 'In mentenanta',
};

function hideForms() {
  document.getElementById('room-form-card').classList.add('hidden');
  document.getElementById('equipment-form-card').classList.add('hidden');
}

function updateToolbar() {
  const addRoomBtn = document.getElementById('addRoomBtn');
  const addEquipmentBtn = document.getElementById('addEquipmentBtn');
  addRoomBtn.classList.add('hidden');
  addEquipmentBtn.classList.add('hidden');
  hideForms();
  if (!isAdmin) return;
  if (entity === 'rooms') {
    addRoomBtn.classList.remove('hidden');
  } else if (entity === 'equipment') {
    addEquipmentBtn.classList.remove('hidden');
  }
}

function renderTrainersTable(data) {
  const head = document.getElementById('table-head');
  const body = document.getElementById('table-body');
  head.innerHTML = '<tr><th>ID</th><th>Nume</th><th>Specializare</th><th>Email</th><th>Actiuni</th></tr>';
  body.innerHTML = data.map(r => `<tr>
    <td>${r.id}</td>
    <td>${escapeHtml(r.full_name)}</td>
    <td>${escapeHtml(r.specialty || '')}</td>
    <td>${escapeHtml(r.email || '')}</td>
    <td><button type="button" class="btn btn-sm btn-danger" onclick="deleteRes(${r.id})">Sterge</button></td>
  </tr>`).join('') || '<tr><td colspan="5">Niciun antrenor</td></tr>';
}

function renderRoomsTable(rooms) {
  const head = document.getElementById('table-head');
  const body = document.getElementById('table-body');
  head.innerHTML = '<tr><th>ID</th><th>Nume</th><th>Capacitate</th><th>Disponibila</th><th>Actiuni</th></tr>';
  body.innerHTML = rooms.map(r => `<tr>
    <td>${r.id}</td>
    <td>${escapeHtml(r.name)}</td>
    <td>${r.capacity}</td>
    <td>${r.available ? 'Da' : 'Nu'}</td>
    <td><button type="button" class="btn btn-sm btn-danger" onclick="deleteRes(${r.id})">Sterge</button></td>
  </tr>`).join('') || '<tr><td colspan="5">Nicio sala</td></tr>';
}

function renderEquipmentTable(items) {
  const head = document.getElementById('table-head');
  const body = document.getElementById('table-body');
  head.innerHTML = '<tr><th>ID</th><th>Nume</th><th>Stare</th><th>Sala</th><th>Actiuni</th></tr>';
  body.innerHTML = items.map(r => `<tr>
    <td>${r.id}</td>
    <td>${escapeHtml(r.name)}</td>
    <td>${escapeHtml(STATUS_LABELS[r.status] || r.status)}</td>
    <td>${escapeHtml(r.room_name || '—')}</td>
    <td><button type="button" class="btn btn-sm btn-danger" onclick="deleteRes(${r.id})">Sterge</button></td>
  </tr>`).join('') || '<tr><td colspan="5">Niciun echipament</td></tr>';
}

async function populateRoomSelect() {
  const data = await KimApi.resources.listRooms();
  const select = document.getElementById('equipment-room');
  const current = select.value;
  select.innerHTML = '<option value="">— Fara sala —</option>';
  if (data.success) {
    (data.rooms || []).filter(r => r.available).forEach(r => {
      const opt = document.createElement('option');
      opt.value = r.id;
      opt.textContent = r.name;
      select.appendChild(opt);
    });
  }
  if (current) select.value = current;
}

async function load() {
  const el = document.getElementById('alert');
  if (entity === 'trainers') {
    const data = await KimApi.resources.list('trainers');
    if (!data.success) {
      showAlert(el, data.message || data.error || 'Eroare incarcare', 'error');
      return;
    }
    renderTrainersTable(data.data || []);
    return;
  }
  if (entity === 'rooms') {
    const data = await KimApi.resources.listRooms();
    if (!data.success) {
      showAlert(el, data.message || data.error || 'Eroare incarcare', 'error');
      return;
    }
    renderRoomsTable(data.rooms || []);
    return;
  }
  const data = await KimApi.resources.listEquipment();
  if (!data.success) {
    showAlert(el, data.message || data.error || 'Eroare incarcare', 'error');
    return;
  }
  renderEquipmentTable(data.equipment || []);
}

window.deleteRes = async (id) => {
  if (!confirm('Stergeti?')) return;
  const data = await KimApi.resources.delete(entity, id);
  const el = document.getElementById('alert');
  if (!data.success) {
    showAlert(el, data.message || data.error || 'Eroare stergere', 'error');
    return;
  }
  load();
};

document.querySelectorAll('#entity-tabs button').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('#entity-tabs button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    entity = btn.dataset.entity;
    updateToolbar();
    load();
  });
});

document.getElementById('addRoomBtn')?.addEventListener('click', () => {
  hideForms();
  document.getElementById('room-form').reset();
  document.getElementById('room-available').checked = true;
  document.getElementById('room-form-card').classList.remove('hidden');
});

document.getElementById('addEquipmentBtn')?.addEventListener('click', async () => {
  hideForms();
  await populateRoomSelect();
  document.getElementById('equipment-form').reset();
  document.getElementById('equipment-form-card').classList.remove('hidden');
});

document.getElementById('room-form-cancel')?.addEventListener('click', () => {
  document.getElementById('room-form-card').classList.add('hidden');
});

document.getElementById('equipment-form-cancel')?.addEventListener('click', () => {
  document.getElementById('equipment-form-card').classList.add('hidden');
});

document.getElementById('room-form')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const el = document.getElementById('alert');
  const data = await KimApi.resources.addRoom({
    name: document.getElementById('room-name').value.trim(),
    capacity: parseInt(document.getElementById('room-capacity').value, 10),
    available: document.getElementById('room-available').checked ? 1 : 0,
  });
  if (data.success) {
    showAlert(el, 'Sala adaugata.', 'success');
    document.getElementById('room-form-card').classList.add('hidden');
    load();
  } else {
    showAlert(el, data.message || data.error || 'Eroare salvare', 'error');
  }
});

document.getElementById('equipment-form')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const el = document.getElementById('alert');
  const roomVal = document.getElementById('equipment-room').value;
  const data = await KimApi.resources.addEquipment({
    name: document.getElementById('equipment-name').value.trim(),
    status: document.getElementById('equipment-status').value,
    room_id: roomVal ? parseInt(roomVal, 10) : null,
  });
  if (data.success) {
    showAlert(el, 'Echipament adaugat.', 'success');
    document.getElementById('equipment-form-card').classList.add('hidden');
    load();
  } else {
    showAlert(el, data.message || data.error || 'Eroare salvare', 'error');
  }
});

(async () => {
  const user = await requireAuth();
  if (!user || user.role !== 'admin') {
    window.location.href = kimUrl('/public/index.html');
    return;
  }
  isAdmin = true;
  renderNav({ role: user.role }, kimUrl('/public/resources/index.html'));
  updateToolbar();
  await load();
})();
