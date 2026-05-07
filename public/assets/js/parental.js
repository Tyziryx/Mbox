// ── Data ──────────────────────────────────────────────────────────────
const DAYS  = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
const HOURS = Array.from({length:24},(_,i)=>i);
const ALLOWED_MODES = ['child', 'teen', 'adult'];

const MODE_FILTERS = {
    child:  {adult:true,  games:true,  streaming:true,  social:true,  ads:true},
    teen:   {adult:true,  games:false, streaming:false, social:false, ads:true},
    adult:  {adult:false, games:false, streaming:false, social:false, ads:false}
};
const MODE_LABELS = {child:'Enfant', teen:'Ado', adult:'Adulte'};

const DEVICE_ICONS = {
    phone:'fa-mobile-alt', laptop:'fa-laptop', tablet:'fa-tablet-alt',
    console:'fa-gamepad', tv:'fa-tv', other:'fa-desktop'
};

const DEVICE_TYPE_LABELS = {
    phone: 'Téléphone', laptop: 'Ordinateur', tablet: 'Tablette',
    console: 'Console', tv: 'TV', other: 'Autre'
};

const FORUM_TOPIC_BY_MODE = { child: 'enfant', teen: 'ado', adult: 'parent' };

// Per-device live quota snapshot used by renderDevices() mini-bar
const deviceLiveQuota = {};

const CATEGORY_META = {
    adult: { label: 'Contenu adulte', icon: 'fa-ban' },
    games: { label: 'Jeux en ligne', icon: 'fa-gamepad' },
    streaming: { label: 'Streaming vidéo', icon: 'fa-video' },
    social: { label: 'Réseaux sociaux', icon: 'fa-hashtag' },
    ads: { label: 'Publicités et trackers', icon: 'fa-bullhorn' }
};
const CATEGORY_KEYS = Object.keys(CATEGORY_META);

const API_DOMAIN_LISTS_URL = (typeof window !== 'undefined' && window.MBOX_API_DOMAIN_LISTS_URL)
    ? window.MBOX_API_DOMAIN_LISTS_URL
    : 'api_domain_lists.php';
const API_PARENTAL_URL = (typeof window !== 'undefined' && window.MBOX_API_PARENTAL_URL)
    ? window.MBOX_API_PARENTAL_URL
    : 'api_parental.php';

// Per-device state (schedule + filters), initialized lazily
const schedules = {};
const filtersState = {};
let statsSummary = {
    modes: {
        child: { adult: 38, games: 22, streaming: 18, social: 34, ads: 27 },
        teen: { adult: 31, games: 24, streaming: 21, social: 29, ads: 23 },
        adult: { adult: 0, games: 8, streaming: 7, social: 0, ads: 11 }
    },
    updated_at: 'Pré-rempli'
};

function normalizeMode(mode) {
    return ALLOWED_MODES.includes(mode) ? mode : 'child';
}

function isValidMac(mac) {
    return /^([0-9A-F]{2}:){5}[0-9A-F]{2}$/i.test(String(mac || '').trim());
}

function normalizeQuota(value) {
    const candidate = String(value ?? '').replace(',', '.');
    const numeric = Number.parseFloat(candidate);
    if (!Number.isFinite(numeric) || numeric < 0) return 0;
    return Math.round(numeric * 100) / 100;
}

function formatQuota(value) {
    const normalized = normalizeQuota(value);
    if (Number.isInteger(normalized)) return String(normalized);
    return normalized.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
}

function defaultSchedule() {
    return Array(7).fill(null).map(() => Array(24).fill('allowed'));
}
function initDevice(dev) {
    const saved = (typeof savedConfigs !== 'undefined') && savedConfigs[dev.mac];
    const baseMode = normalizeMode((saved && saved.mode) || dev.mode || 'child');
    dev.mode = baseMode;

    if (saved) {
        schedules[dev.id]   = saved.schedule || defaultSchedule();
        filtersState[dev.id]= (saved.filters && typeof saved.filters === 'object')
            ? { ...MODE_FILTERS[baseMode], ...saved.filters }
            : { ...MODE_FILTERS[baseMode] };
        dev.download_quota_gb = normalizeQuota(saved.download_quota_gb);
    } else {
        schedules[dev.id]    = defaultSchedule();
        filtersState[dev.id] = {...MODE_FILTERS[baseMode]};
        dev.download_quota_gb = normalizeQuota(dev.download_quota_gb);
    }
}
devices.forEach(initDevice);

let selectedId   = devices[0]?.id || null;
let isDragging   = false;
let dragState    = null;
let currentFilterPanel = 'filters';
let quotaLiveTimer = null;
let quotaLiveRequestInFlight = false;

function bytesToGb(bytes) {
    const numeric = Number.parseInt(bytes, 10);
    if (!Number.isFinite(numeric) || numeric <= 0) return 0;
    return numeric / (1024 * 1024 * 1024);
}

