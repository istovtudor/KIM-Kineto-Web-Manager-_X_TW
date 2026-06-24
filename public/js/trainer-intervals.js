const DAY_NAMES = ['Duminica', 'Luni', 'Marti', 'Miercuri', 'Joi', 'Vineri', 'Sambata'];
let pendingIntervals = [];
let currentTrainerId = null;

function renderPending() {
  const tbody = document.getElementById('pending-body');
  tbody.innerHTML = pendingIntervals.map((row, idx) => `
    <tr>
      <td>${DAY_NAMES[row.day_of_week]}</td>
      <td>${escapeHtml(row.start_time)} - ${escapeHtml(row.end_time)}</td>
      <td><span class="badge badge-${row.type}">${escapeHtml(row.type)}</span></td>
      <td>${row.capacity}</td>
      <td><button type="button" class="btn btn-sm btn-danger" onclick="removePending(${idx})">Sterge</button></td>
    </tr>
  `).join('') || '<tr><td colspan="5">Nicio intrare — adauga intervale mai sus, apoi salveaza.</td></tr>';
}

function renderSaved(intervals) {
  document.getElementById('saved-body').innerHTML = (intervals || []).map(row => `
    <tr>
      <td>${DAY_NAMES[row.day_of_week]}</td>
      <td>${escapeHtml(row.start_time?.slice(0, 5))} - ${escapeHtml(row.end_time?.slice(0, 5))}</td>
      <td><span class="badge badge-${row.type}">${escapeHtml(row.type)}</span></td>
      <td>${row.capacity}</td>
    </tr>
  `).join('') || '<tr><td colspan="4">Niciun interval salvat inca.</td></tr>';
}

window.removePending = (idx) => {
  pendingIntervals.splice(idx, 1);
  renderPending();
};

document.getElementById('interval-form').addEventListener('submit', (e) => {
  e.preventDefault();
  const start = document.getElementById('start_time').value;
  const end = document.getElementById('end_time').value;
  if (!start || !end) {
    showAlert(document.getElementById('alert'), 'Completeaza ora de start si sfarsit.');
    return;
  }
  if (start >= end) {
    showAlert(document.getElementById('alert'), 'Ora de sfarsit trebuie sa fie dupa ora de start.');
    return;
  }
  pendingIntervals.push({
    day_of_week: parseInt(document.getElementById('day_of_week').value, 10),
    start_time: start.length === 5 ? start : start.slice(0, 5),
    end_time: end.length === 5 ? end : end.slice(0, 5),
    type: document.getElementById('type').value,
    capacity: parseInt(document.getElementById('capacity').value, 10) || 10,
  });
  renderPending();
  document.getElementById('alert').classList.add('hidden');
  document.getElementById('start_time').value = '08:00';
  document.getElementById('end_time').value = '12:00';
  document.getElementById('capacity').value = '10';
});

document.getElementById('btn-save-all').addEventListener('click', async () => {
  const el = document.getElementById('alert');
  const btn = document.getElementById('btn-save-all');

  if (pendingIntervals.length === 0) {
    if (!confirm('Lista este goala. Salvez ca sa sterg toate intervalele existente?')) {
      return;
    }
  }

  btn.disabled = true;
  btn.textContent = 'Se salveaza...';

  try {
    const body = { intervals: pendingIntervals };
    if (currentTrainerId) {
      body.trainer_id = currentTrainerId;
    }
    const data = await KimApi.trainerSchedule.saveIntervals(body);
    if (data.success) {
      showAlert(el, `Salvate ${data.saved} intervale.`, 'success');
      const list = await KimApi.trainerSchedule.list(currentTrainerId || undefined);
      if (list.success) {
        currentTrainerId = list.trainer_id || currentTrainerId;
        pendingIntervals = (list.intervals || []).map(i => ({
          day_of_week: parseInt(i.day_of_week, 10),
          start_time: i.start_time?.slice(0, 5),
          end_time: i.end_time?.slice(0, 5),
          type: i.type,
          capacity: parseInt(i.capacity, 10),
        }));
        renderPending();
        renderSaved(list.intervals);
      }
    } else {
      showAlert(el, data.error || 'Eroare salvare');
    }
  } catch (err) {
    showAlert(el, err.message || 'Eroare de retea');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Salveaza toate intervalele';
  }
});

async function loadIntervals() {
  const el = document.getElementById('alert');
  const data = await KimApi.trainerSchedule.list(currentTrainerId || undefined);
  if (!data.success) {
    showAlert(el, data.error || 'Nu s-au putut incarca intervalele.');
    return;
  }
  currentTrainerId = data.trainer_id || null;
  pendingIntervals = (data.intervals || []).map(i => ({
    day_of_week: parseInt(i.day_of_week, 10),
    start_time: i.start_time?.slice(0, 5),
    end_time: i.end_time?.slice(0, 5),
    type: i.type,
    capacity: parseInt(i.capacity, 10),
  }));
  renderPending();
  renderSaved(data.intervals);
}

(async () => {
  const user = await requireAuth();
  if (!user) return;

  if (!['trainer', 'admin'].includes(user.role)) {
    window.location.href = kimUrl('/public/index.html');
    return;
  }

  renderNav(user, kimUrl('/public/trainer/intervals.html'));

  if (user.role === 'admin') {
    const adminBox = document.getElementById('admin-trainer-select');
    if (adminBox) {
      adminBox.classList.remove('hidden');
      const res = await KimApi.resources.list('trainer_users');
      const sel = document.getElementById('trainer_id');
      sel.innerHTML = (res.data || []).map(t =>
        `<option value="${t.id}">${escapeHtml(t.full_name)}</option>`
      ).join('');
      sel.onchange = async () => {
        currentTrainerId = parseInt(sel.value, 10);
        await loadIntervals();
      };
      if (res.data?.length) {
        currentTrainerId = parseInt(res.data[0].id, 10);
      }
    }
  } else if (user.role === 'trainer') {
    currentTrainerId = user.id;
  }

  await loadIntervals();
})();
