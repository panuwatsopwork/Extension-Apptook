const SPREADSHEET_ID = "1cJ0L9JouYfVE8lvLd2F1Lm7PaQtp2RQCus94Ps_qipo";
const SOURCE_LICENSES_SHEET = "source_licenses";
const APPTOOK_KEYS_SHEET = "apptook_keys";
const APPTOOK_KEY_SOURCES_SHEET = "apptook_key_sources";
const DASHBOARD_TOKEN_SHEET = "dashboard token";
const ADMIN_TOKEN_PROPERTY = "APPTOOK_ADMIN_TOKEN";
const ACTIVE_STATUS = "active";
const DEFAULT_CHAT_LIMIT = 80;
const DEFAULT_USAGE_DISPLAY_LIMIT = 100;
const DEFAULT_SOURCE_TOKEN_CAPACITY = 100;
const SESSION_SECRET_PROPERTY = "APPTOOK_SESSION_SECRET";
const SESSION_TOKEN_TTL_MS = 12 * 60 * 60 * 1000;
const USAGE_RESET_TIME_ZONE = "Asia/Bangkok";
const USAGE_RESET_HOUR = 23;
const USAGE_RESET_MINUTE = 0;
const USAGE_RESET_OFFSET_MS = 7 * 60 * 60 * 1000;
const DAY_WINDOW_MS = 24 * 60 * 60 * 1000;

const SOURCE_LICENSES_HEADERS = [
  "source_key",
  "status",
  "source_expire_at",
  "max_devices",
  "note",
  "assigned_apptook_key",
  "last_used_by",
  "last_sync_at",
  "source_token_capacity",
  "source_consumed_raw",
  "source_last_seen_raw_usage",
  "source_usage_date"
];

const APPTOOK_KEYS_HEADERS = [
  "apptook_key",
  "key_type",
  "status",
  "expire_at",
  "chat_limit",
  "current_source_index",
  "current_source_key",
  "last_login_at",
  "last_device",
  "active_session_nonce",
  "active_session_device_id",
  "active_session_issued_at",
  "note",
  "created_at",
  "total_source_capacity",
  "usage_divisor",
  "raw_usage_total",
  "display_usage",
  "usage_date"
];

const APPTOOK_KEY_SOURCES_HEADERS = [
  "apptook_key",
  "sequence",
  "source_key",
  "status",
  "note"
];

const DASHBOARD_TOKEN_HEADERS = [
  "apptook_key",
  "mode",
  "today_chats",
  "chat_limit",
  "current_source_index",
  "total_source_keys",
  "active_source_key",
  "membership_status",
  "expire_at",
  "last_device",
  "last_sync_at",
  "raw_usage_total",
  "display_usage",
  "usage_display_limit",
  "active_source_capacity",
  "current_source_consumed_raw",
  "total_source_capacity",
  "usage_date"
];

function doGet() {
  return HtmlService.createHtmlOutputFromFile("Admin")
    .setTitle("APPTOOK Admin");
}

function doPost(e) {
  try {
    return jsonResponse_(dispatchRequest_(parsePostPayload_(e)));
  } catch (error) {
    return jsonResponse_({ ok: false, message: String(error) });
  }
}

function adminDispatch(payload) {
  try {
    return dispatchAdminRequest_(payload || {});
  } catch (error) {
    return { ok: false, message: String(error) };
  }
}

function parsePostPayload_(e) {
  const raw = (e && e.postData && e.postData.contents) ? e.postData.contents : "{}";

  try {
    return JSON.parse(raw);
  } catch (_error) {
    return {};
  }
}

function dispatchRequest_(payload) {
  const action = normalizeCell_(payload && payload.action);

  if (action === "login") {
    return apiLogin(payload);
  }

  if (action === "loopNextKey") {
    return loopNextKey(payload);
  }

  if (action === "dashboardSync") {
    return dashboardSync(payload);
  }

  return dispatchAdminRequest_(payload, action);
}

function dispatchAdminRequest_(payload, providedAction) {
  const action = providedAction || normalizeCell_(payload && payload.action);

  if (action === "setup") {
    return setupTables(payload);
  }

  if (action === "adminImportSourceLicenses") {
    return adminImportSourceLicenses(payload);
  }

  if (action === "adminCreateApptookKey") {
    return adminCreateApptookKey(payload);
  }

  if (action === "adminSaveApptookKeySources") {
    return adminSaveApptookKeySources(payload);
  }

  if (action === "adminListKeyData") {
    return adminListKeyData(payload);
  }

  return { ok: false, message: "Unknown action." };
}

function getSessionSecret_() {
  const properties = PropertiesService.getScriptProperties();
  let secret = normalizeCell_(properties.getProperty(SESSION_SECRET_PROPERTY));

  if (secret) {
    return secret;
  }

  secret = Utilities.getUuid() + Utilities.getUuid();
  properties.setProperty(SESSION_SECRET_PROPERTY, secret);
  return secret;
}

function base64WebSafeEncodeString_(value) {
  return Utilities.base64EncodeWebSafe(String(value || ""), Utilities.Charset.UTF_8).replace(/=+$/g, "");
}

function base64WebSafeEncodeBytes_(value) {
  return Utilities.base64EncodeWebSafe(value).replace(/=+$/g, "");
}

function base64WebSafeDecodeString_(value) {
  if (!value) {
    return "";
  }

  return Utilities.newBlob(Utilities.base64DecodeWebSafe(value)).getDataAsString("UTF-8");
}

function signSessionPayload_(encodedPayload) {
  const signature = Utilities.computeHmacSha256Signature(encodedPayload, getSessionSecret_());
  return base64WebSafeEncodeBytes_(signature);
}

function padNumber_(value) {
  const numberValue = Math.max(0, Math.floor(Number(value) || 0));
  return numberValue < 10 ? "0" + numberValue : String(numberValue);
}

function formatUsageDateFromLocalTimestamp_(localTimestamp) {
  const date = new Date(localTimestamp);
  return [
    date.getUTCFullYear(),
    padNumber_(date.getUTCMonth() + 1),
    padNumber_(date.getUTCDate())
  ].join("-");
}

function getUsageWindowContext_(dateValue) {
  const sourceDate = dateValue instanceof Date
    ? new Date(dateValue.getTime())
    : new Date(dateValue || new Date());
  const timestamp = sourceDate.getTime();
  const localTimestamp = timestamp + USAGE_RESET_OFFSET_MS;
  const localDate = new Date(localTimestamp);
  const currentBoundaryLocalTimestamp = Date.UTC(
    localDate.getUTCFullYear(),
    localDate.getUTCMonth(),
    localDate.getUTCDate(),
    USAGE_RESET_HOUR,
    USAGE_RESET_MINUTE,
    0,
    0
  );
  const usageDateLocalTimestamp = localTimestamp >= currentBoundaryLocalTimestamp
    ? localTimestamp + DAY_WINDOW_MS
    : localTimestamp;
  const nextBoundaryLocalTimestamp = localTimestamp >= currentBoundaryLocalTimestamp
    ? currentBoundaryLocalTimestamp + DAY_WINDOW_MS
    : currentBoundaryLocalTimestamp;

  return {
    usageDate: formatUsageDateFromLocalTimestamp_(usageDateLocalTimestamp),
    nextBoundaryTime: new Date(nextBoundaryLocalTimestamp - USAGE_RESET_OFFSET_MS),
    now: sourceDate,
    timeZone: USAGE_RESET_TIME_ZONE
  };
}

function createSessionToken_(apptookKey, device, usageDate, issuedAtDate) {
  return createSessionTokenWithNonce_(apptookKey, device, usageDate, issuedAtDate, "");
}

function createSessionTokenWithNonce_(apptookKey, device, usageDate, issuedAtDate, sessionNonce) {
  const usageContext = getUsageWindowContext_(issuedAtDate);
  const now = usageContext.now.getTime();
  const expiryTimestamp = Math.min(now + SESSION_TOKEN_TTL_MS, usageContext.nextBoundaryTime.getTime());
  const payload = {
    sub: normalizeCell_(apptookKey),
    device: normalizeCell_(device),
    usageDate: normalizeCell_(usageDate || usageContext.usageDate),
    nonce: normalizeCell_(sessionNonce),
    iat: now,
    exp: expiryTimestamp
  };
  const encodedPayload = base64WebSafeEncodeString_(JSON.stringify(payload));
  const encodedSignature = signSessionPayload_(encodedPayload);
  return encodedPayload + "." + encodedSignature;
}

