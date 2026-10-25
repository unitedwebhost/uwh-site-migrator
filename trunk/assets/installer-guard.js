(() => {
  'use strict';
  const { __ } = wp.i18n;
  const input = document.querySelector('input[name="pluginzip"]');
  if (!input || !input.form) return;
  const form = input.form;
  const notice = document.createElement('div'); notice.className = 'notice notice-warning inline'; notice.hidden = true;
  notice.setAttribute('role','alert');
  const text = document.createElement('p'); text.textContent = __('This is a website backup, not a plugin installer. ', 'uwh-site-migrator');
  const link = document.createElement('a'); link.href = UWHSM_INSTALL.restoreUrl; link.textContent = __('Open Site Migrator → Restore / Migrate', 'uwh-site-migrator');
  text.appendChild(link); notice.appendChild(text); form.parentNode.insertBefore(notice,form);
  const wrongFile = () => Boolean(input.files[0] && /\.uwh$/i.test(input.files[0].name));
  input.addEventListener('change', () => { notice.hidden = !wrongFile(); });
  form.addEventListener('submit', event => {
    if (!wrongFile()) return;
    event.preventDefault(); event.stopImmediatePropagation(); notice.hidden = false;
    notice.scrollIntoView({block:'center',behavior:'smooth'}); link.focus();
  },true);
})();
