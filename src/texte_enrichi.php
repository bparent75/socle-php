<?php
/**
 * socle-php — texte enrichi court (gras, italique, listes) saisi par le mini éditeur (2.4).
 *
 * Le navigateur (actifs/mini-editeur.js) envoie du HTML ; on n'en garde qu'une liste blanche courte — p, br, strong,
 * em, ul, ol, li —, SANS AUCUN attribut (ni lien, ni style, ni classe) : rien d'exécutable ne peut passer. Le HTML est
 * reconstruit nœud par nœud, le texte échappé par htmlspecialchars(). Le script du navigateur n'est pas une
 * protection : la valeur reçue se nettoie TOUJOURS ici, à l'enregistrement, et se renettoie à l'affichage.
 *
 * Usage :
 *     $html = texte_enrichi_nettoyer($_POST['compte_rendu'] ?? '');     // à l'enregistrement ('' si rien de lisible)
 *     echo '<div class="texte-enrichi">' . texte_enrichi_html($valeur) . '</div>';      // à l'affichage, sans h()
 *     echo '<textarea name="compte_rendu" data-mini-editeur>' . h(texte_enrichi_html($valeur)) . '</textarea>';
 * texte_enrichi_html() accepte aussi un texte brut saisi avant l'éditeur (ou sans JavaScript) : chaque ligne devient
 * un paragraphe, une suite de lignes « - … » une liste à puces.
 *
 * Pourquoi pas symfony/html-sanitizer ou HTMLPurifier ? Sept balises sans attribut, c'est une liste blanche qu'on lit
 * d'un coup d'œil ; un paquet apporterait des dépendances et des réglages pour bien davantage que ce besoin (liens,
 * images, styles filtrés). La décision du 13 septembre 2026 prévoit ce cas : un paquet disproportionné se remplace,
 * en le disant — c'est dit ici.
 *
 * Écrit pour Libris le 20 novembre 2026 (compte rendu des séances de tutorat), générique dès l'origine. Attend
 * l'extension dom (DOMDocument), présente dans toute installation courante de PHP ; rien de l'application hôte.
 * Constantes et fonctions gardées (defined, function_exists) : un projet qui porte encore sa propre copie, chargée
 * avant l'autochargeur, monte de version sans collision, puis la retire.
 */
declare(strict_types=1);

/** Balises gardées (balise d'entrée → balise écrite). Les autres sont retirées, leur texte gardé. */
if (!defined('TEXTE_ENRICHI_BALISES')) {
    define('TEXTE_ENRICHI_BALISES', [
        'p' => 'p', 'div' => 'p', 'br' => 'br', 'strong' => 'strong', 'b' => 'strong', 'em' => 'em', 'i' => 'em',
        'ul' => 'ul', 'ol' => 'ol', 'li' => 'li',
    ]);
}
/** Balises retirées AVEC leur contenu (code, styles, objets, formulaires). */
if (!defined('TEXTE_ENRICHI_A_JETER')) {
    define('TEXTE_ENRICHI_A_JETER', [
        'script', 'style', 'template', 'iframe', 'object', 'embed', 'noscript', 'svg', 'math', 'head', 'title',
        'textarea', 'select', 'button', 'input', 'img', 'video', 'audio', 'canvas', 'form',
    ]);
}

if (!function_exists('texte_enrichi_nettoyer')) {
    /** Le HTML reçu, réduit à la liste blanche et reconstruit ; '' s'il ne reste aucun texte. */
    function texte_enrichi_nettoyer(string $html): string
    {
        if (trim($html) === '') return '';
        $doc = new DOMDocument();
        $precedent = libxml_use_internal_errors(true);
        // L'en-tête d'encodage fait lire l'UTF-8 ; l'enveloppe donne un seul parent à parcourir.
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="texte-enrichi-racine">' . $html . '</div>',
                       LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($precedent);
        $racine = $doc->getElementById('texte-enrichi-racine');
        $sortie = '';
        if ($racine !== null) foreach ($racine->childNodes as $n) $sortie .= texte_enrichi_noeud($n);
        // Les paragraphes et éléments de liste vides que laisse l'édition (« <p><br></p> ») ne servent à rien.
        $sortie = (string)preg_replace('~<(p|li)>(\s|<br>)*</\1>~u', '', $sortie);
        $sortie = (string)preg_replace('~<(ul|ol)>\s*</\1>~u', '', $sortie);
        return texte_enrichi_vide($sortie) ? '' : trim($sortie);
    }
}