function formatQuotaLiveGb(value) {
    const numeric = Number.parseFloat(value);
    if (!Number.isFinite(numeric) || numeric < 0) return '0';
    const rounded = Math.round(numeric * 100) / 100;
    if (Number.isInteger(rounded)) return String(rounded);
    return rounded.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
}

function formatQuotaReset(value) {
    if (!value) return '—';
    const dt = new Date(value);
    if (Number.isNaN(dt.getTime())) return String(value);
    return dt.toLocaleString('fr-FR');
}

function isDeviceOnline(dev) {
    return !!(dev && dev.online);
}

function renderQuotaLiveState(payload = {}) {
    const usedNumEl = document.getElementById('pc-quota-used-num');
    const usedEl = document.getElementById('pc-quota-used');
    const remainingEl = document.getElementById('pc-quota-remaining');
    const currentEl = document.getElementById('pc-quota-current');
    const scaleLimitEl = document.getElementById('pc-quota-live-limit-val');
    const barEl = document.getElementById('pc-quota-live-bar-fill');
    const resetEl = document.getElementById('pc-quota-live-reset');
    const stateEl = document.getElementById('pc-quota-live-state');

    if (!barEl || !resetEl || !stateEl) return;

    const hasQuota = !!payload.quota_enabled;
    const blocked = !!payload.blocked_by_quota;
    const usedGb = Number.isFinite(Number(payload.used_gb))
        ? Number(payload.used_gb)
        : bytesToGb(payload.used_bytes);
    const remainingGb = Number.isFinite(Number(payload.remaining_gb))
        ? Number(payload.remaining_gb)
        : bytesToGb(payload.remaining_bytes);
    const quotaGb = Number.isFinite(Number(payload.quota_gb)) ? Number(payload.quota_gb) : 0;

    if (usedNumEl) usedNumEl.textContent = formatQuotaLiveGb(usedGb);
    if (usedEl) usedEl.textContent = `${formatQuotaLiveGb(usedGb)} Go`;

    // Cache for per-card mini bar
    const dev = devices.find(d => d.id === selectedId);
    if (dev) {
        deviceLiveQuota[dev.id] = {
            used_gb: usedGb,
            quota_gb: hasQuota ? quotaGb : 0,
            blocked
        };
        updateDeviceCardQuota(dev.id);
    }

    if (!hasQuota) {
        if (remainingEl) remainingEl.textContent = 'Illimité';
        if (currentEl) currentEl.textContent = 'Illimitée';
        if (scaleLimitEl) scaleLimitEl.textContent = '∞';
        barEl.style.width = '0%';
        barEl.className = 'pc-quota-live-bar-fill';
        resetEl.textContent = `Reset: ${formatQuotaReset(payload.reset_at)}`;
        stateEl.textContent = 'État: quota désactivé';
        stateEl.style.color = 'var(--color-text-light)';
        return;
    }

    const percentRaw = Number(payload.percent_used);
    const percent = Number.isFinite(percentRaw) ? Math.max(0, Math.min(100, percentRaw)) : 0;

    if (remainingEl) remainingEl.textContent = `${formatQuotaLiveGb(remainingGb)} Go`;
    if (currentEl) currentEl.textContent = `${formatQuotaLiveGb(quotaGb)} Go`;
    if (scaleLimitEl) scaleLimitEl.textContent = `${formatQuotaLiveGb(quotaGb)} Go`;

    barEl.style.width = `${percent}%`;
    barEl.className = 'pc-quota-live-bar-fill' + (blocked ? ' danger' : (percent >= 85 ? ' warn' : ''));

    const collectorState = String(payload.collector_state || 'ok');
    let stateLabel = 'État: suivi actif';
    if (collectorState === 'waiting_ip') {
        stateLabel = 'État: en attente IP appareil';
    }
    if (blocked) {
        stateLabel = 'État: quota atteint (accès bloqué)';
    }

    resetEl.textContent = `Reset: ${formatQuotaReset(payload.reset_at)}`;
    stateEl.textContent = stateLabel;
    stateEl.style.color = blocked ? 'var(--color-danger)' : 'var(--color-text-light)';
}

