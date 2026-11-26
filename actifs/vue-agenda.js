/* socle-php — vue d'agenda (src/vue_agenda.php, actifs/vue-agenda.css, 2.6) : clic pour créer, sans dépendance.
   Sur une colonne de la semaine qui porte data-creer, un repère « + 14h15 » suit la souris au pas choisi (data-pas) ; un
   clic hors d'un bloc, d'un lien ou d'un bouton crée : si data-creer vaut « #id », le <dialog> de cet id est rempli (champs
   data-vue-agenda="jour", "debut", "fin" — la fin vaut le début plus data-duree minutes, 60 par défaut —, et le texte de
   data-vue-agenda="libelle-jour") puis ouvert ; sinon la page va à cette adresse avec « heure=HH:MM ». Une case du mois
   qui porte data-creer fait de même, sans heure. Un bouton data-vue-agenda-fermer referme le dialogue. Sans JavaScript, rien
   ne se crée au clic : le projet garde un formulaire ordinaire. */
(function () {
  'use strict';
  var deux = function (n) { return (n < 10 ? '0' : '') + n; };
  var hhmm = function (m) { m = Math.max(0, Math.min(m, 23 * 60 + 59)); return deux(Math.floor(m / 60)) + ':' + deux(m % 60); };
  var horsCible = function (el) { return !!el.closest('a, button, input, select, textarea, label, form, summary, details, .vue-agenda-bloc, .vue-agenda-puce'); };

  function vise(col, clientY) {
    var px = parseFloat(col.dataset.pxHeure) || 48, pas = parseInt(col.dataset.pas, 10) || 15, hmin = parseInt(col.dataset.heureMin, 10) || 0;
    var m = hmin * 60 + Math.floor(((clientY - col.getBoundingClientRect().top) / px * 60) / pas) * pas;
    m = Math.max(hmin * 60, Math.min(m, 24 * 60 - pas));
    return { minutes: m, top: (m - hmin * 60) * px / 60 };
  }

  function creer(el, minutes) {
    var cible = el.dataset.creer || '';
    if (cible.charAt(0) === '#') {
      var d = document.querySelector(cible);
      if (!d) return;
      d.querySelectorAll('[data-vue-agenda="jour"]').forEach(function (c) { c.value = el.dataset.jour; });
      if (minutes !== null) {
        d.querySelectorAll('[data-vue-agenda="debut"]').forEach(function (c) { c.value = hhmm(minutes); });
        d.querySelectorAll('[data-vue-agenda="fin"]').forEach(function (c) { c.value = hhmm(minutes + (parseInt(c.dataset.duree, 10) || 60)); });
      }
      d.querySelectorAll('[data-vue-agenda="libelle-jour"]').forEach(function (c) { c.textContent = el.dataset.libelle || el.dataset.jour; });
      if (typeof d.showModal === 'function') d.showModal(); else d.setAttribute('open', '');
      var premier = d.querySelector('[autofocus]') || d.querySelector('input:not([type=hidden]), select, button');
      if (premier) premier.focus();
    } else if (cible !== '') {
      window.location.href = cible + (minutes === null ? '' : (cible.indexOf('?') < 0 ? '?' : '&') + 'heure=' + encodeURIComponent(hhmm(minutes)));
    }
  }

  document.querySelectorAll('.vue-agenda-colonne[data-creer]').forEach(function (col) {
    var repere = document.createElement('div');
    repere.className = 'vue-agenda-repere';
    repere.setAttribute('aria-hidden', 'true');
    repere.hidden = true;
    col.appendChild(repere);
    col.addEventListener('mousemove', function (ev) {
      if (horsCible(ev.target)) { repere.hidden = true; return; }
      var v = vise(col, ev.clientY);
      repere.hidden = false;
      repere.style.top = v.top + 'px';
      repere.textContent = '+ ' + hhmm(v.minutes).replace(':', 'h');
    });
    col.addEventListener('mouseleave', function () { repere.hidden = true; });
    col.addEventListener('click', function (ev) { if (!horsCible(ev.target)) creer(col, vise(col, ev.clientY).minutes); });
  });

  document.querySelectorAll('.vue-agenda-mois td[data-creer]').forEach(function (td) {
    td.addEventListener('click', function (ev) { if (!horsCible(ev.target)) creer(td, null); });
  });

  document.querySelectorAll('[data-vue-agenda-fermer]').forEach(function (b) {
    b.addEventListener('click', function () { var d = b.closest('dialog'); if (d) d.close(); });
  });
})();
