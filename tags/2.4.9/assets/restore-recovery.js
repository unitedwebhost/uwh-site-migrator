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
    render('No saved restore token was found in this browser. Do not start a new restore. Preserve the hosting files, database and rollback checkpoint; ask the hosting administrator for recovery.');
    return;
  }
  render('A saved restore session was found. Keep this tab open while resuming.');
  button.disabled = false;
  button.addEventListener('click', async () => {
    button.disabled = true;
    render('Contacting WordPress to resume the saved restore...');
    let busyCount = 0;
    try {
      while (true) {
        const params = new URLSearchParams({ action: 'uwhsm_restore_step', restore_id: saved.restore_id, token: saved.token });
        const response = await fetch(ajax, { method: 'POST', credentials: 'omit', body: params, cache: 'no-store' });
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('json')) throw new Error('WordPress did not return JSON. Check the host error log and confirm the plugin is active.');
        const json = await response.json();
        if (!json.success || !json.data) throw new Error((json.data && json.data.message) || 'Resume request failed. Preserve the checkpoint.');
        const state = json.data;
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
      render('Restore is still incomplete: ' + error.message + '\nDo not remove the backup or checkpoint. If this keeps happening, use your hosting recovery tools.');
      button.disabled = false;
    }
  });
})();
