(function() {
    const vscode = acquireVsCodeApi();

    const pendingAuthRequests = new Map();
    const pendingLoopRequests = new Map();
    const pendingDashboardRequests = new Map();
    const LOOP_STATE_KEY = 'apptook.loopSession';
    const GAS_SESSION_STATE_KEY = 'apptook.gasSession';
    const LOOP_SESSION_STORAGE_KEY = 'apptook.loopSession.persisted';
    const GAS_SESSION_STORAGE_KEY = 'apptook.gasSession.persisted';
    const INSTALL_MARKER_STORAGE_KEY = 'apptook.installMarker';
    const INSTALL_VERSION_STORAGE_KEY = 'apptook.installVersion';
    const DASHBOARD_REFRESH_INTERVAL_MS = 10000;
    const BACKGROUND_REFRESH_MIN_INTERVAL_MS = 2500;
    const INITIAL_HOST_RUNTIME_WAIT_MS = 450;
    const SOURCE_ROTATION_PRESWITCH_BUFFER_RAW = 1;
    const SHOW_RESUME_TRANSITION_OVERLAY = false;

    let isTestLoginUnlocked = false;

    // DOM elements
    const loginForm = document.getElementById('loginForm');
    const userStatus = document.getElementById('userStatus');
    const loading = document.getElementById('loading');
    const noticeArea = document.getElementById('noticeArea');
    const noticeContent = document.getElementById('noticeContent');
    
    const activationCodeInput = document.getElementById('activationCode');
    let passwordInput = null;
    const loginBtn = document.getElementById('loginBtn');
    const loginMessage = document.getElementById('loginMessage');

    const ACTIVATION_CODE_STORAGE_KEY = 'apptook.activationCode';

    function getInjectedHostAttribute(attributeName) {
        const htmlValue = document.documentElement ? document.documentElement.getAttribute(attributeName) : '';
        if (htmlValue) {
            return String(htmlValue).trim();
        }

        const bodyValue = document.body ? document.body.getAttribute(attributeName) : '';
        return String(bodyValue || '').trim();
    }

    function hasPersistedAuthArtifacts() {
        try {
            const state = vscode.getState() || {};
            return Boolean(
                localStorage.getItem(ACTIVATION_CODE_STORAGE_KEY) ||
                state[LOOP_STATE_KEY] ||
                state[GAS_SESSION_STATE_KEY]
            );
        } catch (_err) {
            return false;
        }
    }

    function clearPersistedAuthArtifactsForFreshInstall() {
        try {
            localStorage.removeItem(LOOP_SESSION_STORAGE_KEY);
            localStorage.removeItem(GAS_SESSION_STORAGE_KEY);
            localStorage.removeItem(ACTIVATION_CODE_STORAGE_KEY);
        } catch (_err) {
            // ignore localStorage cleanup failures
        }

        try {
            vscode.setState({});
        } catch (_err) {
            // ignore webview state cleanup failures
        }
    }

    function applyInstallMarkerGuard() {
        const currentInstallMarker = getInjectedHostAttribute('data-apptook-install-marker');
        const currentExtensionVersion = getInjectedHostAttribute('data-apptook-extension-version');
        if (!currentInstallMarker) {
            return false;
        }

        let previousInstallMarker = '';
        try {
            previousInstallMarker = String(localStorage.getItem(INSTALL_MARKER_STORAGE_KEY) || '').trim();
        } catch (_err) {
            previousInstallMarker = '';
        }

        const shouldResetPersistedState = previousInstallMarker !== currentInstallMarker && hasPersistedAuthArtifacts();
        if (shouldResetPersistedState) {
            clearPersistedAuthArtifactsForFreshInstall();
        }

        try {
            localStorage.setItem(INSTALL_MARKER_STORAGE_KEY, currentInstallMarker);
            if (currentExtensionVersion) {
                localStorage.setItem(INSTALL_VERSION_STORAGE_KEY, currentExtensionVersion);
            }
        } catch (_err) {
            // ignore marker persistence failures
        }

        return shouldResetPersistedState;
    }
    
    const userId = document.getElementById('userId');
    const vipStatus = document.getElementById('vipStatus');
    const expireTime = document.getElementById('expireTime');
    const dayScore = document.getElementById('dayScore');
    const dayScoreItem = document.getElementById('dayScoreItem');
    const statusMessage = document.getElementById('statusMessage');
    const activationCodeDisplay = document.getElementById('activationCodeDisplay');

    const refreshBtn = document.getElementById('refreshBtn');
    const logoutBtn = document.getElementById('logoutBtn');
    const activeBtn = document.getElementById('activeBtn');
    const gainNewBtn = document.getElementById('gainNewBtn');
    const copyUserIdBtn = document.getElementById('copyUserIdBtn');
    const copyActivationCodeBtn = document.getElementById('copyActivationCodeBtn');
    const startApiWorkerBtn = document.getElementById('startApiWorkerBtn');

    let isCursorActive = false;
    let hostWorkerHealthKnown = false;
    let hostWorkerHealthy = false;
    let hostWorkerRecovering = false;
    let lastWorkerRecoveryAt = 0;
    let workerRecoveryRequestInFlight = false;
    let lastWorkerRecoveryRequestAt = 0;
    const installMarkerChanged = applyInstallMarkerGuard();
    let cachedWebviewState = installMarkerChanged ? {} : (vscode.getState() || {});
    let loopSession = normalizeLoopSession(cachedWebviewState[LOOP_STATE_KEY]);
    let gasSession = normalizeGasSession(cachedWebviewState[GAS_SESSION_STATE_KEY]);
    let loopRotationState = createLoopRotationState();
    let dashboardRefreshTimer = null;
    let latestUserSnapshot = null;
    let statusRefreshInFlight = false;
    let currentRefreshSource = '';
    let lastBackgroundRefreshAt = 0;
    let dashboardSyncInFlight = false;
    let queuedDashboardSyncTask = null;
    let lastDashboardSyncSignature = '';
    let lastDashboardSyncAt = 0;
    let lastDashboardSyncErrorMessage = '';
    let lastDashboardSyncErrorAt = 0;
    let autoRunState = createAutoRunState();
    let initialHostRuntimeMessageReceived = false;
    let initialHostRuntimeWaiters = [];
    let resumeTransitionOverlay = null;
    let resumeTransitionMessage = null;
    let premiumDashboardRoot = null;
    let premiumHeroLogoFrame = null;
    let premiumHeroLogo = null;
    let premiumStatusTitle = null;
    let premiumStatusNote = null;
    let premiumStatusPill = null;
    let premiumLicenseValue = null;
    let premiumLicenseNote = null;
    let premiumExpireValue = null;
    let premiumExpireNote = null;
    let premiumUsageValue = null;
    let premiumUsageNote = null;
    let premiumLogoutSlot = null;
    let premiumStatusMessageSlot = null;
    let premiumServerButtons = [];
    let premiumSaveHostBtn = null;
    let premiumSupportKeyInput = null;
    let premiumSaveSupportKeyBtn = null;
    let premiumNetworkInlineMessage = null;
    let premiumSupportInlineMessage = null;

    function getInfoItem(element) {
        return element && typeof element.closest === 'function'
            ? element.closest('.info-item')
            : (element && element.parentElement ? element.parentElement : null);
    }

    function setSectionVisibility(element, visible) {
        const target = getInfoItem(element) || element;
        if (!target) {
            return;
        }

        target.style.display = visible ? '' : 'none';
    }

    function setInfoLabelText(element, nextLabel) {
        const target = getInfoItem(element);
        if (!target) {
            return;
        }

        const label = target.querySelector('.label');
        if (label) {
            label.textContent = nextLabel;
        }
    }

    function getDisplayedLicenseKey(user) {
        const sessionKey = String(
            (gasSession && gasSession.apptookKey) ||
            (gasSession && gasSession.username) ||
            (loopSession && loopSession.loopUsername) ||
            (user && user.apptookKey) ||
            activationCodeInput.value ||
            ''
        ).trim();

        return sessionKey || '-';
    }

    function applyCompactStatusLayout() {
        setInfoLabelText(userId, 'License key:');
        setSectionVisibility(userId, true);
        setSectionVisibility(activationCodeDisplay, false);
        setSectionVisibility(vipStatus, false);
        setSectionVisibility(expireTime, false);
        setSectionVisibility(dayScoreItem, false);

        if (refreshBtn) {
            refreshBtn.style.display = 'none';
        }

        if (activeBtn) {
            activeBtn.style.display = 'none';
        }

        if (startApiWorkerBtn) {
            startApiWorkerBtn.style.display = 'none';
        }
    }

    function hideFieldContainer(input) {
        if (!input) {
            return;
        }

        const directGroup = input.closest('.input-group');
        if (directGroup) {
            directGroup.style.display = 'none';
            return;
        }

        if (input.parentElement) {
            input.parentElement.style.display = 'none';
        }
    }

    function applySupportKeyLayout() {
        if (proxyInput) {
            hideFieldContainer(proxyInput);
        }

        if (tokenInput) {
            tokenInput.placeholder = 'Enter your support key';
        }

        if (saveTokenBtn) {
            saveTokenBtn.textContent = 'Save Support Key';
        }

        if (saveNetworkBtn) {
            saveNetworkBtn.textContent = 'Save Host';
        }

        const headings = Array.from(document.querySelectorAll('h3'));
        headings.forEach((heading) => {
            const text = String(heading.textContent || '').trim().toLowerCase();
            if (text === 'token settings' || text === 'support keys' || text === 'support key') {
                heading.textContent = 'Support Key';
            }
        });

        const tokenSection = document.querySelector('.token-settings');

        if (tokenSection) {
            const extraTextInputs = Array.from(tokenSection.querySelectorAll('input[type="text"]'))
                .filter((input) => input !== tokenInput);
            extraTextInputs.forEach((input) => hideFieldContainer(input));
        }
    }

    function getPremiumStatusState(user) {
        const now = Date.now() / 1000;
        const isVipActive = Boolean(user && user.vip && user.vip.expire_at && user.vip.expire_at > now);

        if (loopRotationState.inFlight || loopRotationState.awaitingLogin) {
            return {
                title: 'Switching source key',
                note: 'Moving to the next available source key'
            };
        }

        if (hostWorkerRecovering || (hostWorkerHealthKnown && !hostWorkerHealthy)) {
            return {
                title: 'Reconnecting access',
                note: 'Restarting API worker in background'
            };
        }

        if (autoRunState.active) {
            return {
                title: 'Preparing access',
                note: 'Connecting your APPTOOK session'
            };
        }

        if (isVipActive) {
            return {
                title: 'Ready to use',
                note: 'Connected source key is active and working'
            };
        }

        return {
            title: 'Access expired',
            note: 'Please renew your APPTOOK key'
        };
    }

    function formatPremiumDate(timestamp) {
        const timestampMs = normalizeTimestampMs(timestamp);
        if (!timestampMs) {
            return '--';
        }

        const date = new Date(timestampMs);
        if (Number.isNaN(date.getTime())) {
            return '--';
        }

        return date.toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        });
    }

    function normalizeTimestampMs(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }

        if (typeof value === 'number' && Number.isFinite(value)) {
            if (value > 100000000000) {
                return value;
            }

            if (value > 1000000000) {
                return value * 1000;
            }

            return null;
        }

        const raw = String(value).trim();
        if (!raw) {
            return null;
        }

        if (/^\d+$/.test(raw)) {
            return normalizeTimestampMs(Number(raw));
        }

        if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
            const parsedDate = Date.parse(`${raw}T00:00:00`);
            return Number.isFinite(parsedDate) ? parsedDate : null;
        }

        const parsed = Date.parse(raw);
        return Number.isFinite(parsed) ? parsed : null;
    }

    function formatCompactMetricNumber(value) {
        const numericValue = toFiniteNumber(value, 0);
        const formatter = new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: Math.abs(numericValue % 1) > 0.001 ? 1 : 0
        });

        return formatter.format(numericValue);
    }

    function getSelectedServerValue() {
        const checkedRadio = document.querySelector('.network-settings input[type="radio"]:checked');
        if (!checkedRadio) {
            return '1.1';
        }

        return String(checkedRadio.value || checkedRadio.id || '1.1').trim() || '1.1';
    }

    function getSelectedServerLabel() {
        const selectedValue = getSelectedServerValue();
        if (selectedValue === '2' || selectedValue === 'http2') {
            return 'Server 1';
        }
        if (selectedValue === '1.0' || selectedValue === 'http10') {
            return 'Server 3';
        }
        return 'Server 2';
    }

    function setPremiumInlineMessage(target, text = '', tone = '') {
        if (!target) {
            return;
        }

        const nextText = String(text || '').trim();
        target.textContent = nextText;
        target.className = 'premium-inline-message';

        if (!nextText) {
            target.style.display = 'none';
            return;
        }

        if (tone) {
            target.classList.add(`premium-inline-message-${tone}`);
        }

        target.style.display = 'block';
    }

    function syncPremiumConnectedControls() {
        const selectedValue = getSelectedServerValue();
        premiumServerButtons.forEach((button) => {
            const isSelected = String(button.dataset.httpVersion || '') === selectedValue;
            button.classList.toggle('premium-selected', isSelected);
            button.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
        });

        if (premiumStatusPill) {
            premiumStatusPill.textContent = getSelectedServerLabel();
        }

        if (
            premiumSupportKeyInput &&
            tokenInput &&
            document.activeElement !== premiumSupportKeyInput &&
            premiumSupportKeyInput.value !== tokenInput.value
        ) {
            premiumSupportKeyInput.value = tokenInput.value || '';
        }

        if (premiumSaveHostBtn && saveNetworkBtn) {
            premiumSaveHostBtn.disabled = Boolean(saveNetworkBtn.disabled);
        }

        if (premiumSaveSupportKeyBtn && saveTokenBtn) {
            premiumSaveSupportKeyBtn.disabled = Boolean(saveTokenBtn.disabled);
        }
    }

    function refreshPremiumServerSelection() {
        syncPremiumConnectedControls();
    }

    function getPremiumLogoSource() {
        const preferredSources = [
            ...getWebviewAssetUrls(
                ['apptookLogoWebviewUrl', 'apptookLogoUrl'],
                ['APPTOOK_LOGO_WEBVIEW_URL', 'APPTOOK_LOGO_URL']
            ),
            ...getWebviewAssetUrls(
                ['apptookLogoPlaceholderWebviewUrl', 'apptookLogoPlaceholderUrl'],
                ['APPTOOK_LOGO_PLACEHOLDER_WEBVIEW_URL', 'APPTOOK_LOGO_PLACEHOLDER_URL']
            ),
            getInlineLogoFallbackSrc()
        ];
        const logoSources = [];
        const seen = new Set();
        preferredSources.forEach((src) => pushAssetUrl(logoSources, seen, src));
        return logoSources.length > 0 ? logoSources[0] : getInlineLogoFallbackSrc();
    }

    function ensurePremiumLogoSource() {
        if (!premiumHeroLogo) {
            return;
        }

        const nextSource = getPremiumLogoSource();
        if (!nextSource) {
            return;
        }

        if (!premiumHeroLogo.dataset.sourceQueue) {
            premiumHeroLogo.dataset.sourceQueue = JSON.stringify(buildTestLoginLogoSourceQueue());
            premiumHeroLogo.dataset.sourceIndex = '0';
        }

        premiumHeroLogo.onload = () => {
            if (premiumHeroLogoFrame) {
                premiumHeroLogoFrame.classList.add('loaded');
            }
            premiumHeroLogo.style.display = 'block';
        };

        premiumHeroLogo.onerror = (event) => {
            handleTestLoginLogoError(event);
            if (premiumHeroLogoFrame && premiumHeroLogo.src === getInlineLogoFallbackSrc()) {
                premiumHeroLogoFrame.classList.remove('loaded');
            }
        };

        if (premiumHeroLogoFrame) {
            premiumHeroLogoFrame.classList.remove('loaded');
        }

        premiumHeroLogo.style.display = 'none';

        if (premiumHeroLogo.src !== nextSource) {
            premiumHeroLogo.src = nextSource;
        }
    }

    function markSectionHidden(element) {
        if (!element) {
            return;
        }

        element.classList.add('premium-hidden');
    }

    function syncPremiumEmbeddedSections() {
        if (!premiumDashboardRoot) {
            return;
        }

        const networkSection = document.querySelector('.network-settings');
        const tokenSection = document.querySelector('.token-settings');
        const proxySection = document.querySelector('.proxy-settings');

        if (premiumLogoutSlot && logoutBtn && logoutBtn.parentElement !== premiumLogoutSlot) {
            premiumLogoutSlot.replaceChildren(logoutBtn);
        }

        if (premiumStatusMessageSlot && statusMessage && statusMessage.parentElement !== premiumStatusMessageSlot) {
            premiumStatusMessageSlot.replaceChildren(statusMessage);
        }

        if (proxySection) {
            markSectionHidden(proxySection);
        }

        applySupportKeyLayout();
        if (networkSection) {
            markSectionHidden(networkSection);
        }
        if (tokenSection) {
            markSectionHidden(tokenSection);
        }
        syncPremiumConnectedControls();
    }

    function ensurePremiumDashboardLayout() {
        if (premiumDashboardRoot && document.body.contains(premiumDashboardRoot)) {
            syncPremiumEmbeddedSections();
            return premiumDashboardRoot;
        }

        const userInfoSection = document.querySelector('.user-info');
        const actionsSection = document.querySelector('.actions');
        const proxySection = document.querySelector('.proxy-settings');

        const premiumRoot = document.createElement('div');
        premiumRoot.className = 'premium-dashboard';
        premiumRoot.innerHTML = `
            <section class="premium-card premium-hero">
                <div class="premium-hero-top">
                    <div class="premium-logo-frame">
                        <div class="premium-logo-fallback">
                            ${getInlineLogoFallbackMarkup()}
                        </div>
                        <img class="premium-logo" alt="APPTOOK logo" />
                    </div>
                    <div class="premium-brand-copy">
                        <div class="premium-eyebrow">Premium Access</div>
                        <h2 class="premium-title">APPTOOK</h2>
                        <p class="premium-subtitle">Blue-tech control panel for premium customer access</p>
                    </div>
                </div>
                <div class="premium-status-strip">
                    <div class="premium-status-meta">
                        <span class="premium-status-dot"></span>
                        <div class="premium-status-copy">
                            <strong id="premiumStatusTitle">Ready to use</strong>
                            <span id="premiumStatusNote">Connected source key is active and working</span>
                        </div>
                    </div>
                    <div id="premiumStatusPill" class="premium-status-pill">Server 2</div>
                </div>
            </section>

            <section class="premium-card premium-license-card">
                <div class="premium-card-label">License Key</div>
                <div id="premiumLicenseValue" class="premium-license-value">-</div>
                <div id="premiumLicenseNote" class="premium-license-note">Customer access key</div>
            </section>

            <section class="premium-metrics-grid">
                <article class="premium-card premium-metric-card">
                    <div class="premium-card-label">Expire Date</div>
                    <div id="premiumExpireValue" class="premium-metric-value">--</div>
                    <div id="premiumExpireNote" class="premium-metric-note">Renews on payment</div>
                </article>
                <article class="premium-card premium-metric-card">
                    <div class="premium-card-label">Usage Today</div>
                    <div id="premiumUsageValue" class="premium-metric-value">0</div>
                    <div id="premiumUsageNote" class="premium-metric-note">Daily usage tracking</div>
                </article>
            </section>

            <section class="premium-card premium-settings-card">
                <div class="premium-section-head">
                    <h3>Support Key</h3>
                    <small>Settings</small>
                </div>
                <div class="premium-settings-stack">
                    <div class="premium-settings-block">
                        <div class="premium-settings-label">Server Host</div>
                        <div class="premium-settings-slot premium-network-slot">
                            <div class="premium-server-grid" role="radiogroup" aria-label="Server Host">
                                <button type="button" class="premium-server-option" data-http-version="2" aria-pressed="false">Server 1</button>
                                <button type="button" class="premium-server-option" data-http-version="1.1" aria-pressed="false">Server 2</button>
                                <button type="button" class="premium-server-option" data-http-version="1.0" aria-pressed="false">Server 3</button>
                            </div>
                            <button id="premiumSaveHostBtn" type="button" class="premium-settings-button">Save Host</button>
                            <div id="premiumNetworkInlineMessage" class="premium-inline-message" style="display:none;"></div>
                        </div>
                    </div>
                    <div class="premium-settings-block">
                        <div class="premium-settings-label">Support Key</div>
                        <div class="premium-settings-slot premium-token-slot">
                            <input id="premiumSupportKeyInput" type="text" placeholder="Enter your support key" autocomplete="off" />
                            <button id="premiumSaveSupportKeyBtn" type="button" class="premium-settings-button">Save Support Key</button>
                            <div id="premiumSupportInlineMessage" class="premium-inline-message" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="premium-card premium-action-card">
                <div id="premiumLogoutSlot" class="premium-logout-slot"></div>
                <div id="premiumStatusMessageSlot" class="premium-status-message-slot"></div>
            </section>
        `;

        userStatus.prepend(premiumRoot);

        premiumDashboardRoot = premiumRoot;
        premiumHeroLogoFrame = premiumRoot.querySelector('.premium-logo-frame');
        premiumHeroLogo = premiumRoot.querySelector('.premium-logo');
        premiumStatusTitle = premiumRoot.querySelector('#premiumStatusTitle');
        premiumStatusNote = premiumRoot.querySelector('#premiumStatusNote');
        premiumStatusPill = premiumRoot.querySelector('#premiumStatusPill');
        premiumLicenseValue = premiumRoot.querySelector('#premiumLicenseValue');
        premiumLicenseNote = premiumRoot.querySelector('#premiumLicenseNote');
        premiumExpireValue = premiumRoot.querySelector('#premiumExpireValue');
        premiumExpireNote = premiumRoot.querySelector('#premiumExpireNote');
        premiumUsageValue = premiumRoot.querySelector('#premiumUsageValue');
        premiumUsageNote = premiumRoot.querySelector('#premiumUsageNote');
        premiumLogoutSlot = premiumRoot.querySelector('#premiumLogoutSlot');
        premiumStatusMessageSlot = premiumRoot.querySelector('#premiumStatusMessageSlot');
        premiumServerButtons = Array.from(premiumRoot.querySelectorAll('.premium-server-option'));
        premiumSaveHostBtn = premiumRoot.querySelector('#premiumSaveHostBtn');
        premiumSupportKeyInput = premiumRoot.querySelector('#premiumSupportKeyInput');
        premiumSaveSupportKeyBtn = premiumRoot.querySelector('#premiumSaveSupportKeyBtn');
        premiumNetworkInlineMessage = premiumRoot.querySelector('#premiumNetworkInlineMessage');
        premiumSupportInlineMessage = premiumRoot.querySelector('#premiumSupportInlineMessage');

        markSectionHidden(userInfoSection);
        markSectionHidden(actionsSection);
        markSectionHidden(proxySection);

        if (deleteTokenBtn) {
            deleteTokenBtn.style.display = 'none';
        }

        if (saveTokenBtn) {
            saveTokenBtn.textContent = 'Save Support Key';
        }

        if (saveNetworkBtn) {
            saveNetworkBtn.textContent = 'Save Host';
        }

        premiumServerButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const targetVersion = String(button.dataset.httpVersion || '').trim();
                const targetRadio = document.querySelector(`.network-settings input[type="radio"][value="${targetVersion}"]`)
                    || document.querySelector(`.network-settings input[type="radio"]#${targetVersion === '1.1' ? 'http11' : targetVersion === '1.0' ? 'http10' : 'http2'}`);

                if (!targetRadio) {
                    return;
                }

                targetRadio.checked = true;
                targetRadio.dispatchEvent(new Event('change', { bubbles: true }));
                syncPremiumConnectedControls();
            });
        });

        if (premiumSaveHostBtn) {
            premiumSaveHostBtn.addEventListener('click', () => {
                if (saveNetworkBtn && !saveNetworkBtn.disabled) {
                    saveNetworkBtn.click();
                }
            });
        }

        if (premiumSupportKeyInput) {
            premiumSupportKeyInput.addEventListener('input', () => {
                if (tokenInput && tokenInput.value !== premiumSupportKeyInput.value) {
                    tokenInput.value = premiumSupportKeyInput.value;
                }
            });
        }

        if (premiumSaveSupportKeyBtn) {
            premiumSaveSupportKeyBtn.addEventListener('click', () => {
                if (tokenInput && premiumSupportKeyInput) {
                    tokenInput.value = premiumSupportKeyInput.value;
                }

                if (saveTokenBtn && !saveTokenBtn.disabled) {
                    saveTokenBtn.click();
                }
            });
        }

        const networkRadios = document.querySelectorAll('.network-settings input[type="radio"]');
        networkRadios.forEach((radio) => {
            if (radio.dataset.premiumBound === '1') {
                return;
            }

            radio.dataset.premiumBound = '1';
            radio.addEventListener('change', syncPremiumConnectedControls);
        });

        applySupportKeyLayout();
        applyCompactStatusLayout();
        ensurePremiumLogoSource();
        syncPremiumEmbeddedSections();
        window.setTimeout(syncPremiumEmbeddedSections, 0);
        window.setTimeout(syncPremiumEmbeddedSections, 80);
        return premiumRoot;
    }

    function updatePremiumSummary(user) {
        ensurePremiumDashboardLayout();

        if (!premiumDashboardRoot) {
            return;
        }

        const licenseKey = getDisplayedLicenseKey(user);
        const statusState = getPremiumStatusState(user);
        const expireTimestamp = normalizeTimestampMs(
            (gasSession && gasSession.expireAt) ||
            (user && user.vip && user.vip.expire_at) ||
            null
        );
        const normalizedUsageCount = getCurrentDisplayUsageValue(user);
        const usageDisplayLimit = getUsageDisplayLimitValue();
        const usageLimit = 0;

        if (premiumStatusTitle) {
            premiumStatusTitle.textContent = statusState.title;
        }

        if (premiumStatusNote) {
            premiumStatusNote.textContent = statusState.note;
        }

        if (premiumLicenseValue) {
            premiumLicenseValue.textContent = licenseKey;
        }

        if (premiumLicenseNote) {
            premiumLicenseNote.textContent = gasSession && gasSession.mode === 'loop'
                ? 'Loop-enabled customer access key'
                : 'Customer access key';
        }

        if (premiumExpireValue) {
            premiumExpireValue.textContent = formatPremiumDate(expireTimestamp);
        }

        if (premiumExpireNote) {
            premiumExpireNote.textContent = expireTimestamp
                ? 'Renews on payment'
                : 'No expiry information';
        }

        if (premiumUsageValue) {
            premiumUsageValue.textContent = `${Math.floor(normalizedUsageCount)} / ${Math.floor(usageDisplayLimit)}`;
        }

        if (premiumUsageNote) {
            premiumUsageNote.textContent = usageLimit > 0
                ? `Live count from Today's chats · limit ${formatCompactMetricNumber(usageLimit)}`
                : 'Live count from Today\'s chats';
        }

        if (premiumUsageNote) {
            premiumUsageNote.textContent = 'Package usage from all assigned source keys';
        }

        syncPremiumEmbeddedSections();
    }

    function createLoopRotationState() {
        return {
            active: false,
            inFlight: false,
            awaitingLogin: false,
            pendingKeyCode: '',
            attemptedKeys: new Set()
        };
    }

    function createAutoRunState() {
        return {
            active: false,
            source: '',
            phase: 'idle',
            currentKeyCode: '',
            workerRequested: false,
            refreshRequested: false
        };
    }

    function getResumeOverlayMessage(phase, fallbackMessage = '') {
        const normalizedPhase = String(phase || '').trim();
        if (normalizedPhase === 'activating') {
            return 'Switching APPTOOK account...';
        }
        if (normalizedPhase === 'starting_worker') {
            return 'Starting API worker...';
        }
        if (normalizedPhase === 'refreshing') {
            return 'Restoring APPTOOK session...';
        }
        if (normalizedPhase === 'awaiting_status') {
            return 'Loading account status...';
        }
        return String(fallbackMessage || '').trim() || 'Connecting APPTOOK...';
    }

    function toFiniteNumber(value, fallbackValue = 0) {
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : fallbackValue;
    }

    function readPersistedSession(storageKey) {
        try {
            localStorage.removeItem(storageKey);
        } catch (_err) {
            // ignore local cleanup failures
        }
        return null;
    }

    function writePersistedSession(storageKey, value) {
        try {
            localStorage.removeItem(storageKey);
        } catch (err) {
            // ignore storage errors
        }
    }

    function normalizeGasSession(raw) {
        if (!raw || typeof raw !== 'object') {
            return null;
        }

        const apptookKey = String(raw.apptookKey || raw.username || raw.loopUsername || '').trim();
        const username = apptookKey;
        const currentKeyCode = String(raw.currentKeyCode || raw.licenseCode || '').trim();
        if (!username || !currentKeyCode) {
            return null;
        }

        const mode = String(raw.mode || '').trim() === 'loop' ? 'loop' : 'normal';

        return {
            apptookKey,
            username,
            mode,
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
            lastDevice: String(raw.lastDevice || raw.device || getDeviceId()).trim() || getDeviceId(),
            expireAt: String(raw.expireAt || raw.expire_at || '').trim()
        };
    }

    function normalizeLoopSession(raw) {
        if (!raw || typeof raw !== 'object' || String(raw.mode || '') !== 'loop') {
            return null;
        }

        const loopUsername = String(raw.loopUsername || '').trim();
        const currentKeyCode = String(raw.currentKeyCode || raw.licenseCode || '').trim();
        if (!loopUsername || !currentKeyCode) {
            return null;
        }

        return {
            mode: 'loop',
            loopUsername,
            chatLimit: toFiniteNumber(raw.chatLimit, 80),
            currentKeyCode,
            currentKeyIndex: toFiniteNumber(raw.currentKeyIndex, 0),
            totalKeys: toFiniteNumber(raw.totalKeys, 0)
        };
    }

    function resolveGasMode(raw, fallbackSession) {
        if (String((raw && raw.mode) || '').trim() === 'loop' || String((raw && raw.loopUsername) || '').trim()) {
            return 'loop';
        }

        if (String((raw && raw.username) || '').trim()) {
            return 'normal';
        }

        return fallbackSession && fallbackSession.mode === 'loop' ? 'loop' : 'normal';
    }

    function resolveLoopSessionFromAuthData(raw, fallbackSession) {
        const source = raw && typeof raw === 'object' ? raw : {};
        const hasLoopIdentity = String(source.mode || '').trim() === 'loop' || String(source.loopUsername || '').trim();
        const hasExplicitNormalIdentity = String(source.username || '').trim() && !hasLoopIdentity;
        const keyCode = String(source.currentKeyCode || source.licenseCode || '').trim();

        if (hasExplicitNormalIdentity) {
            return null;
        }

        if (!hasLoopIdentity && !keyCode) {
            return fallbackSession || null;
        }

        const merged = {
            ...(fallbackSession || {}),
            ...source,
            mode: 'loop',
            loopUsername: String(source.loopUsername || (fallbackSession && fallbackSession.loopUsername) || '').trim(),
            currentKeyCode: String(source.currentKeyCode || source.licenseCode || (fallbackSession && fallbackSession.currentKeyCode) || '').trim(),
            licenseCode: String(source.licenseCode || source.currentKeyCode || (fallbackSession && fallbackSession.currentKeyCode) || '').trim(),
            chatLimit: source.chatLimit !== undefined ? source.chatLimit : (fallbackSession && fallbackSession.chatLimit),
            currentKeyIndex: source.currentKeyIndex !== undefined ? source.currentKeyIndex : (fallbackSession && fallbackSession.currentKeyIndex),
            totalKeys: source.totalKeys !== undefined ? source.totalKeys : (fallbackSession && fallbackSession.totalKeys)
        };

        return normalizeLoopSession(merged);
    }

    function copyUsageStateFields(target, source) {
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

    function mergeUsageStateIntoSession(target, sourceSession, fallbackSession) {
        const fallback = fallbackSession && typeof fallbackSession === 'object' ? fallbackSession : null;
        const source = sourceSession && typeof sourceSession === 'object' ? sourceSession : null;

        if (!target || !fallback || String(fallback.username || fallback.apptookKey || '').trim() !== String(target.username || target.apptookKey || '').trim()) {
            return target;
        }

        const sourceUsageDate = String((source && source.usageDate) || '').trim();
        const fallbackUsageDate = String(fallback.usageDate || '').trim();

        if (fallbackUsageDate && sourceUsageDate && fallbackUsageDate > sourceUsageDate) {
            copyUsageStateFields(target, fallback);
            return target;
        }

        if (fallbackUsageDate && sourceUsageDate && sourceUsageDate > fallbackUsageDate) {
            copyUsageStateFields(target, source);
            return target;
        }

        target.usageDisplayLimit = Math.max(1, Math.floor(toFiniteNumber(source && source.usageDisplayLimit, fallback.usageDisplayLimit || target.usageDisplayLimit || 100)));
        target.usageNormalizationDivisor = Math.max(1, toFiniteNumber(source && source.usageNormalizationDivisor, fallback.usageNormalizationDivisor || target.usageNormalizationDivisor || 1));
        target.totalAssignedSourceCapacity = Math.max(100, toFiniteNumber(source && source.totalAssignedSourceCapacity, fallback.totalAssignedSourceCapacity || target.totalAssignedSourceCapacity || 100));
        target.rawUsageTotal = Math.max(0, Math.max(toFiniteNumber(source && source.rawUsageTotal, 0), toFiniteNumber(fallback.rawUsageTotal, 0)));
        target.displayUsage = Math.max(0, Math.max(toFiniteNumber(source && source.displayUsage, 0), toFiniteNumber(fallback.displayUsage, 0)));
        target.usageDate = sourceUsageDate || fallbackUsageDate;

        if (String((source && (source.currentKeyCode || source.licenseCode)) || '').trim() === String(fallback.currentKeyCode || fallback.licenseCode || '').trim()) {
            target.currentSourceCapacity = Math.max(0, Math.max(toFiniteNumber(source && source.currentSourceCapacity, 0), toFiniteNumber(fallback.currentSourceCapacity, 0)));
            target.currentSourceConsumedRaw = Math.max(0, Math.max(toFiniteNumber(source && source.currentSourceConsumedRaw, 0), toFiniteNumber(fallback.currentSourceConsumedRaw, 0)));
        } else {
            target.currentSourceCapacity = Math.max(0, toFiniteNumber(source && source.currentSourceCapacity, fallback.currentSourceCapacity || 0));
            target.currentSourceConsumedRaw = Math.max(0, toFiniteNumber(source && source.currentSourceConsumedRaw, 0));
        }

        return target;
    }

    function resolveGasSessionFromAuthData(raw, fallbackSession) {
        const source = raw && typeof raw === 'object' ? raw : {};
        const apptookKey = String(
            source.apptookKey ||
            source.username ||
            source.loopUsername ||
            (fallbackSession && (fallbackSession.apptookKey || fallbackSession.username)) ||
            ''
        ).trim();
        const username = apptookKey;
        const currentKeyCode = String(
            source.currentKeyCode ||
            source.licenseCode ||
            (fallbackSession && (fallbackSession.currentKeyCode || fallbackSession.licenseCode)) ||
            ''
        ).trim();

        if (!username || !currentKeyCode) {
            return null;
        }

        const merged = {
            ...(fallbackSession || {}),
            ...source,
            apptookKey,
            username,
            mode: resolveGasMode(source, fallbackSession),
            currentKeyCode,
            licenseCode: currentKeyCode,
            chatLimit: source.chatLimit !== undefined ? source.chatLimit : (fallbackSession && fallbackSession.chatLimit),
            currentKeyIndex: source.currentKeyIndex !== undefined ? source.currentKeyIndex : (fallbackSession && fallbackSession.currentKeyIndex),
            totalKeys: source.totalKeys !== undefined ? source.totalKeys : (fallbackSession && fallbackSession.totalKeys),
            lastDevice: String(source.lastDevice || source.device || (fallbackSession && fallbackSession.lastDevice) || getDeviceId()).trim() || getDeviceId(),
            expireAt: String(source.expireAt || source.expire_at || (fallbackSession && fallbackSession.expireAt) || '').trim()
        };

        mergeUsageStateIntoSession(merged, source, fallbackSession);

        return normalizeGasSession(merged);
    }

    function getRestorableLocalSession() {
        const persistedGasSession = gasSession;
        if (!persistedGasSession || !persistedGasSession.currentKeyCode) {
            return null;
        }

        const persistedLoopSession = loopSession;

        if (persistedGasSession.mode === 'loop') {
            return {
                ...persistedGasSession,
                ...(persistedLoopSession || {}),
                mode: 'loop',
                username: persistedGasSession.username,
                loopUsername: String((persistedLoopSession && persistedLoopSession.loopUsername) || persistedGasSession.username || '').trim(),
                licenseCode: persistedGasSession.currentKeyCode,
                currentKeyCode: persistedGasSession.currentKeyCode,
                chatLimit: persistedGasSession.chatLimit,
                currentKeyIndex: persistedGasSession.currentKeyIndex,
                totalKeys: persistedGasSession.totalKeys,
                lastDevice: persistedGasSession.lastDevice
            };
        }

        return {
            ...persistedGasSession,
            mode: 'normal',
            username: persistedGasSession.username,
            licenseCode: persistedGasSession.currentKeyCode,
            currentKeyCode: persistedGasSession.currentKeyCode,
            lastDevice: persistedGasSession.lastDevice
        };
    }

    function buildHostSessionPayload(sourceData = null) {
        const source = sourceData && typeof sourceData === 'object' ? sourceData : {};
        const fallbackGasSession = gasSession;
        const fallbackLoopSession = loopSession;

        if (!fallbackGasSession && !source.username && !source.loopUsername) {
            return null;
        }

        const mode = String(source.mode || (fallbackLoopSession && fallbackLoopSession.mode) || (fallbackGasSession && fallbackGasSession.mode) || '').trim() === 'loop'
            || String(source.loopUsername || (fallbackLoopSession && fallbackLoopSession.loopUsername) || '').trim()
            ? 'loop'
            : 'normal';
        const username = String(
            source.username ||
            source.loopUsername ||
            (fallbackGasSession && fallbackGasSession.username) ||
            (fallbackLoopSession && fallbackLoopSession.loopUsername) ||
            ''
        ).trim();
        const currentKeyCode = String(
            source.currentKeyCode ||
            source.licenseCode ||
            (fallbackGasSession && fallbackGasSession.currentKeyCode) ||
            (fallbackLoopSession && fallbackLoopSession.currentKeyCode) ||
            ''
        ).trim();

        if (!username || !currentKeyCode) {
            return null;
        }

        return {
            apptookKey: username,
            username,
            mode,
            loopUsername: mode === 'loop'
                ? String(source.loopUsername || (fallbackLoopSession && fallbackLoopSession.loopUsername) || username).trim()
                : '',
            licenseCode: currentKeyCode,
            currentKeyCode,
            chatLimit: mode === 'loop'
                ? toFiniteNumber(source.chatLimit, toFiniteNumber(fallbackLoopSession && fallbackLoopSession.chatLimit, toFiniteNumber(fallbackGasSession && fallbackGasSession.chatLimit, 80)))
                : null,
            currentKeyIndex: mode === 'loop'
                ? toFiniteNumber(source.currentKeyIndex, toFiniteNumber(fallbackLoopSession && fallbackLoopSession.currentKeyIndex, toFiniteNumber(fallbackGasSession && fallbackGasSession.currentKeyIndex, 0)))
                : null,
            totalKeys: mode === 'loop'
                ? toFiniteNumber(source.totalKeys, toFiniteNumber(fallbackLoopSession && fallbackLoopSession.totalKeys, toFiniteNumber(fallbackGasSession && fallbackGasSession.totalKeys, 0)))
                : null,
            usageDisplayLimit: toFiniteNumber(
                source.usageDisplayLimit,
                toFiniteNumber(fallbackGasSession && fallbackGasSession.usageDisplayLimit, 100)
            ),
            usageNormalizationDivisor: toFiniteNumber(
                source.usageNormalizationDivisor,
                toFiniteNumber(fallbackGasSession && fallbackGasSession.usageNormalizationDivisor, 1)
            ),
            totalAssignedSourceCapacity: toFiniteNumber(
                source.totalAssignedSourceCapacity,
                toFiniteNumber(fallbackGasSession && fallbackGasSession.totalAssignedSourceCapacity, 100)
            ),
            currentSourceCapacity: toFiniteNumber(
                source.currentSourceCapacity,
                toFiniteNumber(fallbackGasSession && fallbackGasSession.currentSourceCapacity, 0)
            ),
            currentSourceConsumedRaw: toFiniteNumber(
                source.currentSourceConsumedRaw,
                toFiniteNumber(fallbackGasSession && fallbackGasSession.currentSourceConsumedRaw, 0)
            ),
            rawUsageTotal: toFiniteNumber(
                source.rawUsageTotal,
                toFiniteNumber(fallbackGasSession && fallbackGasSession.rawUsageTotal, 0)
            ),
            displayUsage: toFiniteNumber(
                source.displayUsage,
                toFiniteNumber(fallbackGasSession && fallbackGasSession.displayUsage, 0)
            ),
            usageDate: String(source.usageDate || (fallbackGasSession && fallbackGasSession.usageDate) || '').trim(),
            lastDevice: String(
                source.lastDevice ||
                source.device ||
                (fallbackGasSession && fallbackGasSession.lastDevice) ||
                getDeviceId()
            ).trim() || getDeviceId(),
            expireAt: String(source.expireAt || source.expire_at || (fallbackGasSession && fallbackGasSession.expireAt) || '').trim()
        };
    }

    function syncHostRuntimeSession() {
        const sessionPayload = buildHostSessionPayload();
        if (!sessionPayload) {
            return;
        }

        try {
            vscode.postMessage({
                type: 'sessionSync',
                session: sessionPayload,
                latestUser: latestUserSnapshot || null
            });
        } catch (_err) {
            // ignore host sync failures
        }
    }

    function persistAuthState() {
        cachedWebviewState = {
            ...(vscode.getState() || {}),
            [LOOP_STATE_KEY]: loopSession,
            [GAS_SESSION_STATE_KEY]: gasSession
        };
        vscode.setState(cachedWebviewState);
    }

    function persistLoopSession() {
        persistAuthState();
        syncHostRuntimeSession();
    }

    function setLoopSessionFromAuthData(data, options = {}) {
        const fallbackLoopSession = loopSession;
        const fallbackGasSession = gasSession;
        const previousSessionIdentity = fallbackGasSession
            ? `${fallbackGasSession.mode || 'normal'}::${fallbackGasSession.username || ''}::${fallbackGasSession.currentKeyCode || ''}`
            : '';

        loopSession = resolveLoopSessionFromAuthData(data, fallbackLoopSession);
        gasSession = resolveGasSessionFromAuthData(data, fallbackGasSession);
        persistAuthState();
        const nextSessionIdentity = gasSession
            ? `${gasSession.mode || 'normal'}::${gasSession.username || ''}::${gasSession.currentKeyCode || ''}`
            : '';
        const sessionIdentityChanged = previousSessionIdentity !== nextSessionIdentity;

        if (sessionIdentityChanged && !options.preserveLoopState) {
            resetLoopRotationState();
        }

        if (sessionIdentityChanged && !options.preserveDashboardState) {
            resetDashboardSyncState();
        }

        syncDashboardRefreshTimer();

        if (options.syncHost) {
            syncHostRuntimeSession();
        }
    }

    function clearLoopSession() {
        loopSession = null;
        persistAuthState();
        resetLoopRotationState();
    }

    function clearGasSession() {
        gasSession = null;
        persistAuthState();
        resetDashboardSyncState();
        syncDashboardRefreshTimer();
    }

    function clearAuthSessionState() {
        loopSession = null;
        gasSession = null;
        currentRefreshSource = '';
        lastBackgroundRefreshAt = 0;
        persistAuthState();
        resetLoopRotationState();
        resetAutoRunState();
        resetDashboardSyncState();
        stopDashboardRefreshTimer();
    }

    function resetAutoRunState() {
        autoRunState = createAutoRunState();
    }

    function beginAutoRunSequence(source, currentKeyCode) {
        const nextKeyCode = String(currentKeyCode || '').trim();
        if (!nextKeyCode) {
            resetAutoRunState();
            return;
        }

        if (String(source || '').trim() !== 'manual_activate') {
            latestUserSnapshot = null;
        }

        showResumeTransition('', 'awaiting_status');

        autoRunState = {
            active: true,
            source: String(source || '').trim() || 'manual',
            phase: 'awaiting_login',
            currentKeyCode: nextKeyCode,
            workerRequested: false,
            refreshRequested: false
        };
    }

    function failAutoRunSequence(message) {
        const errorMessage = String(message || '').trim() || 'Automatic post-login setup failed.';
        resetAutoRunState();
        hideResumeTransition();
        showMessage(statusMessage, errorMessage, 'error');
    }

    function completeAutoRunSequence() {
        resetAutoRunState();
        hideResumeTransition();
    }

    function hasWorkerRecoveryCooldown() {
        return (Date.now() - lastWorkerRecoveryRequestAt) < 4000;
    }

    function markWorkerRecoveryFinished() {
        workerRecoveryRequestInFlight = false;
    }

    function isBackgroundWorkerRecoverySource() {
        const source = String(autoRunState && autoRunState.source || '').trim();
        return source === 'restore' || source === 'runtime_state' || source === 'host_watchdog' || source === 'worker_recovery';
    }

    function beginWorkerRecoveryFlow(source = 'worker_recovery') {
        if (!gasSession || !gasSession.currentKeyCode) {
            return false;
        }

        if (autoRunState.active && (autoRunState.phase === 'starting_worker' || autoRunState.phase === 'refreshing')) {
            return false;
        }

        if (workerRecoveryRequestInFlight || hasWorkerRecoveryCooldown()) {
            return false;
        }

        lastWorkerRecoveryRequestAt = Date.now();
        workerRecoveryRequestInFlight = true;

        if (!autoRunState.active) {
            autoRunState = {
                active: true,
                source: String(source || '').trim() || 'worker_recovery',
                phase: isCursorActive ? 'starting_worker' : 'activating',
                currentKeyCode: String(gasSession.currentKeyCode || '').trim(),
                workerRequested: false,
                refreshRequested: false
            };
        }

        return true;
    }

    function shouldRecoverWorkerFromHostState() {
        return Boolean(
            gasSession &&
            gasSession.currentKeyCode &&
            hostWorkerHealthKnown &&
            !hostWorkerHealthy
        );
    }

    function markInitialHostRuntimeStateReceived() {
        initialHostRuntimeMessageReceived = true;
        if (!initialHostRuntimeWaiters.length) {
            return;
        }

        const waiters = initialHostRuntimeWaiters.slice();
        initialHostRuntimeWaiters = [];
        waiters.forEach((resolve) => {
            try {
                resolve(true);
            } catch (_err) {
                // ignore waiter failures
            }
        });
    }

    function waitForInitialHostRuntimeState(timeoutMs = INITIAL_HOST_RUNTIME_WAIT_MS) {
        if (initialHostRuntimeMessageReceived) {
            return Promise.resolve(true);
        }

        return new Promise((resolve) => {
            let settled = false;
            const finish = (received) => {
                if (settled) {
                    return;
                }
                settled = true;
                clearTimeout(timer);
                resolve(Boolean(received));
            };

            const timer = setTimeout(() => {
                initialHostRuntimeWaiters = initialHostRuntimeWaiters.filter((waiter) => waiter !== finish);
                finish(false);
            }, timeoutMs);

            initialHostRuntimeWaiters.push(finish);
        });
    }

    function hasUsableHostRuntimeSession() {
        return Boolean(gasSession && gasSession.currentKeyCode && (latestUserSnapshot || isCursorActive));
    }

    function restoreUiFromExistingSession() {
        if (!gasSession || !gasSession.currentKeyCode) {
            return false;
        }

        isTestLoginUnlocked = true;
        unlockMainUi();
        hideResumeTransition();
        showLoading(false);
        showUserStatus();

        if (latestUserSnapshot) {
            updateUserInfo(latestUserSnapshot);
            syncLoopSessionWithUser(latestUserSnapshot);
            syncDashboardRefreshTimer();
        }

        if (shouldRecoverWorkerFromHostState()) {
            beginWorkerRecoveryFlow('restore');
        } else {
            requestRealtimeUsageRefresh('panel_visible', true);
            completeAutoRunSequence();
        }
        return true;
    }

    function continueAutoRunSequence() {
        if (!autoRunState.active) {
            return;
        }

        if (autoRunState.phase !== 'awaiting_login' && autoRunState.phase !== 'awaiting_status') {
            return;
        }

        if (autoRunState.phase === 'awaiting_status' && !latestUserSnapshot) {
            return;
        }

        if (isCursorActive) {
            autoRunState.phase = 'starting_worker';
            autoRunState.workerRequested = true;
            showResumeTransition('', 'starting_worker');
            handleStartApiWorker();
            return;
        }

        autoRunState.phase = 'activating';
        showResumeTransition('', 'activating');
        handleActivate();
    }

    function resetLoopRotationState() {
        loopRotationState = createLoopRotationState();
    }

    function isLoopModeActive() {
        return Boolean(loopSession && loopSession.mode === 'loop' && loopSession.loopUsername && loopSession.currentKeyCode);
    }

    function parseCurrentChatUsage(user) {
        return parseCurrentRawChatUsage(user);
    }

    function parseCurrentRawChatUsage(user) {
        return toFiniteNumber(user && user.day_score_used, 0);
    }

    function getUsageDisplayLimitValue() {
        return Math.max(1, Math.floor(toFiniteNumber(gasSession && gasSession.usageDisplayLimit, 100)));
    }

    function getUsageNormalizationDivisorValue() {
        return Math.max(1, toFiniteNumber(gasSession && gasSession.usageNormalizationDivisor, 1));
    }

    function parseCurrentNormalizedUsage(user) {
        const rawUsage = parseCurrentRawChatUsage(user);
        const divisor = getUsageNormalizationDivisorValue();
        return Math.max(0, Math.floor(rawUsage / divisor));
    }

    function getCurrentDisplayUsageValue(user) {
        const persistedDisplayUsage = gasSession && Number.isFinite(Number(gasSession.displayUsage))
            ? Math.max(0, Math.floor(Number(gasSession.displayUsage)))
            : 0;

        return persistedDisplayUsage;
    }

    function getCurrentSourceCapacityValue() {
        return Math.max(0, toFiniteNumber(gasSession && gasSession.currentSourceCapacity, 0));
    }

    function getCurrentSourcePreSwitchBufferValue(capacity) {
        const normalizedCapacity = Math.max(0, Math.floor(toFiniteNumber(capacity, 0)));
        if (normalizedCapacity <= 1) {
            return 0;
        }

        return Math.min(SOURCE_ROTATION_PRESWITCH_BUFFER_RAW, normalizedCapacity - 1);
    }

    function getCurrentSourceSwitchThresholdValue(capacity) {
        const normalizedCapacity = Math.max(0, Math.floor(toFiniteNumber(capacity, 0)));
        if (!normalizedCapacity) {
            return 0;
        }

        return normalizedCapacity - getCurrentSourcePreSwitchBufferValue(normalizedCapacity);
    }

    function getCurrentSourceConsumedRawValue(user) {
        const liveRawUsage = parseCurrentRawChatUsage(user);
        const persistedConsumedRaw = Math.max(0, toFiniteNumber(gasSession && gasSession.currentSourceConsumedRaw, 0));
        return Math.max(liveRawUsage, persistedConsumedRaw);
    }

    function syncLoopSessionWithUser(user) {
        if (!user) {
            return;
        }

        const currentKeyCode = String(user.activationCode || '').trim();
        if (!currentKeyCode) {
            return;
        }

        let hasChanges = false;
        const previousGasKeyCode = gasSession ? String(gasSession.currentKeyCode || '').trim() : '';
        const liveRawUsage = parseCurrentRawChatUsage(user);

        if (gasSession && currentKeyCode !== gasSession.currentKeyCode) {
            gasSession.currentKeyCode = currentKeyCode;
            gasSession.licenseCode = currentKeyCode;
            hasChanges = true;
        }

        if (gasSession) {
            const nextCurrentSourceConsumedRaw = currentKeyCode && currentKeyCode !== previousGasKeyCode
                ? Math.max(0, liveRawUsage)
                : Math.max(0, Math.max(toFiniteNumber(gasSession.currentSourceConsumedRaw, 0), liveRawUsage));

            if (nextCurrentSourceConsumedRaw !== toFiniteNumber(gasSession.currentSourceConsumedRaw, 0)) {
                gasSession.currentSourceConsumedRaw = nextCurrentSourceConsumedRaw;
                hasChanges = true;
            }
        }

        if (isLoopModeActive() && currentKeyCode !== loopSession.currentKeyCode) {
            loopSession.currentKeyCode = currentKeyCode;
            hasChanges = true;
        }

        if (hasChanges) {
            persistAuthState();
            syncHostRuntimeSession();
        }
    }

    function beginLoopRotationCycle(currentKeyCode) {
        if (!loopRotationState.active) {
            loopRotationState.active = true;
            loopRotationState.attemptedKeys.clear();
        }

        if (currentKeyCode) {
            loopRotationState.attemptedKeys.add(String(currentKeyCode));
        }
    }

    function finishLoopRotationCycle(message, messageType = 'success') {
        if (message) {
            showMessage(statusMessage, message, messageType);
        }

        resetLoopRotationState();
    }

    function getNextLoopKey(apptookKey, currentSourceKey) {
        const requestId = `loop_${Date.now()}_${Math.floor(Math.random() * 100000)}`;

        return new Promise((resolve) => {
            const timer = setTimeout(() => {
                pendingLoopRequests.delete(requestId);
                resolve({ ok: false, message: 'Loop key rotation timeout' });
            }, 15000);

            pendingLoopRequests.set(requestId, (payload) => {
                clearTimeout(timer);
                pendingLoopRequests.delete(requestId);
                resolve(payload || { ok: false, message: 'Loop key rotation failed' });
            });

            vscode.postMessage({
                type: 'hostLoopNextKey',
                requestId,
                apptookKey,
                currentSourceKey
            });
        });
    }

    async function requestLoopKeyRotation(triggerReason = 'Chat limit reached') {
        if (!isLoopModeActive()) {
            return;
        }

        if (loopRotationState.inFlight || loopRotationState.awaitingLogin) {
            return;
        }

        beginLoopRotationCycle(loopSession.currentKeyCode);
        loopRotationState.inFlight = true;

        showMessage(statusMessage, `${triggerReason}. Switching to the next loop key...`, 'info');

        try {
            const currentSnapshot = latestUserSnapshot ? buildDashboardSyncPayload(latestUserSnapshot) : null;
            if (currentSnapshot) {
                const syncResult = await requestDashboardSync(currentSnapshot);
                if (syncResult && syncResult.ok) {
                    applyDashboardUsageState(syncResult.data || null);
                }
            }

            const result = await getNextLoopKey(
                gasSession && gasSession.apptookKey ? gasSession.apptookKey : loopSession.loopUsername,
                loopSession.currentKeyCode
            );
            const nextData = result && result.data ? result.data : null;
            const nextKeyCode = String((nextData && (nextData.licenseCode || nextData.nextKeyCode)) || '').trim();

            if (!result || !result.ok || !nextKeyCode) {
                finishLoopRotationCycle((result && result.message) || 'No usable loop key is available.', 'error');
                return;
            }

            if (loopRotationState.attemptedKeys.has(nextKeyCode)) {
                finishLoopRotationCycle('Every configured loop key has already been tried in this cycle.', 'error');
                return;
            }

            loopRotationState.attemptedKeys.add(nextKeyCode);
            loopRotationState.awaitingLogin = true;
            loopRotationState.pendingKeyCode = nextKeyCode;

            loopSession.currentKeyCode = nextKeyCode;
            loopSession.currentKeyIndex = toFiniteNumber(nextData.currentKeyIndex, loopSession.currentKeyIndex);
            loopSession.totalKeys = toFiniteNumber(nextData.totalKeys, loopSession.totalKeys);
            if (gasSession) {
                gasSession.currentKeyCode = nextKeyCode;
                gasSession.licenseCode = nextKeyCode;
                gasSession.currentKeyIndex = loopSession.currentKeyIndex;
                gasSession.totalKeys = loopSession.totalKeys;
                gasSession.usageDisplayLimit = Math.max(1, Math.floor(toFiniteNumber(nextData && nextData.usageDisplayLimit, gasSession.usageDisplayLimit || 100)));
                gasSession.usageNormalizationDivisor = Math.max(1, toFiniteNumber(nextData && nextData.usageNormalizationDivisor, gasSession.usageNormalizationDivisor || 1));
                gasSession.totalAssignedSourceCapacity = Math.max(100, toFiniteNumber(nextData && nextData.totalAssignedSourceCapacity, gasSession.totalAssignedSourceCapacity || 100));
                gasSession.currentSourceCapacity = Math.max(0, toFiniteNumber(nextData && nextData.currentSourceCapacity, gasSession.currentSourceCapacity || 0));
                gasSession.currentSourceConsumedRaw = Math.max(0, toFiniteNumber(nextData && nextData.currentSourceConsumedRaw, 0));
                gasSession.rawUsageTotal = Math.max(0, toFiniteNumber(nextData && nextData.rawUsageTotal, gasSession.rawUsageTotal || 0));
                gasSession.displayUsage = Math.max(0, toFiniteNumber(nextData && nextData.displayUsage, gasSession.displayUsage || 0));
                gasSession.usageDate = String((nextData && nextData.usageDate) || gasSession.usageDate || '').trim();
            }
            persistAuthState();
            syncHostRuntimeSession();

            activationCodeInput.value = nextKeyCode;
            vscode.postMessage({
                type: 'login',
                activationCode: nextKeyCode
            });
        } catch (err) {
            const message = err && err.message ? err.message : String(err);
            finishLoopRotationCycle(`Loop key rotation failed: ${message}`, 'error');
        } finally {
            loopRotationState.inFlight = false;
        }
    }

    function maybeRotateLoopKey(user) {
        if (!isLoopModeActive()) {
            return;
        }

        syncLoopSessionWithUser(user);

        const currentSourceCapacity = getCurrentSourceCapacityValue();
        const currentSourceConsumedRaw = getCurrentSourceConsumedRawValue(user);
        const currentSourceSwitchThreshold = getCurrentSourceSwitchThresholdValue(currentSourceCapacity);
        const displayUsage = getCurrentDisplayUsageValue(user);
        const displayLimit = getUsageDisplayLimitValue();

        if (!currentSourceCapacity || !currentSourceSwitchThreshold) {
            return;
        }

        if (currentSourceConsumedRaw < currentSourceSwitchThreshold) {
            if (loopRotationState.active) {
                finishLoopRotationCycle(`Loop key switched successfully. Package usage ${displayUsage}/${displayLimit}.`, 'success');
            }
            return;
        }

        if (displayUsage >= displayLimit) {
            finishLoopRotationCycle(`APPTOOK usage reached ${displayLimit}/${displayLimit} for today.`, 'error');
            return;
        }

        requestLoopKeyRotation(`Current source progress ${formatCompactMetricNumber(currentSourceSwitchThreshold)} / ${formatCompactMetricNumber(currentSourceCapacity)}`);
    }

    function resetDashboardSyncState() {
        latestUserSnapshot = null;
        statusRefreshInFlight = false;
        dashboardSyncInFlight = false;
        queuedDashboardSyncTask = null;
        lastDashboardSyncSignature = '';
        lastDashboardSyncAt = 0;
        lastDashboardSyncErrorMessage = '';
        lastDashboardSyncErrorAt = 0;
    }

    function reportDashboardSyncFailure(message) {
        const errorMessage = String(message || '').trim() || 'Dashboard sync failed.';
        const now = Date.now();
        if (errorMessage === lastDashboardSyncErrorMessage && (now - lastDashboardSyncErrorAt) < DASHBOARD_REFRESH_INTERVAL_MS) {
            return;
        }

        lastDashboardSyncErrorMessage = errorMessage;
        lastDashboardSyncErrorAt = now;
        showMessage(statusMessage, `Dashboard sync failed: ${errorMessage}`, 'error');
    }

    function clearDashboardSyncFailure() {
        lastDashboardSyncErrorMessage = '';
        lastDashboardSyncErrorAt = 0;
    }

    function stopDashboardRefreshTimer() {
        if (dashboardRefreshTimer) {
            clearInterval(dashboardRefreshTimer);
            dashboardRefreshTimer = null;
        }
    }

    function shouldRunDashboardRefresh() {
        return Boolean(
            gasSession &&
            gasSession.currentKeyCode &&
            !statusRefreshInFlight &&
            !loopRotationState.inFlight &&
            !loopRotationState.awaitingLogin &&
            !autoRunState.active &&
            !hostWorkerRecovering &&
            (!hostWorkerHealthKnown || hostWorkerHealthy) &&
            document.visibilityState !== 'hidden'
        );
    }

    function isBackgroundRefreshSource() {
        return currentRefreshSource === 'poll' || currentRefreshSource === 'panel_visible';
    }

    function requestRealtimeUsageRefresh(source = 'poll', force = false) {
        if (!shouldRunDashboardRefresh()) {
            return false;
        }

        const now = Date.now();
        if (!force && (now - lastBackgroundRefreshAt) < BACKGROUND_REFRESH_MIN_INTERVAL_MS) {
            return false;
        }

        lastBackgroundRefreshAt = now;
        handleRefresh(source);
        return true;
    }

    function syncDashboardRefreshTimer() {
        if (!gasSession || !gasSession.currentKeyCode || document.visibilityState === 'hidden') {
            stopDashboardRefreshTimer();
            return;
        }

        if (dashboardRefreshTimer) {
            return;
        }

        dashboardRefreshTimer = window.setInterval(() => {
            requestRealtimeUsageRefresh('poll');
        }, DASHBOARD_REFRESH_INTERVAL_MS);
    }

    function getMembershipStatusText(user) {
        if (!user || !user.vip) {
            return 'Expired';
        }

        return formatVipStatus(user.vip).text;
    }

    function getExpireAtIso(user) {
        const timestamp = normalizeTimestampMs(
            (gasSession && gasSession.expireAt) ||
            (user && user.vip ? user.vip.expire_at : null)
        );
        if (!timestamp) {
            return '';
        }

        return new Date(timestamp).toISOString();
    }

    function buildDashboardSyncPayload(user) {
        if (!user || !gasSession || !gasSession.username) {
            return null;
        }

        const currentKeyCode = String(user.activationCode || gasSession.currentKeyCode || '').trim();
        if (!currentKeyCode) {
            return null;
        }

        const isLoopSession = gasSession.mode === 'loop';

        return {
            apptookKey: gasSession.apptookKey || gasSession.username,
            username: gasSession.username,
            licenseCode: currentKeyCode,
            mode: isLoopSession ? 'loop' : 'normal',
            todayChats: parseCurrentRawChatUsage(user),
            chatLimit: '',
            currentKeyIndex: isLoopSession ? gasSession.currentKeyIndex : '',
            totalKeys: isLoopSession ? gasSession.totalKeys : '',
            membershipStatus: getMembershipStatusText(user),
            expireAt: getExpireAtIso(user),
            lastDevice: gasSession.lastDevice || getDeviceId()
        };
    }

    function buildDashboardBootstrapPayload(authData) {
        if (!gasSession || !gasSession.username) {
            return null;
        }

        const source = authData && typeof authData === 'object' ? authData : {};
        const currentKeyCode = String(source.currentKeyCode || source.licenseCode || gasSession.currentKeyCode || '').trim();
        if (!currentKeyCode) {
            return null;
        }

        const isLoopSession = gasSession.mode === 'loop';

        return {
            apptookKey: gasSession.apptookKey || gasSession.username,
            username: gasSession.username,
            licenseCode: currentKeyCode,
            mode: isLoopSession ? 'loop' : 'normal',
            todayChats: '',
            chatLimit: '',
            currentKeyIndex: isLoopSession ? gasSession.currentKeyIndex : '',
            totalKeys: isLoopSession ? gasSession.totalKeys : '',
            membershipStatus: '',
            expireAt: String(source.expireAt || '').trim(),
            lastDevice: gasSession.lastDevice || getDeviceId()
        };
    }

    function requestDashboardSync(snapshot) {
        const requestId = `dashboard_${Date.now()}_${Math.floor(Math.random() * 100000)}`;

        return new Promise((resolve) => {
            const timer = setTimeout(() => {
                pendingDashboardRequests.delete(requestId);
                resolve({ ok: false, message: 'Dashboard sync timeout' });
            }, 15000);

            pendingDashboardRequests.set(requestId, (payload) => {
                clearTimeout(timer);
                pendingDashboardRequests.delete(requestId);
                resolve(payload || { ok: false, message: 'Dashboard sync failed' });
            });

            vscode.postMessage({
                type: 'hostDashboardSync',
                requestId,
                snapshot
            });
        });
    }

    function createDashboardSyncTask(user, force = false) {
        const snapshot = buildDashboardSyncPayload(user);
        if (!snapshot) {
            return null;
        }

        return {
            snapshot,
            signature: JSON.stringify(snapshot),
            force: Boolean(force)
        };
    }

    function createDashboardSyncTaskFromSnapshot(snapshot, force = false) {
        if (!snapshot || typeof snapshot !== 'object') {
            return null;
        }

        const username = String(snapshot.username || '').trim();
        const licenseCode = String(snapshot.licenseCode || snapshot.currentKeyCode || '').trim();
        if (!username || !licenseCode) {
            return null;
        }

        const normalizedSnapshot = {
            ...snapshot,
            username,
            licenseCode
        };

        return {
            snapshot: normalizedSnapshot,
            signature: JSON.stringify(normalizedSnapshot),
            force: Boolean(force)
        };
    }

    function applyDashboardUsageState(data) {
        if (!data || typeof data !== 'object' || !gasSession) {
            return;
        }

        gasSession.usageDisplayLimit = Math.max(1, Math.floor(toFiniteNumber(data.usageDisplayLimit, gasSession.usageDisplayLimit || 100)));
        gasSession.usageNormalizationDivisor = Math.max(1, toFiniteNumber(data.usageNormalizationDivisor, gasSession.usageNormalizationDivisor || 1));
        gasSession.totalAssignedSourceCapacity = Math.max(100, toFiniteNumber(data.totalAssignedSourceCapacity, gasSession.totalAssignedSourceCapacity || 100));
        gasSession.currentSourceCapacity = Math.max(0, toFiniteNumber(data.currentSourceCapacity, gasSession.currentSourceCapacity || 0));
        gasSession.currentSourceConsumedRaw = Math.max(0, toFiniteNumber(data.currentSourceConsumedRaw, gasSession.currentSourceConsumedRaw || 0));
        gasSession.rawUsageTotal = Math.max(0, toFiniteNumber(data.rawUsageTotal, gasSession.rawUsageTotal || 0));
        gasSession.displayUsage = Math.max(0, toFiniteNumber(data.displayUsage, gasSession.displayUsage || 0));
        gasSession.usageDate = String(data.usageDate || gasSession.usageDate || '').trim();

        if (data.activeSourceKey) {
            gasSession.currentKeyCode = String(data.activeSourceKey).trim() || gasSession.currentKeyCode;
            gasSession.licenseCode = gasSession.currentKeyCode;
        }

        persistAuthState();
        syncHostRuntimeSession();
        updatePremiumSummary(latestUserSnapshot || null);
    }

    async function flushDashboardSyncTask(task) {
        if (!task) {
            return;
        }

        const now = Date.now();
        if (!task.force && task.signature === lastDashboardSyncSignature && (now - lastDashboardSyncAt) < DASHBOARD_REFRESH_INTERVAL_MS) {
            return;
        }

        dashboardSyncInFlight = true;

        try {
            const result = await requestDashboardSync(task.snapshot);
            if (result && result.ok) {
                applyDashboardUsageState(result.data || null);
                lastDashboardSyncSignature = task.signature;
                lastDashboardSyncAt = Date.now();
                clearDashboardSyncFailure();
            } else {
                reportDashboardSyncFailure(result && result.message);
            }
        } finally {
            dashboardSyncInFlight = false;

            if (queuedDashboardSyncTask) {
                const nextTask = queuedDashboardSyncTask;
                queuedDashboardSyncTask = null;
                flushDashboardSyncTask(nextTask);
            }
        }
    }

    function queueDashboardSync(user, force = false) {
        const task = createDashboardSyncTask(user, force);
        if (!task) {
            return;
        }

        const now = Date.now();
        if (!task.force && task.signature === lastDashboardSyncSignature && (now - lastDashboardSyncAt) < DASHBOARD_REFRESH_INTERVAL_MS) {
            return;
        }

        if (dashboardSyncInFlight) {
            queuedDashboardSyncTask = task;
            return;
        }

        flushDashboardSyncTask(task);
    }

    function queueDashboardSnapshot(snapshot, force = false) {
        const task = createDashboardSyncTaskFromSnapshot(snapshot, force);
        if (!task) {
            return;
        }

        const now = Date.now();
        if (!task.force && task.signature === lastDashboardSyncSignature && (now - lastDashboardSyncAt) < DASHBOARD_REFRESH_INTERVAL_MS) {
            return;
        }

        if (dashboardSyncInFlight) {
            queuedDashboardSyncTask = task;
            return;
        }

        flushDashboardSyncTask(task);
    }

    function getWebviewAssetUrl(datasetKey) {
        const bodyDataset = (document.body && document.body.dataset) || {};
        const rootDataset = (document.documentElement && document.documentElement.dataset) || {};
        return bodyDataset[datasetKey] || rootDataset[datasetKey] || '';
    }

    function pushAssetUrl(list, seen, value) {
        const normalized = String(value || '').trim();
        if (!normalized || seen.has(normalized)) {
            return;
        }

        seen.add(normalized);
        list.push(normalized);
    }

    function getWebviewAssetUrls(datasetKeys, windowKeys = []) {
        const urls = [];
        const seen = new Set();

        for (const datasetKey of datasetKeys) {
            pushAssetUrl(urls, seen, getWebviewAssetUrl(datasetKey));
        }

        for (const windowKey of windowKeys) {
            pushAssetUrl(urls, seen, window[windowKey]);
        }

        return urls;
    }

    function getInlineLogoFallbackSrc() {
        const svg = `
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 160">
                <rect width="160" height="160" rx="34" fill="#44d3dc"/>
                <path fill="#ffffff" d="M49 118L79 49h18l31 69h-19L83 60l-18 43h26l-6 15H49z"/>
                <path fill="#ffffff" d="M40 69h27l-7 15H33z"/>
            </svg>
        `;

        return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`;
    }

    function getInlineLogoFallbackMarkup() {
        return `
            <svg class="test-login-logo-vector" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 160" aria-hidden="true" focusable="false">
                <rect width="160" height="160" rx="34" fill="#44d3dc"></rect>
                <path fill="#ffffff" d="M49 118L79 49h18l31 69h-19L83 60l-18 43h26l-6 15H49z"></path>
                <path fill="#ffffff" d="M40 69h27l-7 15H33z"></path>
            </svg>
        `;
    }

    function getTestLoginLogoSources() {
        return getWebviewAssetUrls(
            ['apptookLogoWebviewUrl', 'apptookLogoUrl'],
            ['APPTOOK_LOGO_WEBVIEW_URL', 'APPTOOK_LOGO_URL']
        );
    }

    function getTestLoginLogoPlaceholderSources() {
        return getWebviewAssetUrls(
            ['apptookLogoPlaceholderWebviewUrl', 'apptookLogoPlaceholderUrl'],
            ['APPTOOK_LOGO_PLACEHOLDER_WEBVIEW_URL', 'APPTOOK_LOGO_PLACEHOLDER_URL']
        );
    }

    function buildTestLoginLogoSourceQueue() {
        const queue = [];
        const seen = new Set();

        for (const src of getTestLoginLogoSources()) {
            pushAssetUrl(queue, seen, src);
        }

        for (const src of getTestLoginLogoPlaceholderSources()) {
            pushAssetUrl(queue, seen, src);
        }

        pushAssetUrl(queue, seen, getInlineLogoFallbackSrc());

        return queue;
    }

    function getNextLogoSource(logo, advanceIndex) {
        if (!logo) {
            return '';
        }

        let queue = [];
        try {
            queue = JSON.parse(logo.dataset.sourceQueue || '[]');
        } catch (err) {
            queue = [];
        }

        if (!Array.isArray(queue) || queue.length === 0) {
            return '';
        }

        const currentIndex = Number(logo.dataset.sourceIndex || '0');
        const nextIndex = advanceIndex ? currentIndex + 1 : currentIndex;

        if (nextIndex >= queue.length) {
            return '';
        }

        logo.dataset.sourceIndex = String(nextIndex);
        return String(queue[nextIndex] || '');
    }

    function handleTestLoginLogoError(event) {
        const logo = event && event.currentTarget;

        if (!logo) {
            return;
        }

        const nextSrc = getNextLogoSource(logo, true);
        if (nextSrc) {
            logo.src = nextSrc;
            return;
        }

        logo.style.display = 'none';
    }

    function ensureResumeTransitionOverlay() {
        if (resumeTransitionOverlay && document.body.contains(resumeTransitionOverlay)) {
            return resumeTransitionOverlay;
        }

        const overlay = document.createElement('div');
        overlay.id = 'resumeTransitionOverlay';
        overlay.className = 'resume-transition hidden';
        overlay.innerHTML = `
            <div class="resume-transition-card">
                <div class="test-login-brand">
                    <div class="test-login-logo-frame loaded">
                        <div class="test-login-logo-fallback">
                            ${getInlineLogoFallbackMarkup()}
                        </div>
                    </div>
                    <h2 class="test-login-app-name">APPTOOK</h2>
                </div>
                <h3>Connecting...</h3>
                <p id="resumeTransitionMessage">Restoring your APPTOOK session.</p>
                <div class="resume-transition-spinner" aria-hidden="true"></div>
            </div>
        `;

        document.body.appendChild(overlay);
        resumeTransitionOverlay = overlay;
        resumeTransitionMessage = overlay.querySelector('#resumeTransitionMessage');
        return overlay;
    }

    function showResumeTransition(message, phase = '') {
        if (!SHOW_RESUME_TRANSITION_OVERLAY) {
            return;
        }
        const overlay = ensureResumeTransitionOverlay();
        if (resumeTransitionMessage) {
            resumeTransitionMessage.textContent = getResumeOverlayMessage(phase, message);
        }
        overlay.classList.remove('hidden');
    }

    function hideResumeTransition() {
        if (!SHOW_RESUME_TRANSITION_OVERLAY) {
            return;
        }
        const overlay = ensureResumeTransitionOverlay();
        overlay.classList.add('hidden');
    }

    function lockMainUi() {
        const appRoot = document.getElementById('app');
        if (appRoot) {
            appRoot.style.visibility = 'hidden';
            appRoot.style.pointerEvents = 'none';
        }

        loginForm.style.display = 'none';
        userStatus.style.display = 'none';
        loading.style.display = 'none';
        noticeArea.style.display = 'none';
    }

    function unlockMainUi() {
        const appRoot = document.getElementById('app');
        if (appRoot) {
            appRoot.style.visibility = 'visible';
            appRoot.style.pointerEvents = 'auto';
        }

        noticeArea.style.display = '';
    }

    function createTestLoginView() {
        const oldGate = document.getElementById('testLoginGate');
        if (oldGate) {
            oldGate.remove();
        }

        const gate = document.createElement('div');
        gate.id = 'testLoginGate';
        gate.className = 'test-login-gate';
        gate.innerHTML = `
            <div class="test-login-card">
                <div class="test-login-brand">
                    <div class="test-login-logo-frame">
                        <div class="test-login-logo-fallback">
                            ${getInlineLogoFallbackMarkup()}
                        </div>
                        <img class="test-login-logo" alt="APPTOOK logo" />
                    </div>
                    <h2 class="test-login-app-name">APPTOOK</h2>
                </div>
                <h3>Login Required</h3>
                <p class="test-login-hint">Enter your APPTOOK License Key to start using the extension.</p>
                <div class="input-group">
                    <label for="testLoginKey">APPTOOK License Key:</label>
                    <input id="testLoginKey" type="text" placeholder="Enter APPTOOK License Key" />
                </div>
                <button id="testLoginBtn" class="btn-primary">Login</button>
                <div id="testLoginMessage" class="message"></div>
            </div>
        `;

        document.body.prepend(gate);

        const testLoginLogo = gate.querySelector('.test-login-logo');
        const testLoginLogoFrame = gate.querySelector('.test-login-logo-frame');
        if (testLoginLogo) {
            testLoginLogo.addEventListener('load', () => {
                if (testLoginLogoFrame) {
                    testLoginLogoFrame.classList.add('loaded');
                }
                testLoginLogo.style.display = 'block';
            });
            testLoginLogo.addEventListener('error', handleTestLoginLogoError);

            const logoSources = buildTestLoginLogoSourceQueue();
            if (logoSources.length) {
                testLoginLogo.dataset.sourceQueue = JSON.stringify(logoSources);
                testLoginLogo.dataset.sourceIndex = '0';
                testLoginLogo.src = getNextLogoSource(testLoginLogo, false);
            } else {
                testLoginLogo.style.display = 'none';
            }
        }

        const testLoginBtn = document.getElementById('testLoginBtn');
        const testLoginKey = document.getElementById('testLoginKey');
        const testLoginMessage = document.getElementById('testLoginMessage');

        const submitLogin = async () => {
            const apptookKey = (testLoginKey.value || '').trim();

            if (!apptookKey) {
                showMessage(testLoginMessage, 'Please enter your APPTOOK License Key', 'error');
                return;
            }

            testLoginBtn.disabled = true;
            showMessage(testLoginMessage, 'Checking APPTOOK License Key...', 'info');

            try {
                const result = await loginWithGas(apptookKey);
                if (!result || !result.ok) {
                    testLoginBtn.disabled = false;
                    showMessage(testLoginMessage, (result && result.message) || 'Login failed', 'error');
                    return;
                }

                const licenseCode = result.data && result.data.licenseCode ? String(result.data.licenseCode) : '';
                if (!licenseCode) {
                    testLoginBtn.disabled = false;
                    showMessage(testLoginMessage, 'No licenseCode returned from server', 'error');
                    return;
                }

                if (!startExtensionLoginWithSession(result.data || null, 'manual')) {
                    testLoginBtn.disabled = false;
                    showMessage(testLoginMessage, 'Cannot start extension login flow', 'error');
                    return;
                }
            } catch (err) {
                testLoginBtn.disabled = false;
                const msg = err && err.message ? err.message : String(err);
                showMessage(testLoginMessage, `Login failed (${msg})`, 'error');
            }
        };

        testLoginBtn.addEventListener('click', submitLogin);
        testLoginKey.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                submitLogin();
            }
        });
    }

    function initTestLoginGate(statusText = '') {
        isTestLoginUnlocked = false;
        lockMainUi();
        createTestLoginView();

        const testLoginMessage = document.getElementById('testLoginMessage');
        if (statusText && testLoginMessage) {
            showMessage(testLoginMessage, statusText, 'info');
        }
    }

    function transitionToLoginGate(statusText = '') {
        latestUserSnapshot = null;
        currentRefreshSource = '';
        lastBackgroundRefreshAt = 0;
        hostWorkerHealthKnown = false;
        hostWorkerHealthy = false;
        hostWorkerRecovering = false;
        lastWorkerRecoveryAt = 0;
        markWorkerRecoveryFinished();
        showLoading(false);
        showLoginForm();
        initTestLoginGate(statusText);
    }

    // Modal-related elements
    const confirmModal = document.getElementById('confirmModal');
    const confirmModalMessage = document.getElementById('confirmModalMessage');
    const confirmModalCancel = document.getElementById('confirmModalCancel');
    const confirmModalConfirm = document.getElementById('confirmModalConfirm');

    // Proxy settings elements
    const proxyInput = document.getElementById('proxyInput');
    const testProxyBtn = document.getElementById('testProxyBtn');
    const saveProxyBtn = document.getElementById('saveProxyBtn');
    const proxyMessage = document.getElementById('proxyMessage');

    // Network settings elements
    const http2Radio = document.getElementById('http2');
    const http11Radio = document.getElementById('http11');
    const http10Radio = document.getElementById('http10');
    const saveNetworkBtn = document.getElementById('saveNetworkBtn');
    const networkMessage = document.getElementById('networkMessage');

    // Token settings elements
    const tokenInput = document.getElementById('tokenInput');
    const saveTokenBtn = document.getElementById('saveTokenBtn');
    const deleteTokenBtn = document.getElementById('deleteTokenBtn');
    const tokenMessage = document.getElementById('tokenMessage');

    // Event listeners
    loginBtn.addEventListener('click', handleLogin);
    refreshBtn.addEventListener('click', handleRefresh);
    logoutBtn.addEventListener('click', handleLogout);
    activeBtn.addEventListener('click', handleActivate);
    gainNewBtn.addEventListener('click', handleGainNew);
    copyUserIdBtn.addEventListener('click', handleCopyUserId);
    copyActivationCodeBtn.addEventListener('click', handleCopyActivationCode);
    startApiWorkerBtn.addEventListener('click', handleStartApiWorker);
    testProxyBtn.addEventListener('click', handleTestProxy);
    saveProxyBtn.addEventListener('click', handleSaveProxy);
    saveNetworkBtn.addEventListener('click', handleSaveNetwork);
    saveTokenBtn.addEventListener('click', handleSaveToken);
    deleteTokenBtn.addEventListener('click', handleDeleteToken);
    
    // Modal event listeners
    confirmModalCancel.addEventListener('click', hideConfirmModal);
    confirmModalConfirm.addEventListener('click', handleConfirmModalConfirm);
    
    // Press Enter to login
    activationCodeInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            handleLogin();
        }
    });

    document.addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && passwordInput && document.activeElement === passwordInput) {
            handleLogin();
        }
    });

    function fillSavedActivationCode() {
        activationCodeInput.value = '';
        if (passwordInput) {
            passwordInput.value = '';
        }
    }

    function getDeviceId() {
        const ua = navigator.userAgent || 'unknown-client';
        return btoa(unescape(encodeURIComponent(ua))).slice(0, 64);
    }

    function loginWithGas(apptookKey) {
        const requestId = `auth_${Date.now()}_${Math.floor(Math.random() * 100000)}`;

        return new Promise((resolve) => {
            const timer = setTimeout(() => {
                pendingAuthRequests.delete(requestId);
                resolve({ ok: false, message: 'Login timeout' });
            }, 15000);

            pendingAuthRequests.set(requestId, (payload) => {
                clearTimeout(timer);
                pendingAuthRequests.delete(requestId);

                if (!payload || payload.ok !== true) {
                    resolve({ ok: false, message: (payload && payload.message) || 'Login failed' });
                    return;
                }

                resolve(payload);
            });

            vscode.postMessage({
                type: 'hostAuthLogin',
                requestId,
                apptookKey,
                device: getDeviceId()
            });
        });
    }

    function startExtensionLoginWithSession(authData, source = 'manual') {
        const sourceData = authData && typeof authData === 'object' ? authData : {};
        const licenseCode = String(sourceData.licenseCode || sourceData.currentKeyCode || '').trim();
        if (!licenseCode) {
            return false;
        }

        setLoopSessionFromAuthData(sourceData, { syncHost: true });
        beginAutoRunSequence(source, licenseCode);
        queueDashboardSnapshot(buildDashboardBootstrapPayload(sourceData), true);

        isTestLoginUnlocked = true;
        activationCodeInput.value = licenseCode;

        const gate = document.getElementById('testLoginGate');
        if (gate) {
            gate.remove();
        }

        unlockMainUi();
        showUserStatus();
        showLoading(true);
        sendReadyMessage();
        const sessionPayload = buildHostSessionPayload(sourceData);
        vscode.postMessage({
            type: 'login',
            activationCode: licenseCode,
            session: sessionPayload,
            latestUser: latestUserSnapshot || null
        });
        return true;
    }

    function handlePersistedSessionInvalid(message) {
        const errorMessage = String(message || '').trim() || 'Saved session is no longer valid. Please log in again.';
        try {
            vscode.postMessage({
                type: 'logout'
            });
        } catch (_err) {
            // ignore host logout notification failures
        }
        clearAuthSessionState();
        showLoading(false);
        showLoginForm();
        initTestLoginGate(errorMessage);
    }

    // Handle login
    async function handleLogin() {
        const apptookKey = activationCodeInput.value.trim();

        if (!apptookKey) {
            showMessage(loginMessage, 'Please enter your APPTOOK License Key', 'error');
            return;
        }

        showLoading(true);
        loginBtn.disabled = true;
        showMessage(loginMessage, 'Checking APPTOOK License Key...', 'info');

        try {
            const result = await loginWithGas(apptookKey);
            if (!result.ok) {
                showLoading(false);
                loginBtn.disabled = false;
                showMessage(loginMessage, result.message || 'Login failed', 'error');
                return;
            }

            const licenseCode = result.data && result.data.licenseCode ? String(result.data.licenseCode) : '';
            if (!licenseCode) {
                showLoading(false);
                loginBtn.disabled = false;
                showMessage(loginMessage, 'No licenseCode returned from server', 'error');
                return;
            }

            if (!startExtensionLoginWithSession(result.data || null, 'manual')) {
                showLoading(false);
                loginBtn.disabled = false;
                showMessage(loginMessage, 'Cannot start extension login flow', 'error');
            }
        } catch (err) {
            showLoading(false);
            loginBtn.disabled = false;
            showMessage(loginMessage, 'Cannot connect to login service', 'error');
        }
    }

    // Handle refresh
    function handleRefresh(source = 'manual') {
        currentRefreshSource = String(source || 'manual').trim() || 'manual';
        statusRefreshInFlight = true;
        refreshBtn.disabled = true;
        refreshBtn.innerHTML = '<i class="codicon codicon-loading codicon-modifier-spin"></i> Refreshing';
        vscode.postMessage({
            type: 'refresh',
            session: buildHostSessionPayload(),
            latestUser: latestUserSnapshot || null
        });
    }

    // Handle logout
    async function handleLogout() {
        logoutBtn.disabled = true;
        logoutBtn.textContent = 'Logging out...';
        clearAuthSessionState();
        currentRefreshSource = '';
        transitionToLoginGate('Logged out successfully.');

        vscode.postMessage({
            type: 'logout',
            session: null,
            latestUser: null
        });

        logoutBtn.disabled = false;
        logoutBtn.textContent = 'Logout';
    }

    // Handle activate/deactivate
    function handleActivate() {
        const sessionPayload = buildHostSessionPayload();
        if (!isCursorActive && sessionPayload && sessionPayload.currentKeyCode && !autoRunState.active) {
            beginAutoRunSequence('manual_activate', sessionPayload.currentKeyCode);
            autoRunState.phase = 'activating';
        }

        if (!isCursorActive) {
            showResumeTransition('', 'activating');
        }

        vscode.postMessage({
            type: isCursorActive ? 'deactivate' : 'activate',
            session: sessionPayload,
            latestUser: latestUserSnapshot || null
        });
    }

    // Handle start api-worker
    function handleStartApiWorker() {
        startApiWorkerBtn.disabled = true;
        startApiWorkerBtn.textContent = 'Starting...';
        vscode.postMessage({
            type: 'startApiWorker',
            session: buildHostSessionPayload(),
            latestUser: latestUserSnapshot || null
        });
    }

    // Handle account switching
    function handleGainNew() {
        showConfirmModal('Changing account will consume points. Are you sure?', function() {
            gainNewBtn.disabled = true;
            gainNewBtn.innerHTML = '<i class="codicon codicon-loading codicon-modifier-spin"></i> Switching...';
            vscode.postMessage({
                type: 'gainNew'
            });
        });
    }

    // Show confirmation modal
    function showConfirmModal(message, onConfirm) {
        confirmModalMessage.textContent = message;
        confirmModal.style.display = 'flex';
        
        // Store callback function
        confirmModal.dataset.onConfirm = 'pending';
        confirmModal.onConfirmCallback = onConfirm;
    }

    // Hide confirmation modal
    function hideConfirmModal() {
        confirmModal.style.display = 'none';
        confirmModal.dataset.onConfirm = '';
        confirmModal.onConfirmCallback = null;
    }

    // Handle confirm button click
    function handleConfirmModalConfirm() {
        if (confirmModal.onConfirmCallback) {
            confirmModal.onConfirmCallback();
        }
        hideConfirmModal();
    }

    // Handle test proxy
    function handleTestProxy() {
        const proxy = proxyInput.value.trim();
        testProxyBtn.disabled = true;
        testProxyBtn.innerHTML = '<i class="codicon codicon-loading codicon-modifier-spin"></i> Testing';

        vscode.postMessage({
            type: 'testProxy',
            proxy: proxy
        });
    }

    // Handle save proxy
    function handleSaveProxy() {
        const proxy = proxyInput.value.trim();
        saveProxyBtn.disabled = true;
        saveProxyBtn.innerHTML = '<i class="codicon codicon-loading codicon-modifier-spin"></i> Saving';

        vscode.postMessage({
            type: 'setProxy',
            proxy: proxy
        });
    }

    // Get selected HTTP version
    function getSelectedHttpVersion() {
        if (http2Radio.checked) {return '2';}
        if (http11Radio.checked) {return '1.1';}
        if (http10Radio.checked) {return '1.0';}
        return '1.1'; // default value
    }

    // Handle save network settings
    function handleSaveNetwork() {
        const httpVersion = getSelectedHttpVersion();
        saveNetworkBtn.disabled = true;
        saveNetworkBtn.innerHTML = '<i class="codicon codicon-loading codicon-modifier-spin"></i> Saving';

        vscode.postMessage({
            type: 'setNetwork',
            httpVersion: httpVersion
        });
    }

    // Handle save token
    function handleSaveToken() {
        const token = tokenInput.value.trim();
        if (!token) {
            showMessage(tokenMessage, 'Please enter a support key', 'error');
            return;
        }

        saveTokenBtn.disabled = true;
        saveTokenBtn.innerHTML = '<i class="codicon codicon-loading codicon-modifier-spin"></i> Saving';

        vscode.postMessage({
            type: 'saveToken',
            token: token
        });
    }

    // Handle delete token file
    function handleDeleteToken() {
        deleteTokenBtn.disabled = true;
        deleteTokenBtn.innerHTML = '<i class="codicon codicon-loading codicon-modifier-spin"></i> Deleting';

        vscode.postMessage({
            type: 'deleteToken'
        });
    }

    // Handle copy user ID
    function handleCopyUserId() {
        const userIdText = userId.textContent;
        if (!userIdText) {
            return;
        }

        // Use Clipboard API to copy text
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(userIdText).then(() => {
                showMessage(statusMessage, 'License key copied to clipboard', 'success');
            }).catch(() => {
                // Fallback to legacy method
                fallbackCopyTextToClipboard(userIdText);
            });
        } else {
            // Fallback to legacy method
            fallbackCopyTextToClipboard(userIdText);
        }
    }

    // Handle copy activation code
    function handleCopyActivationCode() {
        const activationCode = activationCodeDisplay.dataset.code;
        if (!activationCode) {
            return;
        }

        // Use Clipboard API to copy text
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(activationCode).then(() => {
                showMessage(statusMessage, 'Activation code copied to clipboard', 'success');
            }).catch(() => {
                // Fallback to legacy method
                fallbackCopyTextToClipboard(activationCode);
            });
        } else {
            // Fallback to legacy method
            fallbackCopyTextToClipboard(activationCode);
        }
    }

    // Legacy copy fallback
    function fallbackCopyTextToClipboard(text) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        textArea.style.top = '-999999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            document.execCommand('copy');
            showMessage(statusMessage, 'License key copied to clipboard', 'success');
        } catch (err) {
            showMessage(statusMessage, 'Copy failed, please copy manually', 'error');
        }

        document.body.removeChild(textArea);
    }

    let timer;
    // Show message
    function showMessage(element, message, type = 'info') {
        element.textContent = message;
        element.className = `message ${type}`;
        element.style.display = 'block';
        
        // Auto-hide
        clearTimeout(timer);
        timer = setTimeout(() => {
            element.style.display = 'none';
        }, 10000);
    }

    function hideMessage(element) {
        if (!element) {
            return;
        }

        clearTimeout(timer);
        element.textContent = '';
        element.style.display = 'none';
    }

    // Show loading state
    function showLoading(show = true) {
        loading.style.display = show ? 'flex' : 'none';
    }

    // Show login form
    function showLoginForm() {
        latestUserSnapshot = null;
        statusRefreshInFlight = false;
        resetAutoRunState();
        stopDashboardRefreshTimer();
        hideResumeTransition();
        loginForm.style.display = 'block';
        userStatus.style.display = 'none';
        showLoading(false);
        resetActivateButton();
    }

    // Show user status
    function showUserStatus() {
        ensurePremiumDashboardLayout();
        loginForm.style.display = 'none';
        userStatus.style.display = 'block';
        showLoading(false);
        syncDashboardRefreshTimer();
        syncPremiumEmbeddedSections();
    }

    // Format time
    function formatTime(timestamp) {
        if (!timestamp) {return 'Unknown';}
        const date = new Date(timestamp);
        return date.toLocaleString('en-US');
    }

    // Format VIP status
    function formatVipStatus(vip) {
        const now = Date.now() / 1000;
        if (vip && vip.expire_at && vip.expire_at > now) {
            return {text: vip.product || 'Vip', status: 'active'};
        }
        return {text: 'Expired', status: 'expired'};
    }

    function resetActivateButton() {
        activeBtn.style.display = 'none';
        activeBtn.disabled = false;
        activeBtn.textContent = 'Activate';
        activeBtn.dataset.state = 'hidden';
        activeBtn.classList.remove('btn-expired');
        isCursorActive = false;
    }

    // Update user info display
    function updateUserInfo(user) {
        if (!user) {
            showLoginForm();
            return;
        }

        userId.textContent = getDisplayedLicenseKey(user);
        const vipState = formatVipStatus(user.vip);
        vipStatus.textContent = vipState.text;
        vipStatus.classList.toggle('status-expired', vipState.status === 'expired');
        vipStatus.classList.toggle('status-active', vipState.status === 'active');

        // Show masked activation code (if available)
        if (user.activationCode) {
            const code = user.activationCode;
            const maskedCode = maskActivationCode(code);
            activationCodeDisplay.textContent = maskedCode;
            activationCodeDisplay.dataset.code = code; // Store full code for copying
        } else {
            activationCodeDisplay.textContent = 'None';
            activationCodeDisplay.dataset.code = '';
        }

        if (user.vip && user.vip.expire_at) {
            expireTime.textContent = formatTime(user.vip.expire_at);
        } else {
            expireTime.textContent = 'None';
        }

        // Check whether membership is valid
        const hasVip = Boolean(user.vip);
        const hasVipExpire = Boolean(user.vip && user.vip.expire_at);
        const isVipActive = hasVipExpire && (user.vip.expire_at > Date.now() / 1000);

        // Display content based on vip.product
        if (user.vip && user.vip.product === 'cursor') {
            // Show remaining account-switch attempts
            const labelElement = dayScoreItem.querySelector('.label');
            if (labelElement) {
                labelElement.textContent = 'Switches:';
            }
            const used = user.vip.score_used || 0;
            const total = user.vip.score || 0;
            dayScore.textContent = `${used}/${total}`;
        } else {
            // Show today's chat count
            const labelElement = dayScoreItem.querySelector('.label');
            if (labelElement) {
                labelElement.textContent = 'Today\'s chats:';
            }
            dayScore.textContent = `${getCurrentDisplayUsageValue(user)}/${getUsageDisplayLimitValue()}`;
        }

        if (isVipActive) {
            activeBtn.style.display = 'block';
            activeBtn.disabled = false;
            activeBtn.textContent = isCursorActive ? 'Deactivate' : 'Activate';
            activeBtn.dataset.state = 'active';
            activeBtn.classList.remove('btn-expired');
        } else if (hasVip || hasVipExpire) {
            activeBtn.style.display = 'block';
            activeBtn.disabled = true;
            activeBtn.textContent = 'Expired';
            activeBtn.dataset.state = 'expired';
            activeBtn.classList.add('btn-expired');
        } else {
            activeBtn.style.display = 'block';
            activeBtn.disabled = true;
            activeBtn.textContent = 'Expired';
            activeBtn.dataset.state = 'expired';
            activeBtn.classList.add('btn-expired');
        }

        // Show/hide account-switch button: only for active cursor product members
        if (user.vip && user.vip.product === 'cursor' && isVipActive) {
            gainNewBtn.style.display = 'block';
            gainNewBtn.disabled = false;
            gainNewBtn.innerHTML = 'Switch Account';
        } else {
            gainNewBtn.style.display = 'none';
        }

        applyCompactStatusLayout();
        updatePremiumSummary(user);
        showUserStatus();
    }

    // Mask activation code, only show first and last parts
    function maskActivationCode(code) {
        if (!code || code.length < 6) {
            return code;
        }
        const firstTwo = code.substring(0, 9);
        const lastTwo = code.substring(code.length - 8);
        const masked = '*'.repeat(4);
        return `${firstTwo}${masked}${lastTwo}`;
    }

    // Format chat rate-limit info
    function formatChatLimit(limitInfo) {
        if (typeof limitInfo === 'string') {
            // If string, try parsing as JSON
            try {
                const parsed = JSON.parse(limitInfo);
                return formatChatLimitObject(parsed);
            } catch (e) {
                // If parse fails, return raw string with localized replacement
                return limitInfo.replace(/1?小时/g, 'hours').replace(/分钟/g, 'minutes');
            }
        } else if (typeof limitInfo === 'object') {
            return formatChatLimitObject(limitInfo);
        }
        return 'Unknown';
    }

    // Format chat rate-limit object
    function formatChatLimitObject(obj) {
        if (obj && typeof obj === 'object') {
            // Try common rate-limit fields
            if (obj.limit !== undefined && obj.used !== undefined) {
                return `${obj.used}/${obj.limit}`;
            } else if (obj.remaining !== undefined && obj.total !== undefined) {
                return `${obj.total - obj.remaining}/${obj.total}`;
            } else if (obj.count !== undefined && obj.max !== undefined) {
                return `${obj.count}/${obj.max}`;
            } else {
                // If no standard fields, return JSON with localized unit replacement
                const jsonStr = JSON.stringify(obj);
                return jsonStr.replace(/小时/g, 'hours').replace(/分钟/g, 'minutes');
            }
        }
        return 'Unknown';
    }

    // Listen for messages from extension
    window.addEventListener('message', event => {
        const message = event.data;
        
        switch (message.type) {
            case 'notice':
                handleNotice(message);
                break;
            case 'loginStatus':
                handleLoginStatus(message);
                break;
            case 'userStatus':
                handleUserStatus(message);
                break;
            case 'activateStatus':
                handleActivateStatus(message);
                break;
            case 'activateState':
                handleActivateState(message);
                break;
            case 'startApiWorkerStatus':
                handleStartApiWorkerStatus(message);
                break;
            case 'refreshStatus':
                handleRefreshStatus(message);
                break;
            case 'proxyStatus':
                handleProxyStatus(message);
                break;
            case 'proxySetStatus':
                handleProxySetStatus(message);
                break;
            case 'proxyTestStatus':
                handleProxyTestStatus(message);
                break;
            case 'proxyError':
                handleProxyError(message);
                break;
            case 'networkSettings':
                handleNetworkSettings(message);
                break;
            case 'networkSetStatus':
                handleNetworkSetStatus(message);
                break;
            case 'networkError':
                handleNetworkError(message);
                break;
            case 'gainNewStatus':
                handleGainNewStatus(message);
                break;
            case 'tokenSaveStatus':
                handleTokenSaveStatus(message);
                break;
            case 'tokenDeleteStatus':
                handleTokenDeleteStatus(message);
                break;
            case 'hostAuthLoginResult':
                handleHostAuthLoginResult(message);
                break;
            case 'hostLoopNextKeyResult':
                handleHostLoopNextKeyResult(message);
                break;
            case 'hostDashboardSyncResult':
                handleHostDashboardSyncResult(message);
                break;
            case 'hostEnsureWorker':
                handleHostEnsureWorker(message);
                break;
            case 'hostRuntimeState':
                handleHostRuntimeState(message);
                break;
            case 'hostLoggedOut':
                handleHostLoggedOut(message);
                break;
        }
    });

    // Handle notice message
    function handleNotice(message) {
        if (message.notice && message.notice.html) {
            noticeContent.innerHTML = message.notice.html;
            noticeArea.style.display = 'block';

            // If URL exists, add click behavior and style
            if (message.notice.url) {
                noticeArea.style.cursor = 'pointer';
                noticeArea.title = 'Click to open link';

                // Remove previous listener if any
                noticeArea.removeEventListener('click', handleNoticeClick);

                // Store URL in data attribute
                noticeArea.dataset.url = message.notice.url;

                // Add click listener
                noticeArea.addEventListener('click', handleNoticeClick);
            } else {
                noticeArea.style.cursor = 'default';
                noticeArea.title = '';
                noticeArea.removeEventListener('click', handleNoticeClick);
                delete noticeArea.dataset.url;
            }
        } else {
            noticeArea.style.display = 'none';
            noticeArea.removeEventListener('click', handleNoticeClick);
        }
    }

    // Handle notice click
    function handleNoticeClick() {
        const url = noticeArea.dataset.url;
        if (url) {
            // Send message to extension so it opens browser
            vscode.postMessage({
                type: 'openUrl',
                url: url
            });
        }
    }

    // Handle login status
    function handleLoginStatus(message) {
        switch (message.status) {
            case 'loading':
                statusRefreshInFlight = true;
                showLoading(true);
                showMessage(loginMessage, message.message, 'info');
                loginBtn.disabled = true;
                break;
            case 'success':
                statusRefreshInFlight = false;
                if (loopRotationState.awaitingLogin) {
                    loopRotationState.awaitingLogin = false;
                    loopRotationState.pendingKeyCode = '';
                }
                showLoading(false);
                showMessage(loginMessage, message.message, 'success');
                loginBtn.disabled = false;
                activationCodeInput.value = '';
                if (autoRunState.active) {
                    autoRunState.phase = 'awaiting_status';
                }
                break;
            case 'error':
                statusRefreshInFlight = false;
                if (loopRotationState.awaitingLogin) {
                    loopRotationState.awaitingLogin = false;
                    loopRotationState.pendingKeyCode = '';
                    showLoading(false);
                    showMessage(loginMessage, 'Activation code is invalid', 'error');
                    loginBtn.disabled = false;
                    requestLoopKeyRotation('Loop key activation failed');
                    break;
                }
                showLoading(false);
                showMessage(loginMessage, 'Activation code is invalid', 'error');
                loginBtn.disabled = false;
                if (gasSession && gasSession.currentKeyCode) {
                    handlePersistedSessionInvalid(message.message || 'Activation code is invalid');
                    break;
                }
                if (autoRunState.active) {
                    failAutoRunSequence(message.message || 'Activation code is invalid');
                }
                break;
        }
    }

    // Handle user status
    function handleUserStatus(message) {
        statusRefreshInFlight = false;
        currentRefreshSource = '';
        showLoading(false);
        refreshBtn.disabled = false;
        refreshBtn.innerHTML = 'Refresh Status';

        if (!message.user) {
            if (autoRunState.active && autoRunState.phase === 'awaiting_status') {
                return;
            }

            latestUserSnapshot = null;
            updateUserInfo(null);
            return;
        }

        if (message.error) {
            showMessage(statusMessage, message.error, 'error');
        }

        latestUserSnapshot = message.user || null;
        updateUserInfo(message.user);
        syncLoopSessionWithUser(message.user);
        syncDashboardRefreshTimer();
        queueDashboardSync(message.user);
        if (autoRunState.active) {
            if (autoRunState.phase === 'awaiting_login' || autoRunState.phase === 'awaiting_status') {
                autoRunState.phase = 'awaiting_status';
                continueAutoRunSequence();
            } else if (autoRunState.phase === 'refreshing') {
                completeAutoRunSequence();
            }
        }
        maybeRotateLoopKey(message.user);
    }

    // Handle activate state
    function handleActivateState(message) {
        isCursorActive = Boolean(message.isActive);
        updateActivateButtonText();
        if (autoRunState.active && (autoRunState.phase === 'awaiting_login' || autoRunState.phase === 'awaiting_status')) {
            continueAutoRunSequence();
        }
    }

    // Handle activate status
    function handleActivateStatus(message) {
        switch (message.status) {
            case 'loading':
                showMessage(statusMessage, message.message, 'info');
                showResumeTransition(message.message, 'activating');
                if (activeBtn.dataset.state === 'active') {
                    activeBtn.disabled = true;
                }
                break;
            case 'success':
                showMessage(statusMessage, message.message, 'success');
                showResumeTransition(message.message, 'starting_worker');
                if (activeBtn.dataset.state === 'active') {
                    activeBtn.disabled = false;
                    updateActivateButtonText();
                }
                if (autoRunState.active && autoRunState.phase === 'activating') {
                    autoRunState.phase = 'starting_worker';
                    autoRunState.workerRequested = true;
                    handleStartApiWorker();
                }
                break;
            case 'error':
                if (isBackgroundWorkerRecoverySource()) {
                    hideMessage(statusMessage);
                } else {
                    showMessage(statusMessage, message.message, 'error');
                }
                hideResumeTransition();
                if (activeBtn.dataset.state === 'active') {
                    activeBtn.disabled = false;
                }
                if (autoRunState.active && autoRunState.phase === 'activating') {
                    if (isBackgroundWorkerRecoverySource()) {
                        resetAutoRunState();
                    } else {
                        failAutoRunSequence(message.message || 'Activate failed');
                    }
                }
                break;
        }
    }

    function updateActivateButtonText() {
        if (activeBtn.dataset.state === 'active') {
            activeBtn.textContent = isCursorActive ? 'Deactivate' : 'Activate now';
        }
    }

    // Handle api-worker start status
    function handleStartApiWorkerStatus(message) {
        switch (message.status) {
            case 'loading':
                hideMessage(statusMessage);
                hostWorkerRecovering = true;
                hostWorkerHealthy = false;
                if (autoRunState.active) {
                    showResumeTransition(message.message, 'starting_worker');
                }
                startApiWorkerBtn.disabled = true;
                startApiWorkerBtn.textContent = 'Starting...';
                break;
            case 'success':
                hideMessage(statusMessage);
                hostWorkerHealthKnown = true;
                hostWorkerHealthy = true;
                hostWorkerRecovering = false;
                markWorkerRecoveryFinished();
                if (autoRunState.active) {
                    showResumeTransition(message.message, 'refreshing');
                }
                startApiWorkerBtn.disabled = false;
                startApiWorkerBtn.textContent = 'Start api-worker';
                if (autoRunState.active && autoRunState.phase === 'starting_worker') {
                    autoRunState.phase = 'refreshing';
                    autoRunState.refreshRequested = true;
                    handleRefresh();
                } else if (gasSession && gasSession.currentKeyCode && document.visibilityState !== 'hidden') {
                    requestRealtimeUsageRefresh('panel_visible', true);
                }
                break;
            case 'error':
                {
                const wasWorkerRecoveryFlow = workerRecoveryRequestInFlight;
                const quietRecoveryFailure = wasWorkerRecoveryFlow && isBackgroundWorkerRecoverySource();
                hostWorkerHealthKnown = true;
                hostWorkerHealthy = false;
                hostWorkerRecovering = false;
                markWorkerRecoveryFinished();
                if (quietRecoveryFailure) {
                    hideMessage(statusMessage);
                } else {
                    showMessage(statusMessage, message.message, 'error');
                }
                hideResumeTransition();
                startApiWorkerBtn.disabled = false;
                startApiWorkerBtn.textContent = 'Start api-worker';
                if (autoRunState.active && autoRunState.phase === 'starting_worker') {
                    if (quietRecoveryFailure) {
                        resetAutoRunState();
                    } else {
                        failAutoRunSequence(message.message || 'Start Api Worker failed');
                    }
                }
                break;
                }
        }
    }

    // Handle refresh status
    function handleRefreshStatus(message) {
        switch (message.status) {
            case 'loading':
                statusRefreshInFlight = true;
                refreshBtn.disabled = true;
                refreshBtn.innerHTML = '<i class="codicon codicon-loading codicon-modifier-spin"></i> Refreshing';
                if (!isBackgroundRefreshSource() && (autoRunState.active || (gasSession && gasSession.currentKeyCode))) {
                    showResumeTransition(message.message, 'refreshing');
                }
                break;
            case 'error':
                {
                const backgroundRefresh = isBackgroundRefreshSource();
                statusRefreshInFlight = false;
                currentRefreshSource = '';
                refreshBtn.disabled = false;
                refreshBtn.innerHTML = 'Refresh Status';
                if (!backgroundRefresh) {
                    hideResumeTransition();
                }
                if (!isBackgroundWorkerRecoverySource() && !backgroundRefresh) {
                    showMessage(statusMessage, message.message || 'Refresh failed', 'error');
                } else {
                    hideMessage(statusMessage);
                }
                if (autoRunState.active && autoRunState.phase === 'refreshing') {
                    if (isBackgroundWorkerRecoverySource()) {
                        resetAutoRunState();
                    } else {
                        failAutoRunSequence(message.message || 'Refresh failed');
                    }
                }
                break;
                }
        }
    }

    // Ensure ready message is sent only once
    let readySent = false;

    // Handle proxy status
    function handleProxyStatus(message) {
        proxyInput.value = message.proxy || '';
    }

    // Handle proxy set status
    function handleProxySetStatus(message) {
        switch (message.status) {
            case 'loading':
                showMessage(proxyMessage, message.message, 'info');
                saveProxyBtn.disabled = true;
                break;
            case 'success':
                showMessage(proxyMessage, message.message, 'success');
                saveProxyBtn.disabled = false;
                saveProxyBtn.innerHTML = 'Save';
                break;
            case 'error':
                showMessage(proxyMessage, message.message, 'error');
                saveProxyBtn.disabled = false;
                saveProxyBtn.innerHTML = 'Save';
                break;
        }
    }

    // Handle proxy test status
    function handleProxyTestStatus(message) {
        switch (message.status) {
            case 'loading':
                showMessage(proxyMessage, message.message, 'info');
                testProxyBtn.disabled = true;
                break;
            case 'success':
                showMessage(proxyMessage, message.message, 'success');
                testProxyBtn.disabled = false;
                testProxyBtn.innerHTML = 'Test Proxy';
                break;
            case 'error':
                showMessage(proxyMessage, message.message, 'error');
                testProxyBtn.disabled = false;
                testProxyBtn.innerHTML = 'Test Proxy';
                break;
        }
    }

    // Handle proxy error
    function handleProxyError(message) {
        showMessage(proxyMessage, message.message, 'error');
    }

    // Handle network settings
    function handleNetworkSettings(message) {
        const httpVersion = message.httpVersion || '1.1';

        // Set matching radio button
        http2Radio.checked = (httpVersion === '2');
        http11Radio.checked = (httpVersion === '1.1');
        http10Radio.checked = (httpVersion === '1.0');

        // If unmatched, default to 1.1
        if (!http2Radio.checked && !http11Radio.checked && !http10Radio.checked) {
            http11Radio.checked = true;
        }

        refreshPremiumServerSelection();
    }

    // Handle network save status
    function handleNetworkSetStatus(message) {
        switch (message.status) {
            case 'loading':
                showMessage(networkMessage, message.message, 'info');
                saveNetworkBtn.disabled = true;
                setPremiumInlineMessage(premiumNetworkInlineMessage, message.message, 'info');
                syncPremiumConnectedControls();
                break;
            case 'success':
                showMessage(networkMessage, message.message, 'success');
                saveNetworkBtn.disabled = false;
                saveNetworkBtn.innerHTML = 'Save Host';
                setPremiumInlineMessage(premiumNetworkInlineMessage, message.message, 'success');
                syncPremiumConnectedControls();
                break;
            case 'error':
                showMessage(networkMessage, message.message, 'error');
                saveNetworkBtn.disabled = false;
                saveNetworkBtn.innerHTML = 'Save Host';
                setPremiumInlineMessage(premiumNetworkInlineMessage, message.message, 'error');
                syncPremiumConnectedControls();
                break;
        }
    }

    // Handle network error
    function handleNetworkError(message) {
        showMessage(networkMessage, message.message, 'error');
        setPremiumInlineMessage(premiumNetworkInlineMessage, message.message, 'error');
    }

    // Handle token save status
    function handleTokenSaveStatus(message) {
        switch (message.status) {
            case 'loading':
                showMessage(tokenMessage, message.message, 'info');
                saveTokenBtn.disabled = true;
                setPremiumInlineMessage(premiumSupportInlineMessage, message.message, 'info');
                syncPremiumConnectedControls();
                break;
            case 'success':
                showMessage(tokenMessage, message.message, 'success');
                saveTokenBtn.disabled = false;
                saveTokenBtn.innerHTML = 'Save Support Key';
                // Clear input
                tokenInput.value = '';
                if (premiumSupportKeyInput) {
                    premiumSupportKeyInput.value = '';
                }
                setPremiumInlineMessage(premiumSupportInlineMessage, message.message, 'success');
                syncPremiumConnectedControls();
                break;
            case 'error':
                showMessage(tokenMessage, message.message, 'error');
                saveTokenBtn.disabled = false;
                saveTokenBtn.innerHTML = 'Save Support Key';
                setPremiumInlineMessage(premiumSupportInlineMessage, message.message, 'error');
                syncPremiumConnectedControls();
                break;
        }
    }

    // Handle token delete status
    function handleTokenDeleteStatus(message) {
        switch (message.status) {
            case 'loading':
                showMessage(tokenMessage, message.message, 'info');
                deleteTokenBtn.disabled = true;
                setPremiumInlineMessage(premiumSupportInlineMessage, message.message, 'info');
                break;
            case 'success':
                showMessage(tokenMessage, message.message, 'success');
                deleteTokenBtn.disabled = false;
                deleteTokenBtn.innerHTML = 'Delete File';
                setPremiumInlineMessage(premiumSupportInlineMessage, message.message, 'success');
                break;
            case 'error':
                showMessage(tokenMessage, message.message, 'error');
                deleteTokenBtn.disabled = false;
                deleteTokenBtn.innerHTML = 'Delete File';
                setPremiumInlineMessage(premiumSupportInlineMessage, message.message, 'error');
                break;
        }
    }

    function handleHostAuthLoginResult(message) {
        const requestId = String(message.requestId || '');
        if (!requestId) {
            return;
        }

        const resolver = pendingAuthRequests.get(requestId);
        if (typeof resolver !== 'function') {
            return;
        }

        resolver({
            ok: Boolean(message.ok),
            message: message.message || '',
            data: message.data || null
        });
    }

    function handleHostLoopNextKeyResult(message) {
        const requestId = String(message.requestId || '');
        if (!requestId) {
            return;
        }

        const resolver = pendingLoopRequests.get(requestId);
        if (typeof resolver !== 'function') {
            return;
        }

        resolver({
            ok: Boolean(message.ok),
            message: message.message || '',
            data: message.data || null
        });
    }

    function handleHostDashboardSyncResult(message) {
        const requestId = String(message.requestId || '');
        if (!requestId) {
            return;
        }

        const resolver = pendingDashboardRequests.get(requestId);
        if (typeof resolver !== 'function') {
            return;
        }

        resolver({
            ok: Boolean(message.ok),
            message: message.message || '',
            data: message.data || null
        });
    }

    function handleHostEnsureWorker(message) {
        if (!gasSession || !gasSession.currentKeyCode) {
            return;
        }

        if (!beginWorkerRecoveryFlow((message && message.reason) || 'host_watchdog')) {
            return;
        }

        updatePremiumSummary(latestUserSnapshot);

        if (isCursorActive) {
            autoRunState.phase = 'starting_worker';
            autoRunState.workerRequested = true;
            showResumeTransition('', 'starting_worker');
            handleStartApiWorker();
            return;
        }

        autoRunState.phase = 'activating';
        showResumeTransition('', 'activating');
        handleActivate();
    }

    function handleHostRuntimeState(message) {
        if (!message || typeof message !== 'object') {
            return;
        }

        markInitialHostRuntimeStateReceived();

        const hostInstallMarker = String(message.installMarker || '').trim();
        if (hostInstallMarker) {
            let storedInstallMarker = '';
            try {
                storedInstallMarker = String(localStorage.getItem(INSTALL_MARKER_STORAGE_KEY) || '').trim();
            } catch (_err) {
                storedInstallMarker = '';
            }

            if (storedInstallMarker && storedInstallMarker !== hostInstallMarker) {
                clearPersistedAuthArtifactsForFreshInstall();
                cachedWebviewState = {};
                loopSession = null;
                gasSession = null;
                latestUserSnapshot = null;
                loopRotationState = createLoopRotationState();
                autoRunState = createAutoRunState();
                isCursorActive = false;
                stopDashboardRefreshTimer();
                try {
                    localStorage.setItem(INSTALL_MARKER_STORAGE_KEY, hostInstallMarker);
                    if (message.extensionVersion) {
                        localStorage.setItem(INSTALL_VERSION_STORAGE_KEY, String(message.extensionVersion));
                    }
                } catch (_err) {
                    // ignore marker persistence failures
                }
                transitionToLoginGate('');
                return;
            }
        }

        if (message.pendingResume) {
            showResumeTransition('', message.resumePhase || 'activating');
        } else if (!autoRunState.active) {
            hideResumeTransition();
        }

        hostWorkerHealthKnown = Boolean(message.workerHealthKnown);
        hostWorkerHealthy = Boolean(message.workerHealthy);
        hostWorkerRecovering = Boolean(message.workerRecovering);
        lastWorkerRecoveryAt = Math.max(0, toFiniteNumber(message.lastWorkerRecoveryAt, 0));
        if (hostWorkerHealthy && !hostWorkerRecovering) {
            markWorkerRecoveryFinished();
        }

        if (message.session && typeof message.session === 'object') {
            setLoopSessionFromAuthData(message.session, {
                preserveLoopState: true,
                preserveDashboardState: true
            });
        }

        if (typeof message.isActive === 'boolean') {
            isCursorActive = Boolean(message.isActive);
            updateActivateButtonText();
        }

        if (message.latestUser && typeof message.latestUser === 'object') {
            latestUserSnapshot = message.latestUser;
            updateUserInfo(message.latestUser);
            syncLoopSessionWithUser(message.latestUser);
            if (!message.pendingResume && !shouldRecoverWorkerFromHostState()) {
                completeAutoRunSequence();
            }
        } else if (gasSession) {
            updatePremiumSummary(latestUserSnapshot);
        }

        if (shouldRecoverWorkerFromHostState() && gasSession && gasSession.currentKeyCode) {
            handleHostEnsureWorker({
                reason: 'runtime_state'
            });
        }

        if (!message.pendingResume && !message.session && !message.latestUser && !gasSession && !loopSession && isTestLoginUnlocked) {
            transitionToLoginGate('');
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            stopDashboardRefreshTimer();
            return;
        }

        syncDashboardRefreshTimer();
        requestRealtimeUsageRefresh('panel_visible', true);
    });

    function handleHostLoggedOut(message) {
        transitionToLoginGate((message && message.message) || 'Logged out successfully.');
    }

    // Handle account-switch status
    function handleGainNewStatus(message) {
        switch (message.status) {
            case 'loading':
                showMessage(statusMessage, message.message, 'info');
                gainNewBtn.disabled = true;
                break;
            case 'success':
                showMessage(statusMessage, message.message + (message.account ? ` - Account: ${message.account}` : ''), 'success');
                gainNewBtn.disabled = false;
                gainNewBtn.innerHTML = 'Switch Account';
                break;
            case 'error':
                showMessage(statusMessage, message.message, 'error');
                gainNewBtn.disabled = false;
                gainNewBtn.innerHTML = 'Switch Account';
                break;
        }
    }

    // Notify extension when page is ready and fetch settings
    function sendReadyMessage() {
        if (!readySent) {
            readySent = true;
            vscode.postMessage({
                type: 'ready'
            });
            // Fetch current proxy settings
            vscode.postMessage({
                type: 'getProxy'
            });
            // Fetch current network settings
            vscode.postMessage({
                type: 'getNetworkSettings'
            });
        }
    }

    // Auth now uses local bridge endpoint.

    // Translate any remaining Chinese static labels in dashboard HTML
    function translateStaticChineseLabels() {
        const replacements = {
            '用户ID:': 'User ID:',
            '用户 ID:': 'User ID:',
            '激活码:': 'Activation Code:',
            '会员状态:': 'VIP Status:',
            '到期时间:': 'Expire Time:',
            '今日对话:': 'Today\'s chats:',
            '操作:': 'Actions:',
            '刷新状态': 'Refresh Status',
            '退出登录': 'Logout',
            '复制': 'Copy',
            '立即激活': 'Activate now',
            '取消激活': 'Deactivate'
        };

        replacements['Token Settings'] = 'Support Key';
        replacements['HTTP Protocol Version:'] = 'Server Host';
        replacements['HTTP/2'] = 'Server 1';
        replacements['HTTP/1.1'] = 'Server 2';
        replacements['HTTP/1.0'] = 'Server 3';
        replacements['Recommended'] = 'ค่าเริ่มต้น';

        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        const textNodes = [];

        while (walker.nextNode()) {
            textNodes.push(walker.currentNode);
        }

        textNodes.forEach(node => {
            let text = node.nodeValue;
            if (!text) {return;}
            for (const [zh, en] of Object.entries(replacements)) {
                if (text.includes(zh)) {
                    text = text.replaceAll(zh, en);
                }
            }
            node.nodeValue = text;
        });
    }

    async function bootstrapAuthGate() {
        translateStaticChineseLabels();
        applyCompactStatusLayout();
        applySupportKeyLayout();
        fillSavedActivationCode();
        sendReadyMessage();

        await waitForInitialHostRuntimeState();
        if (hasUsableHostRuntimeSession() && restoreUiFromExistingSession()) {
            return;
        }

        const localSession = getRestorableLocalSession();
        if (localSession && localSession.licenseCode && startExtensionLoginWithSession(localSession, 'restore')) {
            return;
        }

        try {
            vscode.postMessage({
                type: 'logout'
            });
        } catch (_err) {
            // ignore stale host state clear failures
        }

        initTestLoginGate();
    }

    // Notify extension after page load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            bootstrapAuthGate();
        });
    } else {
        // DOM already loaded, run immediately
        bootstrapAuthGate();
    }
})();
