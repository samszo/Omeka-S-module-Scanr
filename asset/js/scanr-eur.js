
(function () {
'use strict';

// ── Constantes EUR ────────────────────────────────────────────────────────
const EURS = [
    {'id':'arts', 'label':'Arts, créations, technologies & industries culturelles','icon':'🎨'},
    {'id':'transitions', 'label':'Transitions numériques, écologiques & économiques','icon':'🌱'},
    {'id':'care', 'label':'Care – prendre soin : santé mentale, handicap, migrations','icon':'💙'},
    {'id':'democratie', 'label':'Enjeux démocratiques contemporains, politiques publiques, risques géopolitiques','icon':'🏛️'}
];
let colorCumul, colorMoyen;
let lastEvaluations = [];

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

    const btnExport = document.getElementById('scanr-eur-btn-export');
    if (btnExport) {
        btnExport.addEventListener('click', exportQuarto);
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
            body:    JSON.stringify({ action: 'evaluate', item_ids: itemIds, ia_service: (document.getElementById('scanr-eur-ia-select') || {}).value || 'claude' }),
        });
        const data = await res.json();

        if (!data.ok) {
            showError(data.message || 'Erreur inconnue');
            return;
        }

        lastEvaluations = data.evaluations || [];
        renderEvaluations(lastEvaluations);
        const btnExport = document.getElementById('scanr-eur-btn-export');
        if (btnExport) btnExport.disabled = !lastEvaluations.length;

    } catch (e) {
        showError('Erreur réseau : ' + e.message);
    } finally {
        setLoading(false);
        btn.disabled = false;
    }
}

// ── Échelle colorimétrique Turbo ─────────────────────────────────────────
function renderTurboScaleCumul(nbEval) {

    colorCumul = d3.scaleSequential([0, 100*nbEval], d3.interpolateTurbo);  
    let canvas = document.getElementById('scanr-eur-scale-cumul'),
        w = canvas.offsetWidth || canvas.parentElement.offsetWidth || 600,
        leg = legend(d3.select("#scanr-eur-scale-cumul"), colorCumul, {
                            title: "Echelle colorimétrique des cumuls",
                            width:w,
                            height:60
                          })
}

function renderTurboScaleMoyen() {
    colorMoyen = d3.scaleSequential([0, 100], d3.interpolateTurbo);  
    let canvas = document.getElementById('scanr-eur-scale-moyen'),
        w = canvas.offsetWidth || canvas.parentElement.offsetWidth || 600,
        leg = legend(d3.select("#scanr-eur-scale-cumul"), colorMoyen, {
                            title: "Echelle colorimétrique des moyennes",
                            width:w,
                            height:60
                          })
}

