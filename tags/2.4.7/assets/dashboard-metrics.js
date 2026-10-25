(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.UWHSM_DASHBOARD = api;
})(typeof window !== 'undefined' ? window : null, function () {
  'use strict';
  const i18n = (typeof wp !== 'undefined' && wp.i18n) ? wp.i18n : { __: text => text };
  const { __ } = i18n;

  function summarizeDrive(files, localTimestamp) {
    const rows = Array.isArray(files) ? files : [];
    let count = 0;
    let bytes = 0;
    let driveLatest = 0;

    rows.forEach(row => {
      count += 1;
      bytes += Math.max(0, Number(row && row.size) || 0);
      driveLatest = Math.max(driveLatest, Math.max(0, Number(row && row.created) || 0));
    });

    const localLatest = Math.max(0, Number(localTimestamp) || 0);
    return {
      count,
      bytes,
      latestTimestamp: Math.max(localLatest, driveLatest),
      latestSource: driveLatest > localLatest ? __('Google Drive', 'uwh-site-migrator') : __('Local backup', 'uwh-site-migrator')
    };
  }

  return { summarizeDrive };
});
