<?php
/**
 * socle-php — surveillance de la tâche planifiée : témoin de passage, alerte si silence.
 *
 * Attend de l'application hôte :
 *   - bd(): PDO, maintenant(), la table `config` (voir sql/) et src/parametres.php
 *   - pour l'alerte : la file de courriels (src/courriel.php) et une adresse, ALERTE_COURRIEL
 *     ou à défaut CONTACT_COURRIEL (constante ou param(), voir socle_param())
 *
 * Pourquoi ici : sur un mutualisé, une tâche planifiée peut cesser de tourner sans que
 * personne ne s'en aperçoive (offre changée, chemin PHP modifié, tâche désactivée par
 * l'hébergeur). Les courriels en file ne partent plus, les relances non plus, et on le
 * découvre des semaines plus tard. Les inscriptions ateliers notaient déjà un témoin
 * (`cron_dernier_passage`, affiché à l'équipe) ; il manquait l'alerte.
 *
 * Usage :
 *   - en tête de cron.php : cron_temoin();
 *   - dans une page que l'équipe ouvre souvent (accueil d'administration), ou en fin de
 *     config.php : cron_surveiller('cron', 2 * 3600);
 *
 * L'alerte part d'une requête web, jamais du cron lui-même — c'est lui qui se tait. Elle
 * est expédiée sur-le-champ (la file, que le cron traite, ne partirait pas), et une seule
 * fois par épisode de silence : le prochain passage du cron réarme la surveillance.
 */
declare(strict_types=1);

if (!function_exists('cron_temoin')) {
    /**
     * Note le passage de la tâche. Le nom par défaut écrit `cron_dernier_passage`, la clé
     * déjà lue par les écrans des inscriptions : la reprise se fait sans migration.
     */
    function cron_temoin(string $tache = 'cron'): void
    {
        param_definir($tache . '_dernier_passage', maintenant());
    }
}

if (!function_exists('cron_dernier_passage')) {
    /** Date UTC du dernier passage (forme de maintenant()), ou null si la tâche n'a jamais tourné. */
    function cron_dernier_passage(string $tache = 'cron'): ?string
    {
        $v = param($tache . '_dernier_passage');
        return $v !== '' ? $v : null;
    }
}

if (!function_exists('cron_situation')) {
    /**
     * État de la tâche : ['dernier' => ?string, 'silence' => ?int (secondes), 'en_retard' => bool].
     * Une tâche qui n'a jamais tourné est en retard : l'écran de l'équipe doit le montrer
     * (tâche jamais créée chez l'hébergeur).
     */
    function cron_situation(string $tache = 'cron', int $delai_max = 7200): array
    {
        $dernier = cron_dernier_passage($tache);
        $ts = $dernier !== null ? strtotime($dernier) : false;
        $silence = $ts !== false ? max(0, time() - $ts) : null;
        return ['dernier' => $dernier, 'silence' => $silence,
                'en_retard' => $silence === null || $silence > $delai_max];
    }
}

if (!function_exists('cron_surveiller')) {
    /**
     * Alerte par courriel si la tâche se tait depuis plus de $delai_max secondes.
     * Rend true si une alerte vient d'être mise en file.
     *
     * Une tâche qui n'a JAMAIS tourné ne déclenche pas d'alerte : c'est aussi le cas de
     * toute copie locale ou de démonstration, qui écrirait sinon à l'équipe à chaque
     * installation (choix déjà fait par Libris). cron_situation() la signale en retard,
     * à l'écran de montrer « jamais ».
     *
     * Une seule alerte par épisode : la clé `<tache>_alerte` retient le dernier passage
     * déjà signalé, et la réclamation se fait par un UPDATE conditionnel — deux requêtes
     * simultanées n'envoient pas deux courriels. Coût hors alerte : une lecture du cache
     * de param(), sans requête supplémentaire.
     */
    function cron_surveiller(string $tache = 'cron', int $delai_max = 7200, ?string $destinataire = null): bool
    {
        $etat = cron_situation($tache, $delai_max);
        if (!$etat['en_retard'] || $etat['dernier'] === null) return false;
        $episode = $etat['dernier'];
        $cle = $tache . '_alerte';
        if (param($cle) === $episode) return false;

        $destinataire ??= socle_param('ALERTE_COURRIEL', socle_param('CONTACT_COURRIEL'));
        if ($destinataire === '') return false;

        bd()->prepare(sql_insert_ignore() . " INTO config (cle, valeur) VALUES (?, '')")->execute([$cle]);
        $r = bd()->prepare('UPDATE config SET valeur = ? WHERE cle = ? AND valeur <> ?');
        $r->execute([$episode, $cle, $episode]);
        param_valeurs(true);
        if ($r->rowCount() !== 1) return false;   // une autre requête a déjà signalé cet épisode

        $site = socle_param('MARQUE_NOM', defined('URL_BASE') ? (string) URL_BASE : 'le site');
        $quand = 'Son dernier passage remonte au ' . date_fr($etat['dernier']) . '.';
        $corps = "La tâche planifiée « $tache » de $site ne passe plus. $quand\n\n"
               . "Tant qu’elle est arrêtée, ce qu’elle assure ne se fait plus : en général l’envoi "
               . "des courriels en file, les relances et les purges.\n\n"
               . "À vérifier chez l’hébergeur : la tâche existe-t-elle encore, avec le bon chemin "
               . "et la bonne version de PHP ? Ses derniers journaux d’exécution disent pourquoi elle échoue.\n\n"
               . "Ce message ne sera pas répété : la surveillance se réarme au prochain passage de la tâche.";
        return filer_courriel($destinataire, "Tâche planifiée arrêtée — $site", $corps,
                              null, '', null, 'cron_alerte', null, true);
    }
}
