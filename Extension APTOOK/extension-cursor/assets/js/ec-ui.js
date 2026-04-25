window.ExtensionCursorModules = window.ExtensionCursorModules || {};
window.ExtensionCursorModules.createEcUI = function createEcUI($) {
  let noticeTimer = null;

  function pressEffect($el) {
    $el.addClass('is-pressed');
    window.setTimeout(() => $el.removeClass('is-pressed'), 160);
  }

  function setLoading($button, isLoading, text) {
    if (!$button || !$button.length) return;
    if (isLoading) {
      $button.data('original-text', $button.text());
      $button.prop('disabled', true).addClass('is-loading').text(text || 'Saving...');
      return;
    }
    $button.prop('disabled', false).removeClass('is-loading');
    const original = $button.data('original-text');
    if (original) $button.text(original);
  }

  function setNotice(message, type = 'success') {
    const notice = $('#ecNotice');
    notice.removeClass('is-success is-error').addClass(type === 'error' ? 'is-error' : 'is-success').text(message).addClass('is-visible');
    window.clearTimeout(noticeTimer);
    noticeTimer = window.setTimeout(() => notice.removeClass('is-visible'), 12000);
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  return { pressEffect, setLoading, setNotice, escapeHtml };
}
