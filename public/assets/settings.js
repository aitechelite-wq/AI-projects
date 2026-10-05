/* Local café configuration. No external services or assets are used. */
(() => {
  'use strict';

  const tabs = [
    ['branding', 'Café identity'], ['payments', 'Payments'], ['printers', 'Printers'],
    ['team', 'Team'], ['audit', 'Activity log'], ['backups', 'Backups']
  ];
  let selected = 'branding';
  let current = {};
  let tokenBusy = false;
  const pos = () => window.POS;
  const esc = value => pos().esc(value == null ? '' : String(value));
  const checked = value => value === true || value === 1 || value === '1' || value === 'true';
  const isOwner = () => pos().user && pos().user.role === 'owner';
  const button = (label, attributes = '', type = 'secondary') => `<button type="button" class="button btn btn-${type}" ${attributes}>${label}</button>`;
  const badge = (text, type = '') => `<span class="badge ${type}">${esc(text)}</span>`;
  const date = value => {
    if (!value) return '—';
    if (pos().date) return pos().date(value);
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? esc(value) : esc(parsed.toLocaleString('en-PK', { dateStyle: 'medium', timeStyle: 'short' }));
  };
  const empty = (title, description) => `<div class="empty-state"><h3>${esc(title)}</h3><p>${esc(description)}</p></div>`;
  const field = (label, input, help = '') => `<div class="field"><label>${label}${input}</label>${help ? `<small class="muted">${help}</small>` : ''}</div>`;
  const input = (name, value, extra = '') => `<input name="${name}" value="${esc(value)}" ${extra}>`;
  const asset = value => {
    if (!value) return '';
    const text = String(value);
    if (/^\/?\?asset=[a-zA-Z0-9_.%\-]+$/.test(text)) return text;
    return `?asset=${encodeURIComponent(text.split(/[\\/]/).pop())}`;
  };
  const panel = (title, description, body, actions = '') => `<section class="panel settings-panel"><div class="panel-head panel-header"><div><h2>${title}</h2>${description ? `<p class="muted">${description}</p>` : ''}</div>${actions}</div>${body}</section>`;

  function branding() {
    const s = current.settings;
    const logo = asset(s.logo_path || s.logo);
    const currencyCodes = Array.from(new Set([s.currency || 'PKR', 'PKR', 'USD', 'AED', 'GBP', 'EUR', 'INR']));
    return `<div class="settings-grid">
      ${panel('Your café, your brand', 'Your identity appears on the app, order slips and customer invoices.', `
        <form id="branding-form" class="settings-form">
          <div class="form-grid">
            ${field('Café name', input('cafe_name', s.cafe_name || 'Ember Café', 'required maxlength="200" autocomplete="organization"'))}
            ${field('Contact number', input('phone', s.phone || '', 'maxlength="200" type="tel" autocomplete="tel"'))}
            <div class="field field-full"><label>Address / location<textarea name="address" rows="2" maxlength="1000" autocomplete="street-address">${esc(s.address || '')}</textarea></label></div>
            <div class="field field-full"><label>Receipt footer<textarea name="footer" rows="2" maxlength="1000">${esc(s.footer ?? 'Thank you for visiting. See you again soon!')}</textarea></label><small class="muted">A thank-you message or a short returns policy. Leave blank to omit it.</small></div>
            ${field('Currency', `<select name="currency" ${checked(s.currency_locked) ? 'disabled' : ''}>${currencyCodes.map(code => `<option value="${esc(code)}" ${s.currency === code || (!s.currency && code === 'PKR') ? 'selected' : ''}>${esc(code)}</option>`).join('')}</select>`, checked(s.currency_locked) ? 'Currency is locked because prices, stock costs or transactions already exist.' : 'Choose before adding prices or transactions. Currency locks once monetary records exist.')}
            ${field('Local timezone', input('timezone', s.timezone || 'Asia/Karachi', 'required maxlength="80"'), 'Use an IANA timezone, such as Asia/Karachi.')}
            ${field('Brand colour', input('accent', s.accent || '#167d74', 'type="color" class="colour-input"'), 'Used for buttons and highlights.')}
            ${field('Receipt paper', `<select name="receipt_width"><option value="80" ${String(s.receipt_width) !== '58' ? 'selected' : ''}>80 mm</option><option value="58" ${String(s.receipt_width) === '58' ? 'selected' : ''}>58 mm</option></select>`, 'Match your thermal printer roll.')}
          </div>
          <div class="settings-subsection"><h3>Tax defaults</h3><p class="muted">New orders use these defaults. You can choose a tax-free bill at the till; menu items can carry their own tax rates.</p>
            <label class="checkbox-row"><input type="checkbox" name="tax_enabled" ${checked(s.tax_enabled) ? 'checked' : ''}> Enable tax by default</label>
            ${field('Default item tax rate (%)', input('default_tax_rate', s.default_tax_rate || 0, 'type="number" min="0" max="100" step="0.01" required'))}
          </div>
          <div class="form-actions"><button class="button btn btn-primary" type="submit">Save café settings</button></div>
        </form>`)}
      <div class="settings-side">
        ${panel('Logo', 'PNG, JPG or WebP. Maximum 2 MB.', `<div class="brand-logo-preview">${logo ? `<img src="${esc(logo)}" alt="Current café logo">` : '<span class="brand-logo-placeholder" aria-label="No logo uploaded">☕</span>'}</div><p class="muted">The app and browser receipts display your logo. Automatic thermal printing uses your café name and address as text.</p>
          <form id="logo-form" class="settings-form"><div class="field"><label>Upload or replace logo<input type="file" name="file" accept="image/png,image/jpeg,image/webp" required></label></div><div class="button-row"><button type="submit" class="button btn btn-secondary">Upload logo</button>${logo ? button('Remove logo', 'data-remove-logo') : ''}</div></form>`)}
        ${panel('Receipt preview', 'A quick look at your printed identity.', `<div class="receipt-preview">${logo ? `<img src="${esc(logo)}" alt="" class="receipt-logo">` : ''}<strong id="brand-preview-name">${esc(s.cafe_name || 'Ember Café')}</strong><span id="brand-preview-address">${esc(s.address || 'Your café address')}</span><span id="brand-preview-phone">${esc(s.phone || '')}</span><div class="receipt-rule"></div><span>Customer invoice</span><div class="receipt-rule"></div><small id="brand-preview-footer">${esc(s.footer ?? 'Thank you for visiting. See you again soon!')}</small></div>`)}
      </div>
    </div>`;
  }

  function payments() {
    const accounts = current.accounts;
    return panel('Payment accounts', 'Accept cash, Easypaisa, JazzCash and bank transfers. Add as many receiving accounts as you need.', `
      <div class="notice"><strong>Confirm every QR payment before recording it.</strong> This app works offline. A QR scan opens your bank or wallet payment flow; verify receipt on your own account and record the transaction reference at checkout.</div>
      ${accounts.length ? `<div class="payment-account-grid">${accounts.map(account => `<article class="payment-account-card">
        <div class="panel-head panel-header"><div><h3>${esc(account.name)}</h3><span class="muted">${esc(methodName(account.method))}</span></div>${badge(checked(account.active) ? 'Active' : 'Inactive', checked(account.active) ? 'badge-success' : 'badge-neutral')}</div>
        <p class="account-details">${esc(account.details || 'No account details added')}</p>
        ${account.qr_path ? `<div class="account-qr"><img src="${esc(asset(account.qr_path))}" alt="Payment QR for ${esc(account.name)}" loading="lazy"></div>` : '<p class="muted">No QR image uploaded</p>'}
        <div class="button-row">${button('Edit account', `data-account-edit="${account.id}"`)}${button(account.qr_path ? 'Replace QR' : 'Upload QR', `data-account-qr="${account.id}"`)}</div>
      </article>`).join('')}</div>` : empty('No receiving accounts yet', 'Add your wallet or bank details and upload its official payment QR image.')}
    `, button('+ Add account', 'data-account-add', 'primary'));
  }

  const methodName = value => ({ easypaisa: 'Easypaisa', jazzcash: 'JazzCash', bank: 'Bank transfer', cash: 'Cash' }[value] || value);

  function printerFields(kind, label) {
    const s = current.settings;
    const config = current.printConfig && current.printConfig[kind] || {};
    const transport = config.transport || s[`${kind}_transport`] || 'windows';
    return `<fieldset class="printer-fieldset"><legend>${label}</legend><p class="muted">${kind === 'kitchen' ? 'Kitchen order tickets for preparation.' : 'Final invoices after an order is fully paid.'}</p><div class="form-grid">
      ${field('Connection', `<select name="${kind}_transport" data-transport="${kind}"><option value="windows" ${transport !== 'network' ? 'selected' : ''}>Windows printer / USB</option><option value="network" ${transport === 'network' ? 'selected' : ''}>LAN / network ESC/POS</option></select>`)}
      ${field('Windows printer name', input(`${kind}_printer`, config.name || s[`${kind}_printer`] || '', `maxlength="160" placeholder="e.g. ${kind === 'kitchen' ? 'Kitchen' : 'Customer'} Thermal"`), 'Use the exact name from Windows Printers & scanners.')}
      ${field('LAN printer address', input(`${kind}_host`, config.host || s[`${kind}_host`] || '', 'maxlength="253" placeholder="e.g. 192.168.1.50"'), 'For a network printer, normally TCP port 9100.')}
    </div></fieldset>`;
  }

  function printers() {
    const config = current.printConfig || {};
    const jobs = current.jobs;
    return `<div class="settings-grid">
      <div>${panel('Two printers. One smooth workflow.', 'Send a KOT to the kitchen and the paid invoice to the customer printer.', `
        <div class="notice">Use printers that support ESC/POS, install their Windows drivers, and run the local print worker. Print a test KOT and customer invoice on the correct printers before service.</div>
        <form id="printer-form" class="settings-form">${printerFields('kitchen', 'Kitchen printer')}${printerFields('customer', 'Customer printer')}<div class="form-actions"><button type="submit" class="button btn btn-primary">Save printer routing</button></div></form>`)}
        ${panel('Print queue', '“Printed” means submitted to the printer; check physical output. Failed or uncertain jobs require a deliberate retry.', jobs.length ? `<div class="table-wrap"><table class="data-table"><thead><tr><th>Job</th><th>Document</th><th>Status</th><th>Created</th><th>Action</th></tr></thead><tbody>${jobs.map(job => `<tr><td>#${esc(job.id)}<small class="muted">${esc(job.order_number || (job.order_id ? `Order #${job.order_id}` : ''))}</small></td><td>${esc(job.kind === 'kot' ? 'Kitchen ticket' : job.kind === 'invoice' ? 'Customer invoice' : job.kind)}</td><td>${badge(job.status, job.status === 'printed' ? 'badge-success' : ['failed', 'uncertain'].includes(job.status) ? 'badge-danger' : 'badge-neutral')}${job.error ? `<small class="text-danger">${esc(job.error)}</small>` : ''}</td><td>${date(job.created_at)}</td><td>${['failed', 'uncertain'].includes(job.status) ? button('Retry', `data-print-retry="${job.id}"`) : '<span class="muted">—</span>'}</td></tr>`).join('')}</tbody></table></div>` : empty('Print queue is clear', 'Kitchen tickets and paid invoices will appear here.'), button('Refresh', 'data-settings-refresh'))}
      </div>
      <div class="settings-side">${panel('Local print worker', 'A private key connects the Windows worker to this app.', `
        <p id="worker-key-status">${badge(config.token_present ? 'Worker key configured' : 'Worker key needed', config.token_present ? 'badge-success' : 'badge-neutral')}</p>
        <ol class="setup-steps"><li>Install Python 3 on your Windows laptop.</li><li>Configure both printer routes here.</li><li>Generate a worker key and start <code>tools/print_worker.py</code> using the instructions in <code>docs/PRINTING.md</code>.</li><li>Print a small test order on each physical printer.</li></ol>
        <p class="muted">Keep the worker running while the café is open. Manual print views remain available from an order.</p>
        ${isOwner() ? button(config.token_present ? 'Replace worker key' : 'Generate worker key', 'data-worker-token', 'primary') : '<p class="muted">The owner can generate or replace the worker key.</p>'}
        <div id="worker-token-result" aria-live="polite"></div>`)}
      </div>
    </div>`;
  }

  function team() {
    if (!isOwner()) return panel('Team access', '', empty('Owner access required', 'Only the café owner can add staff or change their access.'));
    return panel('A login for each team member', 'Staff actions are linked to their individual login. Deactivate an account when someone leaves.', `
      <div class="role-guide"><div><strong>Owner</strong><p class="muted">Full access, staff management and print worker keys.</p></div><div><strong>Manager</strong><p class="muted">Orders, menu, stock, reports and café settings.</p></div><div><strong>Cashier</strong><p class="muted">Till, orders, kitchen and cash shifts.</p></div></div>
      <div class="table-wrap"><table class="data-table"><thead><tr><th>Team member</th><th>Username</th><th>Role</th><th>Status</th><th>Action</th></tr></thead><tbody>${current.users.map(user => `<tr><td><strong>${esc(user.name)}</strong>${Number(user.id) === Number(pos().user.id) ? ' <span class="muted">(you)</span>' : ''}</td><td>${esc(user.username)}</td><td>${badge(user.role, 'badge-neutral')}</td><td>${badge(checked(user.active) ? 'Active' : 'Inactive', checked(user.active) ? 'badge-success' : 'badge-neutral')}</td><td>${button('Edit', `data-user-edit="${user.id}"`)}</td></tr>`).join('')}</tbody></table></div>
    `, button('+ Add team member', 'data-user-add', 'primary'));
  }

  function audit() {
    return panel('Activity log', 'A record of sensitive changes, stock movements, order actions and payments.', current.audit.length ? `<div class="table-wrap"><table class="data-table"><thead><tr><th>When</th><th>Team member</th><th>Action</th><th>Details</th></tr></thead><tbody>${current.audit.map(row => `<tr><td>${date(row.created_at)}</td><td>${esc(row.actor_name || row.user_name || row.username || (row.user_id ? `User #${row.user_id}` : 'System'))}</td><td><strong>${esc(String(row.action || row.event || '').replace(/[._]/g, ' '))}</strong></td><td class="audit-details">${esc(typeof row.details === 'object' ? JSON.stringify(row.details) : row.details || row.description || '—')}</td></tr>`).join('')}</tbody></table></div>` : empty('No activity recorded yet', 'New activity will appear as you operate the café.'), button('Refresh', 'data-settings-refresh'));
  }

  function backups() {
    return `<div class="settings-grid">${panel('Protect your café records', 'Download a consistent database snapshot without interrupting the till.', `
      <div class="backup-hero"><div class="backup-icon" aria-hidden="true">↧</div><div><h3>Your orders, stock and accounts</h3><p class="muted">The backup includes business records, café settings and staff accounts. Uploaded logo and QR image files should be copied separately from the data directory.</p></div></div>
      <a href="?api=backup" class="button btn btn-primary" download>Download database backup</a>
      <p class="muted">Save a copy at the end of each business day, preferably on a separate USB drive. Keep backups private.</p>`)}
      <div class="settings-side">${panel('Restoring a backup', '', `<ol class="setup-steps"><li>Close the POS server and stop the print worker.</li><li>Keep a copy of the current <code>data/cafe.sqlite</code> and uploaded files.</li><li>Follow the backup and restore steps in the project README before replacing the database.</li><li>Restart the POS, log in and check recent orders and stock totals.</li><li>Generate a new print worker key in Printers and update the worker before restarting it.</li></ol><p class="muted">Restore is performed on the laptop, with the application stopped. Worker keys are excluded from downloaded backups.</p>`)}</div>
    </div>`;
  }

  async function render() {
    if (selected === 'team' && !isOwner()) selected = 'branding';
    current.settings = await pos().api('settings');
    if (selected === 'payments') current.accounts = await pos().api('accounts');
    if (selected === 'printers') {
      const [config, jobs] = await Promise.all([pos().api('print/config'), pos().api('print/jobs')]);
      current.printConfig = config; current.jobs = Array.isArray(jobs) ? jobs : jobs.jobs || [];
    }
    if (selected === 'team') current.users = await pos().api('users');
    if (selected === 'audit') current.audit = await pos().api('audit');
    const views = { branding, payments, printers, team, audit, backups };
    const visibleTabs = tabs.filter(([id]) => id !== 'team' || isOwner());
    return `<div class="page-header"><div><div class="eyebrow">MAKE IT YOURS</div><h1>Café settings</h1><p class="muted">Your brand, your team and your day-to-day preferences.</p></div>${badge('Stored on this laptop', 'badge-neutral')}</div>
      <div class="settings-tabs" role="tablist" aria-label="Café settings">${visibleTabs.map(([id, label]) => `<button type="button" role="tab" id="settings-tab-${id}" class="setting-tab ${selected === id ? 'active' : ''}" aria-selected="${selected === id}" aria-controls="settings-tabpanel" tabindex="${selected === id ? '0' : '-1'}" data-settings-tab="${id}">${label}</button>`).join('')}</div>
      <div id="settings-tabpanel" role="tabpanel" aria-labelledby="settings-tab-${selected}">${views[selected]()}</div>`;
  }

  async function saveForm(form, route, data, message) {
    const submit = form.querySelector('[type="submit"]');
    if (submit) submit.disabled = true;
    try {
      await pos().api(route, data);
      if (route === 'settings' && pos().session) pos().session.settings = { ...pos().session.settings, ...data };
      pos().notify(message);
      await pos().refresh();
    } catch (error) { pos().notify(error.message, true); }
    finally { if (submit) submit.disabled = false; }
  }

  function accountModal(id) {
    const account = id ? current.accounts.find(row => Number(row.id) === Number(id)) : null;
    pos().openModal(account ? 'Edit receiving account' : 'Add receiving account', `
      <div class="form-grid">
        ${field('Account label', input('name', account && account.name || '', 'required maxlength="120" placeholder="e.g. Café JazzCash"'))}
        ${field('Payment method', `<select name="method">${['easypaisa', 'jazzcash', 'bank'].map(method => `<option value="${method}" ${account && account.method === method ? 'selected' : ''}>${methodName(method)}</option>`).join('')}</select>`)}
        <div class="field field-full"><label>Account details<textarea name="details" rows="3" maxlength="1000" placeholder="Account holder, mobile / account number, bank or IBAN">${esc(account && account.details || '')}</textarea></label></div>
      </div>
      <label class="checkbox-row"><input type="checkbox" name="active" ${!account || checked(account.active) ? 'checked' : ''}> Available at checkout</label>
      <p class="muted">Upload the account’s official payment QR image after saving. Payments are confirmed manually.</p>`, async data => {
        await pos().api('accounts', { ...(account ? { id: account.id } : {}), name: data.get('name'), method: data.get('method'), details: data.get('details'), active: data.has('active') });
        pos().notify('Payment account saved.');
        await pos().refresh();
      });
  }

  function qrModal(id) {
    const account = current.accounts.find(row => Number(row.id) === Number(id));
    pos().openModal(`Payment QR · ${account.name}`, `<p class="muted">Use the official receiving QR provided by your bank or wallet. PNG, JPG or WebP, up to 2 MB.</p>${field('QR image', '<input name="file" type="file" accept="image/png,image/jpeg,image/webp" required>')}`, async data => {
      validateFile(data.get('file'));
      data.set('kind', 'qr'); data.set('account_id', String(id));
      await pos().upload(data);
      pos().notify('Payment QR uploaded.');
      await pos().refresh();
    }, 'Upload QR');
  }

  function userModal(id) {
    const user = id ? current.users.find(row => Number(row.id) === Number(id)) : null;
    const owner = user && user.role === 'owner';
    const self = user && Number(user.id) === Number(pos().user.id);
    pos().openModal(user ? 'Edit team member' : 'Add team member', `<div class="form-grid">
      ${field('Full name', input('name', user && user.name || '', 'required maxlength="120" autocomplete="name"'))}
      ${field('Username', input('username', user && user.username || '', 'required minlength="3" maxlength="40" autocomplete="off" pattern="[A-Za-z0-9_.\\-]{3,40}"'), '3–40 letters, numbers, dots, underscores or hyphens.')}
      ${field('Role', `<select name="role" ${owner ? 'disabled' : ''}>${owner ? '<option value="owner">Owner</option>' : ['cashier', 'manager'].map(role => `<option value="${role}" ${user && user.role === role ? 'selected' : ''}>${role[0].toUpperCase() + role.slice(1)}</option>`).join('')}</select>`)}
      ${field(user ? 'New password (optional)' : 'Password', '<input name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" ' + (user ? '' : 'required') + '>', user ? 'Leave blank to keep the current password. Otherwise use at least 10 characters (maximum 72).' : 'Use at least 10 characters (maximum 72). Give each person their own login.')}
    </div><label class="checkbox-row"><input name="active" type="checkbox" ${!user || checked(user.active) ? 'checked' : ''} ${self ? 'disabled' : ''}> Account active</label>${self ? '<p class="muted">Your current account remains active.</p>' : ''}`, async data => {
      const payload = { ...(user ? { id: user.id } : {}), name: data.get('name'), username: data.get('username'), role: owner ? 'owner' : data.get('role'), active: self || data.has('active') };
      if (data.get('password')) {
        const password = String(data.get('password'));
        if (new TextEncoder().encode(password).length > 72) throw new Error('This password is too long. Try fewer characters, especially if using emoji or non-English letters.');
        payload.password = password;
      }
      await pos().api('users', payload);
      pos().notify('Team member saved.');
      await pos().refresh();
    });
  }

  function validateFile(file) {
    if (!file || !file.size) throw new Error('Choose an image to upload.');
    if (file.size > 2 * 1024 * 1024) throw new Error('Please choose an image smaller than 2 MB.');
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) throw new Error('Use a PNG, JPG or WebP image.');
  }

  function generateToken() {
    const run = async () => {
      if (tokenBusy) return;
      tokenBusy = true;
      const trigger = document.querySelector('[data-worker-token]');
      if (trigger) trigger.disabled = true;
      try {
        const result = await pos().api('print/config', {});
        const target = document.querySelector('#worker-token-result');
        if (!target) return;
        target.innerHTML = `<div class="notice worker-token"><strong>Copy this key now</strong><p class="muted">It is shown once. Store it privately in the print worker’s local configuration.</p><label>Worker key<input type="text" id="worker-token" readonly value="${esc(result.token)}" autocomplete="off" spellcheck="false"></label><div class="button-row">${button('Copy key', 'data-copy-worker-token')}${button('Hide key', 'data-hide-worker-token')}</div></div>`;
        current.printConfig.token_present = true;
        const status = document.querySelector('#worker-key-status');
        if (status) status.innerHTML = badge('Worker key configured', 'badge-success');
        const action = document.querySelector('[data-worker-token]');
        if (action) action.textContent = 'Replace worker key';
        target.querySelector('input').focus();
        target.querySelector('input').select();
        target.querySelector('[data-hide-worker-token]').addEventListener('click', () => {
          const field = target.querySelector('#worker-token'); if (field) field.value = '';
          target.replaceChildren();
        });
        target.querySelector('[data-copy-worker-token]').addEventListener('click', async () => {
          const field = target.querySelector('#worker-token');
          if (!field) return;
          try {
            if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(field.value);
            else { field.select(); if (!document.execCommand('copy')) throw new Error('Copy unavailable'); }
            pos().notify('Worker key copied.');
          } catch (_) { field.focus(); field.select(); pos().notify('Press Ctrl+C to copy the selected key.'); }
        });
        pos().notify('Worker key generated. Copy it before leaving this tab.');
      } finally {
        tokenBusy = false;
        if (trigger && trigger.isConnected) trigger.disabled = false;
      }
    };
    if (current.printConfig.token_present) pos().confirm('Replace worker key?', 'The running print worker will need the new key before it can print again.', run);
    else run().catch(error => pos().notify(error.message, true));
  }

  function bind() {
    const root = document.querySelector('#page-content');
    if (!root) return;
    root.querySelectorAll('[data-settings-tab]').forEach(tab => {
      tab.addEventListener('click', async () => { selected = tab.dataset.settingsTab; await pos().refresh(); document.getElementById(`settings-tab-${selected}`)?.focus(); });
      tab.addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const buttons = Array.from(root.querySelectorAll('[data-settings-tab]'));
        const index = buttons.indexOf(tab);
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length;
        buttons[next].click();
      });
    });
    const brandForm = root.querySelector('#branding-form');
    if (brandForm) {
      brandForm.addEventListener('submit', event => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(brandForm));
        data.tax_enabled = brandForm.elements.tax_enabled.checked;
        data.default_tax_rate = Number(data.default_tax_rate);
        saveForm(brandForm, 'settings', data, 'Café identity and preferences saved.');
      });
      ['cafe_name', 'address', 'phone', 'footer'].forEach(name => {
        const target = root.querySelector(`#brand-preview-${name === 'cafe_name' ? 'name' : name}`);
        brandForm.elements[name].addEventListener('input', () => { if (target) target.textContent = brandForm.elements[name].value; });
      });
    }
    root.querySelector('#logo-form')?.addEventListener('submit', async event => {
      event.preventDefault();
      const form = event.currentTarget;
      const submit = form.querySelector('button');
      submit.disabled = true;
      try {
        const data = new FormData(form); validateFile(data.get('file')); data.set('kind', 'logo');
        await pos().upload(data); pos().notify('Café logo updated.'); await pos().refresh();
      } catch (error) { pos().notify(error.message, true); }
      finally { submit.disabled = false; }
    });
    root.querySelector('#printer-form')?.addEventListener('submit', event => {
      event.preventDefault();
      const form = event.currentTarget;
      saveForm(form, 'settings', Object.fromEntries(new FormData(form)), 'Printer routing saved.');
    });
    root.querySelector('[data-remove-logo]')?.addEventListener('click', () => pos().confirm('Remove café logo?', 'Receipts and the app will use your café name without an image.', async () => {
      await pos().api('settings', { logo_path: '' }); pos().notify('Café logo removed.'); await pos().refresh();
    }));
    root.querySelector('[data-account-add]')?.addEventListener('click', () => accountModal());
    root.querySelectorAll('[data-account-edit]').forEach(el => el.addEventListener('click', () => accountModal(el.dataset.accountEdit)));
    root.querySelectorAll('[data-account-qr]').forEach(el => el.addEventListener('click', () => qrModal(el.dataset.accountQr)));
    root.querySelector('[data-user-add]')?.addEventListener('click', () => userModal());
    root.querySelectorAll('[data-user-edit]').forEach(el => el.addEventListener('click', () => userModal(el.dataset.userEdit)));
    root.querySelectorAll('[data-settings-refresh]').forEach(el => el.addEventListener('click', () => pos().refresh()));
    root.querySelector('[data-worker-token]')?.addEventListener('click', generateToken);
    root.querySelectorAll('[data-print-retry]').forEach(el => el.addEventListener('click', () => pos().confirm('Retry this print job?', 'The previous attempt may already have reached the printer. Retrying can produce a duplicate ticket or invoice. Check the paper output before continuing.', async () => {
      await pos().api('print/retry', { id: Number(el.dataset.printRetry), confirm_duplicate_risk: true });
      pos().notify('Print job queued for retry.'); await pos().refresh();
    })));
  }

  window.CafeSettings = { render, bind };
})();
