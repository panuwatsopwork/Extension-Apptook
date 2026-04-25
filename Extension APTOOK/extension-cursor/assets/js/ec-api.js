window.ExtensionCursorModules = window.ExtensionCursorModules || {};
window.ExtensionCursorModules.createEcApi = function createEcApi({ ajaxUrl, nonce }) {
  function post(action, data = {}) {
    return jQuery.post(ajaxUrl, { action, nonce, ...data });
  }

  return { post };
};
