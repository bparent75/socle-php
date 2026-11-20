/*
 * socle-php — mini éditeur de texte (2.4), sans dépendance, à copier dans les actifs du projet avec mini-editeur.css
 * (par exemple dans deploy.sh, après composer install, depuis vendor/bparent75/socle-php/actifs/).
 * Écrit pour Libris le 20 novembre 2026 (compte rendu des séances de tutorat).
 *
 * Balisage : <textarea name="…" data-mini-editeur placeholder="…">HTML déjà nettoyé par le serveur</textarea>
 *            avec actifs/mini-editeur.css, et ce script chargé en fin de page.
 * Le champ devient une zone modifiable avec une barre : Gras, Italique, Liste à puces, Liste numérotée, Effacer la mise
 * en forme. Raccourcis ⌘B / Ctrl+B, ⌘I / Ctrl+I ; « - » ou « * » puis espace en début de ligne ouvre une liste à puces,
 * « 1. » une liste numérotée. Le collage se fait en texte brut (rien n'est importé d'un traitement de texte).
 * Le textarea, masqué, reste la valeur envoyée : du HTML que le serveur RENETTOIE toujours (texte_enrichi_nettoyer(),
 * src/texte_enrichi.php du socle) — ce script n'est pas une protection. Sans JavaScript, le textarea sert tel quel.
 */
