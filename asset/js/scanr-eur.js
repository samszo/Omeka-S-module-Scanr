
(function () {
'use strict';

// ── Constantes EUR ────────────────────────────────────────────────────────
const EURS = [
    {'id':'arts', 'label':'Arts, créations, technologies & industries culturelles','icon':'🎨'},
    {'id':'transitions', 'label':'Transitions numériques, écologiques & économiques','icon':'🌱'},
    {'id':'care', 'label':'Care – prendre soin : santé mentale, handicap, migrations','icon':'💙'},
    {'id':'democratie', 'label':'Enjeux démocratiques contemporains, politiques publiques, risques géopolitiques','icon':'🏛️'}
];


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

    //création des élément de la grille
    let grid = d3.select("#scanr-eur-grid").selectAll("div").data(EURS).enter().append('div')
        .attr('class',"scanr-eur-col")
        .attr('id',(d,i)=>"scanr-eur-col-"+d.id),
    col = grid.append("div").attr('class',"scanr-eur-col-header")
        .attr('id',(d,i)=>"scanr-eur-col-"+d.id);
    col.append("span").attr('class',"scanr-eur-icon").html(d=>d.icon);
    col.append("span").attr('class',"scanr-eur-col-title").html(d=>d.label);
    grid.append("div").attr('class',"scanr-eur-col-body")
            .attr('id',(d,i)=>"scanr-eur-body-"+d.id)
            .append('p').attr('class',"scanr-eur-placeholder").html("Cliquez sur « Evaluer » pour lancer l'analyse.");
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
    console.log(evaluations);

    let flatEvals = [];
    evaluations.forEach(e=>{
        e.axes.forEach(a=>{
            flatEvals.push({'id':e.id,'axe':a,'eur':'arts','score':e.scores.arts});
            flatEvals.push({'id':e.id,'axe':a,'eur':'care','score':e.scores.care});
            flatEvals.push({'id':e.id,'axe':a,'eur':'democratie','score':e.scores.democratie});
            flatEvals.push({'id':e.id,'axe':a,'eur':'transitions','score':e.scores.transitions});
        })
    })
    console.log(flatEvals);


    let grEurAxes = Array.from(d3.group(flatEvals, (d) => d.eur, (d) => d.axe)),
        sumEurAxes = Array.from(d3.rollup(flatEvals, v => d3.sum(v, d => d.score), d => d.eur, d => d.axe));


    grEurAxes.forEach(eur=> {
        let evals = flatEvals.filter(e=>e.eur==eur[0]);
        eur.axes = Array.from(eur[1]);
        eur.cumul = d3.sum(evals,d=>d.score);
        eur.avg = Math.round(eur.cumul / evals.length);
        eur.class = eur.avg >= 70 ? 'high' : eur.avg >= 40 ? 'mid' : 'low';

        let summaryHtml = '<div class="scanr-eur-axis-summary">'
            + '<div class="scanr-eur-axis-scores">'
            +   '<span class="scanr-eur-cumul">Cumulé <strong>' + eur.cumul + '</strong></span>'
            +   '<span class="scanr-eur-score ' + eur.class + '">Moy. ' + eur.avg + '/100</span>'
            + '</div>'
            + '<div class="scanr-eur-score-bar">'
            +   '<div class="scanr-eur-score-fill ' + eur.class + '" style="width:' + eur.avg + '%"></div>'
            + '</div>'
            + '</div>',
        body = d3.select('#scanr-eur-body-' + eur[0]);
        body.html(summaryHtml);
        
        body.selectAll("details").data(eur.axes).enter().append("details")
            .attr("class","scanr-eur-details")
            .append("summary").html(axe=>{
                axe.cumul = d3.sum(axe[1],d=>d.score);
                axe.avg = Math.round(axe.cumul / axe[1].length);
                axe.class = axe.avg >= 70 ? 'high' : axe.avg >= 40 ? 'mid' : 'low';
                let html = '<div class="scanr-eur-axis-summary"><span ><strong>' + axe[0] + '</strong> '+axe[1].length+' chercheur.euse(s)</span>'
                + '<div class="scanr-eur-axis-scores">'
                +   '<span class="scanr-eur-cumul">Cumulé <strong>' + axe.cumul + '</strong></span>'
                +   '<span class="scanr-eur-score ' + axe.class + '">Moy. ' + axe.avg + '/100</span>'
                + '</div>'
                + '<div class="scanr-eur-score-bar">'
                +   '<div class="scanr-eur-score-fill ' + axe.class + '" style="width:' + axe.avg + '%"></div>'
                + '</div>'
                + '</div>';
                
                return html;
            })
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
