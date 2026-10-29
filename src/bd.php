<?php
/**
 * socle-php — SQL portable (SQLite et MySQL) et migrations additives.
 *
 * Attend de l'application hôte : bd(): PDO.
 *
 * Pourquoi ici : les projets tournent sur SQLite (petits sites) ou MySQL (OVH,
 * multi-écritures), et les mêmes trois différences de dialecte étaient réécrites
 * partout — l'upsert, l'INSERT ignoré, et « ajouter une colonne si elle manque »,
 * seule forme de migration de ces projets (additive, rejouable à chaque accès).
 */
declare(strict_types=1);

if (!function_exists('bd_mysql')) {
    /** Vrai si la base servie par bd() est MySQL/MariaDB (sinon : SQLite). */
    function bd_mysql(): bool
    {
        return bd()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }
}

if (!function_exists('sql_insert_ignore')) {
    /** « INSERT IGNORE » (MySQL) ou « INSERT OR IGNORE » (SQLite) : à faire suivre de « INTO … ». */
    function sql_insert_ignore(): string
    {
        return bd_mysql() ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    }
}

if (!function_exists('sql_sur_doublon')) {
    /**
     * Fin d'un INSERT … VALUES (…) qui met à jour la ligne existante, pour les colonnes données :
     * « AS nouv ON DUPLICATE KEY UPDATE c = nouv.c » (MySQL 8.0.19+, où VALUES() est déprécié)
     * ou « ON CONFLICT(cles) DO UPDATE SET c = excluded.c » (SQLite).
     */
    function sql_sur_doublon(string $cles, array $colonnes): string
    {
        if (bd_mysql()) {
            return 'AS nouv ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "$c = nouv.$c", $colonnes));
        }
        return "ON CONFLICT($cles) DO UPDATE SET " . implode(', ', array_map(fn($c) => "$c = excluded.$c", $colonnes));
    }
}

if (!function_exists('colonne_assurer')) {
    /**
     * Ajoute une colonne si elle manque — idempotent, rejouable à chaque accès.
     * C'est la forme de migration de tous ces projets : on n'enlève ni ne renomme
     * jamais une colonne en route, on ajoute. $table et $colonne viennent du code,
     * jamais d'une saisie : ils sont tout de même bornés par prudence.
     */
    function colonne_assurer(PDO $pdo, string $table, string $colonne, string $definition): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table . '_' . $colonne)) {
            throw new InvalidArgumentException("nom de table ou de colonne invalide : $table.$colonne");
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $req = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns
                                  WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
            $req->execute([$table, $colonne]);
            if ((int)$req->fetchColumn() > 0) return;
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$colonne` $definition");
            return;
        }
        foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $c) {
            if (($c['name'] ?? '') === $colonne) return;
        }
        $pdo->exec("ALTER TABLE $table ADD COLUMN $colonne $definition");
    }
}