(function () {
  'use strict';

  var ICONES = {
    puces: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false"><circle cx="4" cy="5" r="1.6"/><circle cx="4" cy="10" r="1.6"/><circle cx="4" cy="15" r="1.6"/><rect x="8" y="4.2" width="9" height="1.6" rx=".8"/><rect x="8" y="9.2" width="9" height="1.6" rx=".8"/><rect x="8" y="14.2" width="9" height="1.6" rx=".8"/></svg>',
    numeros: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false"><text x="1.2" y="7" font-size="6" font-family="system-ui, sans-serif" font-weight="700">1</text><text x="1.2" y="12.4" font-size="6" font-family="system-ui, sans-serif" font-weight="700">2</text><text x="1.2" y="17.8" font-size="6" font-family="system-ui, sans-serif" font-weight="700">3</text><rect x="8" y="4.2" width="9" height="1.6" rx=".8"/><rect x="8" y="9.2" width="9" height="1.6" rx=".8"/><rect x="8" y="14.2" width="9" height="1.6" rx=".8"/></svg>',
    effacer: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M5 4h10M10 4l-3 12M13 13l4 4M17 13l-4 4"/></svg>'
  };
  var MAC = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
  var MOD = MAC ? '⌘' : 'Ctrl+';
  var BOUTONS = [
    { cmd: 'bold', libelle: 'Gras', raccourci: MOD + 'B', html: '<b>G</b>' },
    { cmd: 'italic', libelle: 'Italique', raccourci: MOD + 'I', html: '<i>I</i>' },
    { cmd: 'insertUnorderedList', libelle: 'Liste à puces', html: ICONES.puces },
    { cmd: 'insertOrderedList', libelle: 'Liste numérotée', html: ICONES.numeros },
    { cmd: 'removeFormat', libelle: 'Effacer la mise en forme', html: ICONES.effacer, sansEtat: true }
  ];

  function equiper(champ, rang) {
    var cadre = document.createElement('div');
    cadre.className = 'mini-editeur';
    var barre = document.createElement('div');
    barre.className = 'mini-editeur-barre';
    barre.setAttribute('role', 'toolbar');
    barre.setAttribute('aria-label', 'Mise en forme');
    var zone = document.createElement('div');
    zone.className = 'mini-editeur-zone';
    zone.id = (champ.id || 'mini-editeur-' + rang) + '-zone';
    zone.contentEditable = 'true';
    zone.setAttribute('role', 'textbox');
    zone.setAttribute('aria-multiline', 'true');
    zone.setAttribute('spellcheck', 'true');
    zone.setAttribute('lang', document.documentElement.lang || 'fr');
    if (champ.placeholder) zone.dataset.placeholder = champ.placeholder;
    var etiquette = champ.id ? document.querySelector('label[for="' + champ.id + '"]') : null;
    if (etiquette) {
      if (!etiquette.id) etiquette.id = champ.id + '-etiquette';
      zone.setAttribute('aria-labelledby', etiquette.id);
      etiquette.addEventListener('click', function (e) { e.preventDefault(); zone.focus(); });
    }
    zone.innerHTML = champ.value.trim() !== '' ? champ.value : '<p><br></p>';

    var boutons = BOUTONS.map(function (b) {
      var bouton = document.createElement('button');
      bouton.type = 'button';
      bouton.className = 'mini-editeur-bouton';
      bouton.innerHTML = b.html;
      bouton.title = b.libelle + (b.raccourci ? ' (' + b.raccourci + ')' : '');
      bouton.setAttribute('aria-label', b.libelle);
      if (!b.sansEtat) bouton.setAttribute('aria-pressed', 'false');
      // mousedown sans perdre la sélection de la zone.
      bouton.addEventListener('mousedown', function (e) { e.preventDefault(); });
      bouton.addEventListener('click', function () {
        zone.focus();
        executer(b.cmd);
      });
      barre.appendChild(bouton);
      return { def: b, el: bouton };
    });

    function executer(cmd) {
      document.execCommand('defaultParagraphSeparator', false, 'p');
      document.execCommand(cmd, false, null);
      synchroniser();
      etat();
    }
    function synchroniser() {
      champ.value = zone.innerHTML;
      cadre.classList.toggle('vide', zone.textContent.trim() === '' && !zone.querySelector('li'));
    }
    function etat() {
      if (!zone.contains(document.getSelection().anchorNode)) return;
      boutons.forEach(function (b) {
        if (b.def.sansEtat) return;
        var actif = false;
        try { actif = document.queryCommandState(b.def.cmd); } catch (err) { actif = false; }
        b.el.setAttribute('aria-pressed', String(actif));
      });
    }
    // Le bloc (p, li, div) où se trouve le curseur, dans la zone.
    function blocCourant() {
      var n = document.getSelection().anchorNode;
      while (n && n !== zone) {
        if (n.nodeType === 1 && /^(P|DIV|LI)$/.test(n.nodeName)) return n;
        n = n.parentNode;
      }
      return null;
    }

    // « - » ou « * » puis espace en début de ligne : liste à puces ; « 1. » puis espace : liste numérotée. Différé d'un
    // instant : Chrome ignore une commande d'édition lancée pendant une autre (la frappe en est une).
    function transformerEnListe(bloc, longueur, commande) {
      if (!zone.contains(bloc) || bloc.nodeName === 'LI') return;
      var texte = bloc.textContent.replace(/\u00a0/g, ' ');
      if (!/^([-*•]|1[.)]) /.test(texte)) return;
      var reste = longueur;
      var parcours = document.createTreeWalker(bloc, NodeFilter.SHOW_TEXT);
      while (reste > 0 && parcours.nextNode()) {
        var n = parcours.currentNode;
        var coupe = Math.min(reste, n.data.length);
        n.data = n.data.slice(coupe);
        reste -= coupe;
      }
      if (bloc.textContent === '') bloc.innerHTML = '<br>';
      var r = document.createRange();
      r.setStart(bloc, 0);
      r.collapse(true);
      var s = document.getSelection();
      s.removeAllRanges();
      s.addRange(r);
      executer(commande);
    }

    zone.addEventListener('input', function (e) {
      if (e.inputType === 'insertText' && e.data === ' ') {
        var bloc = blocCourant();
        var texte = bloc ? bloc.textContent.replace(/\u00a0/g, ' ') : '';
        var liste = /^[-*•] $/.test(texte) ? 'insertUnorderedList' : /^1[.)] $/.test(texte) ? 'insertOrderedList' : null;
        if (liste && bloc.nodeName !== 'LI') {
          var longueur = texte.length;
          setTimeout(function () { transformerEnListe(bloc, longueur, liste); }, 0);
        }
      }
      synchroniser();
    });
    zone.addEventListener('keydown', function (e) {
      if (!(e.metaKey || e.ctrlKey) || e.altKey) return;
      var touche = e.key.toLowerCase();
      // On prend ces raccourcis en main (Safari réserve ⌘I à l'envoi de la page par courriel).
      if (touche === 'b') { e.preventDefault(); executer('bold'); }
      if (touche === 'i') { e.preventDefault(); executer('italic'); }
    });
    zone.addEventListener('paste', function (e) {
      e.preventDefault();
      var texte = (e.clipboardData || window.clipboardData).getData('text/plain');
      document.execCommand('insertText', false, texte);
      synchroniser();
    });
    zone.addEventListener('drop', function (e) { e.preventDefault(); });
    document.addEventListener('selectionchange', etat);
    zone.addEventListener('focus', function () { cadre.classList.add('actif'); });
    zone.addEventListener('blur', function () { cadre.classList.remove('actif'); synchroniser(); });
    if (champ.form) champ.form.addEventListener('submit', synchroniser);

    champ.hidden = true;
    champ.parentNode.insertBefore(cadre, champ);
    cadre.appendChild(barre);
    cadre.appendChild(zone);
    cadre.appendChild(champ);
    synchroniser();
  }

  Array.prototype.forEach.call(document.querySelectorAll('textarea[data-mini-editeur]'), equiper);
})();
