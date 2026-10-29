<?php
/**
 * socle-php — connexion par lien magique et rôles.
 *
 * Attend de l'application hôte :
 *   - bd(): PDO, SEL_SITE, URL_BASE
 *   - les tables `utilisateur` et `jeton_connexion` (voir sql/), plus `tentative`
 *     (limitation) et `notification` (file de courriels)
 *   - facultatif, la constante ADMINS_AMORCAGE (secrets.php) : liste d'adresses, ou
 *     tableau adresse => nom — voir amorcer_admins()
 *   - facultatif, DUREE_LIEN_MAGIQUE (secondes, 1800 par défaut), NOM_SESSION,
 *     URL_CONNEXION (page de connexion, URL_BASE . '/connexion.php' par défaut)
 *
 * Pourquoi ici : cette brique était réécrite dans cinq projets (inscriptions, Libris,
 * calendrier, Orientation, Tristan), avec les mêmes décisions à chaque fois — et
 * parfois sans l'une d'elles. Elles sont donc figées ici :
 *   - aucun mot de passe : un lien à usage unique, valable 30 minutes, envoyé par
 *     courriel (la boîte de la personne fait office de preuve) ;
 *   - le jeton n'est JAMAIS stocké en clair : son empreinte HMAC va en base ;
 *   - la réponse est identique que l'adresse soit connue ou non (pas d'énumération
 *     des comptes) ; seule la limitation, commune à toutes, est signalée ;
 *   - l'anti-abus est en base (limiter()), par adresse ET par appelant : un compteur
 *     en session se contourne en jetant le cookie (défaut constaté sur le calendrier) ;
 *   - l'adresse n'apparaît pas en clair dans la table des tentatives (empreinte) ;
 *   - la consommation du jeton est ATOMIQUE (UPDATE … WHERE utilise_le IS NULL) :
 *     deux clics simultanés n'ouvrent qu'une session ;
 *   - session_regenerate_id() à chaque ouverture (fixation de session).
 *
 * Le rôle est une colonne de l'utilisateur : « admin » a tous les droits, les autres
 * valeurs appartiennent au projet (« equipe », « compta »…). Un projet à rôles
 * multiples ou par saison (Orientation, calendrier) garde sa propre table : ces
 * fonctions sont gardées par function_exists, il surcharge celles qu'il veut.
 */
declare(strict_types=1);

if (!function_exists('duree_lien_magique')) {
    /**
     * Durée de vie d'un lien de connexion, en secondes : constante DUREE_LIEN_MAGIQUE
     * du projet, sinon 30 minutes. Lue à l'appel, jamais au chargement : l'autochargeur
     * Composer passe avant le config.php du projet, qui définit ses constantes après.
     */
    function duree_lien_magique(): int
    {
        return defined('DUREE_LIEN_MAGIQUE') ? max(60, (int)DUREE_LIEN_MAGIQUE) : 1800;
    }
}

if (!function_exists('amorcer_admins')) {
    /**
     * Crée les comptes administrateurs déclarés dans secrets.php s'ils n'existent pas.
     * ADMINS_AMORCAGE : liste d'adresses, ou tableau adresse => nom. À appeler après la
     * création du schéma. Les adresses en example.invalid (modèle recopié tel quel)
     * sont ignorées. Un compte existant n'est jamais modifié : désactiver un ancien
     * admin reste possible sans qu'il renaisse au prochain amorçage.
     */
    function amorcer_admins(): void
    {
        if (!defined('ADMINS_AMORCAGE')) return;
        $ins = bd()->prepare(sql_insert_ignore() . ' INTO utilisateur (courriel, nom, role, actif, cree_le)
                             VALUES (?, ?, \'admin\', 1, ?)');
        foreach ((array)ADMINS_AMORCAGE as $cle => $valeur) {
            [$courriel, $nom] = is_string($cle) ? [$cle, (string)$valeur] : [(string)$valeur, ''];
            $courriel = mb_strtolower(trim($courriel));
            if ($courriel === '' || str_ends_with($courriel, 'example.invalid')) continue;
            $ins->execute([$courriel, $nom, maintenant()]);
        }
    }
}

if (!function_exists('utilisateur_par_courriel')) {
    /** L'utilisateur portant cette adresse (casse ignorée), actif ou non, ou null. */
    function utilisateur_par_courriel(string $courriel): ?array
    {
        $req = bd()->prepare('SELECT * FROM utilisateur WHERE courriel = ?');
        $req->execute([mb_strtolower(trim($courriel))]);
        return $req->fetch() ?: null;
    }
}

if (!function_exists('utilisateur_courant')) {
    /** La personne connectée (ligne de la table utilisateur), ou null. Lu une fois par requête. */
    function utilisateur_courant(): ?array
    {
        static $u = false;
        if ($u !== false) return $u;
        if (PHP_SAPI === 'cli') return $u = null;
        demarrer_session();
        $id = (int)($_SESSION['utilisateur_id'] ?? 0);
        if ($id <= 0) return $u = null;
        $req = bd()->prepare('SELECT * FROM utilisateur WHERE id = ? AND actif = 1');
        $req->execute([$id]);
        return $u = ($req->fetch() ?: null);
    }
}

if (!function_exists('connexion_ouvrir')) {
    /** Ouvre la session de cet utilisateur (identifiant de session régénéré, connexion journalisée). */
    function connexion_ouvrir(int $utilisateur_id): void
    {
        demarrer_session();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['utilisateur_id'] = $utilisateur_id;
        }
        bd()->prepare('UPDATE utilisateur SET derniere_connexion = ? WHERE id = ?')
            ->execute([maintenant(), $utilisateur_id]);
        journaliser('connexion', 'utilisateur', $utilisateur_id, '', $utilisateur_id);
    }
}

