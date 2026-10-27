(() => {
  'use strict';
  const root = document.querySelector('#svm-backup-monitor');
  if (!root || !window.UWHSM_DATA) return;
  const { __ } = wp.i18n;
  const el = id => document.getElementById(id);
  const storedJob = () => { try { return localStorage.getItem('svmBackupJob') || ''; } catch (_) { return ''; } };
  const clearStoredJob = job => { try { if (!job || localStorage.getItem('svmBackupJob') === job) localStorage.removeItem('svmBackupJob'); } catch (_) {} };
  // Reuse the same monitor inside the Create backup card; no second progress
  // bar is rendered on Import / Export. On other pages it stays below the hero.
  const localProgress = el('svm-backup-progress');
  if (localProgress && localProgress.parentNode) {
    localProgress.parentNode.insertBefore(root, localProgress);
    localProgress.hidden = true;
  }
  let currentJob = '';
  let lastDone = '';
  let lastProgress = -1;
  let lastProgressChange = 0;
  const stopButton = el('svm-stop-backup');
  const resumeButton = el('svm-resume-monitor');
  let monitorWorking = false;
  let workerJob = '';
  let recentWorkerError = '';
  let workerRetries = 0;
  let workerCooldown = 0;
  const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
  const leaseKey = 'uwhsm-monitor-backup-worker';
  const owner = Math.random().toString(36).slice(2) + Date.now().toString(36);
  const releaseLease = () => {
    try { const stored = JSON.parse(localStorage.getItem(leaseKey) || '{}'); if (stored.owner === owner) localStorage.removeItem(leaseKey); } catch (_) {}
  };
  const claimLease = job => {
    try {
      const stored = JSON.parse(localStorage.getItem(leaseKey) || '{}');
      if (stored.owner !== owner && stored.job === job && Number(stored.expires) > Date.now()) return false;
      localStorage.setItem(leaseKey, JSON.stringify({ owner, job, expires: Date.now() + 45000 }));
      return JSON.parse(localStorage.getItem(leaseKey) || '{}').owner === owner;
    } catch (_) { return true; } // Server-side advisory file locks remain authoritative.
  };
  let stoppingJob = '';
  let activeJobsCount = 0;
  const stopComplete = job => {
    clearStoredJob(job);
    if (workerJob === job) workerJob = '';
    releaseLease();
    currentJob = '';
    stoppingJob = '';
    root.hidden = true;
    root.classList.remove('is-running');
    if (stopButton) stopButton.hidden = true;
    if (resumeButton) resumeButton.hidden = true;
    document.dispatchEvent(new Event('uwhsm-backup-stop-finished'));
    // Discover any *other* unfinished jobs instead of resurrecting a deleted job.
    poll();
  };
  const cancelRequest = async job => {
    const body = new URLSearchParams({ action: 'uwhsm_backup_cancel', _ajax_nonce: UWHSM_DATA.nonce, job });
    const response = await fetch(UWHSM_DATA.ajax, { method: 'POST', credentials: 'same-origin', body, cache: 'no-store' });
    const json = await response.json();
    if (!json.success) throw new Error(json.data?.message || __('Could not stop backup.', 'uwh-site-migrator'));
    return json.data;
  };
  if (stopButton) stopButton.addEventListener('click', async () => {
    const job = currentJob;
    if (!job || stoppingJob || !window.confirm(__('Stop this unfinished backup? Its incomplete archive and temporary database export will be deleted. Completed backups will not be affected.', 'uwh-site-migrator'))) return;
    stoppingJob = job;
    workerJob = '';
    releaseLease();
    document.dispatchEvent(new Event('uwhsm-backup-stop-requested'));
    stopButton.disabled = true;
    root.classList.remove('is-running');
    el('svm-backup-monitor-title').textContent = __('Stopping backup safely', 'uwh-site-migrator');
    el('svm-backup-monitor-note').textContent = __('Stopping safely…', 'uwh-site-migrator');
    try {
      const result = await cancelRequest(job);
      if (result.cancelled) {
        stopComplete(job);
      } else {
        el('svm-backup-monitor-note').textContent = result.message || __('Waiting for the current batch to stop…', 'uwh-site-migrator');
      }
    } catch (e) {
      stoppingJob = '';
      stopButton.disabled = false;
      document.dispatchEvent(new Event('uwhsm-backup-stop-finished'));
      el('svm-backup-monitor-note').textContent = e.message;
    }
  });
  const update = state => {
    if (!state) { root.hidden = true; root.classList.remove('is-running'); if (stopButton) stopButton.hidden = true; if (resumeButton) resumeButton.hidden = true; return; }
    const previousJob = currentJob;
    currentJob = state.job;
    const percent = Math.min(100, Math.max(0, Number(state.progress) || 0));
    if (state.job !== previousJob) { recentWorkerError = ''; workerCooldown = 0; }
    if (percent !== lastProgress || state.job !== previousJob) {
      lastProgress = percent;
      lastProgressChange = Date.now();
    }
    root.hidden = false;
    if (stopButton) {
      stopButton.hidden = !!state.done || !state.job;
      stopButton.disabled = !!stoppingJob || !!state.cancel_requested;
    }
    if (resumeButton) {
      resumeButton.hidden = !!state.done || !!state.cancel_requested || !state.job || !!stoppingJob || !!window.UWHSM_BACKUP_ACTIVE || monitorWorking;
      resumeButton.disabled = !!stoppingJob;
    }
    const activityAt = Number(state.last_activity || state.last_tick || 0) * 1000;
    const foregroundActive = (!!window.UWHSM_BACKUP_ACTIVE && state.job === storedJob()) || (monitorWorking && workerJob === state.job);
    const workerActive = !!state.background_enabled && !!state.scheduled && !!activityAt && (Date.now() - activityAt < 45000);
    if (resumeButton && (foregroundActive || workerActive)) resumeButton.hidden = true;
    const running = !state.done && !state.cancel_requested && stoppingJob !== state.job && !state.error && (foregroundActive || workerActive);
    root.classList.toggle('is-running', running);
    root.classList.toggle('is-warning', !!state.error || (!running && !state.cancel_requested && !state.scheduled && !state.done));
    el('svm-backup-monitor-title').textContent = state.done ? __('Backup completed', 'uwh-site-migrator') :
      state.cancel_requested || stoppingJob === state.job ? __('Stopping backup safely', 'uwh-site-migrator') :
      running ? __('Background backup running', 'uwh-site-migrator') :
      state.background_enabled && state.scheduled && !state.error ? __('Waiting for background worker', 'uwh-site-migrator') :
      __('Backup waiting / paused', 'uwh-site-migrator');
    el('svm-backup-monitor-fill').style.width = percent + '%';
    el('svm-backup-monitor-label').textContent = state.label || state.name || __('Preparing…', 'uwh-site-migrator');
    el('svm-backup-monitor-percent').textContent = Math.floor(percent) + '%';
    let note = state.cancel_requested || stoppingJob === state.job ? __('Stopping safely…', 'uwh-site-migrator') : (state.error || '');
    if (!note && !state.done && !state.background_enabled) note = __('Backup is paused. Click Resume now to continue in this tab, or Stop backup to discard it.', 'uwh-site-migrator');
    if (!note && !state.done && state.background_enabled && !state.scheduled) note = __('Server cron is unavailable. Click Resume now to continue while this admin page is open.', 'uwh-site-migrator');
    const recentlyMoving = !!lastProgressChange && Date.now() - lastProgressChange < 150000;
    if (!note && !state.done && state.background_enabled && !recentlyMoving && (!activityAt || Date.now() - activityAt > 150000)) note = __('Waiting for server cron. Background speed depends on site traffic or hosting cron.', 'uwh-site-migrator');
    if (!note && activityAt && !state.done) note = __('Last backup activity:', 'uwh-site-migrator') + ' ' + new Date(activityAt).toLocaleTimeString();
    if (monitorWorking && workerJob === state.job && !state.done && !state.cancel_requested) note = __('Backing up while this admin page is open. Stop backup is available at any time.', 'uwh-site-migrator');
    if (recentWorkerError && !state.done && !state.cancel_requested && !monitorWorking) note = recentWorkerError;
    if (state.done) note = __('The completed package is available under Import / Export.', 'uwh-site-migrator');
    if (activeJobsCount > 1 && !state.done && !state.cancel_requested) note += (note ? ' · ' : '') + String(activeJobsCount) + ' ' + __('unfinished backup jobs remain. Stop each separately if they are no longer needed.', 'uwh-site-migrator');
    el('svm-backup-monitor-note').textContent = note;

  };
  // Authenticated browser assist. WP-Cron remains the unattended fallback;
  // only one tab should assist, and the PHP backup lock serializes all writers.
  const stepRequest = async job => {
    const body = new URLSearchParams({ action: 'uwhsm_backup_step', _ajax_nonce: UWHSM_DATA.nonce, job });
    const response = await fetch(UWHSM_DATA.ajax, { method: 'POST', credentials: 'same-origin', body, cache: 'no-store' });
    const json = await response.json();
    if (!json.success) throw new Error(json.data?.message || __('Backup worker could not continue.', 'uwh-site-migrator'));
    return json.data;
  };
  const assistBackup = async job => {
    if (!job || monitorWorking || stoppingJob || document.hidden || window.UWHSM_BACKUP_ACTIVE || !claimLease(job)) return;
    monitorWorking = true;
    workerJob = job;
    recentWorkerError = '';
    workerRetries = 0;
    if (resumeButton) resumeButton.hidden = true;
    try {
      while (!document.hidden && workerJob === job && !stoppingJob && !window.UWHSM_BACKUP_ACTIVE) {
        if (!claimLease(job)) break;
        let result;
        try {
          result = await stepRequest(job);
          workerRetries = 0;
        } catch (error) {
          // A lost AJAX reply does not mean the ZIP batch failed. The next
          // request resumes the server-committed file index with an advisory lock.
          workerRetries++;
          recentWorkerError = error.message;
          if (workerRetries >= 4) {
            workerCooldown = Date.now() + 60000;
            break;
          }
          await delay(Math.min(8000, 1500 * workerRetries));
          continue;
        }
        if (!result || result.cancelled || result.done) {
          await poll();
          break;
        }
        // Do not perform another batch while another process holds the lock.
        await delay(result.busy ? 1700 : 180);
        // This is a foreground continuation of an existing saved job, not a new backup.
        const note = el('svm-backup-monitor-note');
        if (note && !result.busy && workerJob === job) note.textContent = __('Archiving backup in this tab…', 'uwh-site-migrator');
      }
    } finally {
      monitorWorking = false;
      workerJob = '';
      releaseLease();
      poll();
    }
  };
  if (resumeButton) resumeButton.addEventListener('click', () => {
    const job = currentJob;
    if (!job || stoppingJob) return;
    workerCooldown = 0;
    assistBackup(job);
  });
  document.addEventListener('uwhsm-backup-stop-requested', () => {
    workerJob = '';
    releaseLease();
  });
  window.addEventListener('pagehide', releaseLease);

  const request = async job => {
    const body = new URLSearchParams({ action: 'uwhsm_backup_status', _ajax_nonce: UWHSM_DATA.nonce });
    if (job) body.set('job', job);
    const response = await fetch(UWHSM_DATA.ajax, { method: 'POST', credentials: 'same-origin', body, cache: 'no-store' });
    const json = await response.json();
    if (!json.success) throw new Error('Status unavailable');
    return json.data;
  };
  let polling = false;
  const poll = async () => {
    if (polling) return;
    polling = true;
    try {
      // Server discovery works across browser reloads and other plugin screens.
      const listing = await request('');
      const jobs = Array.isArray(listing.jobs) ? listing.jobs : [];
      activeJobsCount = jobs.length;
      if (jobs.length) {
        update(jobs[0]);
        if (stoppingJob && jobs[0].job === stoppingJob) {
          try {
            const stopped = await cancelRequest(stoppingJob);
            if (stopped.cancelled) {
              stopComplete(stoppingJob);
            }
          } catch (e) {
            el('svm-backup-monitor-note').textContent = e.message;
          }
          return;
        }
        if (jobs[0].cancel_requested) {
          // A tab may have closed after the stop marker was committed. The
          // marker is an authenticated admin decision; finalize cleanup here.
          stoppingJob = jobs[0].job;
          try {
            const stopped = await cancelRequest(stoppingJob);
            if (stopped.cancelled) {
              stopComplete(stoppingJob);
            }
          } catch (e) {
            el('svm-backup-monitor-note').textContent = e.message;
          }
          return;
        }
        // WP-Cron can wait indefinitely on low-traffic sites. Assist an *enabled*
        // background job from this visible admin tab if its worker has been idle.
        const age = Math.max(0, Date.now() - 1000 * Number(jobs[0].last_activity || jobs[0].last_tick || 0));
        if (jobs[0].background_enabled && !jobs[0].error && !jobs[0].cancel_requested &&
            age > 30000 && Date.now() > workerCooldown && !monitorWorking &&
            !window.UWHSM_BACKUP_ACTIVE && !stoppingJob && !document.hidden) {
          assistBackup(jobs[0].job);
        }
        const resume = document.querySelector('#svm-resume-backup');
        if (resume) {
          // A job discovered in storage may belong to an older/abandoned tab.
          // Show it for explicit Resume/Stop; do not silently join it on refresh.
          const savedId = storedJob();
          resume.hidden = false;
          // Display Resume for explicit manual recovery; never click it automatically.
          // The monitor can assist a stalled *enabled* background job below.
        }
      } else {
        // A manually started job also remains visible after a page reload.
        // Background discovery is preferable, but browser storage is a safe
        // fallback for non-background backups and recent completions.
        const savedId = currentJob || storedJob();
        if (savedId) {
          const result = await request(savedId);
          if (result.found && result.job) {
            update(result.job);
            if (stoppingJob && result.job.job === stoppingJob) {
              if (result.job.done) {
                // The last batch completed before the Stop request took effect.
                // Preserve the finished archive and leave the success visible.
                stoppingJob = '';
                clearStoredJob(savedId);
                document.dispatchEvent(new Event('uwhsm-backup-stop-finished'));
                return;
              }
              const stopped = await cancelRequest(stoppingJob);
              if (stopped.cancelled) {
                stopComplete(stoppingJob);
              }
              return;
            }
            if (result.job.cancel_requested) {
              stoppingJob = result.job.job;
              try {
                const stopped = await cancelRequest(stoppingJob);
                if (stopped.cancelled) {
                  stopComplete(stoppingJob);
                }
              } catch (e) {
                el('svm-backup-monitor-note').textContent = e.message;
              }
              return;
            }
            if (result.job.done && lastDone !== savedId) {
              lastDone = savedId;
              clearStoredJob(savedId);
            }
          } else {
            if (stoppingJob === savedId) {
              stoppingJob = '';
              document.dispatchEvent(new Event('uwhsm-backup-stop-finished'));
            }
            clearStoredJob(savedId);
            root.hidden = true;
            currentJob = '';
          }
        } else {
          root.hidden = true;
        }
      }
    } catch (e) {
      if (!root.hidden) el('svm-backup-monitor-note').textContent = __('Could not refresh status. Reconnecting…', 'uwh-site-migrator');
    } finally { polling = false; }
  };
  poll();
  const timer = setInterval(() => { if (!document.hidden) poll(); }, 6000);
  window.addEventListener('pagehide', () => clearInterval(timer));
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
})();