function updateDeviceCardQuota(devId) {
    const card = document.querySelector(`.pc-device-card[data-dev-id="${CSS.escape(devId)}"]`);
    if (!card) return;
    const snap = deviceLiveQuota[devId];
    const dev = devices.find(d => d.id === devId);
    if (!dev) return;
    const quota = snap && snap.quota_gb > 0 ? snap.quota_gb : normalizeQuota(dev.download_quota_gb);
    const used = snap ? snap.used_gb : 0;

    const valueEl = card.querySelector('.pc-device-quota-value');
    const pctEl = card.querySelector('.pc-device-quota-pct');
    const fillEl = card.querySelector('.pc-device-quota-bar-fill');
    const barWrap = card.querySelector('.pc-device-quota-bar');
    if (!valueEl) return;

    if (quota > 0) {
        const pct = Math.max(0, Math.min(100, (used / quota) * 100));
        valueEl.textContent = `${formatQuotaLiveGb(used)} / ${formatQuotaLiveGb(quota)} Go`;
        if (pctEl) {
            pctEl.textContent = `${Math.round(pct)}%`;
            pctEl.style.display = '';
        }
        if (fillEl) {
            fillEl.style.width = `${pct}%`;
            fillEl.className = 'pc-device-quota-bar-fill'
                + (pct >= 90 ? ' danger' : (pct >= 70 ? ' warn' : ''));
        }
        if (barWrap) barWrap.style.display = '';
    } else {
        valueEl.textContent = 'Illimité';
        if (pctEl) pctEl.style.display = 'none';
        if (barWrap) barWrap.style.display = 'none';
    }
}

function renderQuotaLivePlaceholder(message) {
    renderQuotaLiveState({
        quota_enabled: false,
        used_gb: 0,
        remaining_gb: 0,
        reset_at: null,
        blocked_by_quota: false,
        collector_state: 'disabled'
    });

    const stateEl = document.getElementById('pc-quota-live-state');
    if (stateEl && message) {
        stateEl.textContent = `État: ${message}`;
    }
}

function stopQuotaLivePolling() {
    if (quotaLiveTimer) {
        window.clearInterval(quotaLiveTimer);
        quotaLiveTimer = null;
    }
    quotaLiveRequestInFlight = false;
}

async function loadQuotaStatus() {
    const dev = devices.find(d => d.id === selectedId);
    if (!dev || !isValidMac(dev.mac)) {
        renderQuotaLivePlaceholder('appareil sans MAC valide');
        return;
    }

    if (quotaLiveRequestInFlight) return;
    quotaLiveRequestInFlight = true;
    const requestedMac = String(dev.mac).toUpperCase();

    try {
        const response = await fetch(`${API_PARENTAL_URL}?action=quota_status&mac=${encodeURIComponent(requestedMac)}`, {
            cache: 'no-store'
        });
        const raw = await response.text();
        const result = JSON.parse(raw);
        if (!result.success) {
            throw new Error(result.error || 'Erreur quota_status');
        }

        const current = devices.find(d => d.id === selectedId);
        if (!current || String(current.mac || '').toUpperCase() !== requestedMac) {
            return;
        }

        if (Number.isFinite(Number(result.quota_gb))) {
            current.download_quota_gb = normalizeQuota(result.quota_gb);
            if (typeof savedConfigs !== 'undefined' && current.mac) {
                if (!savedConfigs[current.mac]) savedConfigs[current.mac] = {};
                savedConfigs[current.mac].download_quota_gb = current.download_quota_gb;
            }
        }

        renderQuotaLiveState(result);
    } catch (error) {
        renderQuotaLivePlaceholder('statut indisponible');
    } finally {
        quotaLiveRequestInFlight = false;
    }
}

function startQuotaLivePolling() {
    stopQuotaLivePolling();
    loadQuotaStatus();
    quotaLiveTimer = window.setInterval(loadQuotaStatus, 5000);
}

window.addEventListener('mouseup', () => { isDragging = false; dragState = null; });

// ── Render: Device list ───────────────────────────────────────────────
function renderDevices() {
    const container = document.getElementById('pc-device-list');
    container.innerHTML = devices.map(dev => {
        const icon   = DEVICE_ICONS[dev.deviceType] || 'fa-desktop';
        const sel    = dev.id === selectedId;
        const mode   = dev.mode ? (MODE_LABELS[dev.mode] || dev.mode) : '—';
        const online = isDeviceOnline(dev);
        const dotClass = online ? '' : ' offline';
        const escId = escapeAttr(dev.id);
        return `
        <div class="pc-device-card${sel?' selected':''}" data-dev-id="${escapeHtml(dev.id)}" onclick="selectDevice('${escId}')">
            <div class="pc-device-head">
                <div class="pc-device-avatar"><i class="fas ${icon}"></i></div>
                <div class="pc-device-id">
                    <div class="pc-device-name">${escapeHtml(dev.name)}</div>
                    <div class="pc-device-meta">
                        <span class="pc-device-dot${dotClass}"></span>
                        <span class="pc-device-mode">${escapeHtml(mode)}</span>
                    </div>
                </div>
            </div>
            <div class="pc-device-body">
                <div class="pc-device-quota-row">
                    <div>
                        <div class="pc-device-quota-label">Quota jour</div>
                        <div class="pc-device-quota-value">—</div>
                    </div>
                    <div class="pc-device-quota-pct"></div>
                </div>
                <div class="pc-device-quota-bar"><div class="pc-device-quota-bar-fill"></div></div>
                <button class="pc-device-settings" onclick="event.stopPropagation(); openFilters('${escId}')">
                    <i class="fas fa-sliders"></i> Réglages
                </button>
            </div>
        </div>`;
    }).join('');

    devices.forEach(d => updateDeviceCardQuota(d.id));
}