function decodeSessionToken_(token) {
  const normalizedToken = normalizeCell_(token);
  if (!normalizedToken) {
    return { ok: false, message: "Unauthorized." };
  }

  const tokenParts = normalizedToken.split(".");
  if (tokenParts.length !== 2) {
    return { ok: false, message: "Unauthorized." };
  }

  const encodedPayload = tokenParts[0];
  const encodedSignature = tokenParts[1];
  if (signSessionPayload_(encodedPayload) !== encodedSignature) {
    return { ok: false, message: "Unauthorized." };
  }

  let payload = null;
  try {
    payload = JSON.parse(base64WebSafeDecodeString_(encodedPayload));
  } catch (_error) {
    payload = null;
  }

  if (!payload || normalizeCell_(payload.sub) === "") {
    return { ok: false, message: "Unauthorized." };
  }

  const now = Date.now();
  if (!Number(payload.exp) || Number(payload.exp) <= now) {
    return { ok: false, message: "Session expired. Please log in again." };
  }

  return {
    ok: true,
    payload: payload
  };
}

function getAppKeyActiveSessionState_(appKeysState, rowValues) {
  if (!appKeysState || !rowValues) {
    return {
      nonce: "",
      deviceId: "",
      issuedAt: ""
    };
  }

  return {
    nonce: normalizeCell_(getRowValue_(appKeysState, rowValues, "active_session_nonce")),
    deviceId: normalizeCell_(getRowValue_(appKeysState, rowValues, "active_session_device_id")),
    issuedAt: getRowValue_(appKeysState, rowValues, "active_session_issued_at")
  };
}

function verifySessionToken_(token, expectedUsageDate, appKeysState, appKeyRecord) {
  const decoded = decodeSessionToken_(token);
  if (!decoded.ok) {
    return decoded;
  }

  const payload = decoded.payload || {};
  const apptookKey = normalizeCell_(payload.sub);
  const sessionNonce = normalizeCell_(payload.nonce);
  if (!apptookKey || !sessionNonce) {
    return { ok: false, message: "Unauthorized." };
  }

  const usageDate = normalizeCell_(expectedUsageDate || getUsageDateKey_());
  if (normalizeCell_(payload.usageDate) !== usageDate) {
    return { ok: false, message: "Session expired. Please log in again." };
  }

  const activeSession = getAppKeyActiveSessionState_(appKeysState, appKeyRecord && appKeyRecord.values);
  if (!activeSession.nonce || activeSession.nonce !== sessionNonce) {
    return { ok: false, message: "This key is now active on another session." };
  }

  const deviceId = normalizeCell_(payload.device);
  if (activeSession.deviceId && deviceId && activeSession.deviceId !== deviceId) {
    return { ok: false, message: "This key is now active on another session." };
  }

  return {
    ok: true,
    apptookKey: apptookKey,
    device: deviceId,
    usageDate: usageDate,
    sessionNonce: sessionNonce
  };
}

function setupTables(payload) {
  if (arguments.length === 0 || payload == null) {
    payload = { token: getAdminToken_() };
  }

  if (typeof payload !== "object") {
    throw new Error("Unauthorized. Run setupTables() from the Apps Script editor, or use the Admin page with a valid token.");
  }

  guardAdmin(payload && payload.token);

  const ss = SpreadsheetApp.openById(SPREADSHEET_ID);
  resetManagedSheet_(ss, SOURCE_LICENSES_SHEET, SOURCE_LICENSES_HEADERS);
  resetManagedSheet_(ss, APPTOOK_KEYS_SHEET, APPTOOK_KEYS_HEADERS);
  resetManagedSheet_(ss, APPTOOK_KEY_SOURCES_SHEET, APPTOOK_KEY_SOURCES_HEADERS);
  resetManagedSheet_(ss, DASHBOARD_TOKEN_SHEET, DASHBOARD_TOKEN_HEADERS);

  return {
    ok: true,
    message: "Fresh APPTOOK key tables are ready."
  };
}

function setupTablesManual() {
  return setupTables({ token: getAdminToken_() });
}

function adminImportSourceLicenses(payload) {
  guardAdmin(payload && payload.token);

  const sourceKeys = normalizeSourceKeyList_(payload);
  const sourceExpireAt = normalizeCell_(payload && payload.sourceExpireAt);
  const maxDevices = normalizePositiveInteger_(payload && payload.maxDevices, 1);
  const sourceTokenCapacity = normalizePositiveNumber_(payload && payload.sourceTokenCapacity, DEFAULT_SOURCE_TOKEN_CAPACITY);
  const note = normalizeCell_(payload && payload.note);

  if (!sourceKeys.length) {
    throw new Error("Please provide at least one source license.");
  }

  if (!sourceExpireAt) {
    throw new Error("Source expire date is required.");
  }

  const expireDate = parseSheetDate_(sourceExpireAt);
  if (!expireDate) {
    throw new Error("Source expire date is invalid.");
  }

  const ss = SpreadsheetApp.openById(SPREADSHEET_ID);
  const sourceSheet = ensureManagedSheet_(ss, SOURCE_LICENSES_SHEET, SOURCE_LICENSES_HEADERS);
  const sourceState = getSheetState_(sourceSheet);
  const createdKeys = [];
  const skippedKeys = [];

  sourceKeys.forEach(function(sourceKey) {
    const existingRecord = findRowRecord_(sourceState, "source_key", sourceKey);
    if (existingRecord) {
      skippedKeys.push(sourceKey);
      return;
    }

    sourceSheet.appendRow([
      sourceKey,
      ACTIVE_STATUS,
      sourceExpireAt,
      maxDevices,
      note,
      "",
      "",
      "",
      sourceTokenCapacity,
      "",
      "",
      ""
    ]);
    sourceState.rows.push([
      sourceKey,
      ACTIVE_STATUS,
      sourceExpireAt,
      maxDevices,
      note,
      "",
      "",
      "",
      sourceTokenCapacity,
      "",
      "",
      ""
    ]);
    createdKeys.push(sourceKey);
  });

  return {
    ok: true,
    message: buildImportSummaryMessage_(createdKeys.length, skippedKeys.length),
    data: {
      createdCount: createdKeys.length,
      skippedCount: skippedKeys.length,
      createdKeys: createdKeys,
      skippedKeys: skippedKeys
    }
  };
}

function adminCreateApptookKey(payload) {
  guardAdmin(payload && payload.token);

  const ss = SpreadsheetApp.openById(SPREADSHEET_ID);
  const appKeysSheet = ensureManagedSheet_(ss, APPTOOK_KEYS_SHEET, APPTOOK_KEYS_HEADERS);
  const appKeysState = getSheetState_(appKeysSheet);

  const requestedKey = normalizeCell_(payload && payload.apptookKey);
  const apptookKey = requestedKey || generateUniqueApptookKey_(appKeysState);
  const keyType = normalizeKeyType_(payload && payload.keyType);
  const expireAt = normalizeCell_(payload && payload.expireAt);
  const note = normalizeCell_(payload && payload.note);
  const chatLimit = "";

  if (!expireAt) {
    throw new Error("APPTOOK key expire date is required.");
  }

  if (findRowRecord_(appKeysState, "apptook_key", apptookKey)) {
    throw new Error("This APPTOOK key already exists.");
  }

  appKeysSheet.appendRow([
    apptookKey,
    keyType,
    ACTIVE_STATUS,
    expireAt,
    chatLimit,
    0,
    "",
    "",
    "",
    "",
    "",
    "",
    note,
    new Date(),
    "",
    "",
    "",
    "",
    ""
  ]);

  return {
    ok: true,
    message: "APPTOOK key created successfully.",
    data: {
      apptookKey: apptookKey,
      keyType: keyType,
      chatLimit: ""
    }
  };
}

