(() => {
  'use strict';
  const root = document.querySelector('#svm-backup-monitor');
  if (!root || !window.UWHSM_DATA) return;
  const { __ } = wp.i18n;
  const el = id => document.getElementById(id);
  let currentJob = '';
  let lastDone = '';
  const joined = new Set();
  const update = state => {
    if (!state) { root.hidden = true; return; }
    currentJob = state.job;
    const percent = Math.min(100, Math.max(0, Number(state.progress) || 0));
    root.hidden = false;
    root.classList.toggle('is-warning', !!state.error || (!state.scheduled && !state.done));
    el('svm-backup-monitor-title').textContent = state.done ? __('Backup completed', 'uwh-site-migrator') :
      state.background_enabled && state.scheduled ? __('Background backup running', 'uwh-site-migrator') : __('Backup waiting / paused', 'uwh-site-migrator');
    el('svm-backup-monitor-fill').style.width = percent + '%';
    el('svm-backup-monitor-label').textContent = state.label || state.name || __('Preparing…', 'uwh-site-migrator');
    el('svm-backup-monitor-percent').textContent = Math.floor(percent) + '%';
    let note = state.error || '';
    if (!note && !state.done && state.background_enabled && !state.scheduled) note = __('Scheduled worker not found. Return to Import / Export to resume.', 'uwh-site-migrator');
    if (!note && !state.done && state.background_enabled && (!state.last_tick || Date.now() / 1000 - state.last_tick > 150)) note = __('Waiting for server cron. Background speed depends on site traffic or hosting cron.', 'uwh-site-migrator');
    if (!note && state.last_tick && !state.done) note = __('Last background run:', 'uwh-site-migrator') + ' ' + new Date(state.last_tick * 1000).toLocaleTimeString();
    if (state.done) note = __('The completed package is available under Import / Export.', 'uwh-site-migrator');
    el('svm-backup-monitor-note').textContent = note;
    // Show durable progress after refresh, including on the Import / Export page.
    const local = document.querySelector('#svm-backup-progress');
    if (local && !local.hidden && state.background_enabled) {
      local.querySelector('i').style.width = percent + '%';
      local.querySelector('strong').textContent = state.label;
      local.querySelector('span').textContent = Math.floor(percent) + '%';
    }
  };
  const request = async job => {
    const body = new URLSearchParams({ action: 'uwhsm_backup_status', _ajax_nonce: UWHSM_DATA.nonce });
    if (job) body.set('job', job);
    const response = await fetch(UWHSM_DATA.ajax, { method: 'POST', credentials: 'same-origin', body, cache: 'no-store' });
    const json = await response.json();
    if (!json.success) throw new Error('Status unavailable');
    return json.data;
  };
  const poll = async () => {
    try {
      // Server discovery works across browser reloads and other plugin screens.
      const listing = await request('');
      const jobs = Array.isArray(listing.jobs) ? listing.jobs : [];
      if (jobs.length) {
        update(jobs[0]);
        const resume = document.querySelector('#svm-resume-backup');
        if (resume) {
          localStorage.setItem('svmBackupJob', jobs[0].job);
          resume.hidden = false;
          // When returning to Import / Export, join the existing job to keep
          // high-frequency batches going while the admin tab remains open.
          // Never launch a second runner on the same page.
          if (jobs[0].background_enabled && jobs[0].scheduled && !jobs[0].error && !window.UWHSM_BACKUP_ACTIVE && !joined.has(jobs[0].job)) {
            joined.add(jobs[0].job);
            resume.click();
          }
        }
      } else if (currentJob) {
        const completed = await request(currentJob);
        if (completed.found && completed.job && completed.job.done) {
          update(completed.job);
          if (lastDone !== currentJob) {
            lastDone = currentJob;
            localStorage.removeItem('svmBackupJob');
          }
        } else {
          root.hidden = true;
          currentJob = '';
        }
      } else {
        root.hidden = true;
      }
    } catch (e) {
      if (!root.hidden) el('svm-backup-monitor-note').textContent = __('Could not refresh status. Reconnecting…', 'uwh-site-migrator');
    }
  };
  poll();
  const timer = setInterval(() => { if (!document.hidden) poll(); }, 6000);
  window.addEventListener('pagehide', () => clearInterval(timer));
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
})();
