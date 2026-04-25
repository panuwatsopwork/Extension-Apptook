const DEFAULT_WP_API_BASE_URL = "https://apptook.waigona.com";

function resolveWpApiBaseUrl() {
  const envUrl = String(process.env.APPTOOK_WP_API_BASE_URL || "").trim();
  return envUrl || DEFAULT_WP_API_BASE_URL || "";
}

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

async function postToWpApi(path, payload) {
  const baseUrl = resolveWpApiBaseUrl();
  if (!baseUrl) {
    throw new Error("WP API base URL is not configured.");
  }

  const url = `${baseUrl.replace(/\/+$/, "")}${path.startsWith("/") ? "" : "/"}${path}`;
  const response = await fetch(url, {
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

async function loginViaWp({ apptookKey, username, device }) {
  const key = String(apptookKey || username || "").trim();
  const normalizedDevice = String(device || "").trim();

  if (!key) {
    return { ok: false, status: 400, message: "APPTOOK License Key required" };
  }

  logInfo("loginViaWp start", key);

  try {
    const wpRes = await postToWpApi("/wp-json/extension-cursor/v1/runtime/login", {
      apptookKey: key,
      deviceId: normalizedDevice
    });
    logInfo("loginViaWp response", `status=${wpRes.status}`);

    if (wpRes.status >= 400 || !wpRes.data.ok) {
      return {
        ok: false,
        status: wpRes.status || 401,
        message: wpRes.data.message || "APPTOOK License Key is invalid"
      };
    }

    return {
      ok: true,
      status: 200,
      message: wpRes.data.message || "Login success",
      data: wpRes.data.data || wpRes.data || null
    };
  } catch (err) {
    logError("loginViaWp error", err);
    return { ok: false, status: 502, message: "Cannot reach WordPress login service" };
  }
}

async function loopNextKeyViaWp({ sessionToken, currentSourceKey }) {
  const token = String(sessionToken || "").trim();
  const sourceKey = String(currentSourceKey || "").trim();

  if (!token) {
    return { ok: false, status: 401, message: "Session expired. Please log in again." };
  }

  logInfo("loopNextKeyViaWp start", sourceKey || "(empty)");

  try {
    const wpRes = await postToWpApi("/wp-json/extension-cursor/v1/runtime/loop-next", {
      sessionToken: token,
      currentSourceKey: sourceKey
    });
    logInfo("loopNextKeyViaWp response", `status=${wpRes.status}`);

    if (wpRes.status >= 400 || !wpRes.data.ok) {
      return {
        ok: false,
        status: wpRes.status || 400,
        message: wpRes.data.message || "No next loop key available"
      };
    }

    return {
      ok: true,
      status: 200,
      message: wpRes.data.message || "Next loop key ready",
      data: wpRes.data.data || wpRes.data || null
    };
  } catch (err) {
    logError("loopNextKeyViaWp error", err);
    return { ok: false, status: 502, message: "Cannot reach WordPress loop-key service" };
  }
}

async function dashboardSyncViaWp({ sessionToken, snapshot }) {
  const token = String(sessionToken || "").trim();
  const payload = snapshot && typeof snapshot === "object" ? { ...snapshot } : {};

  if (!token) {
    return { ok: false, status: 401, message: "Session expired. Please log in again." };
  }

  logInfo("dashboardSyncViaWp start", String(payload.activeSourceKey || payload.licenseCode || "").trim() || "(empty)");

  try {
    const wpRes = await postToWpApi("/wp-json/extension-cursor/v1/runtime/dashboard-sync", {
      sessionToken: token,
      ...payload
    });
    logInfo("dashboardSyncViaWp response", `status=${wpRes.status}`);

    if (wpRes.status >= 400 || !wpRes.data.ok) {
      return {
        ok: false,
        status: wpRes.status || 400,
        message: wpRes.data.message || "Dashboard sync failed"
      };
    }

    return {
      ok: true,
      status: 200,
      message: wpRes.data.message || "Dashboard synced successfully",
      data: wpRes.data.data || wpRes.data || null
    };
  } catch (err) {
    logError("dashboardSyncViaWp error", err);
    return { ok: false, status: 502, message: "Cannot reach WordPress dashboard service" };
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
  loginViaWp,
  loopNextKeyViaWp,
  dashboardSyncViaWp,
  startAuthHost,
  stopAuthHost
};
