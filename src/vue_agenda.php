<?php
/**
 * socle-php — vue d'agenda (2.6) : une semaine en grille horaire, un mois en tableau, sans dépendance.
 *
 * Reprise du calendrier d'ATEA (colonnes par jour, blocs posés à leur heure, chevauchements côte à côte en « couloirs »,
 * ligne de l'heure, clic pour créer au quart d'heure avec un repère au survol), rendue générique : le projet fournit ses
 * événements, la brique les dessine. Le script (actifs/vue-agenda.js) et la feuille de style (actifs/vue-agenda.css) se
 * recopient dans le projet, comme ceux du mini éditeur.
 *
 * Un événement : [
 *   'jour'  => 'AAAA-MM-JJ', 'debut' => 'HH:MM', 'fin' => 'HH:MM'   (heures locales, fin après début),
 *   'titre' => texte, 'detail' => texte facultatif (seconde ligne), 'infobulle' => texte facultatif,
 *   'classe' => classes CSS ajoutées au bloc, 'couleur' => '#RRGGBB' facultatif (la teinte du bloc),
 *   'url' => lien facultatif (le bloc, ou son titre, y mène),
 *   'html' => fragment HTML ajouté au bloc, DÉJÀ SÛR (un bouton, un petit formulaire) : l'appelant l'échappe.
 * ]
 * Les textes sont échappés ici. Un jour, des heures ou une couleur invalides écartent l'événement.
 */
declare(strict_types=1);

if (!function_exists('vue_agenda_minutes')) {
    /** « 17:30 » → 1050 ; une heure invalide → null. */
    function vue_agenda_minutes(string $hhmm): ?int
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m)) return $hhmm === '24:00' ? 1440 : null;
        return (int)$m[1] * 60 + (int)$m[2];
    }
}

