<?php
/**
 * socle-php — export CSV ouvert directement dans Excel en français.
 *
 * Attend de l'application hôte : rien.
 *
 * Pourquoi ici : le motif en-têtes + BOM + fputcsv était réécrit onze fois, avec un
 * séparateur incohérent d'un export à l'autre. Les décisions sont figées :
 *   - séparateur « ; » et BOM UTF-8 : c'est ce qu'Excel en français attend (un export
 *     destiné à un autre système, Mailchimp par exemple, garde son propre format) ;
 *   - échappement fputcsv désactivé ('') : conforme RFC 4180, et le 5e argument est
 *     obligatoire depuis PHP 8.4 (sa valeur par défaut est dépréciée) ;
 *   - une cellule qui commence par = + - @ (ou tabulation) est préfixée d'une
 *     apostrophe : sans cela le tableur l'exécuterait comme une formule — une donnée
 *     saisie par un tiers deviendrait du code chez la personne qui ouvre l'export.
 */
declare(strict_types=1);

if (!function_exists('csv_cellule')) {
    /** Neutralise une cellule qui serait lue comme une formule par le tableur. */
    function csv_cellule(mixed $valeur): string
    {
        $v = (string)$valeur;
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }
}

if (!function_exists('csv_ecrire')) {
    /**
     * Écrit un CSV pour Excel dans un flux ouvert : BOM, en-têtes, lignes.
     * $options : 'proteger_formules' (vrai par défaut — à ne désactiver que si une
     * colonne doit vraiment commencer par « = » ou « - », un écart négatif par exemple).
     * Rend le nombre de lignes écrites (en-têtes non comptés).
     */
    function csv_ecrire($flux, array $entetes, iterable $lignes, array $options = []): int
    {
        $proteger = (bool)($options['proteger_formules'] ?? true);
        fwrite($flux, "\u{FEFF}");
        fputcsv($flux, $entetes, ';', '"', '');
        $n = 0;
        foreach ($lignes as $ligne) {
            $cellules = $proteger ? array_map('csv_cellule', (array)$ligne)
                                  : array_map('strval', (array)$ligne);
            fputcsv($flux, $cellules, ';', '"', '');
            $n++;
        }
        return $n;
    }
}

if (!function_exists('csv_envoyer')) {
    /**
     * Envoie un CSV en téléchargement et arrête le script. Le nom de fichier est
     * assaini (slug) : il traverse l'en-tête HTTP sans accent ni guillemet.
     * À appeler avant toute sortie ; l'export se journalise AVANT l'appel.
     */
    function csv_envoyer(string $nom_fichier, array $entetes, iterable $lignes, array $options = []): never
    {
        $nom = slug(preg_replace('/\.csv$/i', '', $nom_fichier) ?? $nom_fichier) . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nom . '"');
        header('Cache-Control: no-store');
        $flux = fopen('php://output', 'w');
        csv_ecrire($flux, $entetes, $lignes, $options);
        fclose($flux);
        exit;
    }
}