if (!function_exists('connexion_fermer')) {
    /** Ferme la session (déconnexion journalisée). À appeler depuis un POST avec jeton CSRF. */
    function connexion_fermer(): void
    {
        demarrer_session();
        $id = (int)($_SESSION['utilisateur_id'] ?? 0);
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        if ($id > 0) journaliser('deconnexion', 'utilisateur', $id, '', $id);
    }
}

if (!function_exists('lien_magique_envoyer')) {
    /**
     * Demande d'un lien de connexion. Ne révèle jamais si l'adresse est connue : le
     * retour ne dit qu'une chose, si la demande a été bloquée par la limitation (faux),
     * ce qui vaut pour toute adresse, connue ou non. Une limite atteinte sans le dire
     * fait croire que les courriels ne partent plus (constaté le 16 octobre 2026).
     *
     * Le lien est envoyé en urgence (il expire vite, la tâche planifiée peut être
     * horaire), depuis l'adresse technique. $url : la page qui recevra ?jeton=…,
     * URL_CONNEXION ou URL_BASE . '/connexion.php' à défaut.
     */
    function lien_magique_envoyer(string $courriel, ?string $url = null): bool
    {
        $courriel = mb_strtolower(trim($courriel));
        if ($courriel === '' || filter_var($courriel, FILTER_VALIDATE_EMAIL) === false) return true;
        if (!limiter('lien-courriel-' . substr(hash_hmac('sha256', $courriel, SEL_SITE), 0, 16), 5, 900)) return false;
        if (!limiter('lien-ip-' . empreinte_appelant(), 12, 900)) return false;

        $u = utilisateur_par_courriel($courriel);
        if ($u === null || (int)$u['actif'] !== 1) return true;   // réponse identique : on s'arrête là

        $jeton = jeton_aleatoire();
        bd()->prepare('INSERT INTO jeton_connexion (utilisateur_id, empreinte, expire_le, cree_le)
                       VALUES (?, ?, ?, ?)')
            ->execute([(int)$u['id'], hacher_jeton($jeton),
                       gmdate('Y-m-d\TH:i:s\Z', time() + duree_lien_magique()), maintenant()]);

        $url ??= defined('URL_CONNEXION') ? (string)URL_CONNEXION : URL_BASE . '/connexion.php';
        $lien = $url . (str_contains($url, '?') ? '&' : '?') . 'jeton=' . rawurlencode($jeton);
        $minutes = (int)round(duree_lien_magique() / 60);
        // L'URL seule sur son paragraphe : le gabarit du socle en fait un bouton.
        filer_courriel($courriel, 'Votre lien de connexion',
            "Bonjour,\n\nPour ouvrir votre session " . socle_param('MARQUE_NOM', '')
            . ", utilisez le bouton ci-dessous.\n\n" . $lien
            . "\n\nCe lien est valable $minutes minutes et ne sert qu'une fois. "
            . "Si vous n'êtes pas à l'origine de cette demande, ignorez simplement ce message.",
            (int)$u['id'], '', null, 'connexion', (int)$u['id'], true, expediteur_technique());
        journaliser('lien_connexion_envoye', 'utilisateur', (int)$u['id']);
        return true;
    }
}

if (!function_exists('lien_magique_consommer')) {
    /**
     * Consomme un jeton de lien magique et ouvre la session. Rend l'utilisateur
     * connecté, ou null (jeton inconnu, déjà servi, expiré, compte désactivé, ou trop
     * d'essais depuis cet appareil). La consommation est atomique : deux clics
     * simultanés sur le même lien n'ouvrent qu'une session.
     */
    function lien_magique_consommer(string $jeton): ?array
    {
        if ($jeton === '' || !limiter('jeton-ip-' . empreinte_appelant(), 20, 900)) return null;

        $req = bd()->prepare('SELECT j.id, j.utilisateur_id FROM jeton_connexion j
                              JOIN utilisateur u ON u.id = j.utilisateur_id
                              WHERE j.empreinte = ? AND j.utilise_le IS NULL AND j.expire_le > ? AND u.actif = 1');
        $req->execute([hacher_jeton($jeton), maintenant()]);
        $ligne = $req->fetch();
        if (!$ligne) return null;

        $maj = bd()->prepare('UPDATE jeton_connexion SET utilise_le = ? WHERE id = ? AND utilise_le IS NULL');
        $maj->execute([maintenant(), (int)$ligne['id']]);
        if ($maj->rowCount() !== 1) return null;   // consommé par un autre clic au même instant

        // Purge d'entretien : jetons expirés depuis plus d'un jour.
        bd()->prepare('DELETE FROM jeton_connexion WHERE expire_le < ?')
            ->execute([gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]);

        connexion_ouvrir((int)$ligne['utilisateur_id']);
        $u = bd()->prepare('SELECT * FROM utilisateur WHERE id = ?');
        $u->execute([(int)$ligne['utilisateur_id']]);
        return $u->fetch() ?: null;
    }
}

if (!function_exists('est_admin')) {
    /** Vrai si la personne (connectée par défaut) a le rôle d'administration complète. */
    function est_admin(?array $u = null): bool
    {
        $u ??= utilisateur_courant();
        return $u !== null && ($u['role'] ?? '') === 'admin';
    }
}

if (!function_exists('exiger_connexion')) {
    /** À appeler en tête de chaque page privée. Redirige vers la page de connexion sinon. */
    function exiger_connexion(): array
    {
        $u = utilisateur_courant();
        if ($u === null) {
            header('Location: ' . (defined('URL_CONNEXION') ? (string)URL_CONNEXION : URL_BASE . '/connexion.php'));
            exit;
        }
        return $u;
    }
}

if (!function_exists('exiger_role')) {
    /**
     * Page réservée à certains rôles (« admin » y a toujours accès). Un compte connecté
     * d'un autre rôle reçoit une page 403 lisible, pas une redirection muette.
     */
    function exiger_role(string ...$roles): array
    {
        $u = exiger_connexion();
        if (($u['role'] ?? '') !== 'admin' && !in_array($u['role'] ?? '', $roles, true)) {
            http_response_code(403);
            entetes_prives();
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Accès réservé</title></head>'
               . '<body style="font-family:system-ui,sans-serif;padding:2rem"><h1>Accès réservé</h1>'
               . '<p>Cette page n\'est pas ouverte à votre compte.</p></body></html>';
            exit;
        }
        return $u;
    }
}

if (!function_exists('exiger_admin')) {
    /** Pages réservées à l'administration complète. */
    function exiger_admin(): array
    {
        return exiger_role('admin');
    }
}

if (!function_exists('dernier_admin_actif')) {
    /** Vrai si cet utilisateur est le dernier admin actif : on ne le désactive pas, on ne le rétrograde pas. */
    function dernier_admin_actif(int $id): bool
    {
        $autres = bd()->prepare("SELECT COUNT(*) FROM utilisateur WHERE actif = 1 AND role = 'admin' AND id != ?");
        $autres->execute([$id]);
        if ((int)$autres->fetchColumn() > 0) return false;
        $lui = bd()->prepare("SELECT 1 FROM utilisateur WHERE id = ? AND actif = 1 AND role = 'admin'");
        $lui->execute([$id]);
        return (bool)$lui->fetchColumn();
    }
}
