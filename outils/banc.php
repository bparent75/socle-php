<?php
/**
 * socle-php — banc d'essai minimal, partagé entre les projets.
 *
 * La même fonction verifie() était réécrite dans six projets ; la voici publiée
 * une fois pour toutes. Usage, en tête d'un tests.php :
 *
 *     require __DIR__ . '/vendor/bparent75/socle-php/outils/banc.php';
 *     titre('Formats');
 *     verifie('échappement', h('<b>') === '&lt;b&gt;');
 *     bilan();   // imprime le total et sort avec le code 0 ou 1
 *
 * Aucune dépendance : pas de bd(), pas de constante. Chaque fonction est gardée
 * par function_exists, le temps que les projets retirent leur propre copie.
 */
declare(strict_types=1);

$GLOBALS['socle_banc'] = ['reussis' => 0, 'echecs' => []];

if (!function_exists('verifie')) {
    /** Un contrôle : affiche « ok » ou « ÉCHEC », et retient l'échec pour le bilan. */
    function verifie(string $nom, bool $ok, string $detail = ''): void
    {
        if ($ok) { $GLOBALS['socle_banc']['reussis']++; echo "  ok    $nom\n"; return; }
        $GLOBALS['socle_banc']['echecs'][] = $nom;
        echo "  ÉCHEC $nom" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

if (!function_exists('titre')) {
    /** Un intertitre entre deux groupes de contrôles. */
    function titre(string $t): void { echo "\n$t\n"; }
}

if (!function_exists('bilan')) {
    /** Le total, puis sortie : 0 si tout est passé, 1 sinon (exploitable par la CI). */
    function bilan(): never
    {
        echo "\n" . str_repeat('-', 56) . "\n";
        $reussis = $GLOBALS['socle_banc']['reussis'];
        $echecs = $GLOBALS['socle_banc']['echecs'];
        $total = $reussis + count($echecs);
        if ($echecs !== []) {
            echo "ÉCHEC : $reussis/$total\n";
            foreach ($echecs as $e) echo "  - $e\n";
            exit(1);
        }
        echo "Tous les contrôles sont passés : $reussis/$total.\n";
        exit(0);
    }
}
