(function () {
'use strict';

// ── Constantes EUR ────────────────────────────────────────────────────────
const EUR_LABELS = {
    arts:        'Arts, créations, technologies & industries culturelles',
    transitions: 'Transitions numériques, écologiques & économiques',
    care:        'Care – prendre soin : santé mentale, handicap, migrations',
    democratie:  'Enjeux démocratiques contemporains, politiques publiques, risques géopolitiques',
};

// ── Init ──────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const countEl = document.getElementById('scanr-eur-count');
    if (countEl && EUR_ITEMS.length) {
        countEl.textContent = EUR_ITEMS.length + ' chercheur' + (EUR_ITEMS.length > 1 ? 's' : '') + ' chargé' + (EUR_ITEMS.length > 1 ? 's' : '');
    }

    const btn = document.getElementById('scanr-eur-btn-evaluate');
    if (btn) {
        btn.addEventListener('click', runEvaluation);
    }
});

// ── Evaluation ────────────────────────────────────────────────────────────
async function runEvaluation() {
    const btn = document.getElementById('scanr-eur-btn-evaluate');
    btn.disabled = true;

    setLoading(true);
    clearError();
    clearColumns();

    const itemIds = EUR_ITEMS.map(function (i) { return i.id; });

    try {
        const res  = await fetch(EUR_AJAX_URL + '?action=evaluate', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'evaluate', item_ids: itemIds }),
        });
        const data = await res.json();

        if (!data.ok) {
            showError(data.message || 'Erreur inconnue');
            return;
        }

        renderEvaluations(data.evaluations || []);

    } catch (e) {
        showError('Erreur réseau : ' + e.message);
    } finally {
        setLoading(false);
        btn.disabled = false;
    }
}

// ── Rendu des résultats ───────────────────────────────────────────────────
function renderEvaluations(evaluations) {
    if (!evaluations.length) {
        showError('Aucun résultat retourné par l\'agent.');
        return;
    }

    EUR_IDS.forEach(function (eurId) {
        const sorted = evaluations
            .filter(function (e) { return e.scores && typeof e.scores[eurId] === 'number'; })
            .sort(function (a, b) { return b.scores[eurId] - a.scores[eurId]; })
            .slice(0, EUR_MAX);

        const body = document.getElementById('scanr-eur-body-' + eurId);
        if (!body) return;

        if (!sorted.length) {
            body.innerHTML = '<p class="scanr-eur-placeholder">Aucun résultat.</p>';
            return;
        }

        // Score cumulé et moyenne
        const cumul    = sorted.reduce(function (acc, ev) { return acc + ev.scores[eurId]; }, 0);
        const avg      = Math.round(cumul / sorted.length);
        const avgClass = avg >= 70 ? 'high' : avg >= 40 ? 'mid' : 'low';

        // Résumé des justifications (une ligne par chercheur)
        const justItems = sorted
            .filter(function (ev) { return ev.justification; })
            .map(function (ev) {
                return '<li><strong>' + esc(ev.name || '') + '</strong> — ' + esc(ev.justification || '') + '</li>';
            }).join('');

        const summaryHtml = '<div class="scanr-eur-axis-summary">'
            + '<div class="scanr-eur-axis-scores">'
            +   '<span class="scanr-eur-cumul">Cumulé <strong>' + cumul + '</strong></span>'
            +   '<span class="scanr-eur-score ' + avgClass + '">Moy. ' + avg + '/100</span>'
            + '</div>'
            + '<div class="scanr-eur-score-bar">'
            +   '<div class="scanr-eur-score-fill ' + avgClass + '" style="width:' + avg + '%"></div>'
            + '</div>'
            + (justItems ? '<ul class="scanr-eur-just-list">' + justItems + '</ul>' : '')
            + '</div>';

        const n = sorted.length;
        const cardsHtml = sorted.map(function (ev) { return renderResearcherCard(ev, eurId); }).join('');
        const detailsLabel = n + ' chercheur' + (n > 1 ? 's' : '');

        body.innerHTML = summaryHtml
            + '<details class="scanr-eur-details">'
            + '<summary>' + detailsLabel + '</summary>'
            + cardsHtml
            + '</details>';
    });

    toast('Evaluation terminée — ' + evaluations.length + ' chercheur' + (evaluations.length > 1 ? 's' : '') + ' analysé' + (evaluations.length > 1 ? 's' : ''), 'ok');
}

function renderResearcherCard(ev, eurId) {
    const score      = ev.scores[eurId];
    const scoreClass = score >= 70 ? 'high' : score >= 40 ? 'mid' : 'low';
    const name       = esc(ev.name || '');
    const just       = esc(ev.justification || '');

    return `<div class="scanr-eur-card ${scoreClass}">
        <div class="scanr-eur-card-top">
            <span class="scanr-eur-card-name">${name}</span>
            <span class="scanr-eur-score ${scoreClass}">${score}</span>
        </div>
        ${just ? `<p class="scanr-eur-justification">${just}</p>` : ''}
        <div class="scanr-eur-score-bar">
            <div class="scanr-eur-score-fill ${scoreClass}" style="width:${score}%"></div>
        </div>
    </div>`;
}

// ── UI helpers ────────────────────────────────────────────────────────────
function setLoading(on) {
    const el = document.getElementById('scanr-eur-loading');
    if (el) el.style.display = on ? '' : 'none';
    const grid = document.getElementById('scanr-eur-grid');
    if (grid) grid.style.opacity = on ? '0.4' : '1';
}

function clearColumns() {
    EUR_IDS.forEach(function (eurId) {
        const body = document.getElementById('scanr-eur-body-' + eurId);
        if (body) body.innerHTML = '<p class="scanr-eur-placeholder">Analyse en cours…</p>';
    });
}

function clearError() {
    const el = document.getElementById('scanr-eur-error');
    if (el) { el.style.display = 'none'; el.textContent = ''; }
}

function showError(msg) {
    const el = document.getElementById('scanr-eur-error');
    if (el) { el.textContent = msg; el.style.display = ''; }
    toast(msg, 'err');
}

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

function toast(msg, type, persist) {
    const c  = document.getElementById('scanr-toasts');
    if (!c) return;
    const el = document.createElement('div');
    el.className = 'scanr-toast ' + (type || '');
    el.textContent = msg;
    c.appendChild(el);
    if (!persist) {
        setTimeout(function () {
            el.style.opacity   = '0';
            el.style.transform = 'translateX(30px)';
            setTimeout(function () { el.remove(); }, 3200);
        }, 3200);
    }
}

}());