function selectDevice(id) {
    selectedId = id;
    renderDevices();
    renderSchedule();
    updateSchedMeta();
    renderQuotaLivePlaceholder('chargement du statut');
    renderQuotaCard();

    const modal = document.getElementById('pc-modal');
    const modalOpen = !!modal && !modal.classList.contains('is-hidden');
    if (modalOpen && currentFilterPanel === 'quota') {
        startQuotaLivePolling();
    }
}

function updateSchedMeta() {
    const dev = devices.find(d => d.id === selectedId);
    if (!dev) return;
    document.getElementById('pc-sched-device-name').textContent = dev.name;
    document.getElementById('pc-sched-device-mode').textContent = MODE_LABELS[dev.mode] || dev.mode;
}

function renderQuotaCard() {
    const dev = devices.find(d => d.id === selectedId);
    const nameEl = document.getElementById('pc-quota-device-name');
    const inputEl = document.getElementById('pc-quota-input');
    const currentEl = document.getElementById('pc-quota-current');
    const scaleLimitEl = document.getElementById('pc-quota-live-limit-val');
    const saveBtn = document.getElementById('pc-quota-save-btn');

    if (!nameEl || !inputEl || !currentEl) return;

    if (!dev) {
        nameEl.textContent = '—';
        inputEl.value = '';
        currentEl.textContent = '—';
        if (scaleLimitEl) scaleLimitEl.textContent = '—';
        inputEl.disabled = true;
        if (saveBtn) saveBtn.disabled = true;
        setQuotaSaveState('idle', 'Aucun appareil sélectionné.');
        renderQuotaLivePlaceholder('aucun appareil sélectionné');
        updateQuotaSaveButton();
        return;
    }

    const quota = normalizeQuota(dev.download_quota_gb);
    nameEl.textContent = dev.name;
    if (document.activeElement !== inputEl) {
        inputEl.value = quota > 0 ? formatQuota(quota) : '';
    }
    currentEl.textContent = quota > 0 ? `${formatQuota(quota)} Go` : 'Illimitée';
    if (scaleLimitEl) scaleLimitEl.textContent = quota > 0 ? `${formatQuota(quota)} Go` : '∞';

    const validMac = isValidMac(dev.mac);
    inputEl.disabled = !validMac;
    if (saveBtn) saveBtn.disabled = !validMac;
    if (validMac) {
        setQuotaSaveState('idle', '');
    } else {
        setQuotaSaveState('error', 'MAC absente: quota indisponible pour cet appareil.');
        renderQuotaLivePlaceholder('MAC absente');
    }
    updateQuotaSaveButton();
}

function updateQuotaSaveButton() {
    const inputEl = document.getElementById('pc-quota-input');
    const btn = document.getElementById('pc-quota-save-btn');
    if (!btn || !inputEl) return;
    const raw = String(inputEl.value || '').trim();
    const hasValue = raw !== '' && Number.parseFloat(raw.replace(',', '.')) > 0;
    btn.classList.toggle('active', hasValue);
    btn.textContent = hasValue ? 'Enregistrer le quota' : 'Passer en illimité';
}

function onQuotaInputChange() {
    updateQuotaSaveButton();
}

function getQuotaInputValue() {
    const inputEl = document.getElementById('pc-quota-input');
    if (!inputEl) return 0;
    const raw = String(inputEl.value || '').trim().replace(',', '.');
    if (raw === '') return 0;
    const parsed = Number.parseFloat(raw);
    if (!Number.isFinite(parsed) || parsed < 0) return null;
    return Math.round(parsed * 100) / 100;
}

function setQuotaPreset(value) {
    const inputEl = document.getElementById('pc-quota-input');
    if (!inputEl) return;
    inputEl.value = formatQuota(value);
    inputEl.focus();
}

function setQuotaSaveState(state, message) {
    const progress = document.getElementById('pc-save-progress');
    const status = document.getElementById('pc-quota-status');
    const input = document.getElementById('pc-quota-input');
    const btn = document.getElementById('pc-quota-save-btn');

    if (!progress || !status) return;

    progress.classList.remove('loading', 'success', 'error');
    if (state === 'loading') {
        progress.classList.add('loading');
        if (input) input.disabled = true;
        if (btn) btn.disabled = true;
        status.textContent = message || 'Enregistrement du quota...';
        return;
    }

    if (input) input.disabled = false;
    if (btn) btn.disabled = false;

    if (state === 'success') {
        progress.classList.add('success');
        status.textContent = message || 'Quota sauvegardé.';
        setTimeout(() => {
            progress.classList.remove('success');
        }, 1200);
        return;
    }

    if (state === 'error') {
        progress.classList.add('error');
        status.textContent = message || 'Erreur de sauvegarde quota.';
        setTimeout(() => {
            progress.classList.remove('error');
        }, 1800);
        return;
    }

    status.textContent = message || '';
}

