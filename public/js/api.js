/**
 * Detecteaza radacina aplicatiei (ex: '' sau '/ProiectWEB') pentru XAMPP si PHP built-in server.
 */
function getKimBase() {
  if (typeof window.KIM_BASE === 'string') {
    return window.KIM_BASE;
  }
  const scripts = document.getElementsByTagName('script');
  for (let i = scripts.length - 1; i >= 0; i--) {
    const src = scripts[i].src;
    if (!src || !src.includes('api.js')) continue;
    try {
      const path = new URL(src).pathname;
      const marker = '/public/js/api.js';
      const idx = path.indexOf(marker);
      if (idx !== -1) {
        return path.slice(0, idx);
      }
    } catch (_) { /* ignore */ }
  }
  const p = window.location.pathname;
  const pub = p.indexOf('/public/');
  if (pub > 0) {
    return p.slice(0, pub);
  }
  if (p.startsWith('/public/')) {
    return '';
  }
  return '';
}

const KIM_BASE = getKimBase();
const API_BASE = `${KIM_BASE}/services`;

/** Cale in aplicatie: kimUrl('/public/index.html') */
function kimUrl(path) {
  if (!path) return KIM_BASE || '/';
  const normalized = path.startsWith('/') ? path : `/${path}`;
  return `${KIM_BASE}${normalized}`;
}

const KimApi = {
  async request(service, action, options = {}) {
    const params = new URLSearchParams({ action, ...(options.params || {}) });
    const url = `${API_BASE}/${service}.php?${params}`;
    const config = {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
    };
    if (options.body) {
      config.method = 'POST';
      config.body = JSON.stringify(options.body);
    }
    let res;
    try {
      res = await fetch(url, config);
    } catch (err) {
      return { success: false, error: `Nu se poate contacta serverul: ${url}` };
    }
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch {
      return {
        success: false,
        error: res.status === 404
          ? `Serviciu negasit (404). Verificati ca exista: ${url}`
          : `Raspuns invalid de la server (${res.status})`,
      };
    }
    if (!data.success && res.status === 401) {
      window.location.href = kimUrl('/public/auth/login.html');
    }
    return data;
  },

  user: {
    register: (body) => KimApi.request('user', 'register', { method: 'POST', body }),
    login: (body) => KimApi.request('user', 'login', { method: 'POST', body }),
    logout: () => KimApi.request('user', 'logout', { method: 'POST' }),
    me: () => KimApi.request('user', 'me'),
    profile: (body) => KimApi.request('user', 'profile', { method: 'POST', body }),
    activity: () => KimApi.request('user', 'activity'),
    list: () => KimApi.request('user', 'list'),
  },

  schedule: {
    list: (params) => KimApi.request('schedule', 'list', { params }),
    get: (id) => KimApi.request('schedule', 'get', { params: { id } }),
    create: (body) => KimApi.request('schedule', 'create', { method: 'POST', body }),
    update: (id, body) => KimApi.request('schedule', 'update', { method: 'POST', body, params: { id } }),
    cancel: (id) => KimApi.request('schedule', 'cancel', { method: 'POST', params: { id } }),
    book: (id) => KimApi.request('schedule', 'book', { method: 'POST', params: { id } }),
    bookWithSubscription: (id) => KimApi.request('schedule', 'book_with_subscription', { method: 'POST', params: { id } }),
    getIntervals: (params) => KimApi.request('schedule', 'get_intervals', { params }),
    ensureSession: (body) => KimApi.request('schedule', 'ensure_session', { method: 'POST', body }),
    trainerBookings: (date) => KimApi.request('schedule', 'trainer_bookings', { params: { date } }),
    unbook: (id) => KimApi.request('schedule', 'unbook', { method: 'POST', params: { id } }),
    deleteBooking: (bookingId) => KimApi.request('schedule', 'delete_booking', { method: 'POST', params: { booking_id: bookingId } }),
  },

  trainerSchedule: {
    list: (trainerId) => KimApi.request('trainer_schedule', 'list', { params: trainerId ? { trainer_id: trainerId } : {} }),
    saveIntervals: (body) => KimApi.request('trainer_schedule', 'save_intervals', { method: 'POST', body }),
  },

  subscription: {
    types: () => KimApi.request('subscription', 'types'),
    activate: (body) => KimApi.request('subscription', 'activate', { method: 'POST', body }),
    getRemaining: () => KimApi.request('subscription', 'get_remaining'),
    history: (userId) => KimApi.request('subscription', 'history', { params: userId ? { user_id: userId } : {} }),
    stats: () => KimApi.request('subscription', 'stats'),
    typeCreate: (body) => KimApi.request('subscription', 'type_create', { method: 'POST', body }),
    typeUpdate: (id, body) => KimApi.request('subscription', 'type_update', { method: 'POST', body, params: { id } }),
    typeDelete: (id) => KimApi.request('subscription', 'type_delete', { method: 'POST', params: { id } }),
  },

  resources: {
    list: (entity) => KimApi.request('resources', 'list', { params: { entity } }),
    listRooms: () => KimApi.request('resources', 'list_rooms'),
    listEquipment: () => KimApi.request('resources', 'list_equipment'),
    addRoom: (body) => KimApi.request('resources', 'add_room', { method: 'POST', body }),
    addEquipment: (body) => KimApi.request('resources', 'add_equipment', { method: 'POST', body }),
    create: (entity, body) => KimApi.request('resources', 'create', { method: 'POST', body, params: { entity } }),
    update: (entity, id, body) => KimApi.request('resources', 'update', { method: 'POST', body, params: { entity, id } }),
    delete: (entity, id) => KimApi.request('resources', 'delete', { method: 'POST', params: { entity, id } }),
  },

  report: {
    dashboard: () => KimApi.request('report', 'dashboard'),
    chart: (chart) => KimApi.request('report', 'chart', { params: chart ? { chart } : {} }),
    charts: () => KimApi.request('report', 'charts'),
    exportUrl: (type, format) => `${API_BASE}/report.php?action=export&type=${encodeURIComponent(type)}&format=${encodeURIComponent(format)}`,
  },

  adminUsers: {
    list: () => KimApi.request('admin_users', 'list'),
    updateRole: (body) => KimApi.request('admin_users', 'update_role', { method: 'POST', body }),
  },
};

