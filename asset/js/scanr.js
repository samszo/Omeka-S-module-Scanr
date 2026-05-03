(function () {
'use strict';

// ── État ─────────────────────────────────────────────────────────────────
let keywords   = [],
    creatorId  = 0,
    creatorTitle = "",
    creatorAllowed  = false,
    sortState  = { field: null, dir: 1 },
    filterState= { text: '', status: 'all' };


// ── Init ─────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    if (ITEM_ID > 0) loadExpertises();
    if(!allowed){
        document.getElementById('scanr-expertises-block').remove();
        toast("Vous n'êtes pas autorisé à voir<br/>les expertises.<br/>Veuillez vous connecter.", 'err',true);    
    } 

});

document.getElementById('scanr-btn-sort-title').addEventListener('click', () => sortKeywords('title'));
document.getElementById('scanr-btn-sort-rank').addEventListener('click',  () => sortKeywords('rank'));
document.getElementById('scanr-filter-text').addEventListener('input', function () {
    filterState.text = this.value;
    applyFilter();
});
document.querySelectorAll('#scanr-expertises .scanr-chip').forEach(btn => {
    btn.addEventListener('click', function () {
        filterState.status = this.dataset.filter;
        document.querySelectorAll('#scanr-expertises .scanr-chip').forEach(b => {
            b.className = 'scanr-chip';
        });
        const sfx = filterState.status !== 'all' ? '-' + filterState.status : '';
        this.classList.add('active' + sfx);
        applyFilter();
    });
});

// Délégation d'événements sur la grille
const grid = document.getElementById('scanr-kw-grid');
grid.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    const kwId  = parseInt(btn.dataset.kwId);
    const expId = parseInt(btn.dataset.expId || '0');
    const kw    = keywords.find(k => k.value_resource_id === kwId);
    if (!kw) return;
    switch (btn.dataset.action) {
        case 'create': createExpertise(kw); break;
        case 'update': updateExpertise(kw); break;
        case 'delete': deleteExpertise(kw); break;
    }
});
grid.addEventListener('input', function (e) {
    if (e.target.type === 'range') onSlider(e);
});

async function isAllowed(){
    setLoading(true);
    let res = await fetch(`${AJAX_URL}?action=isAllowed&item_id=${ITEM_ID}`),
        js = await res.json();
    setLoading(false);

    return js.allowed;
}


// ── Chargement (loadPerson) ───────────────────────────────────────────────
async function loadExpertises() {

    setLoading(true);
    keywords = [];
    try {
        const res = await fetch(`${AJAX_URL}?action=load&item_id=${ITEM_ID}`);
        const data = await res.json();
        if (!data.ok) { showEmpty(); return; }

        creatorId = data.creatorId || 0;
        creatorTitle = data.creatorTitle || "";
        creatorAllowed = data.creatorAllowed || false;
        if (!creatorId) {
            document.getElementById('scanr-no-creator').style.display = '';
        }

        keywords = data.keywords || [];
        if (!keywords.length) { showEmpty(); return; }

        sortKeywords('rank', -1);
        renderKeywords();
        document.getElementById('scanr-filter-bar').style.display = '';
        document.getElementById('scanr-kw-count').style.display   = '';

    } catch (e) {
        toast('Erreur : ' + e.message, 'err');
        showEmpty();
    } finally {
        setLoading(false);
    }
}

// ── Tri (sortKeywords) ───────────────────────────────────────────────────
function sortKeywords(field, dir = false) {
    if (dir !== false) {
        sortState.field = field;
        sortState.dir   = dir;
    } else {
        if (sortState.field === field) sortState.dir *= -1;
        else { sortState.field = field; sortState.dir = 1; }
    }
    keywords.sort((a, b) => {
        if (field === 'title')
            return sortState.dir * (a.display_title || '').localeCompare(b.display_title || '', 'fr');
        return sortState.dir * (a.rank - b.rank);
    });
    updateSortButtons();
    renderKeywords();
}

function updateSortButtons() {
    const t = document.getElementById('scanr-btn-sort-title');
    const r = document.getElementById('scanr-btn-sort-rank');
    t.classList.toggle('active', sortState.field === 'title');
    r.classList.toggle('active', sortState.field === 'rank');
    t.textContent = sortState.field === 'title' ? (sortState.dir > 0 ? '↑ Titre' : '↓ Titre') : '↕ Titre';
    r.textContent = sortState.field === 'rank'  ? (sortState.dir > 0 ? '↑ Rang'  : '↓ Rang')  : '↕ Rang';
}