function showToast(message, color) {
    const toast = document.getElementById('pc-toast');
    if (!toast) return;
    toast.textContent = message;
    toast.style.backgroundColor = color || '';
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3000);
}

async function saveQuota() {
    const dev = devices.find(d => d.id === selectedId);
    if (!dev) return;

    if (!isValidMac(dev.mac)) {
        setQuotaSaveState('error', 'Appareil sans adresse MAC valide.');
        showToast('Impossible: appareil sans MAC valide.', '#e74c3c');
        return;
    }

    const quotaValue = getQuotaInputValue();
    if (quotaValue === null) {
        setQuotaSaveState('error', 'Valeur quota invalide.');
        showToast('Quota invalide (valeur >= 0 attendue).', '#e74c3c');
        return;
    }

    setQuotaSaveState('loading', 'Enregistrement du quota en cours...');

    const payload = {
        mac: dev.mac,
        name: dev.name,
        download_quota_gb: quotaValue,
        quota_only: true
    };

    try {
        const response = await fetch(`${API_PARENTAL_URL}?action=save_quota`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const result = await response.json();
        if (!result.success) {
            setQuotaSaveState('error', result.error || 'Erreur API quota.');
            showToast('Erreur : ' + (result.error || 'Inconnue'), '#e74c3c');
            return;
        }

        dev.download_quota_gb = normalizeQuota(result.download_quota_gb ?? quotaValue);
        if (typeof savedConfigs !== 'undefined' && dev.mac) {
            if (!savedConfigs[dev.mac]) savedConfigs[dev.mac] = {};
            savedConfigs[dev.mac].download_quota_gb = dev.download_quota_gb;
        }

        if (result.stats_summary && result.stats_summary.modes) {
            statsSummary = {
                modes: result.stats_summary.modes,
                updated_at: result.stats_summary.updated_at || null
            };
            renderCategoryHelp();
        }

        if (result.quota_status && result.quota_status.success) {
            renderQuotaLiveState(result.quota_status);
        } else {
            await loadQuotaStatus();
        }

        renderQuotaCard();
        setQuotaSaveState('success', 'Quota journalier sauvegardé.');
        showToast('Quota journalier sauvegardé ✓', '#2ecc71');
    } catch (error) {
        console.error('Erreur quota :', error);
        setQuotaSaveState('error', 'Réponse API invalide ou réseau.');
        showToast('Erreur réseau lors de la sauvegarde du quota.', '#e74c3c');
    }
}

// ── Render: Schedule grid ─────────────────────────────────────────────
function renderSchedule() {
    const schedule = schedules[selectedId];
    const container = document.getElementById('pc-schedule');

    let html = `<div class="pc-sched-header">`;
    for (let h = 0; h < 24; h++) {
        html += `<div class="pc-sched-hour-label">${h % 2 === 0 ? String(h).padStart(2,'0') : ''}</div>`;
    }
    html += `</div>`;

    for (let d = 0; d < 7; d++) {
        html += `
        <div class="pc-sched-row">
            <div class="pc-sched-daylabel">
                <span class="pc-sched-dayname">${DAYS[d]}</span>
                <div class="pc-sched-rowbtns">
                    <button class="pc-sched-rowbtn allow" onclick="setDayState(${d},'allowed')" title="Tout autoriser">Tout</button>
                    <button class="pc-sched-rowbtn block" onclick="setDayState(${d},'blocked')" title="Tout bloquer">Rien</button>
                </div>
            </div>
            <div class="pc-sched-cells">`;
        for (let h = 0; h < 24; h++) {
            const state = schedule[d][h];
            html += `<div class="pc-cell ${state}"
                title="${String(h).padStart(2,'0')}h — ${state==='allowed'?'Autorisé':'Bloqué'}"
                onmousedown="cellDown(event,${d},${h})"
                onmouseenter="cellEnter(${d},${h})"></div>`;
        }
        html += `</div></div>`;
    }

    container.innerHTML = html;
}

function cellDown(e, d, h) {
    e.preventDefault();
    isDragging = true;
    const cur = schedules[selectedId][d][h];
    dragState = cur === 'allowed' ? 'blocked' : 'allowed';
    setSlot(d, h, dragState);
}
function cellEnter(d, h) {
    if (isDragging && dragState) setSlot(d, h, dragState);
}
function setSlot(d, h, state) {
    schedules[selectedId][d][h] = state;
    const cells = document.querySelectorAll('.pc-sched-cells')[d];
    if (!cells) return;
    const cell = cells.children[h];
    if (cell) {
        cell.className = `pc-cell ${state}`;
        cell.title = `${String(h).padStart(2,'0')}h — ${state==='allowed'?'Autorisé':'Bloqué'}`;
    }
}
function setDayState(d, state) {
    schedules[selectedId][d] = Array(24).fill(state);
    renderSchedule();
}

// ── Filter modal ──────────────────────────────────────────────────────
function openFilters(forceId) {
    if (forceId && forceId !== selectedId) {
        selectedId = forceId;
        renderDevices();
        renderSchedule();
        updateSchedMeta();
    }
    const dev = devices.find(d => d.id === selectedId);
    if (!dev) return;
    updateModalIdentity(dev);
    renderQuotaLivePlaceholder('en attente');
    renderModalState(dev);
    switchFilterPanel('filters');
    renderQuotaCard();
    renderCategoryHelp();
    const modal = document.getElementById('pc-modal');
    if (modal) modal.classList.remove('is-hidden');
}

function updateModalIdentity(dev) {
    const titleEl = document.getElementById('modal-title');
    const iconI = document.getElementById('pc-modal-device-icon-i');
    const statusEl = document.getElementById('pc-modal-head-status');
    const statusDot = document.querySelector('.pc-modal-head-sub .dot');
    const macEl = document.getElementById('pc-identity-mac');
    const typeEl = document.getElementById('pc-identity-type');
    const forumLink = document.getElementById('pc-forum-link');

    const icon = DEVICE_ICONS[dev.deviceType] || 'fa-desktop';
    const typeLabel = DEVICE_TYPE_LABELS[dev.deviceType] || 'Appareil';
    const online = isDeviceOnline(dev);

    if (titleEl) titleEl.textContent = dev.name;
    if (iconI) iconI.className = `fas ${icon}`;
    if (statusEl) statusEl.textContent = online ? 'Connecté' : 'Hors ligne';
    if (statusDot) statusDot.classList.toggle('offline', !online);
    if (macEl) macEl.textContent = dev.mac || '—';
    if (typeEl) typeEl.textContent = typeLabel;

    if (forumLink) {
        forumLink.href = 'https://192.168.1.174/public/forum.php?cat=2';
    }
}
function closeFilters() {
    const modal = document.getElementById('pc-modal');
    if (modal) modal.classList.add('is-hidden');
    stopQuotaLivePolling();
}
function closeFiltersOutside(e) {
    if (e.target === document.getElementById('pc-modal')) closeFilters();
}

function switchFilterPanel(panel) {
    currentFilterPanel = panel;

    const panels = {
        filters: document.getElementById('pc-panel-filters'),
        quota: document.getElementById('pc-panel-quota')
    };
    const navButtons = {
        filters: document.getElementById('pc-nav-filters'),
        quota: document.getElementById('pc-nav-quota')
    };

    Object.keys(panels).forEach(key => {
        const node = panels[key];
        if (!node) return;
        node.classList.toggle('active', key === panel);
    });

    Object.keys(navButtons).forEach(key => {
        const node = navButtons[key];
        if (!node) return;
        node.classList.toggle('active', key === panel);
    });

    const modal = document.getElementById('pc-modal');
    const modalOpen = !!modal && !modal.classList.contains('is-hidden');
    if (!modalOpen) {
        stopQuotaLivePolling();
        return;
    }

    if (panel === 'quota') {
        startQuotaLivePolling();
    } else {
        stopQuotaLivePolling();
    }
}

function renderModalState(dev) {
    ['child','teen','adult'].forEach(m => {
        document.getElementById('mode-'+m).classList.toggle('active', dev.mode === m);
    });
    const f = filtersState[dev.id];
    ['adult','games','streaming','social','ads'].forEach(key => {
        document.getElementById('filter-'+key).checked = !!f[key];
    });
}

function setMode(mode) {
    const dev = devices.find(d => d.id === selectedId);
    if (!dev) return;
    dev.mode = normalizeMode(mode);
    filtersState[dev.id] = {...MODE_FILTERS[dev.mode]};
    renderModalState(dev);
    updateSchedMeta();
    renderDevices();
    renderCategoryHelp();
    updateModalIdentity(dev);
}

function toggleFilter(key, checked) {
    const dev = devices.find(d => d.id === selectedId);
    if (!dev) return;
    dev.mode = normalizeMode(dev.mode);
    filtersState[dev.id][key] = checked;
    renderModalState(dev);
    updateSchedMeta();
    renderDevices();
    renderCategoryHelp();
}

// ── History collapse 
let histCollapsed = false;
function toggleHistory() {
    histCollapsed = !histCollapsed;
    document.getElementById('hist-body').style.display    = histCollapsed ? 'none' : '';
    document.getElementById('hist-chevron').className = histCollapsed ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
}

async function loadBlockedHistory() {
    const body = document.getElementById('blocked-history-body');
    if (!body) return;

    body.innerHTML = `<tr><td colspan="4" style="text-align:center;color:var(--color-text-light);padding:20px;">Chargement...</td></tr>`;

    try {
        const resp = await fetch(`${API_DOMAIN_LISTS_URL}?action=recent&limit=10&include_reasons=1`, { cache: 'no-store' });
        const raw = await resp.text();
        let data = null;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            body.innerHTML = `<tr><td colspan="4" style="text-align:center;color:#e74c3c;padding:20px;">API invalide (non JSON)</td></tr>`;
            return;
        }

        if (!data.success) {
            body.innerHTML = `<tr><td colspan="4" style="text-align:center;color:#e74c3c;padding:20px;">${escapeHtml(data.error || 'Erreur API')}</td></tr>`;
            return;
        }

        if (!Array.isArray(data.rows) || data.rows.length === 0) {
            body.innerHTML = `<tr><td colspan="4" style="text-align:center;color:var(--color-text-light);padding:20px;">Aucun blocage récent.</td></tr>`;
            return;
        }

        body.innerHTML = data.rows.map(row => {
            const ts = formatHistoryTimestamp(row.ts);
            const host = row.host || '—';
            const reason = normalizeReasonLabel(row.reason_label || row.reason || 'blocked');
            const device = row.device_name
                ? `${row.device_name} <span style="color:var(--color-text-light);font-size:11px;">(${row.ip || '—'})</span>`
                : (row.ip ? row.ip : '—');
            return `
                <tr>
                    <td class="hist-mono">${escapeHtml(ts)}</td>
                    <td>${device}</td>
                    <td class="hist-domain">${escapeHtml(host)}</td>
                    <td><span class="hist-reason">${escapeHtml(reason)}</span></td>
                </tr>`;
        }).join('');
    } catch (e) {
        body.innerHTML = `<tr><td colspan="4" style="text-align:center;color:#e74c3c;padding:20px;">Erreur de chargement.</td></tr>`;
    }
}