function adminSaveApptookKeySources(payload) {
  guardAdmin(payload && payload.token);

  const apptookKey = normalizeCell_(
    (payload && payload.apptookKey) ||
    (payload && payload.key) ||
    (payload && payload.username)
  );
  const sourceKeys = normalizeSourceKeyList_(payload);

  if (!apptookKey) {
    throw new Error("APPTOOK key is required.");
  }

  if (!sourceKeys.length) {
    throw new Error("Please provide at least one source license.");
  }

  const ss = SpreadsheetApp.openById(SPREADSHEET_ID);
  const appKeysSheet = ensureManagedSheet_(ss, APPTOOK_KEYS_SHEET, APPTOOK_KEYS_HEADERS);
  const sourceSheet = ensureManagedSheet_(ss, SOURCE_LICENSES_SHEET, SOURCE_LICENSES_HEADERS);
  const keySourcesSheet = ensureManagedSheet_(ss, APPTOOK_KEY_SOURCES_SHEET, APPTOOK_KEY_SOURCES_HEADERS);

  const appKeysState = getSheetState_(appKeysSheet);
  const sourceState = getSheetState_(sourceSheet);
  const keySourcesState = getSheetState_(keySourcesSheet);
  const usageDate = getUsageDateKey_();
  const sourceAssignmentMap = getSourceAssignmentMapFromKeySources_(keySourcesState);

  const appKeyRecord = findRowRecord_(appKeysState, "apptook_key", apptookKey);
  if (!appKeyRecord) {
    throw new Error("APPTOOK key was not found.");
  }

  const keyType = normalizeKeyType_(getRowValue_(appKeysState, appKeyRecord.values, "key_type"));
  let totalAssignedSourceCapacity = 0;
  if (keyType === "single" && sourceKeys.length !== 1) {
    throw new Error("Single APPTOOK keys can only use one source license.");
  }

  sourceKeys.forEach(function(sourceKey) {
    const sourceRecord = findRowRecord_(sourceState, "source_key", sourceKey);
    if (!sourceRecord) {
      throw new Error("Source license was not found: " + sourceKey);
    }

    const assignedKey = normalizeCell_(sourceAssignmentMap[sourceKey]);
    if (assignedKey && assignedKey !== apptookKey) {
      throw new Error("Source license is already assigned to another APPTOOK key: " + sourceKey);
    }

    totalAssignedSourceCapacity += getSourceTokenCapacity_(sourceState, sourceRecord.values);
  });

  if (totalAssignedSourceCapacity < DEFAULT_USAGE_DISPLAY_LIMIT) {
    throw new Error("Total source token capacity must be at least 100.");
  }

  const currentSourceKeys = getActiveSourceEntriesForApptookKey_(keySourcesState, apptookKey).map(function(entry) {
    return entry.sourceKey;
  });
  const nextSourceLookup = buildLookupMap_(sourceKeys);

  currentSourceKeys.forEach(function(sourceKey) {
    if (!nextSourceLookup[sourceKey]) {
      const sourceRecord = findRowRecord_(sourceState, "source_key", sourceKey);
      if (sourceRecord) {
        updateRowValues_(sourceSheet, sourceRecord.rowNumber, sourceState.headerMap, {
          assigned_apptook_key: "",
          last_used_by: "",
          last_sync_at: "",
          source_consumed_raw: "",
          source_last_seen_raw_usage: "",
          source_usage_date: ""
        });
      }
    }
  });

  sourceKeys.forEach(function(sourceKey) {
    const sourceRecord = findRowRecord_(sourceState, "source_key", sourceKey);
    updateRowValues_(sourceSheet, sourceRecord.rowNumber, sourceState.headerMap, {
      assigned_apptook_key: apptookKey,
      last_used_by: "",
      last_sync_at: "",
      source_consumed_raw: "",
      source_last_seen_raw_usage: "",
      source_usage_date: ""
    });
  });

  const keptRows = keySourcesState.rows.slice(1).filter(function(row) {
    return normalizeCell_(row[keySourcesState.headerMap.apptook_key]) !== apptookKey;
  });

  const nextRows = sourceKeys.map(function(sourceKey, index) {
    return [apptookKey, index + 1, sourceKey, ACTIVE_STATUS, ""];
  });

  replaceSheetBody_(keySourcesSheet, APPTOOK_KEY_SOURCES_HEADERS, keptRows.concat(nextRows));
  const refreshedKeySourcesState = getSheetState_(keySourcesSheet);
  syncSourceAssignmentsFromKeySources_(sourceSheet, sourceState, refreshedKeySourcesState);
  updateRowValues_(appKeysSheet, appKeyRecord.rowNumber, appKeysState.headerMap, {
    current_source_index: 0,
    current_source_key: sourceKeys[0],
    active_session_nonce: "",
    active_session_device_id: "",
    active_session_issued_at: "",
    total_source_capacity: totalAssignedSourceCapacity,
    usage_divisor: calculateUsageNormalizationDivisor_(totalAssignedSourceCapacity),
    raw_usage_total: 0,
    display_usage: 0,
    usage_date: usageDate,
    chat_limit: ""
  });

  return {
    ok: true,
    message: "Source licenses saved successfully.",
    data: {
      apptookKey: apptookKey,
      keyType: keyType,
      totalSourceKeys: sourceKeys.length,
      currentSourceKey: sourceKeys[0],
      currentSourceIndex: 0,
      totalKeys: sourceKeys.length,
      currentKeyCode: sourceKeys[0],
      currentKeyIndex: 0,
      currentSourceCapacity: getSourceTokenCapacity_(sourceState, findRowRecord_(sourceState, "source_key", sourceKeys[0]).values),
      currentSourceConsumedRaw: 0,
      usageDisplayLimit: DEFAULT_USAGE_DISPLAY_LIMIT,
      usageNormalizationDivisor: calculateUsageNormalizationDivisor_(totalAssignedSourceCapacity),
      totalAssignedSourceCapacity: totalAssignedSourceCapacity,
      rawUsageTotal: 0,
      displayUsage: 0,
      usageDate: usageDate
    }
  };
}

function adminListKeyData(payload) {
  guardAdmin(payload && payload.token);

  const ss = SpreadsheetApp.openById(SPREADSHEET_ID);
  const sourceSheet = ensureManagedSheet_(ss, SOURCE_LICENSES_SHEET, SOURCE_LICENSES_HEADERS);
  const appKeysSheet = ensureManagedSheet_(ss, APPTOOK_KEYS_SHEET, APPTOOK_KEYS_HEADERS);
  const keySourcesSheet = ensureManagedSheet_(ss, APPTOOK_KEY_SOURCES_SHEET, APPTOOK_KEY_SOURCES_HEADERS);
  const dashboardSheet = ensureManagedSheet_(ss, DASHBOARD_TOKEN_SHEET, DASHBOARD_TOKEN_HEADERS);
  syncSourceAssignmentsFromKeySources_(sourceSheet, getSheetState_(sourceSheet), getSheetState_(keySourcesSheet));

  return {
    ok: true,
    message: "APPTOOK key data loaded successfully.",
    sourceLicenses: getSheetDisplayValues_(sourceSheet),
    apptookKeys: getSheetDisplayValues_(appKeysSheet),
    apptookKeySources: getSheetDisplayValues_(keySourcesSheet),
    dashboardTokens: getSheetDisplayValues_(dashboardSheet)
  };
}

