(() => {
  'use strict';
  const { __ } = wp.i18n;
  document.addEventListener('click', async event => {
    const button = event.target.closest('.svm-bg-control');
    if (!button) return;
    button.disabled = true;
    const log = document.querySelector('#svm-background-log');
    try {
      const response = await fetch(UWHSM_DATA.ajax, {
        method: 'POST', credentials: 'same-origin',
        body: new URLSearchParams({action: 'uwhsm_background_configure', _ajax_nonce: UWHSM_DATA.nonce, job: button.dataset.job, enabled: button.dataset.enabled})
      });
      const result = await response.json();
      if (!result.success) throw new Error(result.data.message || __('Could not change background settings.', 'uwh-site-migrator'));
      log.textContent = result.data.message;
      button.dataset.enabled = button.dataset.enabled === '1' ? '0' : '1';
      button.textContent = button.dataset.enabled === '1' ? __('Resume background', 'uwh-site-migrator') : __('Pause background', 'uwh-site-migrator');
    } catch (error) { log.textContent = error.message; }
    finally { button.disabled = false; }
  });
})();
