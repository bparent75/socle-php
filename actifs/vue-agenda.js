/* socle-php — vue d'agenda (src/vue_agenda.php, actifs/vue-agenda.css, 2.6, 2.7) : créer, ouvrir, étirer, sans dépendance.
   Créer — sur une colonne de la semaine qui porte data-creer, un repère « + 14h15 » suit le pointeur au pas de la grille
   (data-pas) ; un clic crée à cette heure, un glisser trace la plage (« 14h15 – 15h30 »). Si data-creer vaut « #id », le
   <dialog> de cet id est rempli (champs data-vue-agenda="jour", "debut", "fin" — après un simple clic, la fin vaut le début
   plus data-duree minutes, 60 par défaut —, et le texte de data-vue-agenda="libelle-jour") puis ouvert ; sinon la page va à
   cette adresse avec « heure=HH:MM » (et « fin=HH:MM » après un glisser). Une case du mois qui porte data-creer fait de même,
   sans heure.
   Ouvrir — un bloc data-ouvrir="#id" ouvre ce <dialog> au clic, ou à Entrée et Espace (2.7).
   Étirer — la poignée d'un bloc .vue-agenda-etirable l'allonge ou le raccourcit au pas de la grille, puis ouvre son dialogue
   avec la nouvelle fin dans le champ data-vue-agenda="fin" (2.7).
   Un bouton data-vue-agenda-fermer referme son dialogue. Sans JavaScript, rien de tout cela : le projet garde des formulaires
   ordinaires. */
