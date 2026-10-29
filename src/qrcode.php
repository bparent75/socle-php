<?php
/**
 * socle-php — QR code.
 *
 * L'encodage (Reed-Solomon sur GF(256), masquage, information de format) vient de
 * bacon/bacon-qr-code : c'est de l'arithmétique de corps fini, un terrain où une
 * erreur ne se voit pas à la lecture et ne se découvre qu'au téléphone qui refuse
 * de scanner. Le rendu SVG, lui, reste ici : dix lignes, et cela permet de servir
 * un SVG en ligne accessible plutôt qu'un document XML complet.
 *
 * Dépend de : bacon/bacon-qr-code (BSD-2-Clause).
 */
declare(strict_types=1);

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

if (!function_exists('qr_svg')) {
    /**
     * QR code en SVG, prêt à être inséré dans une page.
     *
     * Correction d'erreur M (~15 %) : le compromis habituel pour un écran, où le code
     * n'est ni sali ni plié. $alt est lu par les lecteurs d'écran ; il doit dire à quoi
     * sert le code, pas répéter « QR code ».
     *
     * Mode octet sans préfixe ECI : certaines applications d'authentification ne lisent
     * pas l'en-tête ECI. Le contenu doit donc rester en ASCII — ce qui est le cas d'une
     * adresse otpauth://, dont les parties non ASCII sont déjà encodées en pourcent.
     */
    function qr_svg(string $texte, int $cote = 220, string $alt = 'QR code'): string
    {
        $qr = Encoder::encode($texte, ErrorCorrectionLevel::M(), 'ISO-8859-1', null, false);
        $matrice = $qr->getMatrix();
        $n = $matrice->getWidth();

        // Marge obligatoire de 4 modules (« quiet zone ») : sans elle, beaucoup de
        // lecteurs échouent, surtout sur fond clair.
        $marge = 4;
        $total = $n + 2 * $marge;

        $chemin = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($matrice->get($x, $y)) {
                    $chemin .= 'M' . ($x + $marge) . ' ' . ($y + $marge) . 'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '"'
             . ' width="' . $cote . '" height="' . $cote . '" role="img"'
             . ' aria-label="' . h($alt) . '" shape-rendering="crispEdges">'
             . '<rect width="' . $total . '" height="' . $total . '" fill="#ffffff"/>'
             . '<path d="' . $chemin . '" fill="#000000"/></svg>';
    }
}
