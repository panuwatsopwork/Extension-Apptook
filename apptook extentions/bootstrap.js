const crypto = require('crypto');
const fs = require('fs');
const net = require('net');
const path = require('path');
const vscode = require('vscode');
const core = require('./dist/extension.js');
const authHost = require('./auth-host.js');

let authOutput = null;
let extensionContext = null;
let latestRuntimeWebview = null;
const assetDataUriCache = new Map();

const HOST_RUNTIME_STATE_KEY = 'apptook.hostRuntimeState';
const INSTALL_MARKER_STATE_KEY = 'apptook.installMarker';
const RUNTIME_SESSION_TOKEN_SECRET_KEY = 'apptook.runtimeSessionToken';
const CURSORPOOL_VIEW_COMMAND = 'cursorpool.openUserPanel';
const CURSORPOOL_CONTAINER_COMMAND = 'workbench.view.extension.cursorpool-sidebar';
const API_WORKER_TERMINAL_PATTERN = /api[\s-_]?worker/i;
const API_WORKER_HOST = '127.0.0.1';
const API_WORKER_PORT = 9182;
const API_WORKER_PROBE_TIMEOUT_MS = 1500;
const API_WORKER_WATCHDOG_INTERVAL_MS = 10000;
const API_WORKER_RECOVERY_COOLDOWN_MS = 15000;

let hostRuntimeState = createDefaultHostRuntimeState();
let infoMessageHookInstalled = false;
let terminalHooksInstalled = false;
let currentInstallMarker = '';
let currentExtensionVersion = '';
let workerWatchdogTimer = null;
let workerHealthProbePromise = null;
let runtimeDeviceFingerprint = '';

function getAuthOutputChannel() {
  if (!authOutput) {
    authOutput = vscode.window.createOutputChannel('APPTOOK AUTH');
  }
  return authOutput;
}

function logAuthInfo(...args) {
  try {
    getAuthOutputChannel().appendLine(args.map((x) => (typeof x === 'string' ? x : JSON.stringify(x))).join(' '));
  } catch (_err) {
    // ignore logging failures
  }
}

function cloneJson(value) {
  if (value === undefined || value === null) {
    return null;
  }

  try {
    return JSON.parse(JSON.stringify(value));
  } catch (_err) {
    return null;
  }
}

function cloneWithoutSessionToken(value) {
  const cloned = cloneJson(value);
  if (!cloned || typeof cloned !== 'object') {
    return cloned;
  }

  delete cloned.sessionToken;
  return cloned;
}

function toFiniteNumber(value, fallbackValue = null) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallbackValue;
}

function getRuntimeDeviceFingerprint() {
  if (runtimeDeviceFingerprint) {
    return runtimeDeviceFingerprint;
  }

  const machineId = String((vscode.env && vscode.env.machineId) || '').trim();
  if (!machineId) {
    runtimeDeviceFingerprint = 'apptook-device-unknown';
    return runtimeDeviceFingerprint;
  }

  runtimeDeviceFingerprint = `apptook-${crypto.createHash('sha256').update(machineId).digest('hex').slice(0, 32)}`;
  return runtimeDeviceFingerprint;
}

async function getRuntimeSessionToken() {
  if (!extensionContext || !extensionContext.secrets) {
    return '';
  }

  try {
    return String(await extensionContext.secrets.get(RUNTIME_SESSION_TOKEN_SECRET_KEY) || '').trim();
  } catch (_err) {
    return '';
  }
}

async function storeRuntimeSessionToken(token) {
  if (!extensionContext || !extensionContext.secrets) {
    return;
  }

  const normalizedToken = String(token || '').trim();
  if (!normalizedToken) {
    return clearRuntimeSessionToken();
  }

  try {
    await extensionContext.secrets.store(RUNTIME_SESSION_TOKEN_SECRET_KEY, normalizedToken);
  } catch (_err) {
    // ignore secret store failures
  }
}

async function clearRuntimeSessionToken() {
  if (!extensionContext || !extensionContext.secrets) {
    return;
  }

  try {
    await extensionContext.secrets.delete(RUNTIME_SESSION_TOKEN_SECRET_KEY);
  } catch (_err) {
    // ignore secret delete failures
  }
}

function isRuntimeAuthFailure(result) {
  const status = Number(result && result.status);
  const message = String(result && result.message || '').trim().toLowerCase();
  return status === 401 || status === 403 || message.includes('unauthorized') || message.includes('session expired');
}

async function invalidateRuntimeSession(webview, messageText) {
  await clearRuntimeSessionToken();
  resetHostRuntimeState();

  if (!webview || typeof webview.postMessage !== 'function') {
    return;
  }

  try {
    webview.postMessage({
      type: 'hostLoggedOut',
      message: messageText || 'Session expired. Please log in again.'
    });
  } catch (_err) {
    // ignore host logout notification failures
  }
}

async function renewRuntimeSessionToken(reasonText = "") {
  const runtimeSession = normalizeHostSession(hostRuntimeState && hostRuntimeState.session);
  const apptookKey = String(runtimeSession && runtimeSession.apptookKey || "").trim();
  const device = String(runtimeSession && runtimeSession.lastDevice || getRuntimeDeviceFingerprint() || "").trim();

  if (!apptookKey) {
    return { ok: false, status: 401, message: "Session expired. Please log in again." };
  }

  logAuthInfo("[APPTOOK AUTH] renewing runtime session", reasonText || apptookKey);
  const result = await authHost.loginViaGas({ apptookKey, device });
  const nextData = cloneWithoutSessionToken(result && result.data);

  if (result && result.ok && result.data && result.data.sessionToken) {
    await storeRuntimeSessionToken(result.data.sessionToken);
    if (nextData) {
      updateHostRuntimeState((state) => ({
        ...state,
        session: mergeHostSession(state.session, nextData) || state.session
      }));
    }
    return {
      ok: true,
      status: result.status || 200,
      message: result.message || "Session renewed.",
      data: nextData || null
    };
  }

  if (isRuntimeAuthFailure(result)) {
    await clearRuntimeSessionToken();
  }

  return result;
}

function createDefaultHostRuntimeState() {
  return {
    isActive: false,
    pendingResume: false,
    resumePhase: 'idle',
    session: null,
    latestUser: null,
    workerHealthKnown: false,
    workerHealthy: false,
    workerRecovering: false,
    lastWorkerRecoveryAt: 0
  };
}