(function () {
  'use strict';
  var deux = function (n) { return (n < 10 ? '0' : '') + n; };
  var hhmm = function (m) { m = Math.max(0, Math.min(m, 24 * 60)); return m === 24 * 60 ? '24:00' : deux(Math.floor(m / 60)) + ':' + deux(m % 60); };
  var minutes = function (t) { var p = /^(\d{2}):(\d{2})$/.exec(t || ''); return p ? parseInt(p[1], 10) * 60 + parseInt(p[2], 10) : null; };
  var fr = function (m) { return hhmm(m).replace(':', 'h'); };
  var horsCible = function (el) { return !!el.closest('a, button, input, select, textarea, label, form, summary, details, .vue-agenda-bloc, .vue-agenda-puce'); };
  var mesures = function (col) {
    return { px: parseFloat(col.dataset.pxHeure) || 48, pas: parseInt(col.dataset.pas, 10) || 15, hmin: parseInt(col.dataset.heureMin, 10) || 0 };
  };
  // L'heure visée sous le pointeur, au pas de la grille (arrondie vers le bas, ou au plus proche pour une fin).
  var vise = function (col, clientY, auPlusProche) {
    var m = mesures(col), brut = m.hmin * 60 + (clientY - col.getBoundingClientRect().top) / m.px * 60;
    var t = (auPlusProche ? Math.round(brut / m.pas) : Math.floor(brut / m.pas)) * m.pas;
    return Math.max(m.hmin * 60, Math.min(t, 24 * 60));
  };
  var haut = function (col, t) { var m = mesures(col); return (t - m.hmin * 60) * m.px / 60; };

  function ouvrir(dialogue, remplir) {
    var d = typeof dialogue === 'string' ? document.querySelector(dialogue) : dialogue;
    if (!d) return;
    if (remplir) remplir(d);
    if (typeof d.showModal === 'function') { if (!d.open) d.showModal(); } else d.setAttribute('open', '');
    var premier = d.querySelector('[autofocus]') || d.querySelector('input:not([type=hidden]), select, button');
    if (premier) premier.focus();
  }

  function creer(el, debut, fin) {
    var cible = el.dataset.creer || '';
    if (cible.charAt(0) === '#') {
      ouvrir(cible, function (d) {
        d.querySelectorAll('[data-vue-agenda="jour"]').forEach(function (c) { c.value = el.dataset.jour; });
        if (debut !== null) {
          d.querySelectorAll('[data-vue-agenda="debut"]').forEach(function (c) { c.value = hhmm(debut); });
          d.querySelectorAll('[data-vue-agenda="fin"]').forEach(function (c) {
            c.value = hhmm(Math.min(fin !== null ? fin : debut + (parseInt(c.dataset.duree, 10) || 60), 23 * 60 + 59));
          });
        }
        d.querySelectorAll('[data-vue-agenda="libelle-jour"]').forEach(function (c) { c.textContent = el.dataset.libelle || el.dataset.jour; });
      });
    } else if (cible !== '') {
      var q = debut === null ? '' : 'heure=' + encodeURIComponent(hhmm(debut)) + (fin !== null ? '&fin=' + encodeURIComponent(hhmm(fin)) : '');
      window.location.href = cible + (q === '' ? '' : (cible.indexOf('?') < 0 ? '?' : '&') + q);
    }
  }

  // ---- Créer : clic ou glisser sur une colonne ----
  document.querySelectorAll('.vue-agenda-colonne[data-creer]').forEach(function (col) {
    var repere = document.createElement('div');
    repere.className = 'vue-agenda-repere';
    repere.setAttribute('aria-hidden', 'true');
    repere.hidden = true;
    col.appendChild(repere);
    var trace = null;   // { debut, fin, id, bouge }
    var montrer = function (debut, fin) {
      var m = mesures(col);
      repere.hidden = false;
      repere.style.top = haut(col, debut) + 'px';
      repere.style.height = Math.max(fin - debut, m.pas) * m.px / 60 + 'px';
      repere.textContent = fin > debut + m.pas ? fr(debut) + ' – ' + fr(fin) : '+ ' + fr(debut);
    };
    col.addEventListener('pointermove', function (ev) {
      if (trace) {
        var t = vise(col, ev.clientY, true), m = mesures(col);
        if (Math.abs(t - trace.debut) >= m.pas) trace.bouge = true;
        trace.fin = Math.max(t, trace.debut + m.pas);
        montrer(trace.debut, trace.fin);
        return;
      }
      if (horsCible(ev.target)) { repere.hidden = true; return; }
      montrer(vise(col, ev.clientY, false), 0);
    });
    col.addEventListener('pointerleave', function () { if (!trace) repere.hidden = true; });
    col.addEventListener('pointerdown', function (ev) {
      if (ev.button !== 0 || horsCible(ev.target)) return;
      var debut = vise(col, ev.clientY, false);
      trace = { debut: debut, fin: debut + mesures(col).pas, bouge: false };
      try { col.setPointerCapture(ev.pointerId); } catch (e) { /* ancien navigateur */ }
      ev.preventDefault();
    });
    var finir = function (ev) {
      if (!trace) return;
      var t = trace;
      trace = null;
      repere.hidden = true;
      try { col.releasePointerCapture(ev.pointerId); } catch (e) { /* déjà relâché */ }
      if (ev.type === 'pointerup') creer(col, t.debut, t.bouge ? t.fin : null);
    };
    col.addEventListener('pointerup', finir);
    col.addEventListener('pointercancel', finir);
  });

  document.querySelectorAll('.vue-agenda-mois td[data-creer]').forEach(function (td) {
    td.addEventListener('click', function (ev) { if (!horsCible(ev.target)) creer(td, null, null); });
  });

  // ---- Ouvrir : un bloc data-ouvrir ----
  var etireRecemment = false;
  document.querySelectorAll('.vue-agenda-bloc[data-ouvrir]').forEach(function (bloc) {
    bloc.addEventListener('click', function (ev) {
      if (etireRecemment || ev.target.closest('a, button, input, select, textarea, label, form, .vue-agenda-poignee')) return;
      ouvrir(bloc.dataset.ouvrir);
    });
    bloc.addEventListener('keydown', function (ev) {
      if ((ev.key === 'Enter' || ev.key === ' ') && ev.target === bloc) { ev.preventDefault(); ouvrir(bloc.dataset.ouvrir); }
    });
  });

  // ---- Étirer : la poignée d'un bloc ----
  document.querySelectorAll('.vue-agenda-etirable .vue-agenda-poignee').forEach(function (poignee) {
    var bloc = poignee.closest('.vue-agenda-bloc'), col = bloc.closest('.vue-agenda-colonne');
    if (!col) return;
    var etire = null;   // { debut, fin, hauteur }
    poignee.addEventListener('pointerdown', function (ev) {
      if (ev.button !== 0) return;
      ev.preventDefault();
      ev.stopPropagation();
      etire = { debut: minutes(bloc.dataset.debut), fin: minutes(bloc.dataset.fin), hauteur: bloc.style.height };
      bloc.classList.add('etire');
      try { poignee.setPointerCapture(ev.pointerId); } catch (e) { /* ancien navigateur */ }
    });
    poignee.addEventListener('pointermove', function (ev) {
      if (!etire) return;
      var m = mesures(col), t = Math.max(vise(col, ev.clientY, true), etire.debut + m.pas);
      etire.fin = Math.min(t, 24 * 60);
      bloc.style.height = Math.max(18, (etire.fin - etire.debut) * m.px / 60 - 2) + 'px';
      bloc.setAttribute('data-fin-visee', fr(etire.fin));
    });
    var lacher = function (ev) {
      if (!etire) return;
      var e = etire;
      etire = null;
      bloc.classList.remove('etire');
      bloc.removeAttribute('data-fin-visee');
      try { poignee.releasePointerCapture(ev.pointerId); } catch (x) { /* déjà relâché */ }
      etireRecemment = true;
      setTimeout(function () { etireRecemment = false; }, 300);
      if (ev.type !== 'pointerup' || e.fin === minutes(bloc.dataset.fin)) { bloc.style.height = e.hauteur; return; }
      if (bloc.dataset.ouvrir) {
        ouvrir(bloc.dataset.ouvrir, function (d) {
          d.querySelectorAll('[data-vue-agenda="fin"]').forEach(function (c) { c.value = hhmm(e.fin); });
        });
        var d = document.querySelector(bloc.dataset.ouvrir);
        if (d) d.addEventListener('close', function () { bloc.style.height = e.hauteur; }, { once: true });
      }
      bloc.dispatchEvent(new CustomEvent('vue-agenda:etire', { bubbles: true, detail: { debut: hhmm(e.debut), fin: hhmm(e.fin) } }));
    };
    poignee.addEventListener('pointerup', lacher);
    poignee.addEventListener('pointercancel', lacher);
  });

  document.querySelectorAll('[data-vue-agenda-fermer]').forEach(function (b) {
    b.addEventListener('click', function () { var d = b.closest('dialog'); if (d) d.close(); });
  });
})();
