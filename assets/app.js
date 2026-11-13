(() => {
  'use strict';

  const API = '/be';
  const PARTS = ['Paha atas', 'Dada', 'Sayap', 'Paha bawah', 'Bebas'];
  const STATUS_LABEL = { baru: 'Baru', diproses: 'Diproses', siap: 'Siap diambil', selesai: 'Selesai', batal: 'Dibatalkan' };
  const SOURCE_LABEL = { langsung: 'Langsung', whatsapp: 'WhatsApp' };
  const METHOD_LABEL = { tunai: 'Tunai', qris: 'QRIS' };

  const ICONS = {
    kasir: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="3"/><path d="M8 8h8M8 12h3M8 16h3M14 14h2v4h-2z"/></svg>',
    dapur: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 13a4 4 0 1 1 3-6.6A4 4 0 0 1 17 8a3.5 3.5 0 0 1-1 5.9V19H8v-5.1"/><path d="M8 17h8"/></svg>',
    pesanan: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4h6l1 2h3v14H5V6h3z"/><path d="M9 12h6M9 16h4"/></svg>',
    menu: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="3.5" width="7" height="7" rx="2"/><rect x="13.5" y="3.5" width="7" height="7" rx="2"/><rect x="3.5" y="13.5" width="7" height="7" rx="2"/><rect x="13.5" y="13.5" width="7" height="7" rx="2"/></svg>',
    laporan: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V4M4 20h16"/><path d="M8 16v-5M12 16V8M16 16v-3"/></svg>',
  };
  const NAV = {
    pemilik: [['kasir', 'Kasir'], ['dapur', 'Dapur'], ['pesanan', 'Pesanan'], ['menu', 'Menu'], ['laporan', 'Laporan']],
    dapur: [['dapur', 'Dapur'], ['pesanan', 'Pesanan']],
  };

  const S = {
    token: localStorage.getItem('kasir_token'),
    user: JSON.parse(localStorage.getItem('kasir_user') || 'null'),
    menu: [],
    cart: [],
    editing: null,
    view: null,
    cat: 'semua',
    source: 'langsung',
    method: 'tunai',
    paid: false,
    customer: '',
    timer: null,
  };

  // ---------- util ----------
  const $ = (sel, root = document) => root.querySelector(sel);
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const rupiah = (n) => 'Rp' + Number(n || 0).toLocaleString('id-ID');
  const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  const jam = (dt) => (dt || '').slice(11, 16);
  const tgl = (dt) => { const p = (dt || '').slice(0, 10).split('-'); return p.length === 3 ? `${p[2]}/${p[1]}` : ''; };
  const isOwner = () => S.user && S.user.role === 'pemilik';

  function toast(msg, type = '') {
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.textContent = msg;
    $('#toasts').appendChild(t);
    setTimeout(() => t.remove(), 3500);
  }

  async function api(path, { method = 'GET', body, form } = {}) {
    const headers = {};
    if (S.token) headers.Authorization = 'Bearer ' + S.token;
    let payload;
    if (form) payload = form;
    else if (body !== undefined) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body); }
    const res = await fetch(API + path, { method, headers, body: payload });
    const data = await res.json().catch(() => ({}));
    if (res.status === 401 && S.token) { logout(); throw new Error('Sesi habis, silakan login lagi'); }
    if (!res.ok) throw new Error(data.error || 'Terjadi kesalahan');
    return data;
  }

  const guard = (fn) => async (...args) => { try { await fn(...args); } catch (e) { toast(e.message, 'err'); } };

  function modal(html) {
    closeModal();
    const d = document.createElement('div');
    d.className = 'overlay';
    d.innerHTML = `<div class="modal" role="dialog" aria-modal="true">${html}</div>`;
    d.addEventListener('click', (e) => { if (e.target === d) closeModal(); });
    document.body.appendChild(d);
    const f = d.querySelector('input,select,button');
    if (f) f.focus();
  }
  function closeModal() { document.querySelectorAll('.overlay').forEach((o) => o.remove()); }
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

  // ---------- auth ----------
  function logout() {
    S.token = null; S.user = null; S.cart = []; S.editing = null;
    localStorage.removeItem('kasir_token');
    localStorage.removeItem('kasir_user');
    clearInterval(S.timer);
    renderLogin();
  }

  function renderLogin() {
    $('#app').innerHTML = `
      <div class="login">
        <form class="login-card" id="loginForm">
          <div class="brand"><div class="brand-mark">K</div>Kasir Ayam</div>
          <p>Masuk untuk mencatat dan mengelola pesanan.</p>
          <label class="field"><span>Username</span><input type="text" name="username" autocomplete="username" required></label>
          <label class="field"><span>Password</span><input type="password" name="password" autocomplete="current-password" required></label>
          <div class="err-text" id="loginErr" role="alert"></div>
          <button class="btn block" type="submit">Masuk</button>
        </form>
      </div>`;
    $('#loginForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      try {
        const r = await api('/auth/login', { method: 'POST', body: { username: fd.get('username'), password: fd.get('password') } });
        S.token = r.token; S.user = r.user;
        localStorage.setItem('kasir_token', r.token);
        localStorage.setItem('kasir_user', JSON.stringify(r.user));
        start();
      } catch (err) { $('#loginErr').textContent = err.message; }
    });
  }

  // ---------- shell ----------
  async function start() {
    if (!S.token || !S.user) return renderLogin();
    const items = NAV[S.user.role] || NAV.dapur;
    $('#app').innerHTML = `
      <div class="shell">
        <aside class="sidebar">
          <div class="brand"><div class="brand-mark">K</div>Kasir Ayam</div>
          <nav class="nav" aria-label="Menu utama">
            ${items.map(([id, label]) => `<button data-view="${id}" onclick="A.go('${id}')">${ICONS[id]}<span>${label}</span></button>`).join('')}
          </nav>
          <div class="me"><strong>${esc(S.user.name)}</strong><span>${S.user.role === 'pemilik' ? 'Pemilik' : 'Dapur'}</span><button onclick="A.logout()">Keluar</button></div>
        </aside>
        <main class="main"><button class="link logout-m" onclick="A.logout()">Keluar</button><div id="content"></div></main>
      </div>`;
    if (isOwner()) await guard(loadMenu)();
    go(items[0][0]);
  }

  async function loadMenu() { S.menu = (await api('/menu')).items; }

  async function go(view) {
    closeModal();
    clearInterval(S.timer);
    S.view = view;
    document.querySelectorAll('.nav button').forEach((b) => {
      if (b.dataset.view === view) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current');
    });
    window.scrollTo(0, 0);
    try {
      if ((view === 'kasir' || view === 'menu') && isOwner()) await loadMenu();
      ({ kasir: renderKasir, dapur: renderDapur, pesanan: renderPesanan, menu: renderMenu, laporan: renderLaporan }[view])();
    } catch (e) { toast(e.message, 'err'); }
  }

  // ---------- Kasir ----------
  const cartTotal = () => S.cart.reduce((s, l) => s + l.price * l.qty, 0);
  const cartCount = () => S.cart.reduce((s, l) => s + l.qty, 0);

  function renderKasir() {
    $('#content').innerHTML = `
      <div class="kasir">
        <section>
          <div class="page-head">
            <h1>Kasir</h1>
            <div class="chips" role="group" aria-label="Kategori menu">
              ${[['semua', 'Semua'], ['ayam', 'Ayam'], ['nasi', 'Nasi'], ['lainnya', 'Lainnya']].map(([id, l]) =>
                `<button class="chip" aria-pressed="${S.cat === id}" onclick="A.setCat('${id}')">${l}</button>`).join('')}
            </div>
          </div>
          <div class="menu-grid" id="menuGrid"></div>
        </section>
        <aside class="order-panel" id="orderPanel" aria-label="Pesanan"></aside>
      </div>
      <button class="cart-bar" id="cartBar" onclick="document.getElementById('orderPanel').scrollIntoView({behavior:'smooth'})"></button>`;
    drawGrid();
    drawPanel();
  }

  function drawGrid() {
    const list = S.menu.filter((m) => S.cat === 'semua' || m.category === S.cat);
    $('#menuGrid').innerHTML = list.length ? list.map((m) => `
      <button class="menu-card" ${m.is_available ? '' : 'disabled'} onclick="A.add(${m.id})">
        <div class="thumb">${m.photo_url ? `<img src="${esc(m.photo_url)}" alt="" loading="lazy">` : esc(m.name.charAt(0))}${m.is_available ? '' : '<span class="tag-out">Habis</span>'}</div>
        <div class="name">${esc(m.name)}</div>
        <div class="price">${rupiah(m.price)}</div>
      </button>`).join('') : '<p class="empty-state">Belum ada menu di kategori ini. Tambahkan lewat halaman Menu.</p>';
  }

  function drawPanel() {
    const el = $('#orderPanel');
    if (!el) return;
    const seg = (key, opts) => `<div class="seg" role="group">${opts.map(([v, l]) =>
      `<button aria-pressed="${S[key] === v}" onclick="A.set('${key}','${v}')">${l}</button>`).join('')}</div>`;
    const lines = S.cart.map((l, i) => `
      <div class="line">
        <div><div class="l-name">${esc(l.name)}</div>${l.part ? `<div class="l-part">${esc(l.part)}</div>` : ''}</div>
        <div class="l-sum">${rupiah(l.price * l.qty)}</div>
        <div class="stepper"><button aria-label="Kurangi" onclick="A.qty(${i},-1)">−</button><b>${l.qty}</b><button aria-label="Tambah" onclick="A.qty(${i},1)">+</button></div>
        <div></div>
      </div>`).join('');
    el.innerHTML = `
      <div class="panel-head">
        <h2>${S.editing ? 'Ubah pesanan #' + S.editing : 'Pesanan baru'}</h2>
        ${S.editing ? '<button class="link" onclick="A.cancelEdit()">Batal ubah</button>' : (S.cart.length ? '<button class="link" onclick="A.clearCart()">Kosongkan</button>' : '')}
      </div>
      ${seg('source', [['langsung', 'Langsung'], ['whatsapp', 'WhatsApp']])}
      <label class="field"><span>Nama pelanggan (boleh kosong)</span>
        <input type="text" maxlength="80" value="${esc(S.customer)}" oninput="A.setCustomer(this.value)" placeholder="Contoh: Bu Rina"></label>
      <div class="lines">${lines || '<p class="empty">Ketuk menu untuk mulai mencatat pesanan.</p>'}</div>
      ${seg('method', [['tunai', 'Tunai'], ['qris', 'QRIS']])}
      ${S.editing ? '' : `<label class="check"><input type="checkbox" ${S.paid ? 'checked' : ''} onchange="A.setPaid(this.checked)">Sudah dibayar</label>`}
      <div class="total-row"><span>Total</span><strong>${rupiah(cartTotal())}</strong></div>
      <button class="btn block" ${S.cart.length ? '' : 'disabled'} onclick="A.save()">${S.editing ? 'Simpan perubahan' : 'Simpan pesanan'}</button>`;
    const bar = $('#cartBar');
    if (bar) { bar.innerHTML = `<span>${cartCount()} item</span><span>${rupiah(cartTotal())}</span>`; bar.style.display = ''; if (!S.cart.length) bar.style.display = 'none'; }
  }

  function pushLine(m, part) {
    const found = S.cart.find((l) => l.menu_id === m.id && (l.part || '') === (part || ''));
    if (found) found.qty = Math.min(99, found.qty + 1);
    else S.cart.push({ menu_id: m.id, name: m.name, price: m.price, part: part || '', qty: 1 });
    drawPanel();
  }

  function resetCart() { S.cart = []; S.editing = null; S.customer = ''; S.paid = false; S.source = 'langsung'; S.method = 'tunai'; }

  // ---------- Dapur ----------
  async function renderDapur() {
    $('#content').innerHTML = `
      <div class="page-head"><div><h1>Dapur</h1><p class="sub">Pesanan aktif, diperbarui otomatis tiap 15 detik.</p></div>
      <button class="btn ghost small" onclick="A.refresh()">Perbarui</button></div>
      <div class="board" id="board"></div>`;
    await drawBoard();
    S.timer = setInterval(() => drawBoard().catch(() => {}), 15000);
  }

  async function drawBoard() {
    const { orders } = await api('/orders?status=aktif');
    const board = $('#board');
    if (!board) return;
    const cols = [['baru', 'Baru'], ['diproses', 'Diproses'], ['siap', 'Siap diambil']];
    board.innerHTML = cols.map(([st, label]) => {
      const list = orders.filter((o) => o.status === st).sort((a, b) => a.id - b.id);
      return `<section class="col"><h2>${label}<span class="count">${list.length}</span></h2><div class="col-body">
        ${list.length ? list.map((o) => ticket(o)).join('') : '<div class="col-empty">Tidak ada pesanan</div>'}</div></section>`;
    }).join('');
  }

  function ticket(o) {
    const who = o.customer_name ? esc(o.customer_name) : SOURCE_LABEL[o.source];
    let action = '';
    if (o.status === 'baru') action = `<button class="btn block" onclick="A.status(${o.id},'diproses')">Mulai masak</button>`;
    else if (o.status === 'diproses') action = `<button class="btn block" onclick="A.status(${o.id},'siap')">Tandai siap</button>`;
    else if (isOwner()) action = `<button class="btn block" onclick="A.status(${o.id},'selesai')">Sudah diambil</button>`;
    return `<article class="ticket">
      <div class="ticket-top"><strong>#${o.id}</strong><span>${jam(o.created_at)}, ${who}</span></div>
      <ul>${o.items.map((i) => `<li><span class="q">${i.qty}×</span><span>${esc(i.name)}${i.part ? `<small>${esc(i.part)}</small>` : ''}</span></li>`).join('')}</ul>
      ${action}
    </article>`;
  }

  // ---------- Pesanan ----------
  const F = { from: null, status: '', unpaid: false };

  function renderPesanan() {
    F.from = F.from || today();
    $('#content').innerHTML = `
      <div class="page-head"><h1>Pesanan</h1></div>
      <div class="card">
        <div class="filters">
          <label class="field"><span>Tanggal</span><input type="date" id="fFrom" value="${F.from}" onchange="A.filter()"></label>
          <label class="field"><span>Status</span><select id="fStatus" onchange="A.filter()">
            <option value="">Semua</option>${Object.keys(STATUS_LABEL).map((k) => `<option value="${k}" ${F.status === k ? 'selected' : ''}>${STATUS_LABEL[k]}</option>`).join('')}
          </select></label>
          <label class="check"><input type="checkbox" id="fUnpaid" ${F.unpaid ? 'checked' : ''} onchange="A.filter()">Hanya yang belum dibayar</label>
        </div>
        <div class="table-wrap" id="orderTable"></div>
      </div>`;
    guard(drawOrders)();
  }

  async function drawOrders() {
    const q = F.unpaid ? 'unpaid=1' : `from=${F.from}&to=${F.from}` + (F.status ? `&status=${F.status}` : '');
    const { orders } = await api('/orders?' + q);
    const box = $('#orderTable');
    if (!box) return;
    if (!orders.length) { box.innerHTML = '<p class="empty-state">Tidak ada pesanan untuk filter ini.</p>'; return; }
    box.innerHTML = `<table>
      <thead><tr><th>No</th><th>Pesanan</th><th>Pembayaran</th><th>Status</th><th class="num">Total</th>${isOwner() ? '<th></th>' : ''}</tr></thead>
      <tbody>${orders.map((o) => `<tr>
        <td><strong>#${o.id}</strong><div class="muted">${F.unpaid ? tgl(o.created_at) + ' ' : ''}${jam(o.created_at)}</div></td>
        <td>${o.items.map((i) => `${i.qty}× ${esc(i.name)}${i.part ? ' (' + esc(i.part) + ')' : ''}`).join('<br>')}
          <div class="muted">${SOURCE_LABEL[o.source]}${o.customer_name ? ', ' + esc(o.customer_name) : ''}</div></td>
        <td><span class="badge ${o.is_paid ? 'b-lunas' : 'b-belum'}">${o.is_paid ? 'Lunas' : 'Belum bayar'}</span><div class="muted">${METHOD_LABEL[o.payment_method]}</div></td>
        <td><span class="badge b-${o.status}">${STATUS_LABEL[o.status]}</span></td>
        <td class="num"><strong>${rupiah(o.total)}</strong></td>
        ${isOwner() ? `<td><div class="actions">${rowActions(o)}</div></td>` : ''}
      </tr>`).join('')}</tbody></table>`;
    S.listed = orders;
  }

  function rowActions(o) {
    if (o.status === 'batal') return '';
    let h = '';
    if (!o.is_paid) h += `<button class="btn small" onclick="A.pay(${o.id})">Tandai lunas</button>`;
    h += `<button class="btn ghost small" onclick="A.edit(${o.id})">Ubah</button>`;
    h += `<button class="btn danger small" onclick="A.cancel(${o.id})">Batalkan</button>`;
    return h;
  }

  // ---------- Menu ----------
  function renderMenu() {
    $('#content').innerHTML = `
      <div class="page-head"><div><h1>Menu</h1><p class="sub">Atur harga, ketersediaan, dan foto menu.</p></div>
      <button class="btn" onclick="A.menuForm()">Tambah menu</button></div>
      <div class="card"><div class="table-wrap"><table>
        <thead><tr><th></th><th>Nama</th><th>Kategori</th><th class="num">Harga</th><th>Status</th><th></th></tr></thead>
        <tbody>${S.menu.map((m) => `<tr>
          <td><div class="mini-thumb">${m.photo_url ? `<img src="${esc(m.photo_url)}" alt="">` : esc(m.name.charAt(0))}</div></td>
          <td><strong>${esc(m.name)}</strong>${m.has_part ? '<div class="muted">Pilih bagian ayam</div>' : ''}</td>
          <td>${esc(m.category)}</td>
          <td class="num"><strong>${rupiah(m.price)}</strong></td>
          <td><span class="badge ${m.is_available ? 'b-siap' : 'b-batal'}">${m.is_available ? 'Tersedia' : 'Habis'}</span></td>
          <td><div class="actions"><button class="btn ghost small" onclick="A.menuForm(${m.id})">Ubah</button><button class="btn danger small" onclick="A.menuHide(${m.id})">Sembunyikan</button></div></td>
        </tr>`).join('') || '<tr><td colspan="6" class="empty-state">Belum ada menu.</td></tr>'}</tbody>
      </table></div></div>`;
  }

  function menuForm(id) {
    const m = id ? S.menu.find((x) => x.id === id) : { name: '', price: '', category: 'ayam', has_part: false, is_available: true, photo_url: null };
    modal(`
      <h2>${id ? 'Ubah menu' : 'Tambah menu'}</h2>
      <form id="menuForm" style="display:flex;flex-direction:column;gap:14px">
        <label class="field"><span>Nama menu</span><input type="text" name="name" maxlength="80" required value="${esc(m.name)}"></label>
        <label class="field"><span>Harga (Rp)</span><input type="number" name="price" min="0" step="500" required value="${esc(m.price)}"></label>
        <label class="field"><span>Kategori</span><select name="category">
          ${['ayam', 'nasi', 'lainnya'].map((c) => `<option value="${c}" ${m.category === c ? 'selected' : ''}>${c}</option>`).join('')}</select></label>
        <label class="field"><span>Ketersediaan</span><select name="is_available">
          <option value="1" ${m.is_available ? 'selected' : ''}>Tersedia</option><option value="0" ${m.is_available ? '' : 'selected'}>Habis</option></select></label>
        <label class="check"><input type="checkbox" name="has_part" value="1" ${m.has_part ? 'checked' : ''}>Pelanggan memilih bagian ayam</label>
        <label class="field"><span>Foto (JPG, PNG, WebP, maksimal 2 MB)</span><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
        ${m.photo_url ? '<label class="check"><input type="checkbox" name="remove_photo" value="1">Hapus foto saat ini</label>' : ''}
        <div class="modal-actions"><button type="button" class="btn ghost" onclick="A.closeModal()">Batal</button><button class="btn" type="submit">Simpan menu</button></div>
      </form>`);
    $('#menuForm').addEventListener('submit', guard(async (e) => {
      e.preventDefault();
      const btn = e.target.querySelector('button[type=submit]');
      btn.disabled = true;
      try {
        await api(id ? `/menu/${id}` : '/menu', { method: 'POST', form: new FormData(e.target) });
        closeModal();
        toast('Menu disimpan');
        await loadMenu();
        renderMenu();
      } finally { btn.disabled = false; }
    }));
  }

  // ---------- Laporan ----------
  const R = { from: null, to: null };

  function renderLaporan() {
    R.from = R.from || today();
    R.to = R.to || today();
    $('#content').innerHTML = `
      <div class="page-head"><h1>Laporan</h1><button class="btn ghost" onclick="A.exportCsv()">Unduh untuk Excel</button></div>
      <div class="card" style="margin-bottom:18px"><div class="filters">
        <label class="field"><span>Dari</span><input type="date" id="rFrom" value="${R.from}" onchange="A.report()"></label>
        <label class="field"><span>Sampai</span><input type="date" id="rTo" value="${R.to}" onchange="A.report()"></label>
      </div></div>
      <div id="reportBody"></div>`;
    guard(drawReport)();
  }

  async function drawReport() {
    const d = await api(`/report/summary?from=${R.from}&to=${R.to}`);
    const box = $('#reportBody');
    if (!box) return;
    box.innerHTML = `
      <div class="stats">
        <div class="stat"><span>Omzet</span><strong>${rupiah(d.revenue)}</strong><small>${d.orders} pesanan</small></div>
        <div class="stat"><span>Sudah dibayar</span><strong>${rupiah(d.paid)}</strong></div>
        <div class="stat"><span>Belum dibayar</span><strong>${rupiah(d.unpaid)}</strong></div>
        <div class="stat"><span>Dibatalkan</span><strong>${d.cancelled}</strong><small>pesanan</small></div>
      </div>
      <div class="two-col">
        <div class="card"><div class="table-wrap"><table>
          <thead><tr><th>Menu terlaris</th><th class="num">Terjual</th><th class="num">Pendapatan</th></tr></thead>
          <tbody>${d.top_items.map((t) => `<tr><td>${esc(t.name)}</td><td class="num">${t.qty}</td><td class="num">${rupiah(t.subtotal)}</td></tr>`).join('') || '<tr><td colspan="3" class="empty-state">Belum ada penjualan pada rentang ini.</td></tr>'}</tbody>
        </table></div></div>
        <div class="card card-pad"><h2 style="margin-bottom:6px">Pembayaran masuk</h2>
          <div class="kv"><span>Tunai</span><strong>${rupiah(d.by_method.tunai)}</strong></div>
          <div class="kv"><span>QRIS</span><strong>${rupiah(d.by_method.qris)}</strong></div>
        </div>
      </div>`;
  }

  // ---------- aksi (dipanggil dari atribut onclick) ----------
  const A = {
    go, logout, closeModal,
    setCat(c) { S.cat = c; renderKasir(); },
    set(k, v) { S[k] = v; drawPanel(); },
    setCustomer(v) { S.customer = v; },
    setPaid(v) { S.paid = v; },
    qty(i, d) { const l = S.cart[i]; if (!l) return; l.qty = Math.min(99, l.qty + d); if (l.qty < 1) S.cart.splice(i, 1); drawPanel(); },
    clearCart() { resetCart(); drawPanel(); },
    cancelEdit() { resetCart(); drawPanel(); },
    add(id) {
      const m = S.menu.find((x) => x.id === id);
      if (!m) return;
      if (!m.has_part) return pushLine(m, '');
      modal(`<h2>Bagian ayam untuk ${esc(m.name)}</h2>
        <div class="part-grid">${PARTS.map((p, i) => `<button onclick="A.part(${id},${i})">${p}</button>`).join('')}</div>
        <div class="modal-actions"><button class="btn ghost" onclick="A.closeModal()">Batal</button></div>`);
    },
    part(id, i) { closeModal(); const m = S.menu.find((x) => x.id === id); if (m) pushLine(m, PARTS[i]); },
    save: guard(async () => {
      const body = {
        source: S.source, payment_method: S.method, customer_name: S.customer, is_paid: S.paid,
        items: S.cart.map((l) => ({ menu_id: l.menu_id, qty: l.qty, part: l.part })),
      };
      if (S.editing) {
        const id = S.editing;
        await api('/orders/' + id, { method: 'PUT', body });
        toast(`Pesanan #${id} diperbarui`);
        resetCart();
        go('pesanan');
      } else {
        const r = await api('/orders', { method: 'POST', body });
        toast(`Pesanan #${r.id} disimpan, total ${rupiah(r.total)}`);
        resetCart();
        drawPanel();
      }
    }),
    refresh: guard(drawBoard),
    status: guard(async (id, status) => {
      await api(`/orders/${id}/status`, { method: 'PATCH', body: { status } });
      if (S.view === 'dapur') await drawBoard(); else await drawOrders();
    }),
    filter() {
      F.from = $('#fFrom').value || today();
      F.status = $('#fStatus').value;
      F.unpaid = $('#fUnpaid').checked;
      guard(drawOrders)();
    },
    pay: guard(async (id) => { await api(`/orders/${id}/pay`, { method: 'POST', body: {} }); toast('Ditandai lunas'); await drawOrders(); }),
    cancel: guard(async (id) => {
      if (!confirm(`Batalkan pesanan #${id}? Tindakan ini tidak bisa diurungkan.`)) return;
      await api(`/orders/${id}/status`, { method: 'PATCH', body: { status: 'batal' } });
      toast(`Pesanan #${id} dibatalkan`);
      await drawOrders();
    }),
    edit(id) {
      const o = (S.listed || []).find((x) => x.id === id);
      if (!o) return;
      S.cart = o.items.filter((i) => i.menu_id).map((i) => ({ menu_id: i.menu_id, name: i.name, price: i.price, part: i.part || '', qty: i.qty }));
      S.editing = id; S.source = o.source; S.method = o.payment_method; S.customer = o.customer_name || '';
      go('kasir');
    },
    menuForm,
    menuHide: guard(async (id) => {
      const m = S.menu.find((x) => x.id === id);
      if (!confirm(`Sembunyikan "${m ? m.name : 'menu'}" dari daftar? Riwayat pesanan tetap tersimpan.`)) return;
      await api('/menu/' + id, { method: 'DELETE' });
      await loadMenu();
      renderMenu();
    }),
    report() { R.from = $('#rFrom').value || today(); R.to = $('#rTo').value || R.from; guard(drawReport)(); },
    exportCsv: guard(async () => {
      const res = await fetch(`${API}/report/export?from=${R.from}&to=${R.to}`, { headers: { Authorization: 'Bearer ' + S.token } });
      if (!res.ok) throw new Error('Gagal mengunduh laporan');
      const a = document.createElement('a');
      a.href = URL.createObjectURL(await res.blob());
      a.download = `laporan-${R.from}_${R.to}.csv`;
      document.body.appendChild(a); a.click(); a.remove();
      URL.revokeObjectURL(a.href);
    }),
  };
  window.A = A;

  start();
})();