async function addDomainToList(domain, target) {
    if (!domain) return;
    const form = new FormData();
    form.append('action', 'add');
    form.append('domain', domain);
    form.append('target', target);

    try {
        const resp = await fetch(API_DOMAIN_LISTS_URL, {
            method: 'POST',
            body: form
        });
        const data = await resp.json();

        if (data.success) {
            let msg = data.added
                ? `${domain} ajouté à ${target}`
                : `${domain} est déjà dans ${target}`;

            if (target === 'blacklist' && data.sync && data.sync.ok === false) {
                msg += ' (sync RPZ KO)';
                showToast(msg, '#e67e22');
            } else {
                showToast(msg, '#2ecc71');
            }
        } else {
            showToast('Erreur: ' + (data.error || 'inconnue'), '#e74c3c');
        }

        loadBlockedHistory();
    } catch (e) {
        showToast('Erreur réseau', '#e74c3c');
    }
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function escapeAttr(value) {
    return String(value).replace(/'/g, "\\'");
}

function normalizeReasonLabel(value) {
    const raw = String(value || 'blocked');
    const cleaned = raw
        .replace(/\s*\([^)]*\)\s*/g, ' ')
        .replace(/\s{2,}/g, ' ')
        .trim();
    return cleaned || 'blocked';
}

function formatHistoryTimestamp(value) {
    if (!value) return '—';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return String(value);
    return parsed.toLocaleString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false
    });
}

