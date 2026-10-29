<?php
/**
 * socle-php — journal d'audit.
 *
 * Attend de l'application hôte : bd(): PDO, maintenant(), et la table `journal`.
 *
 * Pourquoi ici : la règle qui compte n'est pas technique mais éditoriale — le journal
 * trace QUI a fait QUOI sur QUEL objet, et ne contient jamais le contenu manipulé.
 * C'est ce qui permet de le montrer à un tiers sans exposer de données personnelles.
 */
declare(strict_types=1);

if (!function_exists('journaliser')) {
    /**
     * Consigne une action.
     *
     * $detail sert à qualifier l'action, pas à recopier la donnée : « souscription 12 »,
     * « v2 publiée », « dossier non autorisé ». Jamais le texte d'un dossier, jamais un
     * mot de passe, jamais une adresse complète.
     */
    function journaliser(string $action, string $objet_type = '', ?int $objet_id = null,
                         string $detail = '', ?int $utilisateur_id = null): void
    {
        bd()->prepare('INSERT INTO journal (utilisateur_id, action, objet_type, objet_id, detail, cree_le)
                       VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$utilisateur_id, $action, $objet_type, $objet_id,
                       mb_substr($detail, 0, 500), maintenant()]);
    }
}

if (!function_exists('journal_recent')) {
    /** Dernières entrées, pour un écran d'administration. */
    function journal_recent(int $limite = 200): array
    {
        $req = bd()->prepare('SELECT * FROM journal ORDER BY cree_le DESC LIMIT ' . max(1, $limite));
        $req->execute();
        return $req->fetchAll();
    }
}