function normalizeHostSession(raw) {
  if (!raw || typeof raw !== 'object') {
    return null;
  }

  const username = String(raw.apptookKey || raw.username || raw.loopUsername || '').trim();
  const currentKeyCode = String(raw.currentKeyCode || raw.licenseCode || raw.activationCode || '').trim();
  if (!username || !currentKeyCode) {
    return null;
  }

  const mode = String(raw.mode || '').trim() === 'loop' ? 'loop' : 'normal';

  return {
    apptookKey: username,
    username,
    mode,
    loopUsername: mode === 'loop' ? String(raw.loopUsername || username).trim() : '',
    currentKeyCode,
    licenseCode: currentKeyCode,
    chatLimit: mode === 'loop' ? toFiniteNumber(raw.chatLimit, 80) : null,
    currentKeyIndex: mode === 'loop' ? toFiniteNumber(raw.currentKeyIndex, 0) : null,
    totalKeys: mode === 'loop' ? toFiniteNumber(raw.totalKeys, 0) : null,
    usageDisplayLimit: Math.max(1, Math.floor(toFiniteNumber(raw.usageDisplayLimit, 100))),
    usageNormalizationDivisor: Math.max(1, toFiniteNumber(raw.usageNormalizationDivisor, 1)),
    totalAssignedSourceCapacity: Math.max(100, toFiniteNumber(raw.totalAssignedSourceCapacity, 100)),
    currentSourceCapacity: Math.max(0, toFiniteNumber(raw.currentSourceCapacity, 0)),
    currentSourceConsumedRaw: Math.max(0, toFiniteNumber(raw.currentSourceConsumedRaw, 0)),
    rawUsageTotal: Math.max(0, toFiniteNumber(raw.rawUsageTotal, 0)),
    displayUsage: Math.max(0, toFiniteNumber(raw.displayUsage, 0)),
    usageDate: String(raw.usageDate || '').trim(),
    lastDevice: String(raw.lastDevice || raw.device || '').trim()
  };
}

function copyHostSessionUsageState(target, source) {
  if (!target || !source) {
    return target;
  }

  target.usageDisplayLimit = Math.max(1, Math.floor(toFiniteNumber(source.usageDisplayLimit, target.usageDisplayLimit || 100)));
  target.usageNormalizationDivisor = Math.max(1, toFiniteNumber(source.usageNormalizationDivisor, target.usageNormalizationDivisor || 1));
  target.totalAssignedSourceCapacity = Math.max(100, toFiniteNumber(source.totalAssignedSourceCapacity, target.totalAssignedSourceCapacity || 100));
  target.currentSourceCapacity = Math.max(0, toFiniteNumber(source.currentSourceCapacity, target.currentSourceCapacity || 0));
  target.currentSourceConsumedRaw = Math.max(0, toFiniteNumber(source.currentSourceConsumedRaw, target.currentSourceConsumedRaw || 0));
  target.rawUsageTotal = Math.max(0, toFiniteNumber(source.rawUsageTotal, target.rawUsageTotal || 0));
  target.displayUsage = Math.max(0, toFiniteNumber(source.displayUsage, target.displayUsage || 0));
  target.usageDate = String(source.usageDate || target.usageDate || '').trim();
  return target;
}

function mergeHostSession(existingRaw, incomingRaw) {
  const existing = normalizeHostSession(existingRaw);
  const incoming = normalizeHostSession(incomingRaw);

  if (!existing) {
    return incoming;
  }

  if (!incoming) {
    return existing;
  }

  if (existing.username !== incoming.username || existing.mode !== incoming.mode) {
    return incoming;
  }

  const existingUsageDate = String(existing.usageDate || '').trim();
  const incomingUsageDate = String(incoming.usageDate || '').trim();
  const merged = {
    ...existing,
    ...incoming
  };

  if (existingUsageDate && incomingUsageDate && existingUsageDate > incomingUsageDate) {
    copyHostSessionUsageState(merged, existing);
    return normalizeHostSession(merged);
  }

  if (existingUsageDate && incomingUsageDate && incomingUsageDate > existingUsageDate) {
    copyHostSessionUsageState(merged, incoming);
    return normalizeHostSession(merged);
  }

  merged.usageDisplayLimit = Math.max(1, Math.floor(toFiniteNumber(incoming.usageDisplayLimit, existing.usageDisplayLimit || 100)));
  merged.usageNormalizationDivisor = Math.max(1, toFiniteNumber(incoming.usageNormalizationDivisor, existing.usageNormalizationDivisor || 1));
  merged.totalAssignedSourceCapacity = Math.max(100, toFiniteNumber(incoming.totalAssignedSourceCapacity, existing.totalAssignedSourceCapacity || 100));
  merged.rawUsageTotal = Math.max(0, Math.max(toFiniteNumber(existing.rawUsageTotal, 0), toFiniteNumber(incoming.rawUsageTotal, 0)));
  merged.displayUsage = Math.max(0, Math.max(toFiniteNumber(existing.displayUsage, 0), toFiniteNumber(incoming.displayUsage, 0)));
  merged.usageDate = incomingUsageDate || existingUsageDate;

  if (existing.currentKeyCode === incoming.currentKeyCode) {
    merged.currentSourceCapacity = Math.max(0, Math.max(toFiniteNumber(existing.currentSourceCapacity, 0), toFiniteNumber(incoming.currentSourceCapacity, 0)));
    merged.currentSourceConsumedRaw = Math.max(0, Math.max(toFiniteNumber(existing.currentSourceConsumedRaw, 0), toFiniteNumber(incoming.currentSourceConsumedRaw, 0)));
  } else {
    merged.currentSourceCapacity = Math.max(0, toFiniteNumber(incoming.currentSourceCapacity, existing.currentSourceCapacity || 0));
    merged.currentSourceConsumedRaw = Math.max(0, toFiniteNumber(incoming.currentSourceConsumedRaw, 0));
  }

  return normalizeHostSession(merged);
}

function normalizeHostRuntimeState(raw) {
  const source = raw && typeof raw === 'object' ? raw : {};
  const session = normalizeHostSession(source.session);
  const pendingResume = Boolean(source.pendingResume && session);
  const resumePhase = pendingResume ? String(source.resumePhase || 'activating').trim() || 'activating' : 'idle';
  const lastWorkerRecoveryAt = Math.max(0, toFiniteNumber(source.lastWorkerRecoveryAt, 0));

  return {
    isActive: Boolean(source.isActive),
    pendingResume,
    resumePhase,
    session,
    latestUser: cloneJson(source.latestUser),
    workerHealthKnown: Boolean(source.workerHealthKnown),
    workerHealthy: Boolean(source.workerHealthy),
    workerRecovering: Boolean(source.workerRecovering && session),
    lastWorkerRecoveryAt
  };
}