function emptyModesSummary() {
    return {
        child: { adult: 38, games: 22, streaming: 18, social: 34, ads: 27 },
        teen: { adult: 31, games: 24, streaming: 21, social: 29, ads: 23 },
        adult: { adult: 0, games: 8, streaming: 7, social: 0, ads: 11 }
    };
}

function normalizeModesSummary(rawModes) {
    const normalized = emptyModesSummary();
    if (!rawModes || typeof rawModes !== 'object') {
        return normalized;
    }

    ['child', 'teen', 'adult'].forEach(mode => {
        const source = rawModes[mode];
        if (!source || typeof source !== 'object') return;
        CATEGORY_KEYS.forEach(category => {
            const value = Number.parseInt(source[category], 10);
            normalized[mode][category] = Number.isFinite(value) && value > 0 ? value : 0;
        });
    });

    return normalized;
}

function getFrequencyInfo(total) {
    if (total < 10) {
        return { label: 'Faible', className: 'freq-low' };
    }
    if (total < 25) {
        return { label: 'Moyenne', className: 'freq-medium' };
    }
    return { label: 'Élevée', className: 'freq-high' };
}

function formatUpdatedAt(value) {
    if (!value) return '—';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return String(value);
    return parsed.toLocaleString('fr-FR');
}

function renderCategoryHelp() {
    const container = document.getElementById('pc-category-help-body');
    const updatedAtEl = document.getElementById('pc-stats-updated-at');
    if (!container) return;

    const modes = normalizeModesSummary(statsSummary && statsSummary.modes);
    const dev = devices.find(d => d.id === selectedId);
    const selectedMode = normalizeMode(dev ? dev.mode : 'child');

    container.innerHTML = CATEGORY_KEYS.map(category => {
        const value = (modes[selectedMode] && modes[selectedMode][category]) ? modes[selectedMode][category] : 0;
        const freq = getFrequencyInfo(value);
        const meta = CATEGORY_META[category];
        return `
            <div class="pc-activity-row">
                <span class="pc-activity-name">${escapeHtml(meta.label)}</span>
                <span class="pc-activity-badge ${freq.className}" title="${value} blocages">${freq.label}</span>
            </div>`;
    }).join('');

    if (updatedAtEl) {
        updatedAtEl.textContent = formatUpdatedAt(statsSummary && statsSummary.updated_at);
    }
}

