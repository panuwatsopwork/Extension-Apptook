jQuery(function ($) {
  const viewData = window.ExtensionCursorAdminViewData || {};
  const modules = window.ExtensionCursorModules || {};
  const createEcState = modules.createEcState;
  const createEcApi = modules.createEcApi;
  const createEcUI = modules.createEcUI;
  const createEcRenderers = modules.createEcRenderers;
  const createEcActions = modules.createEcActions;
  const createMonitorEdit = modules.createMonitorEdit;

  if (!createEcState || !createEcApi || !createEcUI || !createEcRenderers || !createEcActions || !createMonitorEdit) {
    return;
  }

  const state = createEcState();
  const api = createEcApi(ExtensionCursorAdmin);
  const ui = createEcUI($);
  const renderers = createEcRenderers($, { escapeHtml: ui.escapeHtml });
  const elements = {
    tabs: $('.ec-tab'),
    panels: $('.ec-panel'),
    monitorEditPanel: $('#ecMonitorEditPanel'),
    monitorEditKeyTitle: $('#ecMonitorEditKeyTitle'),
    monitorEditExpiry: $('#ecMonitorEditExpiry'),
    monitorAssignedList: $('#ecMonitorAssignedList'),
    monitorAvailableList: $('#ecMonitorAvailableList'),
    monitorEditLoadAvailable: $('#ecMonitorEditLoadAvailable'),
    monitorEditClose: $('#ecMonitorEditClose'),
    monitorEditAssign: $('#ecMonitorEditAssign'),
    monitorEditUnassign: $('#ecMonitorEditUnassign'),
    refreshButton: $('#ecRefreshList'),
    importButton: $('#ecAddLicenceToPool'),
    clearLicenceForm: $('#ecClearLicenceForm'),
    saveKeyButton: $('#ecSaveKey'),
    generateKeyButton: $('#ecGenerateKey'),
    resetKeyButton: $('#ecResetKey'),
    assignButton: $('#ecAssignSelected'),
    unassignButton: $('#ecUnassignSelected'),
    replaceButton: $('#ecReplaceSelected'),
    licenceList: $('#ecLicenceList'),
    monitorRows: $('#ecMonitorRows'),
    selectKey: $('#ecSelectKey'),
    licenceCode: $('#ecLicenceKey'),
    tokenCapacity: $('#ecTokenCapacity'),
    activeDays: $('#ecActiveDays'),
    importRemark: $('#ecImportRemark'),
    apptookKey: $('#ecApptookKey'),
    note: $('#ecKeyNote'),
    expiry: $('#ecExpiry'),
    debugAvailableButton: $('#ecDebugAvailable'),
    debugAssignmentButton: $('#ecDebugAssignment'),
  };

  const monitorEdit = createMonitorEdit({ api, ui, $, elements, onStateChanged: function () { actions.reloadDashboard(elements.selectKey.val() || 0); } });
  window.ExtensionCursorMonitorEdit = monitorEdit;
  const actions = createEcActions({ api, ui, renderers, state, $, elements, viewData, monitorEdit });
  actions.bindEvents();
  monitorEdit.bindEvents();
  actions.setActiveTab('main');
  actions.reloadDashboard();
});