function persistHostRuntimeState(nextState) {
  hostRuntimeState = normalizeHostRuntimeState(nextState);
  syncApiWorkerWatchdog();

  if (!extensionContext) {
    return hostRuntimeState;
  }

  extensionContext.globalState.update(HOST_RUNTIME_STATE_KEY, hostRuntimeState).then(undefined, () => {
    // ignore persistence failures
  });

  if (latestRuntimeWebview) {
    sendHostRuntimeState(latestRuntimeWebview);
  }

  return hostRuntimeState;
}

function updateHostRuntimeState(updater) {
  const currentState = hostRuntimeState || createDefaultHostRuntimeState();
  const nextState = typeof updater === 'function'
    ? updater(currentState)
    : { ...currentState, ...(updater || {}) };

  return persistHostRuntimeState(nextState);
}

function resetHostRuntimeState() {
  return persistHostRuntimeState(createDefaultHostRuntimeState());
}

function hasRecoverableWorkerSession(state = hostRuntimeState) {
  return Boolean(
    state &&
    state.session &&
    state.session.currentKeyCode &&
    state.session.username &&
    (state.isActive || state.pendingResume || state.latestUser)
  );
}

function updateWorkerRuntimeState(patch) {
  const currentState = hostRuntimeState || createDefaultHostRuntimeState();
  const nextPatch = patch && typeof patch === 'object' ? patch : {};
  const nextWorkerHealthKnown = Object.prototype.hasOwnProperty.call(nextPatch, 'workerHealthKnown')
    ? Boolean(nextPatch.workerHealthKnown)
    : currentState.workerHealthKnown;
  const nextWorkerHealthy = Object.prototype.hasOwnProperty.call(nextPatch, 'workerHealthy')
    ? Boolean(nextPatch.workerHealthy)
    : currentState.workerHealthy;
  const nextWorkerRecovering = Object.prototype.hasOwnProperty.call(nextPatch, 'workerRecovering')
    ? Boolean(nextPatch.workerRecovering)
    : currentState.workerRecovering;
  const nextLastWorkerRecoveryAt = Object.prototype.hasOwnProperty.call(nextPatch, 'lastWorkerRecoveryAt')
    ? Math.max(0, toFiniteNumber(nextPatch.lastWorkerRecoveryAt, 0))
    : currentState.lastWorkerRecoveryAt;

  if (
    currentState.workerHealthKnown === nextWorkerHealthKnown &&
    currentState.workerHealthy === nextWorkerHealthy &&
    currentState.workerRecovering === nextWorkerRecovering &&
    currentState.lastWorkerRecoveryAt === nextLastWorkerRecoveryAt
  ) {
    return currentState;
  }

  return updateHostRuntimeState((state) => ({
    ...state,
    workerHealthKnown: nextWorkerHealthKnown,
    workerHealthy: nextWorkerHealthy,
    workerRecovering: nextWorkerRecovering,
    lastWorkerRecoveryAt: nextLastWorkerRecoveryAt
  }));
}

function canAttemptApiWorkerRecovery(state = hostRuntimeState) {
  if (!hasRecoverableWorkerSession(state)) {
    return false;
  }

  const now = Date.now();
  const lastRecoveryAt = Math.max(0, toFiniteNumber(state && state.lastWorkerRecoveryAt, 0));
  if (Boolean(state && state.workerRecovering) && now - lastRecoveryAt < API_WORKER_RECOVERY_COOLDOWN_MS) {
    return false;
  }

  return now - lastRecoveryAt >= API_WORKER_RECOVERY_COOLDOWN_MS;
}

function postEnsureWorkerToRuntimeWebview(reason = 'watchdog') {
  if (!latestRuntimeWebview || typeof latestRuntimeWebview.postMessage !== 'function') {
    return false;
  }

  try {
    latestRuntimeWebview.postMessage({
      type: 'hostEnsureWorker',
      reason
    });
    return true;
  } catch (_err) {
    return false;
  }
}

async function probeApiWorkerHealth() {
  return new Promise((resolve) => {
    let settled = false;
    const socket = net.createConnection({
      host: API_WORKER_HOST,
      port: API_WORKER_PORT
    });

    const finish = (healthy) => {
      if (settled) {
        return;
      }
      settled = true;
      try {
        socket.removeAllListeners();
        socket.destroy();
      } catch (_err) {
        // ignore socket cleanup failures
      }
      resolve(Boolean(healthy));
    };

    socket.setTimeout(API_WORKER_PROBE_TIMEOUT_MS);
    socket.once('connect', () => finish(true));
    socket.once('timeout', () => finish(false));
    socket.once('error', () => finish(false));
  });
}

async function requestApiWorkerRecovery(reason = 'watchdog') {
  const currentState = hostRuntimeState || createDefaultHostRuntimeState();
  if (!canAttemptApiWorkerRecovery(currentState)) {
    return false;
  }

  const recoveryAt = Date.now();
  updateWorkerRuntimeState({
    workerHealthKnown: true,
    workerHealthy: false,
    workerRecovering: true,
    lastWorkerRecoveryAt: recoveryAt
  });

  if (postEnsureWorkerToRuntimeWebview(reason)) {
    logAuthInfo('[APPTOOK AUTH] requested API Worker recovery via webview', reason);
    return true;
  }

  try {
    const opened = await openCursorPoolView();
    if (opened) {
      logAuthInfo('[APPTOOK AUTH] opened CursorPool to recover API Worker', reason);
    }
  } catch (_err) {
    // ignore panel reopen failures
  }

  return false;
}

async function runApiWorkerWatchdogTick(reason = 'interval') {
  const currentState = hostRuntimeState || createDefaultHostRuntimeState();
  if (!hasRecoverableWorkerSession(currentState)) {
    updateWorkerRuntimeState({
      workerHealthKnown: false,
      workerHealthy: false,
      workerRecovering: false,
      lastWorkerRecoveryAt: 0
    });
    return false;
  }

  if (workerHealthProbePromise) {
    return workerHealthProbePromise;
  }

  workerHealthProbePromise = probeApiWorkerHealth()
    .then(async (healthy) => {
      if (healthy) {
        updateWorkerRuntimeState({
          workerHealthKnown: true,
          workerHealthy: true,
          workerRecovering: false
        });
        return true;
      }

      updateWorkerRuntimeState({
        workerHealthKnown: true,
        workerHealthy: false
      });
      await requestApiWorkerRecovery(reason);
      return false;
    })
    .catch((_err) => false)
    .finally(() => {
      workerHealthProbePromise = null;
    });

  return workerHealthProbePromise;
}