function apiLogin(payload) {
  const apptookKey = normalizeCell_(
    (payload && payload.apptookKey) ||
    (payload && payload.username)
  );
  const device = normalizeCell_(payload && payload.device);

  if (!apptookKey) {
    return { ok: false, message: "APPTOOK key is required." };
  }

  const ss = SpreadsheetApp.openById(SPREADSHEET_ID);
  const sourceSheet = ensureManagedSheet_(ss, SOURCE_LICENSES_SHEET, SOURCE_LICENSES_HEADERS);
  const appKeysSheet = ensureManagedSheet_(ss, APPTOOK_KEYS_SHEET, APPTOOK_KEYS_HEADERS);
  const keySourcesSheet = ensureManagedSheet_(ss, APPTOOK_KEY_SOURCES_SHEET, APPTOOK_KEY_SOURCES_HEADERS);

  const sourceState = getSheetState_(sourceSheet);
  const appKeysState = getSheetState_(appKeysSheet);
  const keySourcesState = getSheetState_(keySourcesSheet);
  const usageDate = getUsageDateKey_();

  const appKeyRecord = findRowRecord_(appKeysState, "apptook_key", apptookKey);
  const appKeyValidation = validateApptookKeyRecord_(appKeysState, appKeyRecord);
  if (!appKeyValidation.ok) {
    return appKeyValidation;
  }

  const keyType = normalizeKeyType_(getRowValue_(appKeysState, appKeyRecord.values, "key_type"));
  const sourceEntries = getActiveSourceEntriesForApptookKey_(keySourcesState, apptookKey);
  if (!sourceEntries.length) {
    return { ok: false, message: "No source licenses are assigned to this APPTOOK key." };
  }

  let resolvedSource = null;
  if (keyType === "loop") {
    const storedIndex = normalizePositiveInteger_(
      getRowValue_(appKeysState, appKeyRecord.values, "current_source_index"),
      0
    );
    const storedSourceKey = normalizeCell_(getRowValue_(appKeysState, appKeyRecord.values, "current_source_key"));
    resolvedSource = resolveCurrentLoopSource_(sourceEntries, sourceState, storedIndex, storedSourceKey, usageDate);
  } else {
    resolvedSource = resolveFirstUsableSource_(sourceEntries, sourceState, usageDate);
  }

  if (!resolvedSource) {
    return { ok: false, message: "APPTOOK key has reached today's usage limit." };
  }

  const usageState = buildUsageStateMetadata_(
    sourceEntries,
    sourceState,
    appKeysState,
    appKeyRecord,
    resolvedSource.entry.sourceKey,
    usageDate
  );
  if (!usageState.ok) {
    return { ok: false, message: usageState.message };
  }

  const syncTime = new Date();
  const effectiveExpireAt = getEarlierDate_(appKeyValidation.expireAt, resolvedSource.expireAt);
  const chatLimit = "";
  const activeSession = getAppKeyActiveSessionState_(appKeysState, appKeyRecord.values);
  const sessionNonce = activeSession.nonce && activeSession.deviceId === device
    ? activeSession.nonce
    : Utilities.getUuid().replace(/-/g, "");

  updateRowValues_(appKeysSheet, appKeyRecord.rowNumber, appKeysState.headerMap, {
    current_source_index: resolvedSource.index,
    current_source_key: resolvedSource.entry.sourceKey,
    last_login_at: syncTime,
    last_device: device,
    active_session_nonce: sessionNonce,
    active_session_device_id: device,
    active_session_issued_at: syncTime,
    total_source_capacity: usageState.totalAssignedSourceCapacity,
    usage_divisor: usageState.usageNormalizationDivisor,
    raw_usage_total: usageState.rawUsageTotal,
    display_usage: usageState.displayUsage,
    usage_date: usageDate
  });

  touchSourceLicenseUsage_(sourceSheet, sourceState.headerMap, resolvedSource.sourceRecord.rowNumber, {
    last_used_by: apptookKey,
    last_sync_at: syncTime
  });

  return {
    ok: true,
    message: "Login successful.",
    data: Object.assign(
      buildLoginResponseData_(
        apptookKey,
        keyType,
        resolvedSource.entry.sourceKey,
        resolvedSource.index,
        sourceEntries.length,
        chatLimit,
        effectiveExpireAt,
        appKeyValidation.expireAt,
        resolvedSource.expireAt,
        usageState,
        device
      ),
      {
      sessionToken: createSessionTokenWithNonce_(apptookKey, device, usageDate, syncTime, sessionNonce)
      }
    )
  };
}

function loopNextKey(payload) {
  const usageDate = getUsageDateKey_();
  const decodedSession = decodeSessionToken_(payload && payload.sessionToken);
  if (!decodedSession.ok) {
    return { ok: false, message: decodedSession.message || "Unauthorized." };
  }

  const apptookKey = normalizeCell_(decodedSession.payload && decodedSession.payload.sub);
  const currentSourceKey = normalizeCell_(
    (payload && payload.currentSourceKey) ||
    (payload && payload.currentKeyCode)
  );

  if (!apptookKey) {
    return { ok: false, message: "APPTOOK key is required." };
  }

  const ss = SpreadsheetApp.openById(SPREADSHEET_ID);
  const sourceSheet = ensureManagedSheet_(ss, SOURCE_LICENSES_SHEET, SOURCE_LICENSES_HEADERS);
  const appKeysSheet = ensureManagedSheet_(ss, APPTOOK_KEYS_SHEET, APPTOOK_KEYS_HEADERS);
  const keySourcesSheet = ensureManagedSheet_(ss, APPTOOK_KEY_SOURCES_SHEET, APPTOOK_KEY_SOURCES_HEADERS);

  const sourceState = getSheetState_(sourceSheet);
  const appKeysState = getSheetState_(appKeysSheet);
  const keySourcesState = getSheetState_(keySourcesSheet);
  const appKeyRecord = findRowRecord_(appKeysState, "apptook_key", apptookKey);
  const appKeyValidation = validateApptookKeyRecord_(appKeysState, appKeyRecord);
  if (!appKeyValidation.ok) {
    return appKeyValidation;
  }

  const sessionValidation = verifySessionToken_(payload && payload.sessionToken, usageDate, appKeysState, appKeyRecord);
  if (!sessionValidation.ok) {
    return { ok: false, message: sessionValidation.message || "Unauthorized." };
  }

  const keyType = normalizeKeyType_(getRowValue_(appKeysState, appKeyRecord.values, "key_type"));
  if (keyType !== "loop") {
    return { ok: false, message: "This APPTOOK key is not configured as a loop key." };
  }

  const sourceEntries = getActiveSourceEntriesForApptookKey_(keySourcesState, apptookKey);
  if (!sourceEntries.length) {
    return { ok: false, message: "No source licenses are assigned to this APPTOOK key." };
  }

  const storedIndex = normalizePositiveInteger_(
    getRowValue_(appKeysState, appKeyRecord.values, "current_source_index"),
    0
  );
  const storedSourceKey = normalizeCell_(getRowValue_(appKeysState, appKeyRecord.values, "current_source_key"));
  const nextSource = resolveNextLoopSource_(
    sourceEntries,
    sourceState,
    currentSourceKey,
    storedIndex,
    storedSourceKey,
    usageDate
  );

  if (!nextSource) {
    return { ok: false, message: "APPTOOK key has reached today's usage limit." };
  }

  const usageState = buildUsageStateMetadata_(
    sourceEntries,
    sourceState,
    appKeysState,
    appKeyRecord,
    nextSource.entry.sourceKey,
    usageDate
  );
  if (!usageState.ok) {
    return { ok: false, message: usageState.message };
  }

  const syncTime = new Date();
  const chatLimit = "";
  const effectiveExpireAt = getEarlierDate_(appKeyValidation.expireAt, nextSource.expireAt);
  updateRowValues_(appKeysSheet, appKeyRecord.rowNumber, appKeysState.headerMap, {
    current_source_index: nextSource.index,
    current_source_key: nextSource.entry.sourceKey,
    total_source_capacity: usageState.totalAssignedSourceCapacity,
    usage_divisor: usageState.usageNormalizationDivisor,
    raw_usage_total: usageState.rawUsageTotal,
    display_usage: usageState.displayUsage,
    usage_date: usageDate
  });
  touchSourceLicenseUsage_(sourceSheet, sourceState.headerMap, nextSource.sourceRecord.rowNumber, {
    last_used_by: apptookKey,
    last_sync_at: syncTime
  });

  return {
    ok: true,
    message: "Next source license selected successfully.",
    data: Object.assign(
      buildLoginResponseData_(
        apptookKey,
        keyType,
        nextSource.entry.sourceKey,
        nextSource.index,
        sourceEntries.length,
        chatLimit,
        effectiveExpireAt,
        appKeyValidation.expireAt,
        nextSource.expireAt,
        usageState,
        sessionValidation.device
      ),
      {
        wrapped: nextSource.wrapped,
      sessionToken: createSessionTokenWithNonce_(apptookKey, sessionValidation.device, usageDate, syncTime, sessionValidation.sessionNonce)
      }
    )
  };
}

