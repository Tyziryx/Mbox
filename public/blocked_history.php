<?php
$current_page = 'parental';
$page_title = 'Historique complet des blocages — MBox Admin';
require_once __DIR__ . '/../includes/auth.php';

$script_dir_url = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/.');
$api_domain_lists_url = ($script_dir_url === '' ? '' : $script_dir_url) . '/api_domain_lists.php';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="pc-container">
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-clock-rotate-left text-danger inline-icon-12"></i>Historique complet des blocages
        </h1>
        <a href="parental.php" class="btn btn-primary">
            <i class="fas fa-arrow-left"></i>&nbsp;Retour au contrôle parental
        </a>
    </div>

    <div class="card mb-16">
        <div class="grid-3col-auto">
            <input type="text" id="hist-search" placeholder="Filtrer par domaine" class="search-bar-full" />
            <button class="pc-btn pc-btn-ghost" onclick="applySearch()"><i class="fas fa-search"></i> Rechercher</button>
            <button class="pc-btn pc-btn-ghost" onclick="reloadHistory()"><i class="fas fa-rotate"></i> Rafraichir</button>
        </div>
        <div class="mt-10 text-12 text-muted" id="hist-meta">Chargement...</div>
    </div>

    <div class="card border-top-danger">
        <div class="scroll-y-70vh">
            <table class="hist-table w-100">
                <thead>
                    <tr>
                        <th>Date/Heure</th>
                        <th>Appareil</th>
                        <th>Domaine bloqué</th>
                        <th>Raison</th>
                    </tr>
                </thead>
                <tbody id="hist-all-body">
                    <tr><td colspan="4" class="tbl-empty">Chargement...</td></tr>
                </tbody>
            </table>
        </div>

        <div class="flex-between mt-14">
            <button class="pc-btn pc-btn-ghost" id="hist-prev" onclick="prevPage()"><i class="fas fa-chevron-left"></i> Précédent</button>
            <div class="text-13 text-muted" id="hist-page-info">Page 1</div>
            <button class="pc-btn pc-btn-ghost" id="hist-next" onclick="nextPage()">Suivant <i class="fas fa-chevron-right"></i></button>
        </div>
    </div>
</div>

<script>
const API_DOMAIN_LISTS_URL = <?= json_encode($api_domain_lists_url) ?>;
let histLimit = 100;
let histOffset = 0;
let histTotal = 0;
let histQuery = '';

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
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

function formatDevice(row) {
    if (row.device_name) {
        return `${escapeHtml(row.device_name)} <span class="text-muted text-11">(${escapeHtml(row.ip || '—')})</span>`;
    }
    if (row.ip) {
        return escapeHtml(row.ip);
    }
    return '—';
}

async function loadHistory() {
    const body = document.getElementById('hist-all-body');
    const meta = document.getElementById('hist-meta');

    body.innerHTML = `<tr><td colspan="4" class="tbl-empty">Chargement...</td></tr>`;

    const url = `${API_DOMAIN_LISTS_URL}?action=history_all&limit=${histLimit}&offset=${histOffset}&q=${encodeURIComponent(histQuery)}`;
    try {
        const resp = await fetch(url, { cache: 'no-store' });
        const data = await resp.json();

        if (!data.success) {
            body.innerHTML = `<tr><td colspan="4" class="tbl-error">${escapeHtml(data.error || 'Erreur API')}</td></tr>`;
            meta.textContent = 'Erreur API';
            return;
        }

        const rows = Array.isArray(data.rows) ? data.rows : [];
        const pg = data.pagination || {};
        histTotal = Number(pg.total || 0);

        if (rows.length === 0) {
            body.innerHTML = `<tr><td colspan="4" class="tbl-empty">Aucun blocage trouvé.</td></tr>`;
        } else {
            body.innerHTML = rows.map(row => {
                const ts = formatHistoryTimestamp(row.ts);
                const host = row.host || '—';
                const reason = normalizeReasonLabel(row.reason_label || row.reason || 'blocked');
                return `
                <tr>
                    <td class="hist-mono">${escapeHtml(ts)}</td>
                    <td>${formatDevice(row)}</td>
                    <td class="hist-domain">${escapeHtml(host)}</td>
                    <td><span class="hist-reason">${escapeHtml(reason)}</span></td>
                </tr>`;
            }).join('');
        }

        const from = histTotal === 0 ? 0 : histOffset + 1;
        const to = Math.min(histOffset + rows.length, histTotal);
        meta.textContent = `Affichage ${from}-${to} sur ${histTotal}`;

        const pageNum = Math.floor(histOffset / histLimit) + 1;
        const pageCount = Math.max(1, Math.ceil(histTotal / histLimit));
        document.getElementById('hist-page-info').textContent = `Page ${pageNum} / ${pageCount}`;

        document.getElementById('hist-prev').disabled = histOffset <= 0;
        document.getElementById('hist-next').disabled = !pg.has_more;
    } catch (e) {
        body.innerHTML = `<tr><td colspan="4" class="tbl-error">Erreur réseau.</td></tr>`;
        meta.textContent = 'Erreur réseau';
    }
}

function applySearch() {
    histQuery = (document.getElementById('hist-search').value || '').trim().toLowerCase();
    histOffset = 0;
    loadHistory();
}

function reloadHistory() {
    loadHistory();
}

function nextPage() {
    histOffset += histLimit;
    loadHistory();
}

function prevPage() {
    histOffset = Math.max(0, histOffset - histLimit);
    loadHistory();
}

document.getElementById('hist-search').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
        applySearch();
    }
});

loadHistory();
</script>

</body>
</html>