// ── Rendu (renderKeywords + renderExpertise) ──────────────────────────────
function renderKeywords() {
    if (!keywords.length) { showEmpty(); return; }

    updateCount();
    grid.innerHTML = keywords.map(kw => renderKwCard(kw)).join('');
    applyFilter();
    setupAddKeyword();
}

function renderKwCard(kw) {
    const myExp = kw.expertises.find(e => e.creatorId == creatorId);
    const myRank = myExp ? myExp.rank : (kw.myRank || 0);

    const expertiseRows = kw.expertises.map(e => `
        <div class="scanr-rank-row">
            <span class="scanr-rank-date">${esc(e.created || '')}</span>
            <span class="scanr-rank-label">${esc(e.creatorTitle || '')}</span>
            <span id="scanr-rv-${kw.value_resource_id}-${e.creatorId}"
                  class="scanr-rank-val ${e.cls || ''}">${(e.sign || '') + e.rank}</span>
        </div>`).join('');

    const btnCreate = `<button class="scanr-btn scanr-btn-success"
        data-action="create" data-kw-id="${kw.value_resource_id}"
        style="${kw.hasExpert || !creatorAllowed ? 'display:none' : ''}">Ajouter</button>`;

    const btnUpdate = `<button class="scanr-btn scanr-btn-warn"
        data-action="update" data-kw-id="${kw.value_resource_id}"
        style="display:none">Modifier</button>`;

    const delExp = (kw.expertises.find(e => e.creatorId == creatorId && e['o:id']));
    const btnDelete = `<button class="scanr-btn scanr-btn-danger"
        data-action="delete" data-kw-id="${kw.value_resource_id}"
        data-exp-id="${delExp ? delExp['o:id'] : ''}"
        style="${kw.hasExpert ? '' : 'display:none'}">Supprimer</button>`;

    const slider = !creatorAllowed ? '' : `
        <div class="scanr-slider-wrap">
            <div class="scanr-slider-bg"></div>
            <div class="scanr-slider-zero"></div>
            <input type="range" min="-100" max="100" step="1" 
                   value="${myRank}" data-kw-id="${kw.value_resource_id}">
        </div>
        <div class="scanr-slider-labels">
            <span class="neg-lbl">−100</span><span>0</span><span class="pos-lbl">+100</span>
        </div>
        `

    return `
    <div id="scanr-kw-card-${kw.value_resource_id}" class="scanr-kw-card ${kw.cls || ''}">
        <div class="scanr-kw-name">
            ${esc(kw.display_title)}
            <span id="scanr-rv-total-${kw.value_resource_id}"
                  class="scanr-rank-val-total ${kw.cls || ''}">${(kw.sign || '') + kw.rank}</span>
        </div>
        <!--
        <div class="scanr-kw-prop-badge">${esc(kw._sourceProp || '')}</div>
        <div class="scanr-kw-meta">
            ${kw.expertises.filter(e => e['o:id']).length
                ? `<span style="color:var(--scanr-success)">● ${kw.expertises.length} expertise(s)</span>`
                : `<span style="color:var(--scanr-muted)">○ Ajouter votre expertise</span>`}
        </div>
        -->
        ${expertiseRows}
        ${slider}
        <div class="scanr-rank-button">
            ${btnCreate}
            ${btnUpdate}
            ${btnDelete}
        </div>
    </div>`;
}

// ── Ajout de keyword (autocomplete sur resource_class_id) ────────────────
let addKwReady = false;

