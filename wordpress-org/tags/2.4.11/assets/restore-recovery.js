(() => {
  'use strict';
  const root = document.querySelector('main[data-ajax]');
  const button = document.getElementById('resume');
  const status = document.getElementById('status');
  if (!root || !button || !status) return;
  const ajax = root.getAttribute('data-ajax');
  let saved;
  const render = text => { status.textContent = text; };
  try {
    saved = JSON.parse(localStorage.getItem('svmRestoreJob') || 'null');
  } catch (_) { saved = null; }
  if (!saved || !/^[a-f0-9]{20,64}$/.test(saved.restore_id || '') || !/^[a-f0-9]{64}$/.test(saved.token || '')) {
    render('No saved restore token was found in this browser. Do not start a new restore. Contact the administrator to check the restore status or turn off a stuck maintenance guard in Troubleshooting.');
    return;
  }
  render('A saved restore session was found. Keep this tab open while resuming.');
  button.disabled = false;
  button.addEventListener('click', async () => {
    button.disabled = true;
    render('Contacting WordPress to resume the saved restore...');
    let busyCount = 0, responseFailures = 0, previousReceipt = '';
    try {
      while (true) {
        const request = async action => {
          const params = new URLSearchParams({ action, restore_id: saved.restore_id, token: saved.token });
          const response = await fetch(ajax, { method: 'POST', credentials: 'omit', body: params, cache: 'no-store' });
          const contentType = response.headers.get('content-type') || '';
          if (!contentType.includes('json')) throw new Error('Server returned HTML or empty output (HTTP ' + response.status + ').');
          const json = await response.json();
          if (!json.success || !json.data) throw new Error((json.data && json.data.message) || 'Restore request failed. Review the activity log.');
          return json.data;
        };
        let state;
        try {
          state = await request('uwhsm_restore_step');
          responseFailures = 0;
        } catch (error) {
          // A lost AJAX response must not automatically restart the whole
          // restore. The read-only receipt identifies the last saved batch.
          if (!/HTTP |Failed to fetch|NetworkError|Load failed/i.test(error.message)) throw error;
          try {
            state = await request('uwhsm_restore_status');
            if (state.phase === 'failed' || state.phase === 'cancelled' || state.last_error) {
              throw new Error(state.last_error || 'Restore stopped on the server.');
            }
            const fingerprint = [state.phase, state.file_index, state.file_progress, state.progress].join(':');
            responseFailures = fingerprint === previousReceipt ? responseFailures + 1 : 0;
            previousReceipt = fingerprint;
            if (responseFailures >= 5) throw new Error('The server has repeatedly stopped at the same saved position. Inspect the hosting PHP error log before retrying.');
            render('Recovered saved position after a lost server response. ' + (state.label || 'Restoring website') + ' — ' + Math.round(state.progress || 0) + '%');
            await new Promise(resolve => setTimeout(resolve, 1200 * (responseFailures + 1)));
          } catch (statusError) {
            throw new Error(error.message + ' ' + statusError.message);
          }
        }
        if (state.restore_id !== saved.restore_id || state.token !== saved.token) {
          if (!/^[a-f0-9]{20,64}$/.test(state.restore_id || '') || !/^[a-f0-9]{64}$/.test(state.token || '')) throw new Error('Unexpected restore session.');
          saved = { restore_id: state.restore_id, token: state.token };
          localStorage.setItem('svmRestoreJob', JSON.stringify(saved));
        }
        render((state.label || 'Resuming restore') + ' — ' + (Math.round(state.progress || 0)) + '%' + (state.busy ? ' (previous request is finishing)' : ''));
        if (state.done) {
          localStorage.removeItem('svmRestoreJob');
          render(state.rolled_back ? 'Automatic rollback completed. Review the site and log in using the appropriate credentials.' : 'Migration completed. Check the site and log in with the source site administrator credentials.');
          return;
        }
        if (state.busy) {
          busyCount++;
          if (busyCount > 240) throw new Error('The previous batch has not completed after 8 minutes. Inspect the server log; do not start another restore.');
        } else { busyCount = 0; }
        await new Promise(resolve => setTimeout(resolve, state.busy ? 2000 : 250));
      }
    } catch (error) {
      render('Restore is still incomplete: ' + error.message + '\nDo not remove any backup files. If this keeps happening, contact your hosting administrator.');
      button.disabled = false;
    }
  });
})();
