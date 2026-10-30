<?php
/**
 * socle-php — formats français et échappement.
 *
 * Attend de l'application hôte : rien. Ces fonctions sont autonomes.
 *
 * Pourquoi ici plutôt qu'un paquet : il ne s'agit pas d'internationalisation mais
 * d'un style d'écriture précis et constant — « lundi 21 septembre 2026 à 14h30 »,
 * « 1 080 € », des adresses sans apostrophe parasite. Aucun paquet ne le fait
 * exactement ainsi, et IntlDateFormatter demande l'extension intl, absente de
 * certains mutualisés.
 */
declare(strict_types=1);

const SOCLE_JOURS_FR = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
const SOCLE_MOIS_FR  = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
                        'août', 'septembre', 'octobre', 'novembre', 'décembre'];

if (!function_exists('h')) {
    /** Échappement de tout contenu affiché. À utiliser systématiquement. */
    function h(?string $s): string
    {
        return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('texte')) {
    /** Texte multiligne saisi par un utilisateur : échappé, puis mis en paragraphes. */
    function texte(?string $s): string
    {
        $s = trim($s ?? '');
        if ($s === '') return '';
        $blocs = preg_split("/\n\s*\n/", $s) ?: [];
        return implode('', array_map(fn($b) => '<p>' . nl2br(h(trim($b))) . '</p>', $blocs));
    }
}

if (!function_exists('maintenant')) {
    /** Instant courant, en UTC, au format stocké en base. */
    function maintenant(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}

if (!function_exists('date_fr')) {
    /**
     * Date-heure en français, convertie dans le fuseau demandé.
     * Formes : complet, jour, court, heure, numerique. L'heure s'écrit toujours avec ses
     * minutes, « 19h00 », jamais « 19h » (décision 4.4 du 30 octobre 2026).
     */
    function date_fr(?string $utc, string $forme = 'complet', ?DateTimeZone $tz = null): string
    {
        if (!$utc) return '';
        try { $d = new DateTimeImmutable($utc); } catch (Exception) { return ''; }
        $d = $d->setTimezone($tz ?? new DateTimeZone('Europe/Paris'));
        $jour  = SOCLE_JOURS_FR[(int)$d->format('N') - 1];
        $mois  = SOCLE_MOIS_FR[(int)$d->format('n') - 1];
        $heure = heure_fr($d->format('H:i'));
        return match ($forme) {
            'jour'      => $jour . ' ' . (int)$d->format('j') . ' ' . $mois . ' ' . $d->format('Y'),
            'court'     => (int)$d->format('j') . ' ' . $mois . ' ' . $d->format('Y'),
            'heure'     => $heure,
            'numerique' => $d->format('d/m/Y'),
            default     => $jour . ' ' . (int)$d->format('j') . ' ' . $mois . ' ' . $d->format('Y') . ' à ' . $heure,
        };
    }
}

if (!function_exists('heure_fr')) {
    /**
     * Heure locale « HH:MM » ou « HH:MM:SS » (colonne TIME) en « 19h00 », « 9h30 » : heure sans
     * zéro initial, minutes toujours sur deux chiffres (décision 4.4). Sans conversion de fuseau :
     * c'est une heure d'horaire, comme date_simple_fr() une date de calendrier. Vide si illisible.
     * Même nom et même entrée que les copies du calendrier et des inscriptions.
     */
    function heure_fr(?string $hhmm): string
    {
        if ($hhmm === null || !preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($hhmm), $m)
            || (int)$m[1] > 23 || (int)$m[2] > 59) {
            return '';
        }
        return (int)$m[1] . 'h' . $m[2];
    }
}

if (!function_exists('date_simple_fr')) {
    /** Date seule (AAAA-MM-JJ), sans conversion de fuseau : c'est une date de calendrier. */
    function date_simple_fr(?string $iso): string
    {
        if (!$iso) return '';
        $p = explode('-', substr($iso, 0, 10));
        if (count($p) !== 3) return $iso;
        return (int)$p[2] . ' ' . (SOCLE_MOIS_FR[(int)$p[1] - 1] ?? '') . ' ' . $p[0];
    }
}

if (!function_exists('euros')) {
    /** Montant en centimes vers « 1 080 € ». Null signifie « Sur devis ». */
    function euros(?int $cents, string $si_null = 'Sur devis'): string
    {
        if ($cents === null) return $si_null;
        // Calcul sur la valeur absolue : un avoir de -10,50 € donnait « -10,-50 € », et -0,50 € « 0,-50 € ».
        $abs = abs($cents);
        $s = number_format(intdiv($abs, 100), 0, ',', ' ');
        if ($abs % 100 !== 0) $s .= ',' . str_pad((string)($abs % 100), 2, '0', STR_PAD_LEFT);
        return ($cents < 0 ? '-' : '') . $s . ' €';
    }
}

if (!function_exists('slug')) {
    /**
     * Adresse lisible et stable à partir d'un titre.
     *
     * La translittération est explicite plutôt que confiée à iconv('ASCII//TRANSLIT') :
     * ce dernier dépend de la locale et produit « sp'ecialit'es » sur certains systèmes.
     * Or une adresse publiée ne doit plus changer.
     */
    function slug(string $s): string
    {
        static $table = [
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','ā'=>'a',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ē'=>'e',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ī'=>'i',
            'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ō'=>'o',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ū'=>'u',
            'ý'=>'y','ÿ'=>'y','ñ'=>'n','ç'=>'c','ß'=>'ss',
            'æ'=>'ae','œ'=>'oe','ð'=>'d','þ'=>'th',
            '’'=>' ', "'"=>' ', '“'=>' ', '”'=>' ', '–'=>'-', '—'=>'-',
        ];
        $s = mb_strtolower(trim($s));
        $s = strtr($s, $table);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-') ?: 'sans-titre';
    }
}

if (!function_exists('liste')) {
    /** Liste séparée par des virgules vers un tableau, sans entrée vide. */
    function liste(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv)), fn($v) => $v !== ''));
    }
}