function dashboardSync(payload) {
  const syncTime = new Date();
  const usageDate = getUsageDateKey_(syncTime);
  const decodedSession = decodeSessionToken_(payload && payload.sessionToken);
  if (!decodedSession.ok) {
    return { ok: false, message: decodedSession.message || "Unauthorized." };
  }

  const apptookKey = normalizeCell_(decodedSession.payload && decodedSession.payload.sub);
  const activeSourceKey = normalizeCell_(
    (payload && payload.activeSourceKey) ||
    (payload && payload.currentSourceKey) ||
    (payload && payload.licenseCode) ||
    (payload && payload.currentKeyCode)
  );

  if (!apptookKey || !activeSourceKey) {
    return { ok: false, message: "APPTOOK key and active source license are required." };
  }

  const mode = normalizeCell_(payload && payload.mode) === "loop" ? "loop" : "normal";
  const todayChats = normalizeOptionalNumber_(payload && payload.todayChats);
  const chatLimit = normalizeOptionalNumber_(payload && payload.chatLimit);
  const currentSourceIndex = normalizeOptionalInteger_(
    (payload && payload.currentSourceIndex) ||
    (payload && payload.currentKeyIndex)
  );
  const totalSourceKeys = normalizeOptionalInteger_(
    (payload && payload.totalSourceKeys) ||
    (payload && payload.totalKeys)
  );
  const membershipStatus = normalizeCell_(payload && payload.membershipStatus);
  const expireAt = normalizeCell_(payload && payload.expireAt);
  const lastDevice = normalizeCell_((payload && payload.lastDevice) || (payload && payload.device));

  const ss = SpreadsheetApp.openById(SPREADSHEET_ID);
  const dashboardSheet = ensureManagedSheet_(ss, DASHBOARD_TOKEN_SHEET, DASHBOARD_TOKEN_HEADERS);
  const sourceSheet = ensureManagedSheet_(ss, SOURCE_LICENSES_SHEET, SOURCE_LICENSES_HEADERS);
  const appKeysSheet = ensureManagedSheet_(ss, APPTOOK_KEYS_SHEET, APPTOOK_KEYS_HEADERS);
  const keySourcesSheet = ensureManagedSheet_(ss, APPTOOK_KEY_SOURCES_SHEET, APPTOOK_KEY_SOURCES_HEADERS);
  const dashboardState = getSheetState_(dashboardSheet);
  const sourceState = getSheetState_(sourceSheet);
  const appKeysState = getSheetState_(appKeysSheet);
  const keySourcesState = getSheetState_(keySourcesSheet);

  const appKeyRecord = findRowRecord_(appKeysState, "apptook_key", apptookKey);
  const appKeyValidation = validateApptookKeyRecord_(appKeysState, appKeyRecord);
  if (!appKeyValidation.ok) {
    return appKeyValidation;
  }

  const sessionValidation = verifySessionToken_(payload && payload.sessionToken, usageDate, appKeysState, appKeyRecord);
  if (!sessionValidation.ok) {
    return { ok: false, message: sessionValidation.message || "Unauthorized." };
  }

  const sourceEntries = getActiveSourceEntriesForApptookKey_(keySourcesState, apptookKey);
  if (!sourceEntries.length) {
    return { ok: false, message: "No source licenses are assigned to this APPTOOK key." };
  }

  if (todayChats !== null) {
    syncSourceUsageSnapshot_(sourceSheet, sourceState, activeSourceKey, apptookKey, todayChats, usageDate, syncTime);
  }

  const usageState = buildUsageStateMetadata_(
    sourceEntries,
    sourceState,
    appKeysState,
    appKeyRecord,
    activeSourceKey,
    usageDate
  );
  if (!usageState.ok) {
    return { ok: false, message: usageState.message };
  }

  const existingRow = findRowRecord_(dashboardState, "apptook_key", apptookKey);
  const rowValues = [
    apptookKey,
    mode,
    todayChats === null ? "" : todayChats,
    chatLimit === null ? "" : chatLimit,
    currentSourceIndex === null ? "" : currentSourceIndex,
    totalSourceKeys === null ? "" : totalSourceKeys,
    activeSourceKey,
    membershipStatus,
    expireAt,
    lastDevice,
    syncTime,
    usageState.rawUsageTotal,
    usageState.displayUsage,
    usageState.usageDisplayLimit,
    usageState.currentSourceCapacity,
    usageState.currentSourceConsumedRaw,
    usageState.totalAssignedSourceCapacity,
    usageDate
  ];

  if (existingRow) {
    dashboardSheet.getRange(existingRow.rowNumber, 1, 1, DASHBOARD_TOKEN_HEADERS.length).setValues([rowValues]);
  } else {
    dashboardSheet.appendRow(rowValues);
  }

  const sourceRecord = findRowRecord_(sourceState, "source_key", activeSourceKey);
  if (sourceRecord) {
    touchSourceLicenseUsage_(sourceSheet, sourceState.headerMap, sourceRecord.rowNumber, {
      last_used_by: apptookKey,
      last_sync_at: syncTime
    });
  }

  updateRowValues_(appKeysSheet, appKeyRecord.rowNumber, appKeysState.headerMap, {
    current_source_index: currentSourceIndex === null ? getRowValue_(appKeysState, appKeyRecord.values, "current_source_index") : currentSourceIndex,
    current_source_key: activeSourceKey,
    last_device: lastDevice || getRowValue_(appKeysState, appKeyRecord.values, "last_device"),
    total_source_capacity: usageState.totalAssignedSourceCapacity,
    usage_divisor: usageState.usageNormalizationDivisor,
    raw_usage_total: usageState.rawUsageTotal,
    display_usage: usageState.displayUsage,
    usage_date: usageDate
  });

  return {
    ok: true,
    message: "Dashboard snapshot synced successfully.",
    data: {
      apptookKey: apptookKey,
      activeSourceKey: activeSourceKey,
      lastSyncAt: syncTime.toISOString(),
      usageDisplayLimit: usageState.usageDisplayLimit,
      usageNormalizationDivisor: usageState.usageNormalizationDivisor,
      totalAssignedSourceCapacity: usageState.totalAssignedSourceCapacity,
      rawUsageTotal: usageState.rawUsageTotal,
      displayUsage: usageState.displayUsage,
      currentSourceCapacity: usageState.currentSourceCapacity,
      currentSourceConsumedRaw: usageState.currentSourceConsumedRaw,
      usageDate: usageDate,
      sessionToken: createSessionTokenWithNonce_(apptookKey, sessionValidation.device || lastDevice, usageDate, syncTime, sessionValidation.sessionNonce)
    }
  };
}

function getAdminToken_() {
  const token = normalizeCell_(PropertiesService.getScriptProperties().getProperty(ADMIN_TOKEN_PROPERTY));
  if (!token) {
    throw new Error("Admin token is not configured. Set Script Property APPTOOK_ADMIN_TOKEN first.");
  }
  return token;
}

function setAdminToken(token) {
  const normalizedToken = normalizeCell_(token);
  if (!normalizedToken) {
    throw new Error("Token is required.");
  }

  PropertiesService.getScriptProperties().setProperty(ADMIN_TOKEN_PROPERTY, normalizedToken);
  return {
    ok: true,
    message: "Admin token saved to Script Properties."
  };
}

function guardAdmin(token) {
  const expectedToken = getAdminToken_();
  if (normalizeCell_(token) !== expectedToken) {
    throw new Error("Unauthorized.");
  }
}

function jsonResponse_(payload) {
  return ContentService
    .createTextOutput(JSON.stringify(payload || {}))
    .setMimeType(ContentService.MimeType.JSON);
}

function resetManagedSheet_(spreadsheet, sheetName, headers) {
  const sheet = ensureManagedSheet_(spreadsheet, sheetName, headers);
  sheet.clearContents();
  ensureSheetColumns_(sheet, headers.length);
  sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
  sheet.setFrozenRows(1);
  return sheet;
}

function ensureManagedSheet_(spreadsheet, sheetName, headers) {
  let sheet = spreadsheet.getSheetByName(sheetName);
  if (!sheet) {
    sheet = spreadsheet.insertSheet(sheetName);
  }

  ensureSheetColumns_(sheet, headers.length);
  sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
  sheet.setFrozenRows(1);
  return sheet;
}

function ensureSheetColumns_(sheet, columnCount) {
  const missingColumns = columnCount - sheet.getMaxColumns();
  if (missingColumns > 0) {
    sheet.insertColumnsAfter(sheet.getMaxColumns(), missingColumns);
  }
}

function getSheetState_(sheet) {
  const lastRow = Math.max(sheet.getLastRow(), 1);
  const lastColumn = Math.max(sheet.getLastColumn(), 1);
  const rows = sheet.getRange(1, 1, lastRow, lastColumn).getValues();
  const headerMap = {};
  const headers = rows[0] || [];

  headers.forEach(function(header, index) {
    const key = normalizeCell_(header);
    if (key) {
      headerMap[key] = index;
    }
  });

  return {
    sheet: sheet,
    rows: rows,
    headerMap: headerMap
  };
}

function getSheetDisplayValues_(sheet) {
  const lastRow = Math.max(sheet.getLastRow(), 1);
  const lastColumn = Math.max(sheet.getLastColumn(), 1);
  return sheet.getRange(1, 1, lastRow, lastColumn).getDisplayValues();
}

function findRowRecord_(state, headerName, expectedValue) {
  const columnIndex = state.headerMap[headerName];
  if (columnIndex === undefined) {
    return null;
  }

  const needle = normalizeCell_(expectedValue);
  for (let index = 1; index < state.rows.length; index += 1) {
    if (normalizeCell_(state.rows[index][columnIndex]) === needle) {
      return {
        rowNumber: index + 1,
        values: state.rows[index]
      };
    }
  }

  return null;
}

function getRowValue_(state, rowValues, headerName) {
  const columnIndex = state.headerMap[headerName];
  if (columnIndex === undefined) {
    return "";
  }
  return rowValues[columnIndex];
}

function updateRowValues_(sheet, rowNumber, headerMap, updates) {
  Object.keys(updates).forEach(function(headerName) {
    const columnIndex = headerMap[headerName];
    if (columnIndex === undefined) {
      return;
    }
    sheet.getRange(rowNumber, columnIndex + 1).setValue(updates[headerName]);
  });
}