if (!function_exists('vue_agenda_hhmm')) {
    /** 1050 → « 17:30 ». */
    function vue_agenda_hhmm(int $minutes): string
    {
        $minutes = max(0, min(1440, $minutes));
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}

if (!function_exists('vue_agenda_heure_fr')) {
    /** « 17:30 » → « 17h30 » (décision de typographie des projets : jamais « 17 h 30 » ni « 17:30 »). */
    function vue_agenda_heure_fr(string $hhmm): string
    {
        return str_replace(':', 'h', $hhmm);
    }
}

if (!function_exists('vue_agenda_couloirs')) {
    /**
     * Les chevauchements d'une journée, côte à côte : chaque événement reçoit 'couloir' (0, 1, …) et 'couloirs' (combien de
     * couloirs compte SON groupe d'événements qui se chevauchent — et non toute la journée, défaut de la première version
     * d'ATEA). Rend les événements triés par début, puis fin.
     */
    function vue_agenda_couloirs(array $evenements): array
    {
        usort($evenements, fn(array $a, array $b): int => [$a['_debut'] ?? 0, $a['_fin'] ?? 0] <=> [$b['_debut'] ?? 0, $b['_fin'] ?? 0]);
        $sortie = [];
        $groupe = [];
        $fins = [];
        $fin_groupe = -1;
        $clore = function () use (&$groupe, &$fins, &$sortie): void {
            foreach ($groupe as $e) $sortie[] = $e + ['couloirs' => max(1, count($fins))];
            $groupe = [];
            $fins = [];
        };
        foreach ($evenements as $e) {
            if ($groupe !== [] && $e['_debut'] >= $fin_groupe) $clore();
            $couloir = null;
            foreach ($fins as $i => $fin) if ($fin <= $e['_debut']) { $couloir = $i; break; }
            if ($couloir === null) $couloir = count($fins);
            $fins[$couloir] = $e['_fin'];
            $fin_groupe = $groupe === [] ? $e['_fin'] : max($fin_groupe, $e['_fin']);
            $groupe[] = $e + ['couloir' => $couloir];
        }
        $clore();
        return $sortie;
    }
}

if (!function_exists('vue_agenda_periode')) {
    /**
     * La période d'une vue autour d'un jour (AAAA-MM-JJ) : ['du', 'au', 'jours' => [AAAA-MM-JJ…], 'titre', 'avant', 'apres'].
     * Semaine : du lundi au dimanche (« Semaine du 30 novembre au 6 décembre 2026 »). Mois : des semaines entières, du lundi
     * de la première au dimanche de la dernière (« Décembre 2026 »).
     */
    function vue_agenda_periode(string $vue, string $jour): array
    {
        $d = new DateTimeImmutable($jour . ' 12:00');
        $lundi = fn(DateTimeImmutable $x): DateTimeImmutable => $x->modify('-' . ((int)$x->format('N') - 1) . ' days');
        $premier_fr = fn(string $j): string => preg_replace('/^1 /u', '1er ', date_simple_fr($j)) ?? $j;
        if ($vue === 'mois') {
            $premier = $d->modify('first day of this month');
            $dernier = $d->modify('last day of this month');
            $du = $lundi($premier);
            $au = $dernier->modify('+' . (7 - (int)$dernier->format('N')) . ' days');
            $titre = ucfirst(preg_replace('/^\d+ /u', '', date_simple_fr($premier->format('Y-m-d'))) ?? '');
            $avant = $premier->modify('-1 month')->format('Y-m-d');
            $apres = $premier->modify('+1 month')->format('Y-m-d');
        } else {
            $du = $lundi($d);
            $au = $du->modify('+6 days');
            $debut = $premier_fr($du->format('Y-m-d'));
            if ($du->format('Y') === $au->format('Y')) $debut = preg_replace('/ \d{4}$/', '', $debut) ?? $debut;
            if ($du->format('Y-m') === $au->format('Y-m')) $debut = preg_replace('/ \D+$/u', '', $debut) ?? $debut;
            $titre = 'Semaine du ' . $debut . ' au ' . $premier_fr($au->format('Y-m-d'));
            $avant = $du->modify('-7 days')->format('Y-m-d');
            $apres = $du->modify('+7 days')->format('Y-m-d');
        }
        $jours = [];
        for ($x = $du; $x <= $au; $x = $x->modify('+1 day')) $jours[] = $x->format('Y-m-d');
        return ['du' => $du->format('Y-m-d'), 'au' => $au->format('Y-m-d'), 'jours' => $jours, 'titre' => $titre,
                'avant' => $avant, 'apres' => $apres];
    }
}

if (!function_exists('vue_agenda_valides')) {
    /** Les événements valides (jour, heures, couleur), avec leurs minutes '_debut' et '_fin', rangés par jour. */
    function vue_agenda_valides(array $evenements): array
    {
        $par_jour = [];
        foreach ($evenements as $e) {
            $jour = (string)($e['jour'] ?? '');
            $debut = vue_agenda_minutes((string)($e['debut'] ?? ''));
            $fin = vue_agenda_minutes((string)($e['fin'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour) || $debut === null || $fin === null || $fin <= $debut) continue;
            $couleur = (string)($e['couleur'] ?? '');
            if ($couleur !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $couleur)) $couleur = '';
            $par_jour[$jour][] = ['_debut' => $debut, '_fin' => $fin, 'couleur' => $couleur] + $e;
        }
        return $par_jour;
    }
}

if (!function_exists('vue_agenda_bloc')) {
    /** Le HTML d'un événement : un bloc de la grille (avec $style) ou une puce du mois ($style = null). */
    function vue_agenda_bloc(array $e, ?string $style): string
    {
        $classe = ($style !== null ? 'vue-agenda-bloc' : 'vue-agenda-puce') . (trim((string)($e['classe'] ?? '')) !== '' ? ' ' . trim((string)$e['classe']) : '');
        $styles = ($style ?? '') . ($e['couleur'] !== '' ? '--c:' . $e['couleur'] . ';' : '');
        $infobulle = (string)($e['infobulle'] ?? '') !== '' ? (string)$e['infobulle']
            : vue_agenda_heure_fr(vue_agenda_hhmm($e['_debut'])) . '–' . vue_agenda_heure_fr(vue_agenda_hhmm($e['_fin'])) . ' · ' . (string)($e['titre'] ?? '');
        $url = (string)($e['url'] ?? '');
        $html = (string)($e['html'] ?? '');
        $dedans = '<span class="vue-agenda-heure">' . h(vue_agenda_heure_fr(vue_agenda_hhmm($e['_debut']))) . '</span> '
                . '<span class="vue-agenda-titre">' . h((string)($e['titre'] ?? '')) . '</span>'
                . ((string)($e['detail'] ?? '') !== '' ? '<span class="vue-agenda-detail">' . h((string)$e['detail']) . '</span>' : '');
        $attr = ' class="' . h($classe) . '"' . ($styles !== '' ? ' style="' . h($styles) . '"' : '') . ' title="' . h($infobulle) . '"';
        // Un lien ne peut pas contenir de bouton ni de formulaire : avec un fragment, le bloc est une boîte et seul son titre est un lien.
        if ($url !== '' && $html === '') return '<a' . $attr . ' href="' . h($url) . '">' . $dedans . '</a>';
        if ($url !== '') $dedans = '<a class="vue-agenda-lien" href="' . h($url) . '">' . $dedans . '</a>';
        return '<div' . $attr . '>' . $dedans . $html . '</div>';
    }
}

if (!function_exists('vue_agenda_semaine')) {
    /**
     * Une semaine (ou quelques jours) en grille horaire. Options :
     *   'jours'        les colonnes (AAAA-MM-JJ), défaut : la semaine de 'aujourdhui' ;
     *   'aujourdhui'   AAAA-MM-JJ (défaut : date du jour) ; 'maintenant' : 'HH:MM', la ligne de l'heure sur ce jour ;
     *   'heure_min', 'heure_max'  la plage affichée (8 et 21), élargie aux événements ; 'px_heure' (48) ; 'pas' (15 minutes) ;
     *   'creer'        fn(string $jour): ?string — une adresse (le clic y mène avec « heure=HH:MM »), ou « #id » d'un <dialog>
     *                  que le script remplit et ouvre (champs data-vue-agenda="jour|debut|fin|libelle-jour") ; null : pas de clic ;
     *   'marques'      [jour => libellé] : journée hachurée et son libellé (fermeture, vacances) ;
     *   'entete'       fn(string $jour): string — HTML sûr ajouté sous le titre d'une colonne ;
     *   'libelle'      texte de l'agenda pour les lecteurs d'écran (« Agenda de la semaine »).
     */
    function vue_agenda_semaine(array $evenements, array $options = []): string
    {
        $aujourdhui = (string)($options['aujourdhui'] ?? date('Y-m-d'));
        $jours = array_values((array)($options['jours'] ?? vue_agenda_periode('semaine', $aujourdhui)['jours']));
        $par_jour = vue_agenda_valides($evenements);
        $hmin = (int)($options['heure_min'] ?? 8);
        $hmax = (int)($options['heure_max'] ?? 21);
        foreach ($jours as $j) foreach ($par_jour[$j] ?? [] as $e) {
            $hmin = min($hmin, intdiv($e['_debut'], 60));
            $hmax = max($hmax, (int)ceil($e['_fin'] / 60));
        }
        $hmin = max(0, $hmin);
        $hmax = min(24, max($hmax, $hmin + 1));
        $px = max(24, (int)($options['px_heure'] ?? 48));
        $pas = max(5, (int)($options['pas'] ?? 15));
        $hauteur = ($hmax - $hmin) * $px;
        $px_min = $px / 60;
        $creer = $options['creer'] ?? null;
        $marques = (array)($options['marques'] ?? []);
        $entete = $options['entete'] ?? null;
        $maintenant = vue_agenda_minutes((string)($options['maintenant'] ?? ''));

        $html = '<div class="vue-agenda" style="--va-px-heure:' . $px . 'px;--va-jours:' . count($jours) . '" role="group" aria-label="'
              . h((string)($options['libelle'] ?? 'Agenda')) . '"><div class="vue-agenda-defile"><div class="vue-agenda-grille">'
              . '<div class="vue-agenda-coin" aria-hidden="true"></div>';
        foreach ($jours as $j) {
            $d = new DateTimeImmutable($j . ' 12:00');
            $nom = ucfirst(SOCLE_JOURS_FR[(int)$d->format('N') - 1]);
            $date = preg_replace('/ \d{4}$/', '', preg_replace('/^1 /u', '1er ', date_simple_fr($j)) ?? '') ?? '';
            $html .= '<div class="vue-agenda-tete' . ($j === $aujourdhui ? ' aujourdhui' : '') . (isset($marques[$j]) ? ' marque' : '') . '">'
                   . h($nom) . '<small>' . h($date) . '</small>' . (is_callable($entete) ? (string)$entete($j) : '') . '</div>';
        }
        $html .= '<div class="vue-agenda-heures" style="height:' . $hauteur . 'px" aria-hidden="true">';
        for ($h = $hmin + 1; $h < $hmax; $h++) $html .= '<span style="top:' . (($h - $hmin) * $px) . 'px">' . $h . 'h00</span>';
        $html .= '</div>';
        foreach ($jours as $j) {
            $d = new DateTimeImmutable($j . ' 12:00');
            $libelle = ucfirst(SOCLE_JOURS_FR[(int)$d->format('N') - 1]) . ' ' . preg_replace('/^1 /u', '1er ', date_simple_fr($j));
            $cible = is_callable($creer) ? $creer($j) : null;
            $html .= '<div class="vue-agenda-colonne' . ($j === $aujourdhui ? ' aujourdhui' : '') . ($j < $aujourdhui ? ' passe' : '')
                   . (isset($marques[$j]) ? ' marque' : '') . '" style="height:' . $hauteur . 'px" data-jour="' . h($j) . '" data-libelle="' . h($libelle) . '"'
                   . ($cible !== null && $cible !== '' ? ' data-creer="' . h($cible) . '" data-heure-min="' . $hmin . '" data-pas="' . $pas . '" data-px-heure="' . $px . '"' : '')
                   . ' aria-label="' . h($libelle) . '">';
            if (isset($marques[$j]) && (string)$marques[$j] !== '') $html .= '<p class="vue-agenda-marque-libelle">' . h((string)$marques[$j]) . '</p>';
            if ($j === $aujourdhui && $maintenant !== null && $maintenant >= $hmin * 60 && $maintenant <= $hmax * 60) {
                $html .= '<div class="vue-agenda-maintenant" style="top:' . round(($maintenant - $hmin * 60) * $px_min) . 'px" aria-hidden="true"></div>';
            }
            foreach (vue_agenda_couloirs($par_jour[$j] ?? []) as $e) {
                $top = round(($e['_debut'] - $hmin * 60) * $px_min);
                $haut = max(18, round(($e['_fin'] - $e['_debut']) * $px_min) - 2);
                $largeur = 100 / $e['couloirs'];
                $style = 'top:' . $top . 'px;height:' . $haut . 'px;left:calc(' . round($largeur * $e['couloir'], 3) . '% + 2px);width:calc('
                       . round($largeur, 3) . '% - 4px);';
                $html .= vue_agenda_bloc($e, $style);
            }
            $html .= '</div>';
        }
        return $html . '</div></div></div>';
    }
}

if (!function_exists('vue_agenda_mois')) {
    /**
     * Un mois en tableau, semaines entières. Options : 'mois' (AAAA-MM, défaut : celui de 'aujourdhui'), 'aujourdhui',
     * 'lien_jour' (fn(string $jour): ?string — le numéro du jour y mène, la semaine par exemple), 'creer' (comme la semaine,
     * sans heure), 'marques' ([jour => libellé]), 'max' (4 puces par jour, puis « + N autres »), 'resume' (fn(string $jour,
     * array $evenements): string — HTML sûr ajouté à la case : un compte, un rappel).
     */
    function vue_agenda_mois(array $evenements, array $options = []): string
    {
        $aujourdhui = (string)($options['aujourdhui'] ?? date('Y-m-d'));
        $mois = (string)($options['mois'] ?? substr($aujourdhui, 0, 7));
        $periode = vue_agenda_periode('mois', $mois . '-01');
        $par_jour = vue_agenda_valides($evenements);
        $max = max(1, (int)($options['max'] ?? 4));
        $lien = $options['lien_jour'] ?? null;
        $creer = $options['creer'] ?? null;
        $resume = $options['resume'] ?? null;
        $marques = (array)($options['marques'] ?? []);
        $html = '<div class="vue-agenda vue-agenda-mois"><table><thead><tr>';
        foreach (SOCLE_JOURS_FR as $nom) $html .= '<th scope="col"><abbr title="' . h($nom) . '">' . h(mb_substr($nom, 0, 3)) . '.</abbr></th>';
        $html .= '</tr></thead><tbody>';
        foreach (array_chunk($periode['jours'], 7) as $semaine) {
            $html .= '<tr>';
            foreach ($semaine as $j) {
                $liste = vue_agenda_couloirs($par_jour[$j] ?? []);
                $cible = is_callable($creer) ? $creer($j) : null;
                $url = is_callable($lien) ? $lien($j) : null;
                $num = (int)substr($j, 8, 2);
                $html .= '<td class="' . trim((substr($j, 0, 7) !== $mois ? 'hors ' : '') . ($j === $aujourdhui ? 'aujourdhui ' : '')
                       . ($j < $aujourdhui ? 'passe ' : '') . (isset($marques[$j]) ? 'marque ' : '') . ($liste === [] ? 'vide' : '')) . '"'
                       . ' data-jour="' . h($j) . '"' . ($cible !== null && $cible !== '' ? ' data-creer="' . h($cible) . '"' : '') . '>'
                       . ($url !== null ? '<a class="vue-agenda-num" href="' . h($url) . '">' : '<span class="vue-agenda-num">')
                       . $num . ($num === 1 ? '<sup>er</sup>' : '') . ($url !== null ? '</a>' : '</span>');
                if (isset($marques[$j]) && (string)$marques[$j] !== '') $html .= '<span class="vue-agenda-marque-libelle">' . h((string)$marques[$j]) . '</span>';
                foreach (array_slice($liste, 0, $max) as $e) $html .= vue_agenda_bloc($e, null);
                if (count($liste) > $max) {
                    $plus = count($liste) - $max;
                    $html .= $url !== null ? '<a class="vue-agenda-plus" href="' . h($url) . '">+ ' . $plus . ' autre' . ($plus > 1 ? 's' : '') . '</a>'
                                           : '<span class="vue-agenda-plus">+ ' . $plus . ' autre' . ($plus > 1 ? 's' : '') . '</span>';
                }
                if (is_callable($resume)) $html .= (string)$resume($j, $liste);
                $html .= '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }
}