async function loadStatsSummary() {
    const updatedAtEl = document.getElementById('pc-stats-updated-at');

    try {
        const response = await fetch(`${API_PARENTAL_URL}?action=stats_summary`, { cache: 'no-store' });
        const raw = await response.text();
        let data = null;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            data = null;
        }

        if (!data || !data.success) {
            throw new Error((data && data.error) || 'Erreur API stats');
        }

        statsSummary = {
            modes: data.modes || {},
            updated_at: data.updated_at || null
        };
        renderCategoryHelp();
    } catch (error) {
        if (updatedAtEl) {
            updatedAtEl.textContent = 'Pré-rempli (hors ligne)';
        }
        renderCategoryHelp();
    }
}

// ── Save schedule & filters to Backend 
async function persistSelectedDeviceConfig(options = {}) {
    const {
        successMessage = 'Sauvegardé et appliqué sur la box ! ✓',
        closeModalOnSuccess = false,
        strictQuotaValidation = true
    } = options;

    const dev = devices.find(d => d.id === selectedId);
    if (!dev) return false;

    const quotaInputValue = getQuotaInputValue();
    if (quotaInputValue === null && strictQuotaValidation) {
        showToast('Quota invalide (valeur >= 0 attendue).', '#e74c3c');
        return false;
    }

    if (quotaInputValue !== null) {
        dev.download_quota_gb = quotaInputValue;
    } else {
        dev.download_quota_gb = normalizeQuota(dev.download_quota_gb);
    }

    const payload = {
        mac: dev.mac,
        name: dev.name,
        mode: dev.mode,
        filters: filtersState[dev.id],
        schedule: schedules[dev.id],
        download_quota_gb: dev.download_quota_gb
    };

    try {
        const response = await fetch(API_PARENTAL_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const result = await response.json();
        if (!result.success) {
            showToast('Erreur : ' + (result.error || 'Inconnue'), '#e74c3c');
            return false;
        }

        dev.download_quota_gb = normalizeQuota(result.download_quota_gb ?? dev.download_quota_gb);
        if (typeof savedConfigs !== 'undefined' && dev.mac) {
            if (!savedConfigs[dev.mac]) savedConfigs[dev.mac] = {};
            savedConfigs[dev.mac].download_quota_gb = dev.download_quota_gb;
            savedConfigs[dev.mac].mode = dev.mode;
            savedConfigs[dev.mac].filters = filtersState[dev.id];
            savedConfigs[dev.mac].schedule = schedules[dev.id];
        }

        if (result.stats_summary && result.stats_summary.modes) {
            statsSummary = {
                modes: result.stats_summary.modes,
                updated_at: result.stats_summary.updated_at || null
            };
            renderCategoryHelp();
        }

        if (result.quota_status && result.quota_status.success) {
            renderQuotaLiveState(result.quota_status);
        } else if (currentFilterPanel === 'quota') {
            await loadQuotaStatus();
        }

        renderQuotaCard();
        showToast(successMessage, '#2ecc71');
        await loadStatsSummary();
        if (closeModalOnSuccess) {
            closeFilters();
        }
        return true;
    } catch (error) {
        console.error("Erreur de connexion avec l'API :", error);
        alert("Erreur réseau : Impossible de contacter la MBox.");
        return false;
    }
}

async function saveSchedule() {
    await persistSelectedDeviceConfig({
        successMessage: 'Sauvegardé et appliqué sur la box ! ✓',
        closeModalOnSuccess: false,
        strictQuotaValidation: true
    });
}

async function finishFilters() {
    await persistSelectedDeviceConfig({
        successMessage: 'Filtres sauvegardés ✓',
        closeModalOnSuccess: true,
        strictQuotaValidation: false
    });
}
// ── Init ──────────────────────────────────────────────────────────────
renderDevices();
renderSchedule();
updateSchedMeta();
renderQuotaCard();
renderCategoryHelp();
loadBlockedHistory();
loadStatsSummary();
