jQuery(function ($) {
  const modules = window.ExtensionCursorModules || {};
  const createEcState = modules.createEcState;
  const createEcApi = modules.createEcApi;
  const createEcUI = modules.createEcUI;
  const createEcRenderers = modules.createEcRenderers;
  const createEcActionsMain = modules.createEcActionsMain;
  const createEcActionsMonitor = modules.createEcActionsMonitor;

  if (!createEcState || !createEcApi || !createEcUI || !createEcRenderers || !createEcActionsMain || !createEcActionsMonitor) {
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

  const monitorActions = createEcActionsMonitor({ api, ui, $, elements, onStateChanged: function () { mainActions.reloadDashboard(elements.selectKey.val() || 0); } });
  window.ExtensionCursorMonitorEdit = monitorActions;
  const mainActions = createEcActionsMain({ api, ui, renderers, state, $, elements, monitorEdit: monitorActions });

  mainActions.bindEvents();
  monitorActions.bindEvents();
  mainActions.setActiveTab('main');
  mainActions.reloadDashboard();
});