function replaceSheetBody_(sheet, headers, bodyRows) {
  const lastRow = sheet.getLastRow();
  const clearColumns = Math.max(sheet.getLastColumn(), headers.length);

  if (lastRow > 1) {
    sheet.getRange(2, 1, lastRow - 1, clearColumns).clearContent();
  }

  const requiredRows = bodyRows.length + 1;
  if (sheet.getMaxRows() < requiredRows) {
    sheet.insertRowsAfter(sheet.getMaxRows(), requiredRows - sheet.getMaxRows());
  }

  if (bodyRows.length) {
    sheet.getRange(2, 1, bodyRows.length, headers.length).setValues(bodyRows);
  }
}

function touchSourceLicenseUsage_(sheet, headerMap, rowNumber, updates) {
  updateRowValues_(sheet, rowNumber, headerMap, updates || {});
}

function getSourceAssignmentMapFromKeySources_(keySourcesState) {
  const keyIndex = keySourcesState.headerMap.apptook_key;
  const sourceKeyIndex = keySourcesState.headerMap.source_key;
  const statusIndex = keySourcesState.headerMap.status;
  const assignmentMap = {};

  if (keyIndex === undefined || sourceKeyIndex === undefined) {
    return assignmentMap;
  }

  keySourcesState.rows.slice(1).forEach(function(row) {
    const sourceKey = normalizeCell_(row[sourceKeyIndex]);
    if (!sourceKey) {
      return;
    }

    const status = normalizeStatus_(statusIndex === undefined ? ACTIVE_STATUS : row[statusIndex]);
    if (status && status !== ACTIVE_STATUS) {
      return;
    }

    assignmentMap[sourceKey] = normalizeCell_(row[keyIndex]);
  });

  return assignmentMap;
}

function syncSourceAssignmentsFromKeySources_(sourceSheet, sourceState, keySourcesState) {
  const assignedIndex = sourceState.headerMap.assigned_apptook_key;
  const sourceKeyIndex = sourceState.headerMap.source_key;
  if (assignedIndex === undefined || sourceKeyIndex === undefined) {
    return;
  }

  const assignmentMap = getSourceAssignmentMapFromKeySources_(keySourcesState);
  for (let index = 1; index < sourceState.rows.length; index += 1) {
    const row = sourceState.rows[index];
    const sourceKey = normalizeCell_(row[sourceKeyIndex]);
    if (!sourceKey) {
      continue;
    }

    const expectedAssignedKey = normalizeCell_(assignmentMap[sourceKey]);
    const currentAssignedKey = normalizeCell_(row[assignedIndex]);
    if (currentAssignedKey === expectedAssignedKey) {
      continue;
    }

    updateRowValues_(sourceSheet, index + 1, sourceState.headerMap, {
      assigned_apptook_key: expectedAssignedKey
    });
    row[assignedIndex] = expectedAssignedKey;
  }
}

function getActiveSourceEntriesForApptookKey_(keySourcesState, apptookKey) {
  const keyIndex = keySourcesState.headerMap.apptook_key;
  const sequenceIndex = keySourcesState.headerMap.sequence;
  const sourceKeyIndex = keySourcesState.headerMap.source_key;
  const statusIndex = keySourcesState.headerMap.status;

  if (keyIndex === undefined || sequenceIndex === undefined || sourceKeyIndex === undefined) {
    return [];
  }

  return keySourcesState.rows.slice(1)
    .filter(function(row) {
      if (normalizeCell_(row[keyIndex]) !== apptookKey) {
        return false;
      }
      const status = normalizeStatus_(statusIndex === undefined ? ACTIVE_STATUS : row[statusIndex]);
      return !status || status === ACTIVE_STATUS;
    })
    .map(function(row, index) {
      return {
        originalIndex: index,
        sequence: normalizePositiveInteger_(row[sequenceIndex], index + 1),
        sourceKey: normalizeCell_(row[sourceKeyIndex])
      };
    })
    .filter(function(entry) {
      return Boolean(entry.sourceKey);
    })
    .sort(function(left, right) {
      return left.sequence - right.sequence;
    });
}

function resolveFirstUsableSource_(entries, sourceState, usageDate) {
  for (let index = 0; index < entries.length; index += 1) {
    const usable = buildUsableSourceEntry_(entries, index, sourceState, usageDate);
    if (usable) {
      return usable;
    }
  }

  return null;
}

function resolveCurrentLoopSource_(entries, sourceState, storedIndex, storedSourceKey, usageDate) {
  const preferredIndexes = [];
  const normalizedStoredKey = normalizeCell_(storedSourceKey);

  if (normalizedStoredKey) {
    const storedKeyIndex = findSourceEntryIndexByKey_(entries, normalizedStoredKey);
    if (storedKeyIndex !== -1) {
      preferredIndexes.push(storedKeyIndex);
    }
  }

  if (storedIndex >= 0 && storedIndex < entries.length && preferredIndexes.indexOf(storedIndex) === -1) {
    preferredIndexes.push(storedIndex);
  }

  const scanned = {};
  for (let index = 0; index < preferredIndexes.length; index += 1) {
    const preferredIndex = preferredIndexes[index];
    scanned[preferredIndex] = true;
    const usable = buildUsableSourceEntry_(entries, preferredIndex, sourceState, usageDate);
    if (usable) {
      return usable;
    }
  }

  for (let index = 0; index < entries.length; index += 1) {
    if (scanned[index]) {
      continue;
    }
    const usable = buildUsableSourceEntry_(entries, index, sourceState, usageDate);
    if (usable) {
      return usable;
    }
  }

  return null;
}

function resolveNextLoopSource_(entries, sourceState, currentSourceKey, storedIndex, storedSourceKey, usageDate) {
  if (!entries.length) {
    return null;
  }

  const normalizedCurrentSourceKey = normalizeCell_(currentSourceKey);
  let currentIndex = findSourceEntryIndexByKey_(entries, normalizedCurrentSourceKey);

  if (currentIndex === -1) {
    currentIndex = findSourceEntryIndexByKey_(entries, storedSourceKey);
  }

  if (currentIndex === -1 && storedIndex >= 0 && storedIndex < entries.length) {
    currentIndex = storedIndex;
  }

  for (let offset = 1; offset <= entries.length; offset += 1) {
    const nextIndex = (currentIndex + offset + entries.length) % entries.length;
    const entry = entries[nextIndex];

    if (normalizedCurrentSourceKey && entry.sourceKey === normalizedCurrentSourceKey) {
      continue;
    }

    const usable = buildUsableSourceEntry_(entries, nextIndex, sourceState, usageDate);
    if (usable) {
      usable.wrapped = currentIndex !== -1 && nextIndex <= currentIndex;
      return usable;
    }
  }

  return null;
}

function buildUsableSourceEntry_(entries, index, sourceState, usageDate) {
  const entry = entries[index];
  if (!entry) {
    return null;
  }

  const sourceRecord = findRowRecord_(sourceState, "source_key", entry.sourceKey);
  const validation = validateSourceLicenseRecord_(sourceState, sourceRecord);
  if (!validation.ok) {
    return null;
  }

  const sourceUsageState = getSourceUsageState_(sourceState, sourceRecord.values, usageDate);
  if (sourceUsageState.capacity > 0 && sourceUsageState.cappedConsumedRaw >= sourceUsageState.capacity) {
    return null;
  }

  return {
    index: index,
    entry: entry,
    sourceRecord: sourceRecord,
    expireAt: validation.expireAt,
    currentSourceCapacity: sourceUsageState.capacity,
    currentSourceConsumedRaw: sourceUsageState.cappedConsumedRaw,
    wrapped: false
  };
}

function findSourceEntryIndexByKey_(entries, sourceKey) {
  const normalizedSourceKey = normalizeCell_(sourceKey);
  if (!normalizedSourceKey) {
    return -1;
  }

  for (let index = 0; index < entries.length; index += 1) {
    if (normalizeCell_(entries[index].sourceKey) === normalizedSourceKey) {
      return index;
    }
  }

  return -1;
}

function validateSourceLicenseRecord_(sourceState, sourceRecord) {
  if (!sourceRecord) {
    return { ok: false, message: "Assigned source license was not found." };
  }

  const status = normalizeStatus_(getRowValue_(sourceState, sourceRecord.values, "status"));
  if (status !== ACTIVE_STATUS) {
    return { ok: false, message: "Source license is expired or disabled." };
  }

  const expireAt = parseSheetDate_(getRowValue_(sourceState, sourceRecord.values, "source_expire_at"));
  if (!expireAt || expireAt.getTime() < Date.now()) {
    return { ok: false, message: "Source license is expired or disabled." };
  }

  return {
    ok: true,
    expireAt: expireAt
  };
}

