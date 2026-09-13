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

/** Échappement de tout contenu affiché. À utiliser systématiquement. */
function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Texte multiligne saisi par un utilisateur : échappé, puis mis en paragraphes. */
function texte(?string $s): string
{
    $s = trim($s ?? '');
    if ($s === '') return '';
    $blocs = preg_split("/\n\s*\n/", $s) ?: [];
    return implode('', array_map(fn($b) => '<p>' . nl2br(h(trim($b))) . '</p>', $blocs));
}

/** Instant courant, en UTC, au format stocké en base. */
function maintenant(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

/**
 * Date-heure en français, convertie dans le fuseau demandé.
 * Formes : complet, jour, court, heure, numerique.
 */
function date_fr(?string $utc, string $forme = 'complet', ?DateTimeZone $tz = null): string
{
    if (!$utc) return '';
    try { $d = new DateTimeImmutable($utc); } catch (Exception) { return ''; }
    $d = $d->setTimezone($tz ?? new DateTimeZone('Europe/Paris'));
    $jour  = SOCLE_JOURS_FR[(int)$d->format('N') - 1];
    $mois  = SOCLE_MOIS_FR[(int)$d->format('n') - 1];
    $heure = $d->format('i') === '00' ? $d->format('G') . 'h' : $d->format('G') . 'h' . $d->format('i');
    return match ($forme) {
        'jour'      => $jour . ' ' . (int)$d->format('j') . ' ' . $mois . ' ' . $d->format('Y'),
        'court'     => (int)$d->format('j') . ' ' . $mois . ' ' . $d->format('Y'),
        'heure'     => $heure,
        'numerique' => $d->format('d/m/Y'),
        default     => $jour . ' ' . (int)$d->format('j') . ' ' . $mois . ' ' . $d->format('Y') . ' à ' . $heure,
    };
}

/** Date seule (AAAA-MM-JJ), sans conversion de fuseau : c'est une date de calendrier. */
function date_simple_fr(?string $iso): string
{
    if (!$iso) return '';
    $p = explode('-', substr($iso, 0, 10));
    if (count($p) !== 3) return $iso;
    return (int)$p[2] . ' ' . (SOCLE_MOIS_FR[(int)$p[1] - 1] ?? '') . ' ' . $p[0];
}

/** Montant en centimes vers « 1 080 € ». Null signifie « Sur devis ». */
function euros(?int $cents, string $si_null = 'Sur devis'): string
{
    if ($cents === null) return $si_null;
    $e = intdiv($cents, 100);
    $reste = $cents % 100;
    $s = number_format($e, 0, ',', ' ');
    if ($reste !== 0) $s .= ',' . str_pad((string)$reste, 2, '0', STR_PAD_LEFT);
    return $s . ' €';
}

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

/** Liste séparée par des virgules vers un tableau, sans entrée vide. */
function liste(string $csv): array
{
    return array_values(array_filter(array_map('trim', explode(',', $csv)), fn($v) => $v !== ''));
}

/** @return string[] lignes non vides d'un texte multiligne */
function liste_lignes(string $s): array
{
    return array_values(array_filter(array_map('trim', preg_split("/\r?\n/", $s) ?: [])));
}

/** Taille de fichier lisible : « 1,4 Mo ». */
function taille_lisible(int $octets): string
{
    if ($octets < 1024) return $octets . ' o';
    if ($octets < 1024 * 1024) return round($octets / 1024) . ' Ko';
    return str_replace('.', ',', (string)round($octets / (1024 * 1024), 1)) . ' Mo';
}