function syncApiWorkerWatchdog() {
  const activeSession = hasRecoverableWorkerSession(hostRuntimeState);
  if (!activeSession) {
    if (workerWatchdogTimer) {
      clearInterval(workerWatchdogTimer);
      workerWatchdogTimer = null;
    }
    return;
  }

  if (workerWatchdogTimer) {
    return;
  }

  workerWatchdogTimer = setInterval(() => {
    runApiWorkerWatchdogTick('interval').catch(() => {
      // ignore watchdog loop failures
    });
  }, API_WORKER_WATCHDOG_INTERVAL_MS);

  setTimeout(() => {
    runApiWorkerWatchdogTick('startup').catch(() => {
      // ignore startup watchdog failures
    });
  }, 250);
}

function shouldSuppressActivatePrompt(message, args) {
  const text = String(message || '').trim().toLowerCase();
  if (!text) {
    return false;
  }

  const actionLabels = [];
  for (const arg of args || []) {
    if (!arg) {
      continue;
    }

    if (typeof arg === 'string') {
      actionLabels.push(arg.toLowerCase());
      continue;
    }

    if (Array.isArray(arg)) {
      for (const item of arg) {
        if (typeof item === 'string') {
          actionLabels.push(item.toLowerCase());
        } else if (item && typeof item === 'object') {
          const label = String(item.title || item.label || '').trim().toLowerCase();
          if (label) {
            actionLabels.push(label);
          }
        }
      }
      continue;
    }

    if (typeof arg === 'object') {
      const label = String(arg.title || arg.label || '').trim().toLowerCase();
      if (label) {
        actionLabels.push(label);
      }
    }
  }

  const hasActivateAction = actionLabels.some((label) => label.includes('activate now'));
  const looksLikePrompt = text.includes('logged in successfully') || text.includes('activate now') || text.includes('do you want to activate');

  return hasActivateAction || looksLikePrompt;
}

function installInformationMessageHook() {
  if (infoMessageHookInstalled) {
    return;
  }

  const originalShowInformationMessage = vscode.window.showInformationMessage.bind(vscode.window);

  vscode.window.showInformationMessage = function patchedShowInformationMessage(message, ...args) {
    if (shouldSuppressActivatePrompt(message, args)) {
      logAuthInfo('[APPTOOK AUTH] suppressed activate prompt notification');
      return Promise.resolve(undefined);
    }

    return originalShowInformationMessage(message, ...args);
  };

  infoMessageHookInstalled = true;
}