function setupAddKeyword() {
    if (addKwReady || !creatorAllowed) return;
    addKwReady = true;

    // Injection du widget sous la barre de filtre
    const filterBar = document.getElementById('scanr-filter-bar');
    const wrap = document.createElement('div');
    wrap.id        = 'scanr-add-kw';
    wrap.className = 'scanr-add-kw';
    wrap.innerHTML = `
        <div class="scanr-autocomplete-wrap">
            <input type="text" id="scanr-add-kw-input" class="scanr-person-input"
                   placeholder="Ajouter un mot-clef…" autocomplete="off"
                   aria-autocomplete="list" aria-controls="scanr-add-kw-suggestions" />
            <ul id="scanr-add-kw-suggestions" class="scanr-suggestions" role="listbox" hidden></ul>
        </div>`;
    filterBar.after(wrap);

    const input = document.getElementById('scanr-add-kw-input');
    const sugg  = document.getElementById('scanr-add-kw-suggestions');
    let timer   = null;

    function buildUrl(term) {
        /*
        const p = new URLSearchParams({
            resource_class_id: KW_CLASS_ID,
            fulltext_search: term,
            per_page: 10,
            sort_by: 'title',
        });
        */
        const p = `property[0][property]=1&property[0][type]=in&property[0][text]=${term}&resource_class_id[]=${KW_CLASS_ID}`;

        return API_BASE + '?' + p.toString();
    }

    function renderSugg(items) {
        sugg.innerHTML = '';
        // Exclut les keywords déjà présents dans la liste
        const linked = new Set(keywords.map(k => k.value_resource_id));
        const filtered = items.filter(i => !linked.has(i['o:id']));
        if (!filtered.length) { sugg.hidden = true; return; }
        filtered.forEach(function (item) {
            const li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.textContent   = item['o:title'] || '(sans titre)';
            li.dataset.id    = item['o:id'];
            li.dataset.title = li.textContent;
            li.addEventListener('mousedown', function (e) {
                e.preventDefault();
                addKeyword(item['o:id'], li.dataset.title);
            });
            sugg.appendChild(li);
        });
        sugg.hidden = false;
    }

    async function addKeyword(conceptId, title) {
        input.value = '';
        sugg.hidden = true;
        try {
            const res = await apiPost({ action: 'addKeyword', sourceId: ITEM_ID, conceptId: parseInt(conceptId, 10) });
            if (!res.ok) { toast('Erreur : ' + (res.message || 'Ajout impossible'), 'err'); return; }
            // Ajoute un slot placeholder pour le créateur courant
            const kw = Object.assign({}, res.keyword, {
                expertises: [{
                    'o:id': null, rank: 0, cls: 'pos', sign: '',
                    creatorId, creatorTitle, created: '-', kwId: conceptId,
                }],
            });
            keywords.push(kw);
            // Insère la carte en fin de grille
            const tmp = document.createElement('div');
            tmp.innerHTML = renderKwCard(kw);
            grid.appendChild(tmp.firstElementChild);
            updateCount();
            toast('Mot-clef « ' + esc(title) + ' » ajouté', 'ok');
        } catch (e) {
            toast('Erreur : ' + e.message, 'err');
        }
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        const term = input.value.trim();
        if (term.length < 2) { sugg.hidden = true; return; }
        timer = setTimeout(function () {
            fetch(buildUrl(term))
                .then(r => r.json())
                .then(renderSugg)
                .catch(() => { sugg.hidden = true; });
        }, 250);
    });

    input.addEventListener('blur', function () {
        setTimeout(function () { sugg.hidden = true; }, 150);
    });

    input.addEventListener('keydown', function (e) {
        const lis    = sugg.querySelectorAll('li');
        const active = sugg.querySelector('li.scanr-active');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const next = active ? active.nextElementSibling : lis[0];
            if (active) active.classList.remove('scanr-active');
            if (next)   next.classList.add('scanr-active');
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const prev = active ? active.previousElementSibling : lis[lis.length - 1];
            if (active) active.classList.remove('scanr-active');
            if (prev)   prev.classList.add('scanr-active');
        } else if (e.key === 'Enter' && active) {
            e.preventDefault();
            addKeyword(active.dataset.id, active.dataset.title);
        } else if (e.key === 'Escape') {
            sugg.hidden = true;
        }
    });
}

function updateCount() {
    const count = document.getElementById('scanr-kw-count');
    if (count) {
        count.textContent = keywords.length + ' mot' + (keywords.length > 1 ? 's' : '');
        count.style.display = '';
    }
}

