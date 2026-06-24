let currentUser = null;

async function deleteBooking(bookingId) {
  if (!bookingId) return;
  if (!confirm('Stergi aceasta rezervare?')) return;
  const el = document.getElementById('alert');
  const data = await KimApi.schedule.deleteBooking(bookingId);
  if (data.success) {
    showAlert(el, 'Rezervare stearsa.', 'success');
    loadBookings();
  } else {
    showAlert(el, data.message || data.error || 'Nu ai permisiunea să ștergi această rezervare.', 'error');
  }
}

async function loadBookings() {
  const date = document.getElementById('booking-date').value;
  if (!date) return;
  const data = await KimApi.schedule.trainerBookings(date);
  const tbody = document.getElementById('bookings-body');
  if (!data.success) {
    tbody.innerHTML = '<tr><td colspan="6">Eroare incarcare</td></tr>';
    return;
  }

  tbody.innerHTML = (data.bookings || []).map(b => {
    const time = (b.start_time || '').slice(11, 16);
    const deleteBtn = b.can_delete === true
      ? `<button type="button" class="btn btn-sm btn-danger" onclick="deleteBooking(${Number(b.booking_id)})">Sterge</button>`
      : '';
    return `<tr>
      <td>${escapeHtml(time)}</td>
      <td><span class="badge badge-${b.type}">${escapeHtml(b.type)}</span></td>
      <td>${escapeHtml(b.trainer_name)}</td>
      <td>${escapeHtml(b.title)}</td>
      <td>${escapeHtml(b.member_name)}</td>
      <td>${deleteBtn}</td>
    </tr>`;
  }).join('') || '<tr><td colspan="6">Nicio rezervare in aceasta zi</td></tr>';
}

(async () => {
  currentUser = await requireAuth();
  if (!currentUser || !['trainer', 'admin'].includes(currentUser.role)) {
    window.location.href = kimUrl('/public/index.html');
    return;
  }
  renderNav(currentUser, kimUrl('/public/trainer/bookings.html'));
  document.getElementById('booking-date').value = new Date().toISOString().slice(0, 10);
  document.getElementById('btn-load').onclick = loadBookings;
  loadBookings();
})();

window.deleteBooking = deleteBooking;