// ── Rendu des résultats ───────────────────────────────────────────────────
function renderEvaluations(evaluations) {
    if (!evaluations.length) {
        showError('Aucun résultat retourné par l\'agent.');
        return;
    }

    let flatEvals = [];
    evaluations.forEach(e => {
        e.axes.forEach(a => {
            flatEvals.push({'id': e.id, 'axe': a, 'eur': 'arts',        'score': e.scores.arts});
            flatEvals.push({'id': e.id, 'axe': a, 'eur': 'care',        'score': e.scores.care});
            flatEvals.push({'id': e.id, 'axe': a, 'eur': 'democratie',  'score': e.scores.democratie});
            flatEvals.push({'id': e.id, 'axe': a, 'eur': 'transitions', 'score': e.scores.transitions});
        });
    });
    renderTurboScaleMoyen();
    //renderTurboScaleCumul(evaluations.length);
    let grEurAxes = Array.from(d3.group(flatEvals, d => d.eur, d => d.axe));

    grEurAxes.forEach(eur => {
        let evals = flatEvals.filter(e => e.eur === eur[0]);
        eur.axes  = Array.from(eur[1]);
        eur.cumul = d3.sum(evals, d => d.score);
        eur.avg   = Math.round(eur.cumul / evals.length);

        const eurColor = colorMoyen(eur.avg);

        // Colorier le bas de l'en-tête de colonne avec l'échelle Turbo de l'EUR
        const bodyEl   = document.getElementById('scanr-eur-body-' + eur[0]);
        const headerEl = bodyEl && bodyEl.parentElement.querySelector('.scanr-eur-col-header');
        if (headerEl) headerEl.style.borderBottom = '4px solid ' + eurColor;

        const summaryHtml = '<div class="scanr-eur-axis-scores">'
            +   '<span class="scanr-eur-cumul" >Cumulé <strong>' + eur.cumul + '</strong> (' + evaluations.length + ' p.)</span></span>'
            +   '<span class="scanr-eur-score" style="color:' + eurColor + '">Moy. ' + eur.avg + '/100</span>'
            + '</div>'
            + '<div class="scanr-eur-score-bar">'
            +   '<div class="scanr-eur-score-fill" style="width:' + eur.avg + '%;background:' + eurColor + '"></div>'
            + '</div>';

        const body = d3.select('#scanr-eur-body-' + eur[0]);
        body.html(summaryHtml);
        const summaries = body.append("div").attr("class","scanr-eur-axis-summary").style("border-left",'4px solid ' + eurColor);
        let axes = summaries.selectAll("summary").data(eur.axes).enter().append("summary").append('div')
            .attr("class","scanr-eur-axis-summary").style("border-left",axe => {
                axe.cumul = d3.sum(axe[1], d => d.score);
                axe.avg   = Math.round(axe.cumul / axe[1].length);
                axe.color = colorMoyen(axe.avg);
                return '4px solid ' + axe.color;
            })
            .html(axe => {
                return '<span><strong>' + esc(axe[0]) + '</strong> => </span>'
                    +   '<span class="scanr-eur-cumul" >Cumulé <strong>' + axe.cumul + '</strong> (' + axe[1].length + ' p.)</span>'
                    + '<div class="scanr-eur-axis-scores">'
                    +   '<span class="scanr-eur-score" style="color:' + axe.color + '">Moy. ' + axe.avg + '/100</span>'
                    + '</div>'
                    + '<div class="scanr-eur-score-bar">'
                    +   '<div class="scanr-eur-score-fill" style="width:' + axe.avg + '%;background:' + axe.color + '"></div>'
                    + '</div>';
            }),
        details = axes.append("details").attr("class","scanr-eur-axes");
        details.selectAll("div").data(a=>a[1].sort((a, b) => b.score - a.score)).enter().append('div')
            .attr("class","scanr-eur-axis-summary").style("border-left",d => {
                const c = evaluations.filter(e=>e.id==d.id)[0];
                d.color = colorMoyen(d.score);
                d.name = c.name;
                d.just = c.justification;
                d.adminUrl = c.adminUrl;
                return '4px solid ' + d.color;
            })
            .html(d => {
                return '<span><a target="_blank" href="' + d.adminUrl + '">&#128100;</a> <strong>' + d.name + '</strong> => </span>'
                    +   '<span class="scanr-eur-cumul" >Score <strong>' + d.score + '</strong></span>'
                    + '<div class="scanr-eur-score-bar">'
                    +   '<div class="scanr-eur-score-fill" style="width:' + d.score + '%;background:' + d.color + '"></div>'
                    + '</div>'
                    + '<p>'+d.just+'</p>';
            })
        
        /*
        append("ul").selectAll("li").data(a=>a[1].sort((a, b) => b.score - a.score)).enter().append("li")
            .attr("class", "scanr-eur-details").html(d=>{
                const c = evaluations.filter(e=>e.id==d.id)[0];
                return c.name+" "+c.justification;
            })
        */
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

// ── Export Quarto Markdown ────────────────────────────────────────────────
function exportQuarto() {
    if (!lastEvaluations.length) return;

    const now      = new Date();
    const dateStr  = now.toISOString().slice(0, 10);
    const iaValue  = (document.getElementById('scanr-eur-ia-select') || {}).value || 'ia';
    const nbCherch = lastEvaluations.length;

    let qmd = `---
title: "Convergences EUR – Analyse IA"
subtitle: "Service IA : ${iaValue} — ${nbCherch} chercheur${nbCherch > 1 ? 's' : ''} analysé${nbCherch > 1 ? 's' : ''}"
date: "${dateStr}"
format:
  html:
    toc: true
    toc-depth: 3
    theme: cosmo
---

`;

    // Même logique de données que renderEvaluations
    let flatEvals = [];
    lastEvaluations.forEach(e => {
        e.axes.forEach(a => {
            flatEvals.push({'id': e.id, 'axe': a, 'eur': 'arts',        'score': e.scores.arts});
            flatEvals.push({'id': e.id, 'axe': a, 'eur': 'care',        'score': e.scores.care});
            flatEvals.push({'id': e.id, 'axe': a, 'eur': 'democratie',  'score': e.scores.democratie});
            flatEvals.push({'id': e.id, 'axe': a, 'eur': 'transitions', 'score': e.scores.transitions});
        });
    });

    const grEurAxes = Array.from(d3.group(flatEvals, d => d.eur, d => d.axe));

    grEurAxes.forEach(eur => {
        const eurMeta  = EURS.find(u => u.id === eur[0]) || { icon: '', label: eur[0] };
        const eurEvals = flatEvals.filter(e => e.eur === eur[0]);
        const eurCumul = d3.sum(eurEvals, d => d.score);
        const eurAvg   = Math.round(eurCumul / eurEvals.length);

        qmd += `## ${eurMeta.icon} ${eurMeta.label}\n\n`;
        qmd += `**Score cumulé :** ${eurCumul} — **Moyenne :** ${eurAvg}/100\n\n`;

        const axes = Array.from(eur[1]);
        axes.forEach(axe => {
            const axeResearchers = axe[1].slice().sort((a, b) => b.score - a.score);
            const axeCumul = d3.sum(axeResearchers, d => d.score);
            const axeAvg   = Math.round(axeCumul / axeResearchers.length);

            qmd += `### ${axe[0]}\n\n`;
            qmd += `**Score cumulé :** ${axeCumul} (${axeResearchers.length} p.) — **Moyenne :** ${axeAvg}/100\n\n`;

            qmd += `| Chercheur | Score | Justification |\n`;
            qmd += `|-----------|------:|---------------|\n`;
            axeResearchers.forEach(d => {
                const c    = lastEvaluations.find(e => e.id === d.id);
                const name = c && c.name ? c.name.replace(/\|/g, '\\|') : '—';
                const just = c && c.justification ? c.justification.replace(/\n/g, ' ').replace(/\|/g, '\\|') : '—';
                const url  = c && c.adminUrl ? c.adminUrl : '#';
                qmd += `| [${name}](${url}) | ${d.score} | ${just} |\n`;
            });
            qmd += '\n';
        });
    });

    const blob   = new Blob([qmd], { type: 'text/markdown;charset=utf-8' });
    const url    = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href     = url;
    anchor.download = `eur-convergences-${dateStr}.qmd`;
    document.body.appendChild(anchor);
    anchor.click();
    setTimeout(function () {
        document.body.removeChild(anchor);
        URL.revokeObjectURL(url);
    }, 100);

    toast('Export Quarto téléchargé', 'ok');
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