if (!function_exists('texte_enrichi_noeud')) {
    /** Un nœud reconstruit : texte échappé, balise de la liste blanche sans attribut, ou seulement son contenu. */
    function texte_enrichi_noeud(DOMNode $n): string
    {
        if ($n instanceof DOMText) return htmlspecialchars($n->data, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        if (!$n instanceof DOMElement) return '';   // commentaires, instructions de traitement…
        $balise = strtolower($n->nodeName);
        if (in_array($balise, TEXTE_ENRICHI_A_JETER, true)) return '';
        if ($balise === 'br') return '<br>';
        $interieur = '';
        foreach ($n->childNodes as $enfant) $interieur .= texte_enrichi_noeud($enfant);
        if (preg_match('/^h[1-6]$/', $balise)) return '<p><strong>' . $interieur . '</strong></p>';
        $cible = TEXTE_ENRICHI_BALISES[$balise] ?? null;
        // Un paragraphe qui enveloppe une liste ou d'autres blocs (Chrome produit « <p><ul>…</ul></p> ») est défait :
        // écrit tel quel, le navigateur le couperait en paragraphes vides autour de la liste.
        if ($cible === 'p') {
            foreach (['ul', 'ol', 'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $bloc) {
                if ($n->getElementsByTagName($bloc)->length > 0) return $interieur;
            }
        }
        return $cible === null ? $interieur : '<' . $cible . '>' . $interieur . '</' . $cible . '>';
    }
}

if (!function_exists('texte_enrichi_depuis_texte')) {
    /** Un texte brut (saisi avant l'éditeur, ou sans JavaScript) en HTML : chaque ligne devient un paragraphe (une liste
     *  à puces de l'éditeur en fait alors une puce par ligne), et une suite de lignes commençant par « - », « * » ou
     *  « • » devient une liste à puces. */
    function texte_enrichi_depuis_texte(string $texte): string
    {
        $texte = trim(str_replace(["\r\n", "\r"], "\n", $texte));
        if ($texte === '') return '';
        $html = '';
        foreach (preg_split('/\n\s*\n/u', $texte) ?: [] as $bloc) {
            $lignes = array_values(array_filter(array_map('trim', explode("\n", $bloc)), fn($l) => $l !== ''));
            if ($lignes === []) continue;
            $puces = array_filter($lignes, fn($l) => preg_match('/^[-*•]\s+/u', $l) === 1);
            if (count($puces) === count($lignes)) {
                $html .= '<ul>' . implode('', array_map(fn($l) => '<li>' . htmlspecialchars((string)preg_replace('/^[-*•]\s+/u', '', $l),
                                                        ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</li>', $lignes)) . '</ul>';
            } else {
                $html .= implode('', array_map(fn($l) => '<p>' . htmlspecialchars($l, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>', $lignes));
            }
        }
        return $html;
    }
}

if (!function_exists('texte_enrichi_html')) {
    /** Ce qu'on montre ou qu'on charge dans l'éditeur : le HTML nettoyé, ou le texte brut d'avant mis en HTML. */
    function texte_enrichi_html(string $valeur): string
    {
        return preg_match('~<(p|div|br|strong|b|em|i|ul|ol|li)\b~i', $valeur) === 1
            ? texte_enrichi_nettoyer($valeur) : texte_enrichi_depuis_texte($valeur);
    }
}

if (!function_exists('texte_enrichi_vide')) {
    /** Vrai s'il n'y a aucun texte visible (balises vides, espaces, espaces insécables). */
    function texte_enrichi_vide(string $html): bool
    {
        $texte = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $texte)) === '';
    }
}
