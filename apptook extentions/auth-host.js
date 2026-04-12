const GAS_WEB_APP_URL = "https://script.google.com/macros/s/AKfycbyJvMCawEeP2qCWTCxEUEZ3ygaV8f9aVJjXgJz8GcAVgoUKyKo9EiTmBRewOpecrYZE/exec";

let logger = {
  info: (...args) => console.log(...args),
  error: (...args) => console.error(...args)
};

function setLogger(customLogger) {
  if (!customLogger) {
    return;
  }

  logger = {
    info: typeof customLogger.info === "function" ? customLogger.info.bind(customLogger) : logger.info,
    error: typeof customLogger.error === "function" ? customLogger.error.bind(customLogger) : logger.error
  };
}

function logInfo(eventName, details = "") {
  if (details) {
    logger.info(`[APPTOOK AUTH] ${eventName}:`, details);
    return;
  }

  logger.info(`[APPTOOK AUTH] ${eventName}`);
}

function logError(eventName, err) {
  const message = err && err.message ? err.message : String(err || "");
  logger.error(`[APPTOOK AUTH] ${eventName}:`, message);
}

async function postToGas(payload) {
  const response = await fetch(GAS_WEB_APP_URL, {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: JSON.stringify(payload || {})
  });

  let data = null;
  try {
    data = await response.json();
  } catch (_err) {
    data = null;
  }

  return {
    status: response.status,
    data: data && typeof data === "object" ? data : { ok: false, message: "Invalid GAS response." }
  };
}

async function loginViaGas({ apptookKey, username, device }) {
  const key = String(apptookKey || username || "").trim();
  const normalizedDevice = String(device || "").trim();

  if (!key) {
    return { ok: false, status: 400, message: "APPTOOK License Key required" };
  }

  logInfo("loginViaGas start", key);

  try {
    const gasRes = await postToGas({
      action: "login",
      apptookKey: key,
      device: normalizedDevice
    });
    logInfo("loginViaGas response", `status=${gasRes.status}`);

    if (gasRes.status >= 400 || !gasRes.data.ok) {
      return {
        ok: false,
        status: gasRes.status || 401,
        message: gasRes.data.message || "APPTOOK License Key is invalid"
      };
    }

    return {
      ok: true,
      status: 200,
      message: gasRes.data.message || "Login success",
      data: gasRes.data.data || null
    };
  } catch (err) {
    logError("loginViaGas error", err);
    return { ok: false, status: 502, message: "Cannot reach Google Apps Script login service" };
  }
}

async function loopNextKeyViaGas({ sessionToken, currentSourceKey }) {
  const token = String(sessionToken || "").trim();
  const sourceKey = String(currentSourceKey || "").trim();

  if (!token) {
    return { ok: false, status: 401, message: "Session expired. Please log in again." };
  }

  logInfo("loopNextKeyViaGas start", sourceKey || "(empty)");

  try {
    const gasRes = await postToGas({
      action: "loopNextKey",
      sessionToken: token,
      currentSourceKey: sourceKey
    });
    logInfo("loopNextKeyViaGas response", `status=${gasRes.status}`);

    if (gasRes.status >= 400 || !gasRes.data.ok) {
      return {
        ok: false,
        status: gasRes.status || 400,
        message: gasRes.data.message || "No next loop key available"
      };
    }

    return {
      ok: true,
      status: 200,
      message: gasRes.data.message || "Next loop key ready",
      data: gasRes.data.data || null
    };
  } catch (err) {
    logError("loopNextKeyViaGas error", err);
    return { ok: false, status: 502, message: "Cannot reach Google Apps Script loop-key service" };
  }
}

async function dashboardSyncViaGas({ sessionToken, snapshot }) {
  const token = String(sessionToken || "").trim();
  const payload = snapshot && typeof snapshot === "object" ? { ...snapshot } : {};

  if (!token) {
    return { ok: false, status: 401, message: "Session expired. Please log in again." };
  }

  logInfo("dashboardSyncViaGas start", String(payload.activeSourceKey || payload.licenseCode || "").trim() || "(empty)");

  try {
    const gasRes = await postToGas({
      action: "dashboardSync",
      sessionToken: token,
      ...payload
    });
    logInfo("dashboardSyncViaGas response", `status=${gasRes.status}`);

    if (gasRes.status >= 400 || !gasRes.data.ok) {
      return {
        ok: false,
        status: gasRes.status || 400,
        message: gasRes.data.message || "Dashboard sync failed"
      };
    }

    return {
      ok: true,
      status: 200,
      message: gasRes.data.message || "Dashboard synced successfully",
      data: gasRes.data.data || null
    };
  } catch (err) {
    logError("dashboardSyncViaGas error", err);
    return { ok: false, status: 502, message: "Cannot reach Google Apps Script dashboard service" };
  }
}

function startAuthHost() {
  // kept as no-op for compatibility with existing bootstrap code paths
}

function stopAuthHost() {
  // kept as no-op for compatibility with existing bootstrap code paths
}

module.exports = {
  setLogger,
  loginViaGas,
  loopNextKeyViaGas,
  dashboardSyncViaGas,
  startAuthHost,
  stopAuthHost
};
