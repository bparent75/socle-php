<?php
/**
 * socle-php — paramètres modifiables par l'équipe (table `config`).
 *
 * Attend de l'application hôte :
 *   - bd(): PDO
 *   - la table `config` (voir sql/)
 *
 * Pourquoi ici : la même paire param()/param_definir() était réécrite dans cinq
 * projets. Une valeur que l'équipe peut changer (adresse de contact, version d'un
 * lien signé) vit en base plutôt que dans une constante ; socle_param() la consulte
 * déjà avant les constantes. Le cache est invalidé après écriture : une valeur
 * écrite se relit juste, sans surprise (défaut constaté dans trois des cinq copies).
 */
declare(strict_types=1);

if (!function_exists('param_valeurs')) {
    /**
     * Les valeurs de la table config, lues une fois par requête (relues après écriture).
     * Sans table config (projet qui n'utilise pas cette brique), rend un tableau vide
     * plutôt que d'échouer : les défauts s'appliquent.
     */
    function param_valeurs(bool $relire = false): array
    {
        static $cache = null;
        if ($cache === null || $relire) {
            try {
                $cache = bd()->query('SELECT cle, valeur FROM config')->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (PDOException) {
                $cache = [];
            }
        }
        return $cache;
    }
}

if (!function_exists('param')) {
    /** Valeur modifiable par l'équipe, avec défaut. Consultée par socle_param() avant les constantes. */
    function param(string $cle, string $defaut = ''): string
    {
        return param_valeurs()[$cle] ?? $defaut;
    }
}

if (!function_exists('param_definir')) {
    /** Écrit une valeur (création ou remplacement), et invalide le cache de lecture. */
    function param_definir(string $cle, string $valeur): void
    {
        bd()->prepare('INSERT INTO config (cle, valeur) VALUES (?, ?) ' . sql_sur_doublon('cle', ['valeur']))
            ->execute([$cle, $valeur]);
        param_valeurs(true);
    }
}