function validateApptookKeyRecord_(appKeysState, appKeyRecord) {
  if (!appKeyRecord) {
    return { ok: false, message: "APPTOOK key was not found." };
  }

  const status = normalizeStatus_(getRowValue_(appKeysState, appKeyRecord.values, "status"));
  if (status !== ACTIVE_STATUS) {
    return { ok: false, message: "APPTOOK key is expired or disabled." };
  }

  const expireAt = parseSheetDate_(getRowValue_(appKeysState, appKeyRecord.values, "expire_at"));
  if (!expireAt || expireAt.getTime() < Date.now()) {
    return { ok: false, message: "APPTOOK key is expired or disabled." };
  }

  return {
    ok: true,
    expireAt: expireAt
  };
}

function buildLoginResponseData_(apptookKey, keyType, sourceKey, sourceIndex, totalSourceKeys, chatLimit, effectiveExpireAt, keyExpireAt, sourceExpireAt, usageScale, lastDevice) {
  const isLoop = keyType === "loop";
  const data = {
    apptookKey: apptookKey,
    username: apptookKey,
    licenseCode: sourceKey,
    currentSourceKey: sourceKey,
    currentKeyCode: sourceKey,
    expireAt: effectiveExpireAt.toISOString(),
    keyExpireAt: keyExpireAt.toISOString(),
    userExpireAt: keyExpireAt.toISOString(),
    sourceExpireAt: sourceExpireAt.toISOString(),
    licenseExpireAt: sourceExpireAt.toISOString(),
    mode: isLoop ? "loop" : "normal",
    lastDevice: lastDevice || "",
    currentSourceIndex: isLoop ? sourceIndex : 0,
    currentKeyIndex: isLoop ? sourceIndex : 0,
    totalSourceKeys: totalSourceKeys,
    totalKeys: totalSourceKeys,
    usageDisplayLimit: usageScale && usageScale.usageDisplayLimit ? usageScale.usageDisplayLimit : DEFAULT_USAGE_DISPLAY_LIMIT,
    usageNormalizationDivisor: usageScale && usageScale.usageNormalizationDivisor ? usageScale.usageNormalizationDivisor : 1,
    totalAssignedSourceCapacity: usageScale && usageScale.totalAssignedSourceCapacity ? usageScale.totalAssignedSourceCapacity : DEFAULT_USAGE_DISPLAY_LIMIT,
    currentSourceCapacity: usageScale && usageScale.currentSourceCapacity ? usageScale.currentSourceCapacity : DEFAULT_SOURCE_TOKEN_CAPACITY,
    currentSourceConsumedRaw: usageScale && usageScale.currentSourceConsumedRaw ? usageScale.currentSourceConsumedRaw : 0,
    rawUsageTotal: usageScale && usageScale.rawUsageTotal ? usageScale.rawUsageTotal : 0,
    displayUsage: usageScale && usageScale.displayUsage ? usageScale.displayUsage : 0,
    usageDate: usageScale && usageScale.usageDate ? usageScale.usageDate : getUsageDateKey_()
  };

  if (isLoop) {
    data.chatLimit = chatLimit;
    data.loopUsername = apptookKey;
  }

  return data;
}

function getEarlierDate_(firstDate, secondDate) {
  if (!firstDate) {
    return secondDate;
  }
  if (!secondDate) {
    return firstDate;
  }
  return firstDate.getTime() <= secondDate.getTime() ? firstDate : secondDate;
}

function getSourceKeysAssignedToApptookKey_(sourceState, apptookKey) {
  const assignedIndex = sourceState.headerMap.assigned_apptook_key;
  const sourceKeyIndex = sourceState.headerMap.source_key;

  if (assignedIndex === undefined || sourceKeyIndex === undefined) {
    return [];
  }

  const keys = [];
  for (let index = 1; index < sourceState.rows.length; index += 1) {
    const row = sourceState.rows[index];
    if (normalizeCell_(row[assignedIndex]) === apptookKey) {
      keys.push(normalizeCell_(row[sourceKeyIndex]));
    }
  }

  return keys.filter(Boolean);
}

function getSourceTokenCapacity_(sourceState, rowValues) {
  if (!sourceState || !rowValues) {
    return DEFAULT_SOURCE_TOKEN_CAPACITY;
  }

  const rawValue = getRowValue_(sourceState, rowValues, "source_token_capacity");
  return normalizePositiveNumber_(rawValue, DEFAULT_SOURCE_TOKEN_CAPACITY);
}

function normalizeNonNegativeNumber_(value, fallbackValue) {
  const numberValue = Number(value);
  return Number.isFinite(numberValue) && numberValue >= 0 ? numberValue : fallbackValue;
}

function getUsageDateKey_(dateValue) {
  return getUsageWindowContext_(dateValue).usageDate;
}

function getSourceUsageState_(sourceState, rowValues, usageDate) {
  const normalizedUsageDate = normalizeCell_(usageDate || getUsageDateKey_());
  const storedUsageDate = normalizeCell_(getRowValue_(sourceState, rowValues, "source_usage_date"));
  const capacity = getSourceTokenCapacity_(sourceState, rowValues);
  const consumedRaw = storedUsageDate === normalizedUsageDate
    ? normalizeNonNegativeNumber_(getRowValue_(sourceState, rowValues, "source_consumed_raw"), 0)
    : 0;
  const lastSeenRawUsage = storedUsageDate === normalizedUsageDate
    ? normalizeNonNegativeNumber_(getRowValue_(sourceState, rowValues, "source_last_seen_raw_usage"), 0)
    : 0;

  return {
    capacity: capacity,
    consumedRaw: consumedRaw,
    lastSeenRawUsage: lastSeenRawUsage,
    usageDate: storedUsageDate === normalizedUsageDate ? normalizedUsageDate : "",
    cappedConsumedRaw: Math.min(consumedRaw, capacity)
  };
}

function getStoredAppKeyUsageState_(appKeysState, rowValues, usageDate) {
  const normalizedUsageDate = normalizeCell_(usageDate || getUsageDateKey_());
  const storedUsageDate = normalizeCell_(getRowValue_(appKeysState, rowValues, "usage_date"));
  const sameUsageWindow = storedUsageDate === normalizedUsageDate;

  return {
    usageDate: sameUsageWindow ? normalizedUsageDate : "",
    rawUsageTotal: sameUsageWindow
      ? normalizeNonNegativeNumber_(getRowValue_(appKeysState, rowValues, "raw_usage_total"), 0)
      : 0,
    displayUsage: sameUsageWindow
      ? normalizeNonNegativeNumber_(getRowValue_(appKeysState, rowValues, "display_usage"), 0)
      : 0
  };
}

function calculateUsageNormalizationDivisor_(totalAssignedSourceCapacity) {
  const normalizedCapacity = normalizePositiveNumber_(totalAssignedSourceCapacity, DEFAULT_USAGE_DISPLAY_LIMIT);
  return normalizedCapacity / DEFAULT_USAGE_DISPLAY_LIMIT;
}

function buildUsageStateMetadata_(entries, sourceState, appKeysState, appKeyRecord, currentSourceKey, usageDate) {
  let totalAssignedSourceCapacity = 0;
  let summedRawUsageTotal = 0;
  let currentSourceCapacity = 0;
  let currentSourceConsumedRaw = 0;
  const normalizedCurrentSourceKey = normalizeCell_(currentSourceKey);
  const normalizedUsageDate = normalizeCell_(usageDate || getUsageDateKey_());

  (entries || []).forEach(function(entry) {
    const sourceRecord = findRowRecord_(sourceState, "source_key", entry && entry.sourceKey);
    const validation = validateSourceLicenseRecord_(sourceState, sourceRecord);
    if (!validation.ok) {
      return;
    }

    const capacity = getSourceTokenCapacity_(sourceState, sourceRecord.values);
    const usageState = getSourceUsageState_(sourceState, sourceRecord.values, normalizedUsageDate);

    totalAssignedSourceCapacity += capacity;
    summedRawUsageTotal += Math.min(usageState.cappedConsumedRaw, capacity);

    if (normalizedCurrentSourceKey && normalizeCell_(entry.sourceKey) === normalizedCurrentSourceKey) {
      currentSourceCapacity = capacity;
      currentSourceConsumedRaw = Math.min(usageState.cappedConsumedRaw, capacity);
    }
  });

  if (totalAssignedSourceCapacity < DEFAULT_USAGE_DISPLAY_LIMIT) {
    return {
      ok: false,
      message: "Total assigned source token capacity must be at least 100."
      };
    }

  const persistedUsageState = getStoredAppKeyUsageState_(
    appKeysState,
    appKeyRecord && appKeyRecord.values,
    normalizedUsageDate
  );
  const usageNormalizationDivisor = calculateUsageNormalizationDivisor_(totalAssignedSourceCapacity);
  const rawUsageTotal = Math.min(
    totalAssignedSourceCapacity,
    Math.max(
      0,
      Math.max(summedRawUsageTotal, persistedUsageState.rawUsageTotal)
    )
  );
  const displayUsage = Math.max(
    0,
    Math.min(
      DEFAULT_USAGE_DISPLAY_LIMIT,
      Math.floor(rawUsageTotal / usageNormalizationDivisor)
    )
  );

  return {
    ok: true,
    usageDisplayLimit: DEFAULT_USAGE_DISPLAY_LIMIT,
    usageNormalizationDivisor: usageNormalizationDivisor,
    totalAssignedSourceCapacity: totalAssignedSourceCapacity,
    summedRawUsageTotal: summedRawUsageTotal,
    persistedRawUsageTotal: persistedUsageState.rawUsageTotal,
    rawUsageTotal: rawUsageTotal,
    displayUsage: displayUsage,
    currentSourceCapacity: currentSourceCapacity,
    currentSourceConsumedRaw: currentSourceConsumedRaw,
    usageDate: normalizedUsageDate
  };
}

