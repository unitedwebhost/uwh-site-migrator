(() => {
  'use strict';
  const { __, _n, sprintf } = wp.i18n;
  const $ = s => document.querySelector(s);
  const post = async (action, data = {}, isForm = false) => {
    let last;
    for (let attempt = 0; attempt < 4; attempt++) {
      try {
        let body;
        if (isForm) { body = data; if (!body.has('action')) body.append('action', action); if (!body.has('_ajax_nonce')) body.append('_ajax_nonce', UWHSM_DATA.nonce); }
        else { body = new URLSearchParams({ action, _ajax_nonce: UWHSM_DATA.nonce, ...data }); }
        const r = await fetch(UWHSM_DATA.ajax, { method: 'POST', credentials: 'same-origin', body });
        const text = await r.text(); let json;
        try { json = JSON.parse(text); } catch (_) { throw new Error(__('Server returned an invalid response. The plugin will retry automatically.', 'uwh-site-migrator')); }
        if (!json.success) { const err = new Error((json.data && json.data.message) || json.data || __('Request failed.', 'uwh-site-migrator')); err.noRetry = r.status < 500; throw err; }
        return json.data;
      } catch (e) {
        last = e; if (e.noRetry || attempt === 3) break;
        await new Promise(r => setTimeout(r, 900 * Math.pow(2, attempt)));
      }
    }
    throw last;
  };
  const progress = (root, value, label) => {
    root.hidden = false; value = Math.max(0, Math.min(100, Number(value) || 0));
    root.querySelector('i').style.width = value + '%'; root.querySelector('strong').textContent = label;
    root.querySelector('span').textContent = Math.floor(value) + '%';
  };
  const log = (el, msg, bad = false) => { el.textContent = msg; el.className = 'svm-log ' + (bad ? 'is-error' : 'is-ok'); };
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  let backupRun = 0;

  let driveRun = 0, inventoryRun = 0, driveCount = 0, driveBytes = 0;
  const humanBytes = bytes => {
    const units = ['B','KB','MB','GB','TB']; let value = Math.max(0, Number(bytes) || 0), unit = 0;
    while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit++; }
    return `${value.toFixed(unit ? 2 : 0)} ${units[unit]}`;
  };
  const showDriveState = (state, startedAt, startedBytes, root = null) => {
    root = root || $('#svm-drive-progress'); if (!root) return;
    progress(root, state.progress, state.label);
    const seconds = Math.max(.001, (Date.now() - startedAt) / 1000);
    const speed = Math.max(0, Number(state.bytes_sent) - startedBytes) / seconds;
    const meta = root.querySelector('.svm-row-transfer-meta') || $('#svm-drive-transfer-meta');
    if (meta) meta.textContent = `${humanBytes(state.bytes_sent)} / ${humanBytes(state.bytes_total)} · ${(speed / 1048576).toFixed(2)} MB/s`;
  };
  const runDriveTransfer = async (transferId, ui = {}) => {
    if (!transferId) return { complete: false, error: new Error(__('Drive transfer ID is missing.', 'uwh-site-migrator')) };
    const thisRun = ++driveRun; localStorage.setItem('svmDriveTransfer', transferId);
    const progressRoot = ui.progressRoot || $('#svm-drive-progress');
    const error = ui.errorRoot || $('#svm-drive-error');
    const resume = ui.resumeButton || $('#svm-drive-resume');
    if (error) { error.hidden = true; error.textContent = ''; }
    if (resume) resume.hidden = true;
    try {
      let state = await post('uwhsm_drive_upload_status', { transfer_id: transferId });
      const startedAt = Date.now(), startedBytes = Number(state.bytes_sent) || 0; showDriveState(state, startedAt, startedBytes, progressRoot);
      while (!state.done && thisRun === driveRun) {
        if (state.status === 'failed' || state.reconnect_required || state.auto_paused) {
          throw new Error(state.error || (state.reconnect_required ? __('Reconnect Google Drive, then resume this upload.', 'uwh-site-migrator') : __('Drive upload paused.', 'uwh-site-migrator')));
        }
        if (state.retry_after) await sleep(Math.min(900, Number(state.retry_after)) * 1000);
        if (thisRun !== driveRun) return { complete: false, superseded: true };
        state = await post('uwhsm_drive_upload_step', { transfer_id: transferId });
        showDriveState(state, startedAt, startedBytes, progressRoot);
      }
      if (thisRun !== driveRun) return { complete: false, superseded: true };
      localStorage.removeItem('svmDriveTransfer'); if (resume) resume.hidden = true;
      const meta = progressRoot ? progressRoot.querySelector('.svm-row-transfer-meta') : $('#svm-drive-transfer-meta');
      if (meta) meta.textContent = sprintf(
        /* translators: %s: uploaded backup size. */
        __('Uploaded %s.', 'uwh-site-migrator'),
        humanBytes(state.bytes_total)
      ) + (state.local_cleanup_done ? __(' Automatic local copy removed.', 'uwh-site-migrator') : __(' The local backup remains available.', 'uwh-site-migrator'));
      let driveInventory = null;
      if ($('#svm-drive') || document.querySelector('.svm-send-drive') || $('#svm-dashboard-drive-count')) {
        driveInventory = await loadDriveInventory();
      }
      return { complete: true, state, driveInventory };
    } catch (e) {
      if (thisRun !== driveRun) return { complete: false, superseded: true, error: e };
      if (error) { error.textContent = e.message; error.hidden = false; error.className = 'svm-log is-error' + (error.classList.contains('svm-drive-row-error') ? ' svm-drive-row-error' : ''); }
      if (resume) { resume.hidden = false; resume.dataset.transferId = transferId; }
      return { complete: false, error: e };
    }
  };
  const appendDriveRow = row => {
    const body = $('#svm-drive-table'); if (!body) return;
    const tr = document.createElement('tr');
    tr.dataset.driveFileId = row.file_id || '';
    tr.dataset.driveName = row.name || '';
    [row.name, row.source_domain || __('Unknown source', 'uwh-site-migrator'), new Date(Number(row.created) * 1000).toLocaleString(), humanBytes(row.size)].forEach(value => {
      const td = document.createElement('td'); td.textContent = value; tr.appendChild(td);
    });
    const actionsTd = document.createElement('td');
    const downloadUrl = UWHSM_DATA.ajax.replace('admin-ajax.php', 'admin-post.php') + '?action=uwhsm_drive_download&file_id=' + encodeURIComponent(row.file_id) + '&name=' + encodeURIComponent(row.name) + '&_wpnonce=' + encodeURIComponent(UWHSM_DATA.driveDownloadNonce || '');
    actionsTd.innerHTML = `<div class="svm-row-actions">
      <button type="button" class="button svm-drive-fetch" data-id="${row.file_id}" data-name="${row.name}">${__('Import to Local', 'uwh-site-migrator')}</button>
      <a class="button" href="${downloadUrl}">${__('Download', 'uwh-site-migrator')}</a>
      <button type="button" class="button svm-delete svm-delete-drive" data-id="${row.file_id}" data-name="${row.name}">${__('Delete', 'uwh-site-migrator')}</button>
    </div>
    <div class="svm-progress-wrap svm-row-progress svm-drive-import-progress" hidden>
      <div class="svm-progress"><i></i></div>
      <div class="svm-progress-meta"><strong>${__('Waiting…', 'uwh-site-migrator')}</strong><span>0%</span></div>
      <p class="svm-row-transfer-meta"></p>
      <p class="svm-log svm-drive-import-error" aria-live="polite"></p>
    </div>`;
    tr.appendChild(actionsTd);
    body.appendChild(tr);
  };

  const showDriveImportState = (state, root, startedAt, startedBytes) => {
    if (!root) return;
    progress(root, state.progress, state.label || __('Importing from Google Drive', 'uwh-site-migrator'));
    const seconds = Math.max(.001, (Date.now() - startedAt) / 1000);
    const speed = Math.max(0, Number(state.bytes_received) - startedBytes) / seconds;
    const meta = root.querySelector('.svm-row-transfer-meta');
    if (meta) meta.textContent = `${humanBytes(state.bytes_received)} / ${humanBytes(state.bytes_total)} · ${(speed / 1048576).toFixed(2)} MB/s`;
  };

  const runDriveImport = async (importId, root) => {
    if (!importId) throw new Error(__('Drive import ID is missing.', 'uwh-site-migrator'));
    const error = root ? root.querySelector('.svm-drive-import-error') : null;
    if (error) { error.textContent = ''; error.hidden = true; }
    let state = await post('uwhsm_drive_import_status', { import_id: importId });
    const startedAt = Date.now(), startedBytes = Number(state.bytes_received) || 0;
    showDriveImportState(state, root, startedAt, startedBytes);
    while (!state.done) {
      if (state.status === 'failed') throw new Error(state.error || __('Drive import stopped.', 'uwh-site-migrator'));
      state = await post('uwhsm_drive_import_step', { import_id: importId });
      showDriveImportState(state, root, startedAt, startedBytes);
      if (!state.done) await sleep(100);
    }
    const meta = root ? root.querySelector('.svm-row-transfer-meta') : null;
    if (meta) meta.textContent = sprintf(
      /* translators: %s: imported backup size. */
      __('Imported %s to local backups.', 'uwh-site-migrator'),
      humanBytes(state.bytes_total)
    );
    return { complete: true, state };
  };

  // Case-insensitive robust check for local backups
  const updateLocalSyncStatus = () => {
    const localBackupNames = new Set(
      Array.isArray(UWHSM_DATA.local_backups)
        ? UWHSM_DATA.local_backups.map(name => String(name).trim().toLowerCase()).filter(Boolean)
        : []
    );
    
    // Collect from data-backup attributes outside drive
    document.querySelectorAll('[data-backup]').forEach(el => {
      if (!el.closest('#svm-drive')) {
        const name = el.dataset.backup;
        if (name) localBackupNames.add(name.trim().toLowerCase());
      }
    });

    // Collect from table rows text cells outside drive table
    document.querySelectorAll('table tr').forEach(row => {
      if (row.closest('#svm-drive-table') || row.closest('#svm-drive')) return;
      row.querySelectorAll('td').forEach(td => {
        const text = td.textContent.trim();
        if ((text.includes('.uwh') || text.includes('site-vault') || text.includes('.zip')) && 
            !text.includes('Import') && !text.includes(__('Download', 'uwh-site-migrator')) && !text.includes(__('Delete', 'uwh-site-migrator'))) {
          localBackupNames.add(text.toLowerCase());
        }
      });
    });

    document.querySelectorAll('.svm-drive-fetch').forEach(btn => {
      const fileName = (btn.dataset.name || '').trim().toLowerCase();
      if (fileName && localBackupNames.has(fileName)) {
        btn.textContent = __('Already in Local', 'uwh-site-migrator');
        btn.disabled = true;
        btn.style.cursor = 'not-allowed';
        btn.style.opacity = '0.65';
      }
    });
  };

  const resetDriveSyncStatus = () => {
    document.querySelectorAll('.svm-send-drive').forEach(btn => {
      const row = btn.closest('tr');
      if (row) row.querySelectorAll('.svm-drive-badge').forEach(badge => badge.remove());
      if ([__('Already in Drive', 'uwh-site-migrator'), __('Drive status unavailable', 'uwh-site-migrator')].includes(btn.textContent.trim())) {
        btn.textContent = __('Send to Drive', 'uwh-site-migrator');
        btn.disabled = !UWHSM_DATA.drive_connected;
        btn.style.cursor = '';
        btn.style.opacity = '';
      }
    });
  };

  const updateDriveSyncStatus = (driveFiles) => {
    const driveFileNames = new Set(driveFiles.map(f => f.name.trim().toLowerCase()));
    document.querySelectorAll('.svm-send-drive').forEach(btn => {
      const backupName = (btn.dataset.backup || '').trim().toLowerCase();
      if (backupName && driveFileNames.has(backupName)) {
        btn.textContent = __('Already in Drive', 'uwh-site-migrator');
        btn.disabled = true;
        btn.style.cursor = 'not-allowed';
        btn.style.opacity = '0.65';
        const row = btn.closest('tr');
        const stack = row ? row.querySelector('.svm-status-stack') : null;
        if (stack && !stack.querySelector('.svm-drive-badge')) {
          const badge = document.createElement('span');
          badge.className = 'svm-status is-drive svm-drive-badge';
          badge.textContent = __('In Drive', 'uwh-site-migrator');
          stack.appendChild(badge);
        }
      }
    });
  };

  const loadDriveInventory = async () => {
    const panel = $('#svm-drive');
    const sendButtonsPresent = !!document.querySelector('.svm-send-drive');
    const dashboardDriveMetric = $('#svm-dashboard-drive-count');
    const connected = panel ? panel.dataset.connected === '1' : !!UWHSM_DATA.drive_connected;
    if (!connected || (!panel && !sendButtonsPresent && !dashboardDriveMetric)) return;
    const thisRun = ++inventoryRun, body = $('#svm-drive-table'), loading = $('#svm-drive-loading'), empty = $('#svm-drive-empty'), error = $('#svm-drive-error');
    resetDriveSyncStatus();
    if (body) body.textContent = ''; if (loading) loading.hidden = false; if (empty) empty.hidden = true; if (error) error.hidden = true;
    driveCount = 0; driveBytes = 0; let cursor = '';
    let allDriveFiles = [];
    try {
      do {
        const page = await post('uwhsm_drive_list', { cursor }); if (thisRun !== inventoryRun) return;
        page.files.forEach(row => { if (body) appendDriveRow(row); driveCount += 1; driveBytes += Number(row.size) || 0; });
        allDriveFiles = allDriveFiles.concat(page.files);
        const count = $('#svm-drive-count'), total = $('#svm-drive-total');
        if (count) count.textContent = String(driveCount);
        if (total) total.textContent = humanBytes(driveBytes);
        cursor = page.next_cursor || '';
      } while (cursor && thisRun === inventoryRun);
      
      updateDriveSyncStatus(allDriveFiles);
      updateLocalSyncStatus();

      if (window.UWHSM_DASHBOARD && typeof window.UWHSM_DASHBOARD.summarizeDrive === 'function') {
        const lastBackup = $('#svm-dashboard-last-backup');
        const localTimestamp = lastBackup ? Number(lastBackup.dataset.localTs || 0) : 0;
        const summary = window.UWHSM_DASHBOARD.summarizeDrive(allDriveFiles, localTimestamp);
        const dashboardCount = $('#svm-dashboard-drive-count');
        const dashboardSize = $('#svm-dashboard-drive-size');
        const dashboardLastSource = $('#svm-dashboard-last-source');
        if (dashboardCount) dashboardCount.textContent = String(summary.count);
        if (dashboardSize) dashboardSize.textContent = sprintf(
          /* translators: %s: Google Drive backup storage size. */
          __('%s stored in Drive', 'uwh-site-migrator'),
          humanBytes(summary.bytes)
        );
        if (lastBackup && summary.latestTimestamp > 0) {
          lastBackup.textContent = new Date(summary.latestTimestamp * 1000).toLocaleString([], { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
          if (dashboardLastSource) dashboardLastSource.textContent = summary.latestSource === __('Google Drive', 'uwh-site-migrator') ? __('Latest completed cloud backup', 'uwh-site-migrator') : __('Latest completed local backup', 'uwh-site-migrator');
        }
      }

      if (loading) loading.hidden = true; if (empty) empty.hidden = driveCount !== 0;
      return { ok: true, files: allDriveFiles };
    } catch (e) {
      if (thisRun !== inventoryRun) return;
      if (loading) loading.hidden = true;
      const dashboardCount = $('#svm-dashboard-drive-count');
      const dashboardSize = $('#svm-dashboard-drive-size');
      if (dashboardCount) dashboardCount.textContent = '—';
      if (dashboardSize) dashboardSize.textContent = __('Drive list unavailable', 'uwh-site-migrator');
      if (error) { error.textContent = e.message; error.hidden = false; }
      return { ok: false, files: [] };
    }
  };

  const start = $('#svm-start'), startDrive = $('#svm-start-drive'), resumeBackup = $('#svm-resume-backup');
  const runBackup = async (job, delivery = 'local') => {
    const thisRun = ++backupRun;
    window.UWHSM_BACKUP_ACTIVE = true;
    const p = $('#svm-backup-progress'), l = $('#svm-backup-log'); if (start) start.disabled = true; if (startDrive) startDrive.disabled = true; if (resumeBackup) resumeBackup.disabled = true; if (l) l.textContent = '';
    try {
      let state = job ? { job, done: false } : await post('uwhsm_backup_start', { delivery });
      localStorage.setItem('svmBackupJob', state.job);
      if (!job && $('#svm-background-opt') && $('#svm-background-opt').checked) { await post('uwhsm_background_configure', {job: state.job, enabled: '1'}); }
      progress(p, 1, state.label || __('Resuming backup', 'uwh-site-migrator'));
      while (!state.done && thisRun === backupRun) { await sleep(state.busy ? 1500 : 120); state = await post('uwhsm_backup_step', { job: state.job }); progress(p, state.progress, state.label); }
      if(thisRun !== backupRun){return;}
      localStorage.removeItem('svmBackupJob'); if (resumeBackup) resumeBackup.hidden = true;
      progress(p, 100, __('Backup complete', 'uwh-site-migrator'));
      let driveResult = null;
      if (state.drive_transfer_id) { localStorage.setItem('svmDriveTransfer', state.drive_transfer_id); driveResult = await runDriveTransfer(state.drive_transfer_id, { progressRoot: p, errorRoot: l }); }
      const drivePaused = state.drive_transfer_id && (!driveResult || !driveResult.complete);
      log(l, drivePaused ? __('Backup completed locally, but Drive upload is paused. Use Resume Drive upload after resolving the message in the Google Drive panel.', 'uwh-site-migrator') : (state.delivery_warning || (state.warnings ? sprintf(
        _n(
          'Backup completed successfully. %s backup notice recorded. Refreshing the download list…',
          'Backup completed successfully. %s backup notices recorded. Refreshing the download list…',
          Number(state.warnings),
          'uwh-site-migrator'
        ),
        Number(state.warnings)
      ) : __('Backup completed successfully. Refreshing the download list…', 'uwh-site-migrator'))), drivePaused || !!state.delivery_warning);
      setTimeout(() => location.reload(), 800);
    } catch (e) { if(thisRun !== backupRun){return;} if (l) log(l, e.message + __(' You can use Resume interrupted backup.', 'uwh-site-migrator'), true); if (start) start.disabled = false; if (startDrive) startDrive.disabled = false; if (resumeBackup) resumeBackup.hidden = false; }
    finally { window.UWHSM_BACKUP_ACTIVE = false; if (resumeBackup) resumeBackup.disabled = false; }
  };
  if (start) start.addEventListener('click', () => runBackup(undefined, 'local'));
  if (startDrive) startDrive.addEventListener('click', () => runBackup(undefined, 'drive'));
  if (resumeBackup) {
    resumeBackup.hidden = !localStorage.getItem('svmBackupJob');
    resumeBackup.addEventListener('click', () => runBackup(localStorage.getItem('svmBackupJob')));
  }

  const file = $('#svm-file'), confirm = $('#svm-confirm'), upload = $('#svm-upload');
  const fileDrop = $('#svm-file-drop'), selectedFileCard = $('#svm-selected-file'), selectedFileName = $('#svm-selected-file-name'), selectedFileMeta = $('#svm-selected-file-meta'), clearSelectedFile = $('#svm-selected-file-clear');
  const formatFileSize = (bytes) => {
    const value = Number(bytes || 0);
    if (!Number.isFinite(value) || value <= 0) return __('Ready for restore', 'uwh-site-migrator');
    if (value < 1024 * 1024) return sprintf(__('Size: %s KB', 'uwh-site-migrator'), (value / 1024).toFixed(1));
    return sprintf(__('Size: %s MB', 'uwh-site-migrator'), (value / (1024 * 1024)).toFixed(1));
  };
  const updateSelectedFileUI = () => {
    const selected = file && file.files ? file.files[0] : null;
    if (selected) {
      if (selectedFileCard) selectedFileCard.hidden = false;
      if (selectedFileName) selectedFileName.textContent = selected.name;
      if (selectedFileMeta) selectedFileMeta.textContent = formatFileSize(selected.size);
      if (fileDrop) fileDrop.classList.add('is-selected');
    } else {
      if (selectedFileCard) selectedFileCard.hidden = true;
      if (selectedFileName) selectedFileName.textContent = '—';
      if (selectedFileMeta) selectedFileMeta.textContent = __('Ready for restore', 'uwh-site-migrator');
      if (fileDrop) fileDrop.classList.remove('is-selected');
    }
  };
  const toggle = () => { if (upload) upload.disabled = !(file.files.length && confirm.checked) || UWHSM_DATA.multisite; };
  if (file) file.addEventListener('change', () => {
    const selected = file.files[0];
    if (selected && /^(?:uwh-site-migrator|site-vault-migrator|site-migrator)(?:-v|\.)/i.test(selected.name)) {
      file.value = ''; updateSelectedFileUI(); log($('#svm-restore-log'), __('You selected the plugin installer ZIP. Choose the large website backup named site-vault-…svm.zip instead.', 'uwh-site-migrator'), true);
    } else if (selected) { updateSelectedFileUI(); log($('#svm-restore-log'), __('Selected website backup: ', 'uwh-site-migrator') + selected.name, false); }
    else { updateSelectedFileUI(); }
    toggle();
  });
  if (clearSelectedFile) clearSelectedFile.addEventListener('click', () => { if (file) file.value = ''; updateSelectedFileUI(); toggle(); });
  updateSelectedFileUI();
  if (confirm) confirm.addEventListener('change', toggle);
  const resumeRestore = $('#svm-resume-restore');
  const runRestore = async (state, ui = {}) => {
    const p = ui.progress || $('#svm-restore-progress') || $('#svm-direct-destination-progress'), l = ui.log || $('#svm-restore-log') || $('#svm-direct-destination-log');
    if (resumeRestore && resumeRestore.disabled) return;
    if (resumeRestore) resumeRestore.disabled = true;
    let rollbackAnnounced = false;
    try {
      while (!state.done) {
        await sleep(state.busy ? 1500 : 140);
        state = await post('uwhsm_restore_step', { restore_id: state.restore_id, token: state.token });
        localStorage.setItem('svmRestoreJob', JSON.stringify({ restore_id: state.restore_id, token: state.token }));
        if (state.automatic_rollback && !rollbackAnnounced) {
          rollbackAnnounced = true;
          log(l, __('Restore error detected. Automatic rollback is restoring the safety checkpoint.', 'uwh-site-migrator') + (state.original_error ? __(' Original error: ', 'uwh-site-migrator') + state.original_error : ''), true);
        }
        if (!state.busy) progress(p, 50 + state.progress * .50, state.label);
      }
      localStorage.removeItem('svmRestoreJob'); if (resumeRestore) resumeRestore.hidden = true;
      progress(p, 99, state.rolled_back ? __('Rollback restored files and database — checking homepage', 'uwh-site-migrator') : __('Files and database restored — checking homepage', 'uwh-site-migrator'));
      let homepageOk = false;
      try {
        const url = new URL(UWHSM_DATA.home); url.searchParams.set('uwhsm_check', Date.now());
        const response = await fetch(url, {credentials:'omit',cache:'no-store',signal:AbortSignal.timeout(20000)});
        const html = await response.text();
        if (!response.ok || /There has been a critical error|Fatal error:|Error establishing a database connection/i.test(html)) throw new Error(__('Homepage returned an error', 'uwh-site-migrator'));
        homepageOk = true;
        if (state.rolled_back) {
          progress(p,100,__('Automatic rollback complete — destination restored', 'uwh-site-migrator'));
          log(l, __('The migration did not complete, but the automatic safety checkpoint was restored successfully.', 'uwh-site-migrator') + (state.original_error ? __(' Original error: ', 'uwh-site-migrator') + state.original_error : ''), true);
        } else {
          progress(p,100,__('Restore complete — homepage responded', 'uwh-site-migrator'));
          log(l,__('Files and database restored; homepage check passed. Review inner pages and sign in using the source site account. Save Settings → Permalinks once.', 'uwh-site-migrator'));
        }
      } catch (_) {
        progress(p,100,state.rolled_back ? __('Rollback complete — homepage needs review', 'uwh-site-migrator') : __('Files and database restored — homepage needs review', 'uwh-site-migrator'));
        log(l,state.rolled_back ? __('The automatic rollback finished, but the homepage check did not pass. Review the destination error log and the retained safety checkpoint.', 'uwh-site-migrator') : __('Import finished, but the homepage check did not pass. Check the destination error log before calling this migration successful. Do not repeat the restore automatically.', 'uwh-site-migrator'),true);
      }
      state.homepage_ok = homepageOk;
      document.dispatchEvent(new CustomEvent('uwhsm:restore-complete', { detail: state }));
      return state;
    } catch (e) { if (l) log(l, e.message + __(' You can use Resume interrupted restore.', 'uwh-site-migrator'), true); if (resumeRestore) resumeRestore.hidden = false; throw e; }
    finally { if (resumeRestore) resumeRestore.disabled = false; }
  };

  const runSafetyCheckpoint = async (state, ui = {}) => {
    if (!state || !state.checkpoint_required) return state;
    const p = ui.progress || $('#svm-restore-progress') || $('#svm-direct-destination-progress'), l = ui.log || $('#svm-restore-log') || $('#svm-direct-destination-log');
    let checkpoint = { job: state.checkpoint_job, done: false, progress: 0, label: __('Creating automatic rollback checkpoint', 'uwh-site-migrator') };
    localStorage.setItem('svmRestoreJob', JSON.stringify({ restore_id: state.restore_id, token: state.token }));
    while (!checkpoint.done) {
      await sleep(checkpoint.busy ? 1200 : 120);
      checkpoint = await post('uwhsm_backup_step', { job: state.checkpoint_job });
      if (!checkpoint.busy) progress(p, 35 + Number(checkpoint.progress || 0) * .15, checkpoint.label || __('Creating automatic rollback checkpoint', 'uwh-site-migrator'));
    }
    if (l) log(l, sprintf(
      /* translators: %s: checkpoint filename. */
      __('Safety checkpoint created: %s. Starting restore.', 'uwh-site-migrator'),
      checkpoint.name || state.checkpoint_name || __('checkpoint', 'uwh-site-migrator')
    ));
    state = await post('uwhsm_restore_checkpoint_finish', { restore_id: state.restore_id, token: state.token });
    localStorage.setItem('svmRestoreJob', JSON.stringify({ restore_id: state.restore_id, token: state.token }));
    return state;
  };

  const startRestoreWorkflow = async (state, ui = {}) => {
    state = await runSafetyCheckpoint(state, ui);
    return runRestore(state, ui);
  };
  if (upload) upload.addEventListener('click', async () => {
    const f = file.files[0], p = $('#svm-restore-progress'), l = $('#svm-restore-log'); upload.disabled = true; l.textContent = '';
    try {
      const init = await post('uwhsm_upload_start', { name: f.name, size: f.size });
      const configuredChunkSize = Number(init.chunk_size || UWHSM_DATA.chunkSize);
      const chunkSize = Number.isSafeInteger(configuredChunkSize) && configuredChunkSize > 0
        ? Math.min(configuredChunkSize, init.chunk_size ? 8 * 1024 * 1024 : 4 * 1024 * 1024) : 4 * 1024 * 1024;
      let offset = 0, index = 0; const uploadStarted = Date.now();
      while (offset < f.size) {
        const blob = f.slice(offset, Math.min(f.size, offset + chunkSize)), fd = new FormData();
        fd.append('upload_id', init.upload_id); fd.append('index', index); fd.append('chunk', blob, 'chunk');
        await post('uwhsm_upload_chunk', fd, true); offset += blob.size; index++;
        const elapsed = Math.max(0.001, (Date.now() - uploadStarted) / 1000);
        progress(p, (offset / f.size) * 35, sprintf(
          /* translators: 1: uploaded megabytes, 2: total megabytes, 3: upload speed in MB/s. */
          __('Uploading backup · %1$s / %2$s MB · %3$s MB/s', 'uwh-site-migrator'),
          (offset / 1048576).toFixed(1),
          (f.size / 1048576).toFixed(1),
          (offset / 1048576 / elapsed).toFixed(2)
        ));
      }
      progress(p, 35, __('Upload complete — validating backup archive', 'uwh-site-migrator'));
      let state = await post('uwhsm_restore_start', { upload_id: init.upload_id, token: init.token });
      localStorage.setItem('svmRestoreJob', JSON.stringify({ restore_id: state.restore_id, token: state.token }));
      progress(p, 36, state.label);
      await startRestoreWorkflow(state);
    } catch (e) { log(l, e.message, true); upload.disabled = false; }
  });
  if (resumeRestore) {
    resumeRestore.hidden = !localStorage.getItem('svmRestoreJob');
    resumeRestore.addEventListener('click', async () => {
      try {
        const saved = JSON.parse(localStorage.getItem('svmRestoreJob'));
        if (!saved || !saved.restore_id) throw new Error(__('Saved restore session is missing.', 'uwh-site-migrator'));
        const state = await post('uwhsm_restore_recover', { restore_id: saved.restore_id });
        localStorage.setItem('svmRestoreJob', JSON.stringify({ restore_id: state.restore_id, token: state.token }));
        await startRestoreWorkflow(state);
      } catch (_) { localStorage.removeItem('svmRestoreJob'); resumeRestore.hidden = true; }
    });
  }
  const localModal = $('#svm-local-restore-modal'), localName = $('#svm-local-backup-name');
  const localConfirm = $('#svm-local-confirm'), localStart = $('#svm-local-start'), localCancel = $('#svm-local-cancel');
  let localBackup = '', localOpener = null;
  const closeLocalModal = restoreFocus => {
    if (!localModal) return;
    localModal.hidden = true;
    if (restoreFocus && localOpener) localOpener.focus();
  };
  document.addEventListener('click', e => {
    const button = e.target.closest('.svm-restore-local'); if (!button) return;
    if (resumeRestore.disabled) { log($('#svm-restore-log'), __('A restore batch is already running.', 'uwh-site-migrator'), true); return; }
    localBackup = button.dataset.backup || ''; if (!localBackup) return;
    localOpener = button; localName.textContent = localBackup; localConfirm.checked = false; localStart.disabled = true;
    localModal.hidden = false; $('#svm-local-restore-title').focus();
  });
  if (localConfirm) localConfirm.addEventListener('change', () => { localStart.disabled = !localConfirm.checked; });
  if (localCancel) localCancel.addEventListener('click', () => closeLocalModal(true));
  if (localStart) localStart.addEventListener('click', async () => {
    const backupName = localBackup;
    if (!backupName || !localConfirm.checked || resumeRestore.disabled) return;
    localStart.disabled = true;
    try {
      const state = await post('uwhsm_restore_local_start', { backup_name: backupName });
      localStorage.setItem('svmRestoreJob', JSON.stringify({ restore_id: state.restore_id, token: state.token }));
      closeLocalModal(false); progress($('#svm-restore-progress'), 35, state.label); await startRestoreWorkflow(state);
    } catch (error) { log($('#svm-restore-log'), error.message, true); localStart.disabled = false; }
  });
  document.addEventListener('click', async e => {
    const button = e.target.closest('.svm-send-drive'); if (!button) return;
    const row = button.closest('tr');
    const rowProgress = row ? row.querySelector('.svm-drive-row-progress') : null;
    const rowError = rowProgress ? rowProgress.querySelector('.svm-drive-row-error') : null;
    button.disabled = true;
    try {
      const state = await post('uwhsm_drive_upload_start', { backup_name: button.dataset.backup || '' });
      localStorage.setItem('svmDriveTransfer', state.transfer_id);
      const result = await runDriveTransfer(state.transfer_id, { progressRoot: rowProgress, errorRoot: rowError });
      if (result.complete) {
        const backupName = (button.dataset.backup || '').trim().toLowerCase();
        const verified = !!(result.driveInventory && result.driveInventory.ok && result.driveInventory.files.some(file => String(file.name || '').trim().toLowerCase() === backupName));
        if (!verified) {
          button.textContent = result.driveInventory && result.driveInventory.ok ? __('Send to Drive', 'uwh-site-migrator') : __('Drive status unavailable', 'uwh-site-migrator');
          button.disabled = !(result.driveInventory && result.driveInventory.ok && UWHSM_DATA.drive_connected);
          button.style.cursor = '';
          button.style.opacity = '';
        }
      } else {
        button.textContent = __('Resume Drive upload', 'uwh-site-migrator');
        button.disabled = false;
      }
    } catch (error) {
      if (rowError) { rowError.textContent = error.message; rowError.hidden = false; rowError.className = 'svm-log is-error svm-drive-row-error'; }
      button.textContent = __('Resume Drive upload', 'uwh-site-migrator'); button.disabled = false;
    }
  });
  const driveRefresh = $('#svm-drive-refresh'); if (driveRefresh) driveRefresh.addEventListener('click', loadDriveInventory);
  const driveResume = $('#svm-drive-resume'); if (driveResume) driveResume.addEventListener('click', () => runDriveTransfer(driveResume.dataset.transferId || localStorage.getItem('svmDriveTransfer')));
  const drivePanel = $('#svm-drive');
  const crossPageDriveSync = !!UWHSM_DATA.drive_connected && (!!document.querySelector('.svm-send-drive') || !!$('#svm-dashboard-drive-count'));
  if ((drivePanel && drivePanel.dataset.connected === '1') || crossPageDriveSync) {
    loadDriveInventory();
    if (drivePanel) {
      const storedTransfer = localStorage.getItem('svmDriveTransfer');
      const renderedTransfer = Array.from(document.querySelectorAll('[data-active-transfer]')).map(node => node.dataset.activeTransfer || '').find(Boolean) || '';
      const activeTransfer = storedTransfer || renderedTransfer; if (activeTransfer) runDriveTransfer(activeTransfer);
    }
  }
  document.addEventListener('click', async e => {
    const button = e.target.closest('.svm-recover-restore'); if (!button) return;
    if (resumeRestore && resumeRestore.disabled) return;
    button.disabled = true;
    try {
      const state = await post('uwhsm_restore_recover', {restore_id: button.dataset.restore});
      localStorage.setItem('svmRestoreJob', JSON.stringify({restore_id: state.restore_id, token: state.token}));
      await startRestoreWorkflow(state);
    } catch (error) { log($('#svm-restore-log'), error.message, true); }
    finally { button.disabled = false; }
  });
  document.addEventListener('click', async e => {
    const b = e.target.closest('.svm-delete'); 
    if (!b || b.classList.contains('svm-delete-drive')) return;
    if (!confirmDelete(__('Delete this backup permanently?', 'uwh-site-migrator'))) return;
    b.disabled = true;
    try {
      const row = b.closest('tr');
      const backupName = b.dataset.backup || b.value || (row && row.dataset.backup) || (row && row.querySelector('td strong') ? row.querySelector('td strong').textContent.trim() : '');
      if (!backupName) throw new Error(__('Could not identify this backup. Refresh the page and try again.', 'uwh-site-migrator'));
      const result = await post('uwhsm_delete_backup', { backup_name: backupName });
      backupRun++; localStorage.removeItem('svmBackupJob'); if (resumeBackup) resumeBackup.hidden = true; if (start) start.disabled = false;
      const backupProgress = $('#svm-backup-progress'); if (backupProgress) backupProgress.hidden = true;
      const backupLog = $('#svm-backup-log'); if (backupLog) log(backupLog, result.pending ? __('Deletion queued. The active backup batch will stop and remove its temporary files.', 'uwh-site-migrator') : (result.already_missing ? __('Temporary backup record cleared.', 'uwh-site-migrator') : __('Backup and its temporary job files were deleted.', 'uwh-site-migrator')));
      if (result.pending) { b.disabled = false; b.textContent = __('Check deletion', 'uwh-site-migrator'); }
      else { row.remove(); }
    }
    catch (err) { alert(err.message); b.disabled = false; }
  });

  // Google Drive Handlers added here
  document.addEventListener('click', async e => {
    const b = e.target.closest('.svm-delete-drive'); if (!b) return;
    if (!confirmDelete(__('Delete this backup permanently from Google Drive?', 'uwh-site-migrator'))) return;
    b.disabled = true;
    try {
      const result = await post('uwhsm_drive_delete', { file_id: b.dataset.id });
      $('#svm-drive-error').textContent = result.message || __('Deleted successfully from Drive.', 'uwh-site-migrator');
      $('#svm-drive-error').hidden = false;
      $('#svm-drive-error').className = 'svm-log is-ok';
      if ($('#svm-drive')) loadDriveInventory();
    } catch (err) {
      alert(err.message);
      b.disabled = false;
    }
  });

  document.addEventListener('click', async e => {
    const b = e.target.closest('.svm-drive-fetch'); if (!b) return;
    const row = b.closest('tr');
    const root = row ? row.querySelector('.svm-drive-import-progress') : null;
    const error = root ? root.querySelector('.svm-drive-import-error') : null;
    b.disabled = true; b.textContent = __('Importing...', 'uwh-site-migrator');
    if (error) { error.textContent = ''; error.hidden = true; }
    try {
      const initial = await post('uwhsm_drive_import_start', { file_id: b.dataset.id, name: b.dataset.name });
      const result = await runDriveImport(initial.import_id, root);
      if (result.complete) {
        b.textContent = __('Already in Local', 'uwh-site-migrator'); b.disabled = true; b.style.cursor = 'not-allowed'; b.style.opacity = '0.65';
        if (Array.isArray(UWHSM_DATA.local_backups) && !UWHSM_DATA.local_backups.includes(b.dataset.name)) UWHSM_DATA.local_backups.push(b.dataset.name);
      }
    } catch (err) {
      if (error) { error.textContent = err.message; error.hidden = false; error.className = 'svm-log is-error svm-drive-import-error'; }
      b.textContent = __('Resume Import', 'uwh-site-migrator'); b.disabled = false;
    }
  });

  document.addEventListener('click', async e => {
    const b = e.target.closest('.svm-checkpoint-rollback'); if (!b) return;
    if (!window.confirm(__('Rollback this website to the selected safety checkpoint? A new checkpoint of the current site will be created first.', 'uwh-site-migrator'))) return;
    b.disabled = true;
    try {
      const state = await post('uwhsm_checkpoint_rollback_start', { checkpoint_name: b.dataset.checkpoint || '' });
      progress($('#svm-restore-progress'), 35, state.label || __('Creating safety checkpoint before rollback', 'uwh-site-migrator'));
      await startRestoreWorkflow(state);
    } catch (err) { const l = $('#svm-restore-log'); if (l) log(l, err.message, true); b.disabled = false; }
  });

  document.addEventListener('click', async e => {
    const b = e.target.closest('.svm-checkpoint-delete'); if (!b) return;
    if (!window.confirm(__('Delete this restore safety checkpoint permanently?', 'uwh-site-migrator'))) return;
    b.disabled = true;
    try { await post('uwhsm_checkpoint_delete', { checkpoint_name: b.dataset.checkpoint || '' }); const row = b.closest('tr'); if (row) row.remove(); }
    catch (err) { alert(err.message); b.disabled = false; }
  });

  const directPrepare = $('#svm-direct-source-prepare'), directBackup = $('#svm-direct-backup'), directKeyCreate = $('#svm-direct-key-create');
  const directSourceProgress = $('#svm-direct-source-progress'), directSourceLog = $('#svm-direct-source-log');
  const directKeyBox = $('#svm-direct-key-box'), directSourceOut = $('#svm-direct-source-url-out'), directKeyOut = $('#svm-direct-key-out'), directExpiry = $('#svm-direct-key-expiry'), directKeyStatus = $('#svm-direct-key-status');
  let activeDirectKey = '', activeDirectKeyExpires = 0;

  const remainingTimeLabel = expires => {
    const seconds = Math.max(0, Number(expires || 0) - Math.floor(Date.now() / 1000));
    if (!seconds) return __('Expired', 'uwh-site-migrator');
    const hours = Math.floor(seconds / 3600), minutes = Math.max(1, Math.floor((seconds % 3600) / 60));
    if (hours) return sprintf(
      /* translators: 1: hours remaining, 2: minutes remaining. */
      __('%1$dh %2$dm left', 'uwh-site-migrator'),
      hours,
      minutes
    );
    return sprintf(
      /* translators: %s: minutes remaining. */
      __('%s min left', 'uwh-site-migrator'),
      minutes
    );
  };

  const setStatusChip = (node, state, text) => {
    if (!node) return;
    node.classList.remove('is-neutral', 'is-ready', 'is-warning', 'is-error', 'is-checking');
    node.classList.add(state || 'is-neutral');
    node.textContent = text || '';
  };

  const refreshSourceKeyStatus = () => {
    if (!directKeyStatus || !activeDirectKeyExpires) return;
    const valid = Number(activeDirectKeyExpires) > Math.floor(Date.now() / 1000);
    setStatusChip(directKeyStatus, valid ? 'is-ready' : 'is-error', valid ? __('Valid', 'uwh-site-migrator') : __('Expired', 'uwh-site-migrator'));
    if (directExpiry) directExpiry.textContent = valid ? remainingTimeLabel(activeDirectKeyExpires) : __('Generate a new key before migrating.', 'uwh-site-migrator');
    const copyButton = $('#svm-direct-copy');
    if (copyButton) copyButton.disabled = !valid;
  };

  const showDirectKey = result => {
    activeDirectKey = result.migration_key || '';
    activeDirectKeyExpires = Number(result.expires || 0);
    if (directSourceOut) directSourceOut.value = result.source_url || '';
    if (directKeyOut) directKeyOut.value = activeDirectKey;
    refreshSourceKeyStatus();
    if (directKeyBox) directKeyBox.hidden = false;
    if (directSourceLog) log(directSourceLog, sprintf(
      /* translators: %s: backup filename. */
      __('Secure migration key ready for %s.', 'uwh-site-migrator'),
      result.backup_name || __('the selected backup', 'uwh-site-migrator')
    ));
  };
  const createDirectKey = async backupName => {
    if (!backupName) throw new Error(__('Choose or prepare a completed backup first.', 'uwh-site-migrator'));
    const result = await post('uwhsm_site_transfer_key_create', { backup_name: backupName }); showDirectKey(result); return result;
  };
  if (directKeyCreate) directKeyCreate.addEventListener('click', async () => {
    directKeyCreate.disabled = true; try { await createDirectKey(directBackup ? directBackup.value : ''); } catch (e) { log(directSourceLog, e.message, true); } finally { directKeyCreate.disabled = false; }
  });
  if (directPrepare) directPrepare.addEventListener('click', async () => {
    directPrepare.disabled = true; if (directSourceLog) directSourceLog.textContent = '';
    try {
      let state = await post('uwhsm_backup_start', { delivery: 'local' });
      progress(directSourceProgress, state.progress || 1, __('Preparing source migration package', 'uwh-site-migrator'));
      while (!state.done) { await sleep(state.busy ? 1200 : 120); state = await post('uwhsm_backup_step', { job: state.job }); progress(directSourceProgress, state.progress, state.label); }
      progress(directSourceProgress, 100, __('Migration package ready', 'uwh-site-migrator'));
      if (directBackup && state.name) {
        let option = Array.from(directBackup.options).find(o => o.value === state.name);
        if (!option) { option = new Option(state.name, state.name, true, true); directBackup.add(option); } else directBackup.value = state.name;
      }
      await createDirectKey(state.name);
    } catch (e) { log(directSourceLog, e.message, true); directPrepare.disabled = false; }
  });

  const directCopy = $('#svm-direct-copy'); if (directCopy) directCopy.addEventListener('click', async () => {
    const sourceUrl = directSourceOut ? directSourceOut.value.trim() : '';
    const migrationKey = directKeyOut ? directKeyOut.value.trim() : '';
    const text = sourceUrl + '\n' + migrationKey;
    const copiedLabel = () => {
      directCopy.textContent = __('✓ Copied', 'uwh-site-migrator');
      directCopy.classList.add('is-copied');
      setTimeout(() => { directCopy.textContent = __('Copy connection details', 'uwh-site-migrator'); directCopy.classList.remove('is-copied'); }, 1800);
    };
    try {
      if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') throw new Error('clipboard-unavailable');
      await navigator.clipboard.writeText(text);
      copiedLabel();
    } catch (_) {
      const fallback = document.createElement('textarea');
      fallback.value = text;
      fallback.setAttribute('readonly', 'readonly');
      fallback.setAttribute('aria-hidden', 'true');
      fallback.style.position = 'fixed'; fallback.style.left = '-9999px'; fallback.style.top = '0';
      document.body.appendChild(fallback); fallback.focus(); fallback.select();
      let copied = false;
      try { copied = document.execCommand('copy'); } catch (_) { copied = false; }
      fallback.remove();
      if (copied) copiedLabel(); else if (directKeyOut) directKeyOut.focus();
    }
  });
  const directRevoke = $('#svm-direct-revoke'); if (directRevoke) directRevoke.addEventListener('click', async () => {
    if (!activeDirectKey) return; directRevoke.disabled = true;
    try {
      await post('uwhsm_site_transfer_key_revoke', { migration_key: activeDirectKey });
      activeDirectKey = ''; activeDirectKeyExpires = 0;
      if (directKeyBox) directKeyBox.hidden = true;
      log(directSourceLog, __('Migration key revoked.', 'uwh-site-migrator'));
    } catch (e) { log(directSourceLog, e.message, true); } finally { directRevoke.disabled = false; }
  });

  const directSourceUrl = $('#svm-direct-source-url'), directDestinationKey = $('#svm-direct-key'), directConfirm = $('#svm-direct-confirm'), directStart = $('#svm-direct-start'), directResume = $('#svm-direct-resume');
  const directPaste = $('#svm-direct-paste'), directPasteCatcher = $('#svm-direct-paste-catcher'), directTest = $('#svm-direct-test');
  const directReadiness = $('#svm-direct-readiness'), directDestinationKeyStatus = $('#svm-direct-destination-key-status');
  const directPreflight = $('#svm-direct-preflight'), directPreflightSummary = $('#svm-direct-preflight-summary'), directPreflightList = $('#svm-direct-preflight-list');
  const directTargetDomain = $('#svm-direct-target-domain'), directConfirmText = $('#svm-direct-confirm-text');
  const directDestinationProgress = $('#svm-direct-destination-progress'), directDestinationLog = $('#svm-direct-destination-log');
  const directSuccess = $('#svm-direct-success'), directSuccessLog = $('#svm-direct-success-log');
  let directPreflightReady = false, directVerifiedSignature = '', destinationKeyExpires = 0, directMigrationBusy = false;
  let directLastPreflight = null, directOperationStartedAt = 0, directFinalState = null;

  const normalizeDirectSourceUrl = value => {
    let raw = String(value || '').trim();
    if (!raw) return '';
    if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(raw)) raw = 'https://' + raw;
    raw = raw.replace(/^http:\/\//i, 'https://');
    let parsed;
    try { parsed = new URL(raw); } catch (_) { return raw; }
    if (!parsed.hostname) return raw;
    parsed.protocol = 'https:'; parsed.username = ''; parsed.password = ''; parsed.hash = ''; parsed.search = '';
    let path = parsed.pathname || '/';
    const adminIndex = path.search(/\/wp-admin(?:\/|$)|\/wp-login\.php(?:\/|$)/i);
    if (adminIndex >= 0) path = path.slice(0, adminIndex) || '/';
    path = path.replace(/\/{2,}/g, '/');
    if (path !== '/') path = path.replace(/\/+$/, '') + '/';
    parsed.pathname = path;
    return parsed.toString();
  };

  const currentDirectSignature = () => {
    const url = directSourceUrl ? normalizeDirectSourceUrl(directSourceUrl.value) : '';
    const key = directDestinationKey ? directDestinationKey.value.trim().toLowerCase() : '';
    return url + '\n' + key;
  };

  const updateDestinationKeyStatus = () => {
    if (!directDestinationKeyStatus) return;
    if (!destinationKeyExpires) {
      setStatusChip(directDestinationKeyStatus, 'is-neutral', __('Key not checked', 'uwh-site-migrator'));
      return;
    }
    const valid = destinationKeyExpires > Math.floor(Date.now() / 1000);
    setStatusChip(directDestinationKeyStatus, valid ? 'is-ready' : 'is-error', valid ? sprintf(
      /* translators: %s: remaining key lifetime. */
      __('Key valid · %s', 'uwh-site-migrator'),
      remainingTimeLabel(destinationKeyExpires)
    ) : __('Key expired', 'uwh-site-migrator'));
  };

  const renderDirectReadiness = () => {
    const same = directVerifiedSignature && directVerifiedSignature === currentDirectSignature();
    const keyStillValid = !destinationKeyExpires || destinationKeyExpires > Math.floor(Date.now() / 1000);
    if (!same || !directPreflightReady || !keyStillValid) {
      setStatusChip(directReadiness, 'is-neutral', __('Not ready', 'uwh-site-migrator'));
    } else if (!directConfirm || !directConfirm.checked) {
      setStatusChip(directReadiness, 'is-warning', __('Checks passed · confirm destination', 'uwh-site-migrator'));
    } else {
      setStatusChip(directReadiness, 'is-ready', __('Ready to migrate', 'uwh-site-migrator'));
    }
  };

  const toggleDirectStart = () => {
    if (directStart) directStart.disabled = directMigrationBusy || !(directSourceUrl && directSourceUrl.value.trim() && directDestinationKey && directDestinationKey.value.trim() && directConfirm && directConfirm.checked) || UWHSM_DATA.multisite;
    if (directTest) directTest.disabled = directMigrationBusy || !(directSourceUrl && directSourceUrl.value.trim() && directDestinationKey && directDestinationKey.value.trim()) || UWHSM_DATA.multisite;
    renderDirectReadiness();
  };

  const invalidateDirectTest = () => {
    if (directVerifiedSignature && directVerifiedSignature !== currentDirectSignature()) {
      directVerifiedSignature = ''; directPreflightReady = false; destinationKeyExpires = 0;
      if (directPreflight) directPreflight.hidden = true;
      updateDestinationKeyStatus();
    }
    toggleDirectStart();
  };

  [directSourceUrl, directDestinationKey].forEach(el => { if (el) { el.addEventListener('input', invalidateDirectTest); el.addEventListener('change', invalidateDirectTest); } });
  if (directConfirm) directConfirm.addEventListener('change', toggleDirectStart);
  if (directSourceUrl) directSourceUrl.addEventListener('blur', () => {
    const normalized = normalizeDirectSourceUrl(directSourceUrl.value);
    if (normalized && normalized !== directSourceUrl.value) {
      directSourceUrl.value = normalized;
      invalidateDirectTest();
    }
  });

  const destinationDomain = String((UWHSM_DATA && UWHSM_DATA.destinationDomain) || '').trim() || (() => { try { return new URL(UWHSM_DATA.home).hostname; } catch (_) { return __('this destination', 'uwh-site-migrator'); } })();
  if (directTargetDomain) directTargetDomain.textContent = destinationDomain;
  if (directConfirmText) directConfirmText.textContent = sprintf(
    /* translators: %s: destination website domain. */
    __('I understand %s will be replaced after an automatic rollback checkpoint is created.', 'uwh-site-migrator'),
    destinationDomain
  );

  const parseDirectConnectionDetails = raw => {
    raw = String(raw || '').replace(/^\uFEFF/, '').trim();
    if (!raw) throw new Error(__('Clipboard does not contain connection details.', 'uwh-site-migrator'));
    const urlMatch = raw.match(/https?:\/\/[^\s<>"']+|(?:www\.)?[a-z0-9.-]+\.[a-z]{2,}(?:\/[^\s<>"']*)?/i);
    const keyMatch = raw.match(/svm1_[a-f0-9]{24}_[a-f0-9]{64}/i);
    const sourceUrl = urlMatch ? normalizeDirectSourceUrl(urlMatch[0]) : '';
    const migrationKey = keyMatch ? keyMatch[0] : '';
    if (!sourceUrl || !migrationKey) throw new Error(__('Clipboard connection details are invalid. Copy them again from the source site.', 'uwh-site-migrator'));
    let parsedUrl;
    try { parsedUrl = new URL(sourceUrl); } catch (_) { throw new Error(__('The copied source URL is invalid.', 'uwh-site-migrator')); }
    if (parsedUrl.protocol !== 'https:' || parsedUrl.username || parsedUrl.password) throw new Error(__('The copied source URL must be a secure HTTPS WordPress URL.', 'uwh-site-migrator'));
    if (!/^svm1_[a-f0-9]{24}_[a-f0-9]{64}$/i.test(migrationKey)) throw new Error(__('The copied migration key is invalid.', 'uwh-site-migrator'));
    return { sourceUrl: parsedUrl.toString(), migrationKey: migrationKey.toLowerCase() };
  };

  const setDirectPasteLabel = (label, withIcon = true) => {
    if (!directPaste) return;
    directPaste.textContent = '';
    if (withIcon) {
      const icon = document.createElement('span'); icon.className = 'dashicons dashicons-clipboard'; icon.setAttribute('aria-hidden', 'true'); directPaste.appendChild(icon);
    }
    directPaste.appendChild(document.createTextNode(label));
  };

  const applyDirectConnectionDetails = raw => {
    const details = parseDirectConnectionDetails(raw);
    directSourceUrl.value = details.sourceUrl;
    directDestinationKey.value = details.migrationKey;
    directVerifiedSignature = ''; directPreflightReady = false; destinationKeyExpires = 0;
    if (directPreflight) directPreflight.hidden = true;
    directSourceUrl.dispatchEvent(new Event('input', { bubbles: true }));
    directDestinationKey.dispatchEvent(new Event('input', { bubbles: true }));
    toggleDirectStart();
    if (directDestinationLog) log(directDestinationLog, __('Connection details pasted into the correct fields.', 'uwh-site-migrator'));
    setDirectPasteLabel(__('✓ Pasted', 'uwh-site-migrator'), false);
    setTimeout(() => setDirectPasteLabel(__('Paste connection details', 'uwh-site-migrator')), 1500);
    if (directTest) directTest.focus(); else if (directConfirm) directConfirm.focus();
    return true;
  };

  const readDirectClipboardText = async () => {
    if (window.isSecureContext && navigator.clipboard && typeof navigator.clipboard.readText === 'function') {
      try { const text = await navigator.clipboard.readText(); if (String(text || '').trim()) return text; } catch (_) {}
    }
    try { if (window.clipboardData && typeof window.clipboardData.getData === 'function') { const text = window.clipboardData.getData('Text'); if (String(text || '').trim()) return text; } } catch (_) {}
    return '';
  };
  const closeDirectPasteCatcher = () => { if (!directPasteCatcher) return; directPasteCatcher.value = ''; directPasteCatcher.hidden = true; };
  const openDirectPasteCatcher = () => {
    if (!directPasteCatcher) return false;
    directPasteCatcher.value = ''; directPasteCatcher.hidden = false;
    requestAnimationFrame(() => directPasteCatcher.focus());
    setDirectPasteLabel(__('Press Ctrl+V', 'uwh-site-migrator'), false);
    return true;
  };
  const handleDirectPasteText = raw => { try { applyDirectConnectionDetails(raw); closeDirectPasteCatcher(); return true; } catch (_) { return false; } };

  if (directSourceUrl && directDestinationKey) {
    [directSourceUrl, directDestinationKey, directPasteCatcher].filter(Boolean).forEach(target => {
      target.addEventListener('paste', event => {
        const clipboard = event.clipboardData || window.clipboardData;
        if (!clipboard || typeof clipboard.getData !== 'function') return;
        const raw = clipboard.getData('text/plain') || clipboard.getData('Text') || '';
        if (!raw || !/svm1_[a-f0-9]{24}_[a-f0-9]{64}/i.test(raw)) return;
        if (handleDirectPasteText(raw)) event.preventDefault();
      });
    });
  }
  if (directPasteCatcher) {
    directPasteCatcher.addEventListener('input', () => { const raw = directPasteCatcher.value; if (/svm1_[a-f0-9]{24}_[a-f0-9]{64}/i.test(raw)) handleDirectPasteText(raw); });
    directPasteCatcher.addEventListener('blur', () => { if (!directPasteCatcher.hidden && !directPasteCatcher.value.trim()) { closeDirectPasteCatcher(); setDirectPasteLabel(__('Paste connection details', 'uwh-site-migrator')); } });
  }
  if (directPaste) directPaste.addEventListener('click', async () => {
    if (!directSourceUrl || !directDestinationKey) return;
    directPaste.disabled = true;
    try {
      const text = await readDirectClipboardText();
      if (text) { applyDirectConnectionDetails(text); return; }
      if (!openDirectPasteCatcher()) throw new Error(__('Could not read connection details from the clipboard.', 'uwh-site-migrator'));
    } catch (e) {
      const message = e && e.message ? e.message : __('Could not read connection details from the clipboard.', 'uwh-site-migrator');
      if (directDestinationLog) log(directDestinationLog, message, true);
    } finally { directPaste.disabled = false; }
  });

  const renderPreflightChecks = result => {
    if (!directPreflight || !directPreflightList) return;
    directPreflightList.textContent = '';
    const checks = Array.isArray(result.checks) ? result.checks : [];
    let passed = 0, warnings = 0, failed = 0;
    checks.forEach(check => {
      const status = ['pass', 'warning', 'fail'].includes(check.status) ? check.status : 'fail';
      if (status === 'pass') passed++; else if (status === 'warning') warnings++; else failed++;
      const row = document.createElement('div'); row.className = 'svm-direct-preflight-item is-' + status;
      const icon = document.createElement('span'); icon.className = 'svm-direct-preflight-icon'; icon.textContent = status === 'pass' ? '✓' : (status === 'warning' ? '!' : '×');
      const copy = document.createElement('span');
      const title = document.createElement('strong'); title.textContent = check.label || __('Check', 'uwh-site-migrator');
      const message = document.createElement('small'); message.textContent = check.message || '';
      copy.appendChild(title); copy.appendChild(message); row.appendChild(icon); row.appendChild(copy); directPreflightList.appendChild(row);
    });
    if (directPreflightSummary) directPreflightSummary.textContent = sprintf(
      /* translators: 1: passed checks, 2: warnings, 3: failed checks. */
      __('%1$d passed · %2$d warning · %3$d issue', 'uwh-site-migrator'),
      passed, warnings, failed
    );
    directPreflight.hidden = false;
  };

  const runDirectConnectionTest = async () => {
    if (!directSourceUrl || !directDestinationKey) throw new Error(__('Enter the source URL and migration key first.', 'uwh-site-migrator'));
    const normalized = normalizeDirectSourceUrl(directSourceUrl.value);
    if (normalized) directSourceUrl.value = normalized;
    const signature = currentDirectSignature();
    if (!normalized || !directDestinationKey.value.trim()) throw new Error(__('Enter the source URL and migration key first.', 'uwh-site-migrator'));
    if (directTest) directTest.disabled = true;
    setStatusChip(directReadiness, 'is-checking', __('Checking…', 'uwh-site-migrator'));
    if (directDestinationKeyStatus) setStatusChip(directDestinationKeyStatus, 'is-checking', __('Checking key…', 'uwh-site-migrator'));
    try {
      const result = await post('uwhsm_site_connection_test', { source_url: normalized, migration_key: directDestinationKey.value.trim() });
      renderPreflightChecks(result);
      directLastPreflight = result;
      directVerifiedSignature = signature;
      directPreflightReady = !!result.ready;
      destinationKeyExpires = Number(result.expires || 0);
      updateDestinationKeyStatus();
      renderDirectReadiness();
      if (directDestinationLog) log(directDestinationLog, result.message || (result.ready ? __('Connection and pre-flight checks passed.', 'uwh-site-migrator') : __('Pre-flight checks need attention.', 'uwh-site-migrator')), !result.ready);
      return result;
    } catch (e) {
      directVerifiedSignature = ''; directPreflightReady = false; destinationKeyExpires = 0;
      updateDestinationKeyStatus();
      setStatusChip(directReadiness, 'is-error', __('Connection failed', 'uwh-site-migrator'));
      if (directDestinationLog) log(directDestinationLog, e.message, true);
      throw e;
    } finally { if (directTest) directTest.disabled = false; toggleDirectStart(); }
  };
  if (directTest) directTest.addEventListener('click', async () => { try { await runDirectConnectionTest(); } catch (_) {} });

  const showDirectSuccess = state => {
    if (!directSuccess) return;
    directFinalState = state || null;
    if (!state || state.rolled_back || state.homepage_ok !== true) { directSuccess.hidden = true; return; }
    directSuccess.hidden = false;
    directSuccess.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  };
  document.addEventListener('uwhsm:restore-complete', event => showDirectSuccess(event.detail || {}));
  if (directSuccessLog) directSuccessLog.addEventListener('click', () => {
    const report = [];
    report.push(__('United Web Host Site Migrator — Direct Migration Log', 'uwh-site-migrator'));
    report.push(__('Generated: ', 'uwh-site-migrator') + new Date().toISOString());
    report.push(__('Source: ', 'uwh-site-migrator') + (directSourceUrl ? normalizeDirectSourceUrl(directSourceUrl.value) : ''));
    report.push(__('Destination: ', 'uwh-site-migrator') + destinationDomain);
    if (directOperationStartedAt) report.push(__('Started: ', 'uwh-site-migrator') + new Date(directOperationStartedAt).toISOString());
    if (directLastPreflight) {
      report.push(''); report.push(__('PRE-FLIGHT CHECK', 'uwh-site-migrator'));
      (Array.isArray(directLastPreflight.checks) ? directLastPreflight.checks : []).forEach(check => {
        report.push('[' + String(check.status || '').toUpperCase() + '] ' + String(check.label || '') + (check.message ? ' — ' + String(check.message) : ''));
      });
      if (directLastPreflight.backup_name) report.push(__('Backup: ', 'uwh-site-migrator') + directLastPreflight.backup_name);
      if (directLastPreflight.package_size) report.push(__('Package bytes: ', 'uwh-site-migrator') + String(directLastPreflight.package_size));
    }
    report.push(''); report.push(__('RESULT', 'uwh-site-migrator'));
    report.push(directFinalState && directFinalState.homepage_ok ? __('Migration completed and homepage check passed.', 'uwh-site-migrator') : __('Migration result requires review.', 'uwh-site-migrator'));
    const visible = directDestinationLog ? directDestinationLog.innerText.trim() : '';
    if (visible) report.push(visible);
    report.push(''); report.push(__('Security: migration keys and restore tokens are intentionally excluded from this log.', 'uwh-site-migrator'));
    const blob = new Blob([report.join('\n')], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob), link = document.createElement('a');
    link.href = url; link.download = 'site-migrator-direct-migration-log.txt'; document.body.appendChild(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
  });

  const continueDirectPull = async pull => {
    localStorage.setItem('svmDirectPull', pull.pull_id);
    if (directResume) directResume.hidden = true;
    const startedAt = Date.now(), initialBytes = Number(pull.bytes_received) || 0;
    while (!pull.done) {
      const elapsed = Math.max(.001, (Date.now() - startedAt) / 1000);
      const speed = Math.max(0, Number(pull.bytes_received) - initialBytes) / elapsed;
      progress(directDestinationProgress, Number(pull.progress || 0) * .35, sprintf(
        /* translators: 1: transfer status, 2: downloaded size, 3: total size, 4: speed in MB/s. */
        __('%1$s · %2$s / %3$s · %4$s MB/s', 'uwh-site-migrator'),
        pull.label,
        humanBytes(pull.bytes_received),
        humanBytes(pull.bytes_total),
        (speed / 1048576).toFixed(2)
      ));
      await sleep(pull.busy ? 1200 : 100); pull = await post('uwhsm_site_pull_step', { pull_id: pull.pull_id });
    }
    progress(directDestinationProgress, 35, __('Source package downloaded — validating and creating safety checkpoint', 'uwh-site-migrator'));
    let restore = await post('uwhsm_site_pull_restore', { pull_id: pull.pull_id });
    localStorage.removeItem('svmDirectPull');
    return startRestoreWorkflow(restore, { progress: directDestinationProgress, log: directDestinationLog });
  };
  if (directStart) directStart.addEventListener('click', async () => {
    if (directStart.disabled) return;
    directMigrationBusy = true;
    directOperationStartedAt = Date.now();
    directFinalState = null;
    directStart.disabled = true;
    if (directSuccess) directSuccess.hidden = true;
    if (directDestinationLog) directDestinationLog.textContent = '';
    try {
      const cachedCheckStillValid = directVerifiedSignature === currentDirectSignature() && directPreflightReady && (!destinationKeyExpires || destinationKeyExpires > Math.floor(Date.now() / 1000));
      const check = cachedCheckStillValid ? { ready: true } : await runDirectConnectionTest();
      if (!check.ready) throw new Error(__('Fix the failed pre-flight checks before starting migration.', 'uwh-site-migrator'));
      if (!directConfirm || !directConfirm.checked) throw new Error(__('Confirm the destination replacement before starting migration.', 'uwh-site-migrator'));
      const pull = await post('uwhsm_site_pull_start', { source_url: normalizeDirectSourceUrl(directSourceUrl.value), migration_key: directDestinationKey.value.trim() });
      const finalState = await continueDirectPull(pull);
      showDirectSuccess(finalState);
    } catch (e) {
      log(directDestinationLog, e.message, true);
      if (directResume && localStorage.getItem('svmDirectPull')) directResume.hidden = false;
    } finally { directMigrationBusy = false; toggleDirectStart(); }
  });
  if (directResume) {
    directResume.hidden = !localStorage.getItem('svmDirectPull');
    directResume.addEventListener('click', async () => {
      const pullId = localStorage.getItem('svmDirectPull'); if (!pullId) return;
      directResume.disabled = true;
      try { const pull = await post('uwhsm_site_pull_status', { pull_id: pullId }); const finalState = await continueDirectPull(pull); showDirectSuccess(finalState); }
      catch (e) { log(directDestinationLog, e.message, true); directResume.hidden = false; }
      finally { directResume.disabled = false; }
    });
  }

  if (directSourceUrl || directKeyStatus) {
    setInterval(() => { refreshSourceKeyStatus(); updateDestinationKeyStatus(); renderDirectReadiness(); }, 30000);
  }
  toggleDirectStart();

  function confirmDelete(message) { return window.confirm(message); }
  
  // Auto backup settings save handler (Updated with UWHSM_DATA)
  jQuery(document).ready(function($) {
      $('#save-auto-backup').on('click', function(e) {
          e.preventDefault();
          const $btn = $(this);$btn.prop('disabled', true);

          $.post(UWHSM_DATA.ajax, {
              action: 'uwhsm_save_auto_backup',
              _ajax_nonce: UWHSM_DATA.nonce,
              frequency: $('#auto-freq').val(),
              backup_time: $('#auto-backup-time').val(),
              drive_retention: $('#auto-drive-retention').val()
          }, function(response) {
              $btn.prop('disabled', false);
              if (response && response.success) {
                  alert(response.data.message || __('Settings saved successfully!', 'uwh-site-migrator'));
              } else {
                  let errorMsg = (response && response.data && response.data.message) ? response.data.message : __('Error saving settings.', 'uwh-site-migrator');
                  alert(errorMsg);
              }
          }).fail(function(xhr) {
              $btn.prop('disabled', false);
              const response = xhr && xhr.responseJSON;
              const message = response && response.data && response.data.message
                  ? response.data.message
                  : __('Server request failed. Please try again.', 'uwh-site-migrator');
              alert(message);
          });
      });
  });
})();