function escapeHtmlAttribute(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

function generateInstallMarker() {
  if (typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  return crypto.randomBytes(16).toString('hex');
}

function ensureInstallMarker(extensionUri) {
  if (!extensionUri || !extensionUri.fsPath) {
    return '';
  }

  const markerPath = path.join(extensionUri.fsPath, '.apptook-install-marker');

  try {
    if (fs.existsSync(markerPath)) {
      const existingMarker = String(fs.readFileSync(markerPath, 'utf8') || '').trim();
      if (existingMarker) {
        return existingMarker;
      }
    }
  } catch (_err) {
    // ignore stale marker read failures
  }

  const nextMarker = generateInstallMarker();

  try {
    fs.writeFileSync(markerPath, nextMarker, 'utf8');
  } catch (_err) {
    // ignore write failures and fall back to in-memory marker
  }

  return nextMarker;
}

function getAssetMimeType(filePath) {
  const normalizedPath = String(filePath || '').toLowerCase();

  if (normalizedPath.endsWith('.svg')) {
    return 'image/svg+xml';
  }

  if (normalizedPath.endsWith('.png')) {
    return 'image/png';
  }

  return 'application/octet-stream';
}

function readAssetAsDataUri(extensionUri, ...pathSegments) {
  if (!extensionUri || !pathSegments.length) {
    return '';
  }

  try {
    const assetUri = vscode.Uri.joinPath(extensionUri, ...pathSegments);
    const cacheKey = assetUri.toString();

    if (assetDataUriCache.has(cacheKey)) {
      return assetDataUriCache.get(cacheKey);
    }

    const buffer = fs.readFileSync(assetUri.fsPath);
    const dataUri = `data:${getAssetMimeType(assetUri.fsPath)};base64,${buffer.toString('base64')}`;

    assetDataUriCache.set(cacheKey, dataUri);
    return dataUri;
  } catch (_err) {
    return '';
  }
}

function applyAssetAttributesToTag(html, tagName, attributes) {
  if (typeof html !== 'string' || !html) {
    return html;
  }

  const tagPattern = new RegExp(`<${tagName}\\b([^>]*)>`, 'i');

  return html.replace(tagPattern, (_match, attrs = '') => {
    let nextAttrs = attrs;

    for (const attributeName of Object.keys(attributes)) {
      const attributePattern = new RegExp(`\\s${attributeName}="[^"]*"`, 'i');
      nextAttrs = nextAttrs.replace(attributePattern, '');
    }

    for (const [attributeName, attributeValue] of Object.entries(attributes)) {
      nextAttrs += ` ${attributeName}="${escapeHtmlAttribute(attributeValue)}"`;
    }

    return `<${tagName}${nextAttrs}>`;
  });
}

function ensureLocalResourceRoots(webview, extensionUri) {
  if (!webview || !extensionUri) {
    return;
  }

  try {
    const existingRoots = Array.isArray(webview.options && webview.options.localResourceRoots)
      ? [...webview.options.localResourceRoots]
      : [];
    const requiredRoots = [
      extensionUri,
      vscode.Uri.joinPath(extensionUri, 'webview')
    ];

    for (const root of requiredRoots) {
      const hasRoot = existingRoots.some((item) => {
        try {
          return item.toString() === root.toString();
        } catch (_err) {
          return false;
        }
      });

      if (!hasRoot) {
        existingRoots.push(root);
      }
    }

    webview.options = {
      ...(webview.options || {}),
      localResourceRoots: existingRoots
    };
  } catch (_err) {
    // ignore local resource root updates
  }
}

function injectWebviewAssetData(webview, html, extensionUri) {
  if (!webview || !extensionUri || typeof html !== 'string' || !html) {
    return html;
  }

  const logoWebviewUri = webview.asWebviewUri(
    vscode.Uri.joinPath(extensionUri, 'webview', 'APPTOOK.png')
  ).toString();
  const placeholderWebviewUri = webview.asWebviewUri(
    vscode.Uri.joinPath(extensionUri, 'webview', 'app-logo-placeholder.svg')
  ).toString();
  const logoDataUri = readAssetAsDataUri(extensionUri, 'webview', 'APPTOOK.png') || logoWebviewUri;
  const placeholderDataUri = readAssetAsDataUri(extensionUri, 'webview', 'app-logo-placeholder.svg') || placeholderWebviewUri;

  const assetAttributes = {
    'data-apptook-logo-url': logoDataUri,
    'data-apptook-logo-webview-url': logoWebviewUri,
    'data-apptook-logo-placeholder-url': placeholderDataUri,
    'data-apptook-logo-placeholder-webview-url': placeholderWebviewUri,
    'data-apptook-install-marker': currentInstallMarker || '',
    'data-apptook-extension-version': currentExtensionVersion || ''
  };

  let nextHtml = applyAssetAttributesToTag(html, 'html', assetAttributes);
  nextHtml = applyAssetAttributesToTag(nextHtml, 'body', assetAttributes);

  return nextHtml;
}

function getPropertyDescriptor(target, propertyName) {
  let current = target;

  while (current) {
    const descriptor = Object.getOwnPropertyDescriptor(current, propertyName);
    if (descriptor) {
      return descriptor;
    }
    current = Object.getPrototypeOf(current);
  }

  return null;
}

function buildHostRuntimeMessage() {
  const state = hostRuntimeState || createDefaultHostRuntimeState();
  return {
    type: 'hostRuntimeState',
    isActive: Boolean(state.isActive),
    pendingResume: Boolean(state.pendingResume),
    resumePhase: state.resumePhase || 'idle',
    session: cloneJson(state.session),
    latestUser: cloneJson(state.latestUser),
    workerHealthKnown: Boolean(state.workerHealthKnown),
    workerHealthy: Boolean(state.workerHealthy),
    workerRecovering: Boolean(state.workerRecovering),
    lastWorkerRecoveryAt: Math.max(0, toFiniteNumber(state.lastWorkerRecoveryAt, 0)),
    installMarker: currentInstallMarker || '',
    extensionVersion: currentExtensionVersion || ''
  };
}

function sendHostRuntimeState(webview) {
  if (!webview || typeof webview.postMessage !== 'function') {
    return;
  }

  try {
    webview.postMessage(buildHostRuntimeMessage());
  } catch (_err) {
    // ignore host runtime push failures
  }
}

async function openCursorPoolView() {
  try {
    await vscode.commands.executeCommand(CURSORPOOL_VIEW_COMMAND);
    return true;
  } catch (_err) {
    // ignore and fall back
  }

  try {
    await vscode.commands.executeCommand(CURSORPOOL_CONTAINER_COMMAND);
  } catch (_err) {
    // ignore container focus errors
  }

  try {
    await vscode.commands.executeCommand(CURSORPOOL_VIEW_COMMAND);
    return true;
  } catch (_err) {
    return false;
  }
}

function schedulePendingResumeOpen() {
  const state = hostRuntimeState || createDefaultHostRuntimeState();
  if (!state.pendingResume || !state.session) {
    return;
  }

  let reopenLogged = false;
  const delays = [0, 120, 450, 1000, 2200, 4000];
  for (const delayMs of delays) {
    setTimeout(() => {
      const latestState = hostRuntimeState || createDefaultHostRuntimeState();
      if (!latestState.pendingResume || !latestState.session) {
        return;
      }

      openCursorPoolView().then((opened) => {
        if (opened && !reopenLogged) {
          reopenLogged = true;
          logAuthInfo('[APPTOOK AUTH] reopened CursorPool after activate reload');
        }
      }, () => {
        // ignore reopen failures
      });
    }, delayMs);
  }
}

function schedulePersistedSessionOpen() {
  const state = hostRuntimeState || createDefaultHostRuntimeState();
  if (state.pendingResume || !state.session || !state.session.currentKeyCode || !state.session.username) {
    return;
  }

  let reopenLogged = false;
  const delays = [0, 180, 600, 1400];
  for (const delayMs of delays) {
    setTimeout(() => {
      const latestState = hostRuntimeState || createDefaultHostRuntimeState();
      if (latestState.pendingResume || !latestState.session || !latestState.session.currentKeyCode || !latestState.session.username) {
        return;
      }

      getRuntimeSessionToken().then((token) => {
        if (!token) {
          return;
        }

        openCursorPoolView().then((opened) => {
          if (opened && !reopenLogged) {
            reopenLogged = true;
            logAuthInfo('[APPTOOK AUTH] reopened CursorPool from persisted session');
          }
        }, () => {
          // ignore reopen failures
        });
      }).catch(() => {
        // ignore secret read failures
      });
    }, delayMs);
  }
}

function shouldHideApiWorkerTerminal(arg) {
  if (!arg) {
    return false;
  }

  if (typeof arg === 'string') {
    return API_WORKER_TERMINAL_PATTERN.test(arg);
  }

  if (typeof arg === 'object') {
    return API_WORKER_TERMINAL_PATTERN.test(String(arg.name || '').trim());
  }

  return false;
}

function closeTerminalPanelSoon(delayMs = 30) {
  setTimeout(() => {
    vscode.commands.executeCommand('workbench.action.closePanel').then(undefined, () => {
      // ignore panel close failures
    });
  }, delayMs);
}

function installTerminalHooks() {
  if (terminalHooksInstalled) {
    return;
  }

  terminalHooksInstalled = true;
  const originalCreateTerminal = vscode.window.createTerminal.bind(vscode.window);

  vscode.window.createTerminal = function patchedCreateTerminal(...args) {
    const firstArg = args[0];
    const hideWorkerTerminal = shouldHideApiWorkerTerminal(firstArg);

    if (hideWorkerTerminal && firstArg && typeof firstArg === 'object' && !Array.isArray(firstArg)) {
      args[0] = {
        ...firstArg,
        isTransient: true,
        hideFromUser: true
      };
    }

    const terminal = originalCreateTerminal(...args);

    if (!hideWorkerTerminal || !terminal) {
      return terminal;
    }

    try {
      if (!terminal.__apptookShowPatched && typeof terminal.show === 'function') {
        const originalShow = terminal.show.bind(terminal);
        terminal.show = function patchedShow(...showArgs) {
          logAuthInfo('[APPTOOK AUTH] suppressed API Worker terminal reveal');
          closeTerminalPanelSoon(0);
          return undefined;
        };
        terminal.__apptookShowPatched = originalShow;
      }

      if (!terminal.__apptookSendTextPatched && typeof terminal.sendText === 'function') {
        const originalSendText = terminal.sendText.bind(terminal);
        terminal.sendText = function patchedSendText(...sendArgs) {
          const result = originalSendText(...sendArgs);
          closeTerminalPanelSoon(40);
          return result;
        };
        terminal.__apptookSendTextPatched = originalSendText;
      }
    } catch (_err) {
      // ignore terminal patching issues
    }

    closeTerminalPanelSoon(0);
    return terminal;
  };
}

function promotePendingResumeAfterRestart() {
  updateHostRuntimeState((state) => {
    if (!state.pendingResume || !state.session) {
      return state;
    }

    if (state.resumePhase === 'activating') {
      return {
        ...state,
        isActive: true,
        resumePhase: 'starting_worker'
      };
    }

    return state;
  });
}

function patchWebviewHtmlSetter(webview, extensionUri) {
  if (!webview || webview.__apptookHtmlSetterPatched) {
    return;
  }

  try {
    const descriptor = getPropertyDescriptor(Object.getPrototypeOf(webview), 'html');

    if (!descriptor || typeof descriptor.get !== 'function' || typeof descriptor.set !== 'function') {
      return;
    }

    Object.defineProperty(webview, 'html', {
      configurable: true,
      enumerable: descriptor.enumerable,
      get() {
        return descriptor.get.call(this);
      },
      set(value) {
        const nextValue = injectWebviewAssetData(this, value, extensionUri);
        return descriptor.set.call(this, nextValue);
      }
    });

    webview.__apptookHtmlSetterPatched = true;
  } catch (_err) {
    // ignore html setter patching issues
  }
}

function observeOutgoingWebviewMessage(message) {
  if (!message || typeof message !== 'object') {
    return;
  }

  if (message.type === 'activateState') {
    updateHostRuntimeState((state) => ({
      ...state,
      isActive: Boolean(message.isActive),
      pendingResume: Boolean(message.isActive) ? state.pendingResume : false,
      resumePhase: Boolean(message.isActive) ? state.resumePhase : 'idle'
    }));
    return;
  }

  if (message.type === 'userStatus') {
    updateHostRuntimeState((state) => {
      const nextSession = mergeHostSession(state.session, {
        ...(state.session || {}),
        currentKeyCode: message.user && message.user.activationCode ? message.user.activationCode : (state.session && state.session.currentKeyCode),
        licenseCode: message.user && message.user.activationCode ? message.user.activationCode : (state.session && state.session.licenseCode)
      }) || state.session;

      return {
        ...state,
        session: nextSession,
        latestUser: cloneJson(message.user),
        pendingResume: false,
        resumePhase: 'idle'
      };
    });
    return;
  }

  if (message.type === 'activateStatus') {
    if (message.status === 'success') {
      updateHostRuntimeState((state) => ({
        ...state,
        isActive: true,
        resumePhase: state.pendingResume ? 'starting_worker' : state.resumePhase,
        workerHealthKnown: false,
        workerHealthy: false,
        workerRecovering: true,
        lastWorkerRecoveryAt: Math.max(Date.now(), toFiniteNumber(state.lastWorkerRecoveryAt, 0))
      }));
      return;
    }

    if (message.status === 'error') {
      updateHostRuntimeState((state) => ({
        ...state,
        isActive: false,
        pendingResume: false,
        resumePhase: 'idle',
        workerHealthKnown: true,
        workerHealthy: false,
        workerRecovering: false
      }));
      return;
    }
  }

  if (message.type === 'startApiWorkerStatus') {
    if (message.status === 'loading') {
      updateHostRuntimeState((state) => ({
        ...state,
        workerHealthKnown: false,
        workerHealthy: false,
        workerRecovering: true,
        lastWorkerRecoveryAt: Math.max(Date.now(), toFiniteNumber(state.lastWorkerRecoveryAt, 0))
      }));
      return;
    }

    if (message.status === 'success') {
      updateHostRuntimeState((state) => ({
        ...state,
        pendingResume: false,
        resumePhase: state.pendingResume ? 'refreshing' : state.resumePhase,
        workerHealthKnown: true,
        workerHealthy: true,
        workerRecovering: false
      }));
      return;
    }

    if (message.status === 'error') {
      updateHostRuntimeState((state) => ({
        ...state,
        pendingResume: false,
        resumePhase: 'idle',
        workerHealthKnown: true,
        workerHealthy: false,
        workerRecovering: false
      }));
      return;
    }
  }

  if (message.type === 'refreshStatus' && message.status === 'error') {
    updateHostRuntimeState((state) => ({
      ...state,
      pendingResume: false,
      resumePhase: 'idle'
    }));
    return;
  }

  if (message.type === 'loginStatus' && message.status === 'error') {
    updateHostRuntimeState((state) => ({
      ...state,
      pendingResume: false,
      resumePhase: 'idle'
    }));
  }
}

function patchWebviewPostMessage(webview) {
  if (!webview || webview.__apptookPostMessagePatched) {
    return;
  }

  const originalPostMessage = webview.postMessage.bind(webview);

  webview.postMessage = function patchedPostMessage(message, ...args) {
    observeOutgoingWebviewMessage(message);
    return originalPostMessage(message, ...args);
  };

  webview.__apptookPostMessagePatched = true;
}

function refreshInjectedWebviewHtml(webview, extensionUri) {
  if (!webview || !extensionUri) {
    return;
  }

  try {
    const currentHtml = webview.html;
    const nextHtml = injectWebviewAssetData(webview, currentHtml, extensionUri);

    if (typeof currentHtml === 'string' && currentHtml !== nextHtml) {
      webview.html = nextHtml;
    }
  } catch (_err) {
    // ignore html refresh failures
  }
}

function decorateWebview(webview, extensionUri) {
  ensureLocalResourceRoots(webview, extensionUri);
  patchWebviewHtmlSetter(webview, extensionUri);
  patchWebviewPostMessage(webview);
  refreshInjectedWebviewHtml(webview, extensionUri);
}

function handleRuntimeMessage(webview, message) {
  latestRuntimeWebview = webview || latestRuntimeWebview;

  if (!message || typeof message !== 'object') {
    return;
  }

  const incomingSession = normalizeHostSession(message.session);
  const incomingLatestUser = cloneJson(message.latestUser);

  if (message.type === 'ready') {
    sendHostRuntimeState(webview);
    const state = hostRuntimeState || createDefaultHostRuntimeState();
    if (hasRecoverableWorkerSession(state) && state.workerHealthKnown && (!state.workerHealthy || state.workerRecovering)) {
      setTimeout(() => {
        postEnsureWorkerToRuntimeWebview('ready');
      }, 60);
    }
  }

  if (message.type === 'login') {
    if (incomingSession) {
      updateHostRuntimeState((state) => ({
        ...state,
        session: mergeHostSession(state.session, incomingSession) || incomingSession,
        latestUser: incomingLatestUser || state.latestUser
      }));
    }
    return;
  }

  if (message.type === 'sessionSync') {
    if (incomingSession || incomingLatestUser) {
      updateHostRuntimeState((state) => ({
        ...state,
        session: mergeHostSession(state.session, incomingSession) || state.session,
        latestUser: incomingLatestUser || state.latestUser
      }));
    }
    return;
  }

  if (message.type === 'activate') {
    updateHostRuntimeState((state) => ({
      ...state,
      session: mergeHostSession(state.session, incomingSession) || state.session,
      latestUser: incomingLatestUser || state.latestUser,
      pendingResume: true,
      resumePhase: 'activating'
    }));
    return;
  }

  if (message.type === 'deactivate') {
    updateHostRuntimeState((state) => ({
      ...state,
      isActive: false,
      pendingResume: false,
      resumePhase: 'idle',
      workerHealthKnown: false,
      workerHealthy: false,
      workerRecovering: false,
      lastWorkerRecoveryAt: 0
    }));
    return;
  }

  if (message.type === 'startApiWorker') {
    updateHostRuntimeState((state) => ({
      ...state,
      session: mergeHostSession(state.session, incomingSession) || state.session,
      resumePhase: state.pendingResume ? 'starting_worker' : state.resumePhase,
      workerHealthKnown: false,
      workerHealthy: false,
      workerRecovering: true,
      lastWorkerRecoveryAt: Math.max(Date.now(), toFiniteNumber(state.lastWorkerRecoveryAt, 0))
    }));
    return;
  }

  if (message.type === 'refresh') {
    updateHostRuntimeState((state) => ({
      ...state,
      session: mergeHostSession(state.session, incomingSession) || state.session,
      resumePhase: state.pendingResume ? 'refreshing' : state.resumePhase
    }));
    return;
  }

  if (message.type === 'logout') {
    resetHostRuntimeState();
    clearRuntimeSessionToken().then(undefined, () => {
      // ignore secret cleanup failures
    });
    try {
      webview.postMessage({
        type: 'hostLoggedOut',
        message: 'Logged out successfully.'
      });
    } catch (_err) {
      // ignore logout notification failures
    }
  }
}

function wireAuthBridgeToWebview(webview) {
  if (!webview || webview.__apptookAuthBridgeWired) {
    return;
  }

  latestRuntimeWebview = webview;
  webview.__apptookAuthBridgeWired = true;

  webview.onDidReceiveMessage(async (message) => {
    if (!message || !message.type) {
      return;
    }

    handleRuntimeMessage(webview, message);

    if (message.type === 'hostAuthLogin') {
      const requestId = String(message.requestId || '');
      const apptookKey = String(message.apptookKey || message.username || '');
      const device = getRuntimeDeviceFingerprint();

      const result = await authHost.loginViaGas({ apptookKey, device });
      const nextData = cloneWithoutSessionToken(result.data);

      if (result && result.ok && result.data && result.data.sessionToken) {
        await storeRuntimeSessionToken(result.data.sessionToken);
        if (nextData) {
          updateHostRuntimeState((state) => ({
            ...state,
            session: mergeHostSession(state.session, nextData) || normalizeHostSession(nextData) || state.session
          }));
        }
      }

      webview.postMessage({
        type: 'hostAuthLoginResult',
        requestId,
        ok: Boolean(result.ok),
        message: result.message || '',
        data: nextData || null
      });
      return;
    }

    if (message.type === 'hostLoopNextKey') {
      const requestId = String(message.requestId || '');
      const currentSourceKey = String(message.currentSourceKey || message.currentKeyCode || '');
      let sessionToken = await getRuntimeSessionToken();
      let result = sessionToken
        ? await authHost.loopNextKeyViaGas({ sessionToken, currentSourceKey })
        : { ok: false, status: 401, message: 'Session expired. Please log in again.' };

      if (!sessionToken || isRuntimeAuthFailure(result)) {
        const renewResult = await renewRuntimeSessionToken('loopNextKey');
        if (renewResult && renewResult.ok) {
          sessionToken = await getRuntimeSessionToken();
          result = await authHost.loopNextKeyViaGas({ sessionToken, currentSourceKey });
        } else if (!sessionToken) {
          result = renewResult || result;
        }
      }

      const nextData = cloneWithoutSessionToken(result && result.data);

      if (result && result.ok && result.data && result.data.sessionToken) {
        await storeRuntimeSessionToken(result.data.sessionToken);
        if (nextData) {
          updateHostRuntimeState((state) => ({
            ...state,
            session: mergeHostSession(state.session, nextData) || state.session
          }));
        }
      } else if (isRuntimeAuthFailure(result)) {
        await invalidateRuntimeSession(webview, result.message || 'Session expired. Please log in again.');
      }

      webview.postMessage({
        type: 'hostLoopNextKeyResult',
        requestId,
        ok: Boolean(result.ok),
        message: result.message || '',
        data: nextData || null
      });
      return;
    }

    if (message.type === 'hostDashboardSync') {
      const requestId = String(message.requestId || '');
      const snapshot = message.snapshot && typeof message.snapshot === 'object' ? message.snapshot : {};
      let sessionToken = await getRuntimeSessionToken();
      let result = sessionToken
        ? await authHost.dashboardSyncViaGas({ sessionToken, snapshot })
        : { ok: false, status: 401, message: 'Session expired. Please log in again.' };

      if (!sessionToken || isRuntimeAuthFailure(result)) {
        const renewResult = await renewRuntimeSessionToken('dashboardSync');
        if (renewResult && renewResult.ok) {
          sessionToken = await getRuntimeSessionToken();
          result = await authHost.dashboardSyncViaGas({ sessionToken, snapshot });
        } else if (!sessionToken) {
          result = renewResult || result;
        }
      }

      const nextData = cloneWithoutSessionToken(result && result.data);

      if (result && result.ok && result.data && result.data.sessionToken) {
        await storeRuntimeSessionToken(result.data.sessionToken);
        if (nextData) {
          updateHostRuntimeState((state) => ({
            ...state,
            session: mergeHostSession(state.session, nextData) || state.session
          }));
        }
      } else if (isRuntimeAuthFailure(result)) {
        await invalidateRuntimeSession(webview, result.message || 'Session expired. Please log in again.');
      }

      webview.postMessage({
        type: 'hostDashboardSyncResult',
        requestId,
        ok: Boolean(result.ok),
        message: result.message || '',
        data: nextData || null
      });
    }
  });
}

function installWebviewHooks(extensionUri) {
  const originalCreateWebviewPanel = vscode.window.createWebviewPanel.bind(vscode.window);

  vscode.window.createWebviewPanel = function patchedCreateWebviewPanel(...args) {
    const panel = originalCreateWebviewPanel(...args);

    try {
      decorateWebview(panel.webview, extensionUri);
      wireAuthBridgeToWebview(panel.webview);
    } catch (_err) {
      // ignore panel hook errors
    }

    return panel;
  };

  const originalRegisterWebviewViewProvider = vscode.window.registerWebviewViewProvider.bind(vscode.window);

  vscode.window.registerWebviewViewProvider = function patchedRegisterWebviewViewProvider(viewId, provider, options) {
    const wrappedProvider = {
      ...provider,
      async resolveWebviewView(webviewView, context, token) {
        latestRuntimeWebview = webviewView.webview;

        try {
          decorateWebview(webviewView.webview, extensionUri);
          wireAuthBridgeToWebview(webviewView.webview);
        } catch (_err) {
          // ignore bridge wiring errors
        }

        const result = await provider.resolveWebviewView(webviewView, context, token);

        try {
          refreshInjectedWebviewHtml(webviewView.webview, extensionUri);
          sendHostRuntimeState(webviewView.webview);
        } catch (_err) {
          // ignore html refresh errors
        }

        return result;
      }
    };

    const nextOptions = {
      ...(options || {}),
      webviewOptions: {
        ...((options && options.webviewOptions) || {}),
        retainContextWhenHidden: true
      }
    };

    return originalRegisterWebviewViewProvider(viewId, wrappedProvider, nextOptions);
  };

  const originalRegisterWebviewPanelSerializer = vscode.window.registerWebviewPanelSerializer.bind(vscode.window);

  vscode.window.registerWebviewPanelSerializer = function patchedRegisterWebviewPanelSerializer(viewType, serializer) {
    const wrappedSerializer = {
      async deserializeWebviewPanel(panel, state) {
        try {
          decorateWebview(panel.webview, extensionUri);
          wireAuthBridgeToWebview(panel.webview);
        } catch (_err) {
          // ignore bridge wiring errors
        }

        const result = await serializer.deserializeWebviewPanel(panel, state);

        try {
          refreshInjectedWebviewHtml(panel.webview, extensionUri);
          sendHostRuntimeState(panel.webview);
        } catch (_err) {
          // ignore html refresh errors
        }

        return result;
      }
    };

    return originalRegisterWebviewPanelSerializer(viewType, wrappedSerializer);
  };
}

function bindAuthPanel(panel) {
  try {
    if (!panel) {
      return;
    }

    if (panel.webview) {
      latestRuntimeWebview = panel.webview;
      wireAuthBridgeToWebview(panel.webview);
      sendHostRuntimeState(panel.webview);
      return;
    }

    // allow passing webview directly
    latestRuntimeWebview = panel;
    wireAuthBridgeToWebview(panel);
    sendHostRuntimeState(panel);
  } catch (_err) {
    // ignore explicit bind errors
  }
}

function bindFromActivateResult(result) {
  try {
    if (!result) {
      return;
    }

    if (result.webview) {
      bindAuthPanel(result);
      return;
    }

    if (result.panel && result.panel.webview) {
      bindAuthPanel(result.panel);
      return;
    }

    if (typeof result.getPanel === 'function') {
      const panel = result.getPanel();
      bindAuthPanel(panel);
    }
  } catch (_err) {
    // ignore binding errors from activate return
  }
}

function activate(context) {
  extensionContext = context;
  currentExtensionVersion = String((context.extension && context.extension.packageJSON && context.extension.packageJSON.version) || '').trim();
  currentInstallMarker = ensureInstallMarker(context.extensionUri);

  const previousInstallMarker = String(context.globalState.get(INSTALL_MARKER_STATE_KEY) || '').trim();
  if (currentInstallMarker && previousInstallMarker !== currentInstallMarker) {
    hostRuntimeState = createDefaultHostRuntimeState();
    context.globalState.update(HOST_RUNTIME_STATE_KEY, hostRuntimeState).then(undefined, () => {
      // ignore persistence failures
    });
    clearRuntimeSessionToken().then(undefined, () => {
      // ignore secret cleanup failures
    });
  } else {
    hostRuntimeState = normalizeHostRuntimeState(context.globalState.get(HOST_RUNTIME_STATE_KEY));
  }

  context.globalState.update(INSTALL_MARKER_STATE_KEY, currentInstallMarker || '').then(undefined, () => {
    // ignore marker persistence failures
  });

  try {
    const out = getAuthOutputChannel();
    context.subscriptions.push(out);

    authHost.setLogger({
      info: (...args) => {
        out.appendLine(args.map((x) => (typeof x === 'string' ? x : JSON.stringify(x))).join(' '));
      },
      error: (...args) => {
        out.appendLine(args.map((x) => (typeof x === 'string' ? x : JSON.stringify(x))).join(' '));
      }
    });

    out.appendLine('[APPTOOK AUTH] bootstrap activated');
    out.appendLine('[APPTOOK AUTH] host runtime bridge ready');
  } catch (err) {
    const out = getAuthOutputChannel();
    out.appendLine(`[APPTOOK AUTH] startup error: ${err && err.message ? err.message : String(err)}`);
  }

  installInformationMessageHook();
  promotePendingResumeAfterRestart();

  // Must install hooks before core.activate so newly created webviews are captured
  installWebviewHooks(context.extensionUri);
  installTerminalHooks();

  let result;
  if (core && typeof core.activate === 'function') {
    result = core.activate(context);

    // Optional: if core.activate returns panel/provider, bind directly as well
    bindFromActivateResult(result);
  }

  schedulePendingResumeOpen();
  schedulePersistedSessionOpen();
  syncApiWorkerWatchdog();

  return result;
}

function deactivate() {
  if (workerWatchdogTimer) {
    clearInterval(workerWatchdogTimer);
    workerWatchdogTimer = null;
  }

  if (core && typeof core.deactivate === 'function') {
    return core.deactivate();
  }
}

module.exports = {
  activate,
  deactivate,
  bindAuthPanel
};