function showAlert(el, message, type = 'error') {
  el.className = `alert alert-${type}`;
  el.textContent = message;
  el.classList.remove('hidden');
}

function escapeHtml(str) {
  const d = document.createElement('div');
  d.textContent = str ?? '';
  return d.innerHTML;
}

function formatActivityLabel(item) {
  return item.action_label || item.action || '-';
}

async function requireAuth() {
  const data = await KimApi.user.me();
  if (!data.success) {
    window.location.href = kimUrl('/public/auth/login.html');
    return null;
  }
  const user = data.user || {};
  return {
    id: user.id,
    email: user.email,
    role: user.role,
    full_name: user.full_name || '',
  };
}

/** Actualizeaza elementele UI cu sedintele ramase (fitness+forta si kineto). */
async function refreshSessionsRemaining(fitnessElementId, kinetoElementId) {
  const data = await KimApi.subscription.getRemaining();
  if (data.success) {
    const fitnessEl = fitnessElementId ? document.getElementById(fitnessElementId) : null;
    const kinetoEl = kinetoElementId ? document.getElementById(kinetoElementId) : null;
    if (fitnessEl) fitnessEl.textContent = data.fitness_forta ?? 0;
    if (kinetoEl) kinetoEl.textContent = data.kineto ?? 0;
  }
  return data;
}

function renderNav(user, active) {
  const nav = document.getElementById('main-nav');
  if (!nav) return;
  const role = user.role || 'member';
  const links = [
    { href: kimUrl('/public/index.html'), label: 'Dashboard', roles: ['member', 'trainer', 'admin'] },
    { href: kimUrl('/public/schedule/index.html'), label: 'Program', roles: ['member', 'trainer', 'admin'] },
    { href: kimUrl('/public/trainer/intervals.html'), label: 'Intervale', roles: ['trainer', 'admin'] },
    { href: kimUrl('/public/trainer/bookings.html'), label: 'Rezervari', roles: ['trainer', 'admin'] },
    { href: kimUrl('/public/subscriptions/index.html'), label: 'Abonamente', roles: ['member', 'trainer', 'admin'] },
    { href: kimUrl('/public/resources/index.html'), label: 'Resurse', roles: ['admin'] },
    { href: kimUrl('/public/admin/users.html'), label: 'Utilizatori', roles: ['admin'] },
    { href: kimUrl('/public/reports/index.html'), label: 'Rapoarte', roles: ['trainer', 'admin'] },
    { href: kimUrl('/public/profile.html'), label: 'Profil', roles: ['member', 'trainer', 'admin'] },
  ];
  const activePath = typeof active === 'string' ? active : '';
  nav.innerHTML = links
    .filter((l) => l.roles.includes(role))
    .map((l) => {
      const isActive = activePath && (activePath === l.href || activePath.endsWith(l.href.replace(kimUrl(''), '')));
      return `<a href="${l.href}" class="${isActive ? 'active' : ''}">${l.label}</a>`;
    })
    .join('') + `<a href="#" id="logout-btn">Logout</a>`;
  document.getElementById('logout-btn')?.addEventListener('click', async (e) => {
    e.preventDefault();
    await KimApi.user.logout();
    window.location.href = kimUrl('/public/auth/login.html');
  });
}