function syncSourceUsageSnapshot_(sourceSheet, sourceState, sourceKey, apptookKey, rawUsage, usageDate, syncTime) {
  const sourceRecord = findRowRecord_(sourceState, "source_key", sourceKey);
  const validation = validateSourceLicenseRecord_(sourceState, sourceRecord);
  if (!validation.ok) {
    return null;
  }

  const normalizedUsageDate = normalizeCell_(usageDate || getUsageDateKey_());
  const previousUsage = getSourceUsageState_(sourceState, sourceRecord.values, normalizedUsageDate);
  const sourceCapacity = Math.max(0, previousUsage.capacity || getSourceTokenCapacity_(sourceState, sourceRecord.values));
  const currentRawUsage = Math.min(sourceCapacity, normalizeNonNegativeNumber_(rawUsage, 0));
  let consumedRaw = currentRawUsage;
  let nextLastSeenRawUsage = currentRawUsage;

  if (previousUsage.usageDate === normalizedUsageDate) {
    if (currentRawUsage >= previousUsage.lastSeenRawUsage) {
      consumedRaw = previousUsage.consumedRaw + (currentRawUsage - previousUsage.lastSeenRawUsage);
      nextLastSeenRawUsage = currentRawUsage;
    } else {
      consumedRaw = previousUsage.consumedRaw;
      nextLastSeenRawUsage = previousUsage.lastSeenRawUsage;
    }
  }

  consumedRaw = Math.min(sourceCapacity, Math.max(0, consumedRaw));
  nextLastSeenRawUsage = Math.min(sourceCapacity, Math.max(0, nextLastSeenRawUsage));

  updateRowValues_(sourceSheet, sourceRecord.rowNumber, sourceState.headerMap, {
    last_used_by: apptookKey,
    last_sync_at: syncTime || new Date(),
    source_consumed_raw: consumedRaw,
    source_last_seen_raw_usage: nextLastSeenRawUsage,
    source_usage_date: normalizedUsageDate
  });

  if (sourceState && sourceRecord && sourceRecord.values) {
    sourceRecord.values[sourceState.headerMap.last_used_by] = apptookKey;
    sourceRecord.values[sourceState.headerMap.last_sync_at] = syncTime || new Date();
    sourceRecord.values[sourceState.headerMap.source_consumed_raw] = consumedRaw;
    sourceRecord.values[sourceState.headerMap.source_last_seen_raw_usage] = nextLastSeenRawUsage;
    sourceRecord.values[sourceState.headerMap.source_usage_date] = normalizedUsageDate;
  }

  return {
    sourceRecord: sourceRecord,
    consumedRaw: consumedRaw,
    currentRawUsage: nextLastSeenRawUsage
  };
}

function buildLookupMap_(items) {
  const result = {};
  (items || []).forEach(function(item) {
    const key = normalizeCell_(item);
    if (key) {
      result[key] = true;
    }
  });
  return result;
}

function normalizeSourceKeyList_(payload) {
  return normalizeDelimitedList_(payload, ["sourceKeys", "keys", "sourceKeysText", "keysText"]);
}

function normalizeDelimitedList_(payload, fieldNames) {
  let rawItems = [];

  fieldNames.forEach(function(fieldName) {
    if (rawItems.length) {
      return;
    }

    const value = payload && payload[fieldName];
    if (Array.isArray(value)) {
      rawItems = value.slice();
      return;
    }

    if (value != null && value !== "") {
      rawItems = String(value).split(/\r?\n|,/);
    }
  });

  const seen = {};
  const normalized = [];

  rawItems.forEach(function(item) {
    const value = normalizeCell_(item);
    if (!value || seen[value]) {
      return;
    }

    seen[value] = true;
    normalized.push(value);
  });

  return normalized;
}

function buildImportSummaryMessage_(createdCount, skippedCount) {
  if (!createdCount && skippedCount) {
    return "No new source licenses were added. The provided keys already exist.";
  }

  if (createdCount && skippedCount) {
    return "Source licenses imported. Duplicate keys were skipped.";
  }

  return "Source licenses imported successfully.";
}

function generateUniqueApptookKey_(appKeysState) {
  for (let attempt = 0; attempt < 20; attempt += 1) {
    const candidate = generateApptookKey_();
    if (!findRowRecord_(appKeysState, "apptook_key", candidate)) {
      return candidate;
    }
  }

  throw new Error("Cannot generate a unique APPTOOK key right now.");
}

function generateApptookKey_() {
  return "apptook_" + randomString_(10, "ABCDEFGHJKLMNPQRSTUVWXYZ23456789");
}

function randomString_(length, alphabet) {
  const chars = alphabet || "abcdefghijklmnopqrstuvwxyz0123456789";
  let result = "";
  for (let index = 0; index < length; index += 1) {
    result += chars.charAt(Math.floor(Math.random() * chars.length));
  }
  return result;
}

function normalizeKeyType_(value) {
  return normalizeCell_(value).toLowerCase() === "loop" ? "loop" : "single";
}

function normalizeCell_(value) {
  return String(value == null ? "" : value).trim();
}

function normalizeStatus_(value) {
  return normalizeCell_(value).toLowerCase();
}

function normalizePositiveNumber_(value, fallbackValue) {
  const numberValue = Number(value);
  return Number.isFinite(numberValue) && numberValue > 0 ? numberValue : fallbackValue;
}

function normalizePositiveInteger_(value, fallbackValue) {
  const numberValue = Math.floor(Number(value));
  return Number.isFinite(numberValue) && numberValue >= 0 ? numberValue : fallbackValue;
}

function normalizeOptionalNumber_(value) {
  if (value === "" || value == null) {
    return null;
  }

  const numberValue = Number(value);
  return Number.isFinite(numberValue) ? numberValue : null;
}

function normalizeOptionalInteger_(value) {
  if (value === "" || value == null) {
    return null;
  }

  const numberValue = Math.floor(Number(value));
  return Number.isFinite(numberValue) ? numberValue : null;
}

function parseSheetDate_(value) {
  if (value instanceof Date && !isNaN(value.getTime())) {
    const parsedDate = new Date(value.getTime());
    if (
      parsedDate.getHours() === 0 &&
      parsedDate.getMinutes() === 0 &&
      parsedDate.getSeconds() === 0 &&
      parsedDate.getMilliseconds() === 0
    ) {
      parsedDate.setHours(23, 59, 59, 999);
    }
    return parsedDate;
  }

  const textValue = normalizeCell_(value);
  if (!textValue) {
    return null;
  }

  const plainDateMatch = textValue.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (plainDateMatch) {
    return new Date(
      Number(plainDateMatch[1]),
      Number(plainDateMatch[2]) - 1,
      Number(plainDateMatch[3]),
      23,
      59,
      59,
      999
    );
  }

  const parsedDate = new Date(textValue);
  if (!isNaN(parsedDate.getTime())) {
    if (
      parsedDate.getHours() === 0 &&
      parsedDate.getMinutes() === 0 &&
      parsedDate.getSeconds() === 0 &&
      parsedDate.getMilliseconds() === 0
    ) {
      parsedDate.setHours(23, 59, 59, 999);
    }
    return parsedDate;
  }

  return null;
}