if (!function_exists('liste_lignes')) {
    /** @return string[] lignes non vides d'un texte multiligne */
    function liste_lignes(string $s): array
    {
        return array_values(array_filter(array_map('trim', preg_split("/\r?\n/", $s) ?: [])));
    }
}

if (!function_exists('taille_lisible')) {
    /** Taille de fichier lisible : « 1,4 Mo ». */
    function taille_lisible(int $octets): string
    {
        if ($octets < 1024) return $octets . ' o';
        if ($octets < 1024 * 1024) return round($octets / 1024) . ' Ko';
        return str_replace('.', ',', (string)round($octets / (1024 * 1024), 1)) . ' Mo';
    }
}

if (!function_exists('nombre_fr')) {
    /**
     * Nombre décimal à la française : « 3,5 », « 1 250 », « 2 » (zéros finaux de la partie
     * décimale retirés ; ceux de la partie entière, jamais : nombre_fr(40, 0) donne « 40 »).
     * Pour accorder un mot au nombre, voir accorder() ; pour un montant, euros().
     */
    function nombre_fr(float $n, int $decimales = 2): string
    {
        $s = number_format($n, $decimales, ',', ' ');
        return $decimales > 0 ? rtrim(rtrim($s, '0'), ',') : $s;
    }
}

if (!function_exists('accorder')) {
    /** « 1 bac blanc corrigé », « 3 bacs blancs corrigés » : le nombre et le mot accordé. */
    function accorder(int $n, string $singulier, ?string $pluriel = null): string
    {
        return $n . ' ' . ($n > 1 ? ($pluriel ?? $singulier . 's') : $singulier);
    }
}

if (!function_exists('initiales')) {
    /** Initiales d'un nom (deux premiers mots) : vignette ou portrait absent — rien d'inventé. */
    function initiales(string $nom): string
    {
        $mots = preg_split('/\s+/u', trim($nom), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $i = '';
        foreach (array_slice($mots, 0, 2) as $m) $i .= mb_strtoupper(mb_substr($m, 0, 1));
        return $i !== '' ? $i : '?';
    }
}

if (!function_exists('sans_accents')) {
    /**
     * Retire les accents par une table fixe : le résultat ne dépend ni de la locale ni
     * d'intl (iconv('ASCII//TRANSLIT') produit « sp'ecialit'es » sur certains systèmes).
     * La casse est conservée : pour une clé de comparaison, mb_strtolower(sans_accents()).
     */
    function sans_accents(string $s): string
    {
        static $table = [
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','ā'=>'a',
            'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ē'=>'e','ě'=>'e',
            'È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ī'=>'i',
            'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I',
            'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ō'=>'o',
            'Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ū'=>'u','ů'=>'u',
            'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U',
            'ý'=>'y','ÿ'=>'y','Ý'=>'Y','Ÿ'=>'Y',
            'ç'=>'c','Ç'=>'C','ñ'=>'n','Ñ'=>'N',
            'œ'=>'oe','Œ'=>'OE','æ'=>'ae','Æ'=>'AE','ß'=>'ss',
        ];
        return strtr($s, $table);
    }
}