// ── Filtre (applyFilter) ─────────────────────────────────────────────────
function applyFilter() {
    const text = filterState.text.trim().toLowerCase();
    let visible = 0;
    keywords.forEach(kw => {
        const card = document.getElementById('scanr-kw-card-' + kw.value_resource_id);
        if (!card) return;
        const matchText = !text || (kw.display_title || '').toLowerCase().includes(text);
        let matchStatus = true;
        switch (filterState.status) {
            case 'todo': matchStatus = !kw.hasExpert; break;
            case 'done': matchStatus =  kw.hasExpert; break;
            case 'pos':  matchStatus =  kw.rank > 0;  break;
            case 'neg':  matchStatus =  kw.rank <= 0; break;
        }
        const show = matchText && matchStatus;
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const el = document.getElementById('scanr-filter-count');
    el.textContent = visible < keywords.length
        ? visible + ' / ' + keywords.length + ' affiché' + (visible > 1 ? 's' : '')
        : '';
}

// ── Slider (onSlider) ─────────────────────────────────────────────────────
function onSlider(e) {
    if(!creatorAllowed)return;
    const rank  = parseInt(e.target.value);
    const kwId  = parseInt(e.target.dataset.kwId);
    const kw    = keywords.find(k => k.value_resource_id === kwId);
    if (!kw) return;

    const cls  = rank > 0 ? 'pos' : rank < 0 ? 'neg' : '';
    const sign = rank > 0 ? '+'   : '';
    const rv   = document.getElementById(`scanr-rv-${kwId}-${creatorId}`);
    if (rv) { rv.className = 'scanr-rank-val ' + cls; rv.textContent = sign + rank; }

    document.getElementById('scanr-kw-card-' + kwId).className = 'scanr-kw-card ' + cls;

    // Affiche Ajouter ou Modifier selon état
    const card = document.getElementById('scanr-kw-card-' + kwId);
    const btnCreate = card.querySelector('[data-action="create"]');
    const btnUpdate = card.querySelector('[data-action="update"]');
    if (btnCreate) btnCreate.style.display = kw.hasExpert ? 'none' : '';
    if (btnUpdate) btnUpdate.style.display = kw.hasExpert ? '' : 'none';
}

// ── CRUD ──────────────────────────────────────────────────────────────────

function getSliderRank(kwId) {
    const card = document.getElementById('scanr-kw-card-' + kwId);
    return card ? parseInt(card.querySelector('input[type=range]').value) : 0;
}

async function createExpertise(kw) {
    if (!creatorId) { toast('Configurez votre ID évaluateur', 'err'); return; }
    const rank = getSliderRank(kw.value_resource_id);
    try {
        setCardBusy(kw.value_resource_id, true);
        const res = await apiPost({
            action:      'create',
            sourceId:    ITEM_ID,
            expertiseId: kw.value_resource_id,
            creatorId:   creatorId,
            rank:        rank,
        });
        if (!res.ok) throw new Error(res.message || 'Erreur création');
        // Recharge pour obtenir les données complètes du nouvel item
        await reloadKw(kw.value_resource_id);
        toast('Expertise ajoutée', 'ok');
    } catch (e) {
        toast('Erreur : ' + e.message, 'err');
    } finally {
        setCardBusy(kw.value_resource_id, false);
    }
}

async function updateExpertise(kw) {
    const myExp = kw.expertises.find(e => e.creatorId == creatorId && e['o:id']);
    if (!myExp) { toast('Aucune expertise à modifier', 'err'); return; }
    const rank = getSliderRank(kw.value_resource_id);
    try {
        setCardBusy(kw.value_resource_id, true);
        const res = await apiPost({ action: 'update', id: myExp['o:id'], rank });
        if (!res.ok) throw new Error(res.message || 'Erreur modification');
        myExp.rank = rank;
        myExp.cls  = rank > 0 ? 'pos' : 'neg';
        myExp.sign = rank > 0 ? '+' : '';
        refreshTotals(kw);
        toast('Expertise modifiée', 'ok');
    } catch (e) {
        toast('Erreur : ' + e.message, 'err');
    } finally {
        setCardBusy(kw.value_resource_id, false);
    }
}

async function deleteExpertise(kw) {
    const myExp = kw.expertises.find(e => e.creatorId == creatorId && e['o:id']);
    if (!myExp) { toast('Aucune expertise à supprimer', 'err'); return; }
    if (!confirm('Supprimer cette expertise ?')) return;
    try {
        setCardBusy(kw.value_resource_id, true);
        const res = await apiPost({ action: 'delete', id: myExp['o:id'] });
        if (!res.ok) throw new Error(res.message || 'Erreur suppression');
        kw.expertises = kw.expertises.filter(e => e['o:id'] !== myExp['o:id']);
        kw.hasExpert  = false;
        // Ajoute placeholder
        kw.expertises.push({ 'o:id': null, rank: 0, cls: 'pos', sign: '', 'creatorId':creatorId, 'creatorTitle': creatorTitle, created: '-', kwId: kw.value_resource_id });
        // Remplace la carte
        const card = document.getElementById('scanr-kw-card-' + kw.value_resource_id);
        if (card) card.outerHTML = renderKwCard(kw);
        refreshTotals(kw);
        toast('Expertise supprimée', 'ok');
    } catch (e) {
        toast('Erreur : ' + e.message, 'err');
    } finally {
        setCardBusy(kw.value_resource_id, false);
    }
}

// Recharge les données d'un concept depuis le serveur
async function reloadKw(kwId) {
    const res  = await fetch(`${AJAX_URL}?action=load&item_id=${ITEM_ID}`);
    const data = await res.json();
    if (!data.ok) return;
    const fresh = (data.keywords || []).find(k => k.value_resource_id === kwId);
    if (!fresh) return;
    const idx = keywords.findIndex(k => k.value_resource_id === kwId);
    if (idx !== -1) keywords[idx] = fresh;
    // Remplace la carte
    const card = document.getElementById('scanr-kw-card-' + kwId);
    if (card) card.outerHTML = renderKwCard(fresh);
}

// ── Recalcul des totaux sans re-render ────────────────────────────────────
function refreshTotals(kw) {
    kw.rank = kw.expertises.reduce((s, e) => s + (e.rank || 0), 0);
    kw.cls  = kw.rank > 0 ? 'pos' : 'neg';
    kw.sign = kw.rank > 0 ? '+' : '';
    const tot = document.getElementById('scanr-rv-total-' + kw.value_resource_id);
    if (tot) {
        tot.className   = 'scanr-rank-val-total ' + kw.cls;
        tot.textContent = kw.sign + kw.rank;
    }
    const card = document.getElementById('scanr-kw-card-' + kw.value_resource_id);
    if (card) {
        card.className = 'scanr-kw-card ' + kw.cls;
        const rv = card.querySelector('#scanr-rv-' + kw.value_resource_id + '-' + creatorId);
        if (rv) {
            const myExp = kw.expertises.find(e => e.creatorId == creatorId && e['o:id']);
            if (myExp) {
                rv.className   = 'scanr-rank-val ' + myExp.cls;
                rv.textContent = myExp.sign + myExp.rank;
            }
        }
    }
}

// ── Busy state ────────────────────────────────────────────────────────────
function setCardBusy(kwId, busy) {
    const card = document.getElementById('scanr-kw-card-' + kwId);
    if (!card) return;
    card.querySelectorAll('button').forEach(b => { b.disabled = busy; });
    const input = card.querySelector('input[type=range]');
    if (input) input.disabled = busy;
}

// ── HTTP ──────────────────────────────────────────────────────────────────
async function apiPost(body) {
    const r = await fetch(AJAX_URL + '?action=' + body.action, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(body),
    });
    return r.json();
}

// ── UI helpers ────────────────────────────────────────────────────────────
function setLoading(on) {
    document.getElementById('scanr-loading').style.display = on ? '' : 'none';
    document.getElementById('scanr-kw-grid').style.display = on ? 'none' : '';
}

function showEmpty() {
    document.getElementById('scanr-empty').style.display    = '';
    document.getElementById('scanr-kw-grid').style.display  = 'none';
    document.getElementById('scanr-filter-bar').style.display = 'none';
}

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

function toast(msg, type, persist=false) {
    const c  = document.getElementById('scanr-toasts');
    const el = document.createElement('div');
    el.className   = 'scanr-toast ' + (type || '');
    el.innerHTML = msg;
    c.appendChild(el);
    if(!persist){
        setTimeout(() => {
            el.style.opacity   = '0';
            el.style.transform = 'translateX(30px)';
            setTimeout(() => el.remove(), 3200);
        }, 3200);
    }
}

// Exposé globalement pour permettre l'appel depuis le bloc site
window.loadExpertises = loadExpertises;

})();