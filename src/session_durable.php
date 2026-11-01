<?php
/**
 * socle-php — navigateur de confiance (2.3) : après un second facteur réussi, ce navigateur ne
 * le redemande plus pendant 30 jours. Le premier facteur (mot de passe ou lien) reste exigé à
 * chaque connexion : le cookie ne rouvre jamais une session à lui seul.
 *
 * Jusqu'à la 2.2, ce même cookie servait à « Rester connecté 30 jours » (il rouvrait la
 * session). Règle simplifiée par Brice le 1er novembre 2026 (spécification commune, D17) :
 * une heure d'inactivité ferme la session, et la confiance du navigateur dispense du code.
 * Table et fonctions sont gardées telles quelles ; une ligne ouverte avant la 2.3 avec un
 * second facteur (« mdp+totp »…) vaut confiance jusqu'à son échéance, une ligne sans ne vaut
 * rien.
 *
 * Attend : bd(), SEL_SITE, la table auth_session_durable (voir sql/) ; facultatif,
 * NOM_COOKIE_DURABLE (à défaut : NOM_SESSION . '_durable') et DUREE_SESSION_DURABLE
 * (30 jours, en secondes).
 *
 * Motif sélecteur / validateur : le cookie porte « sélecteur:validateur ». Le sélecteur,
 * en clair en base, retrouve la ligne ; seule l'empreinte HMAC du validateur est stockée, et
 * comparée en temps constant. Une fuite de la base ne donne donc aucun cookie valable.
 *
 * Décisions (spécification commune d'octobre 2026, D17 de novembre) :
 *   - durée ABSOLUE de 30 jours, comptée depuis le code, jamais prolongée par l'usage ;
 *   - le validateur est renouvelé au plus une fois par 24 h (pas à chaque requête : deux
 *     onglets ouverts en même temps se déconnecteraient l'un l'autre) ;
 *   - un validateur faux sur un sélecteur connu = cookie volé ou copié : la ligne est
 *     supprimée et la personne prévenue ;
 *   - la ligne retient les facteurs de la connexion d'origine (« mdp+totp »…) : seule une ligne
 *     ouverte avec un second facteur vaut confiance ;
 *   - la déconnexion ne retire pas la confiance (c'est tout son intérêt) ; « Retirer » dans la
 *     liste du compte, si ;
 *   - les lignes des autres navigateurs tombent au changement de mot de passe ou de second
 *     facteur, toutes à la désactivation et à la réinitialisation par un admin.
 */
declare(strict_types=1);

if (!function_exists('durable_duree')) {
    function durable_duree(): int
    {
        return defined('DUREE_SESSION_DURABLE') ? max(3600, (int)DUREE_SESSION_DURABLE) : 30 * 86400;
    }
}

if (!function_exists('durable_nom_cookie')) {
    /**
     * Nom du cookie. En HTTPS, préfixe __Host- : le navigateur refuse alors tout cookie de ce
     * nom qui ne serait pas Secure, sans domaine et sur le chemin « / » (pas de cookie posé par
     * un sous-domaine voisin).
     */
    function durable_nom_cookie(): string
    {
        $base = defined('NOM_COOKIE_DURABLE') ? (string)NOM_COOKIE_DURABLE
              : (defined('NOM_SESSION') ? (string)NOM_SESSION : 'socle_session') . '_durable';
        return (requete_https() ? '__Host-' : '') . $base;
    }
}

if (!function_exists('durable_cookie_poser')) {
    /** Pose (ou efface, $expire = 0) le cookie ; $_COOKIE suit, pour la suite de la requête. */
    function durable_cookie_poser(string $valeur, int $expire): void
    {
        $nom = durable_nom_cookie();
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie($nom, $valeur, ['expires' => $expire > 0 ? $expire : time() - 3600, 'path' => '/',
                                      'secure' => requete_https(), 'httponly' => true, 'samesite' => 'Lax']);
        }
        if ($expire > 0) $_COOKIE[$nom] = $valeur; else unset($_COOKIE[$nom]);
    }
}

if (!function_exists('durable_appareil')) {
    /** « Safari · macOS » : de quoi reconnaître l'appareil dans la liste, rien de plus. */
    function durable_appareil(string $agent): string
    {
        $navigateur = match (true) {
            str_contains($agent, 'Edg/')     => 'Edge',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/')  => 'Chrome',
            str_contains($agent, 'Safari/')  => 'Safari',
            default                          => 'Navigateur',
        };
        $systeme = match (true) {
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Android')   => 'Android',
            str_contains($agent, 'Mac OS X')  => 'macOS',
            str_contains($agent, 'Windows')   => 'Windows',
            str_contains($agent, 'Linux')     => 'Linux',
            default                           => '',
        };
        return $navigateur . ($systeme !== '' ? " · $systeme" : '');
    }
}

if (!function_exists('durable_ouvrir')) {
    /**
     * Ce navigateur devient de confiance pour ce compte (30 jours), avec les facteurs prouvés.
     * Une seule confiance par navigateur : celle qu'il portait déjà (même pour un autre compte)
     * est remplacée.
     */
    function durable_ouvrir(int $id, string $facteurs): void
    {
        durable_fermer();
        $selecteur = jeton_aleatoire(16);
        $validateur = jeton_aleatoire(32);
        $expire = time() + durable_duree();
        bd()->prepare('INSERT INTO auth_session_durable (compte_id, selecteur, validateur_empreinte, facteurs, appareil,
                                                         cree_le, expire_le, derniere_utilisation, derniere_rotation)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $selecteur, hacher_jeton($validateur), $facteurs,
                       durable_appareil((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), maintenant(),
                       gmdate('Y-m-d\TH:i:s\Z', $expire), maintenant(), maintenant()]);
        durable_cookie_poser($selecteur . ':' . $validateur, $expire);
        journaliser('session_durable_ouverte', 'utilisateur', $id, '', $id);
    }
}

if (!function_exists('durable_reprendre')) {
    /**
     * Le cookie de ce navigateur désigne-t-il une confiance valable ? Rend
     * ['compte_id' => …, 'facteurs' => …] (cookie renouvelé au besoin), ou null (cookie effacé).
     * N'ouvre pas la session : voir auth_premier_facteur().
     */
    function durable_reprendre(): ?array
    {
        $brut = (string)($_COOKIE[durable_nom_cookie()] ?? '');
        if ($brut === '') return null;
        if (!preg_match('/^([A-Za-z0-9_-]{20,30}):([A-Za-z0-9_-]{40,50})$/', $brut, $m)) {
            durable_cookie_poser('', 0);
            return null;
        }
        $req = bd()->prepare('SELECT * FROM auth_session_durable WHERE selecteur = ?');
        $req->execute([$m[1]]);
        $ligne = $req->fetch();
        if (!$ligne) { durable_cookie_poser('', 0); return null; }

        $id = (int)$ligne['compte_id'];
        if (!hash_equals((string)$ligne['validateur_empreinte'], hacher_jeton($m[2]))) {
            // Renouvelé il y a moins de deux minutes : un autre onglet a présenté l'ancien cookie
            // en même temps. Pas un vol ; on ne touche ni à la ligne ni au cookie (la réponse de
            // l'autre onglet pose le bon).
            if ((string)$ligne['derniere_rotation'] > gmdate('Y-m-d\TH:i:s\Z', time() - 120)) return null;
            bd()->prepare('DELETE FROM auth_session_durable WHERE id = ?')->execute([(int)$ligne['id']]);
            durable_cookie_poser('', 0);
            journaliser('session_durable_volee', 'utilisateur', $id, (string)$ligne['appareil']);
            auth_aviser($id, 'Navigateur de confiance retiré',
                "Le cookie d'un navigateur de confiance de votre compte (" . $ligne['appareil'] . ") a été présenté "
                . "avec une clé fausse : il a peut-être été copié. Ce navigateur n'est plus de confiance par "
                . "précaution ; le code de double authentification y sera simplement redemandé.");
            return null;
        }
        $compte = auth_compte($id);
        if ((string)$ligne['expire_le'] <= maintenant() || !$compte || !auth_compte_actif($compte)) {
            bd()->prepare('DELETE FROM auth_session_durable WHERE id = ?')->execute([(int)$ligne['id']]);
            durable_cookie_poser('', 0);
            return null;
        }

        $maintenant = maintenant();
        if ((string)$ligne['derniere_rotation'] < gmdate('Y-m-d\TH:i:s\Z', time() - 86400)) {
            $validateur = jeton_aleatoire(32);
            bd()->prepare('UPDATE auth_session_durable SET validateur_empreinte = ?, derniere_rotation = ?,
                           derniere_utilisation = ? WHERE id = ?')
                ->execute([hacher_jeton($validateur), $maintenant, $maintenant, (int)$ligne['id']]);
            durable_cookie_poser($m[1] . ':' . $validateur, (int)strtotime((string)$ligne['expire_le']));
        } else {
            bd()->prepare('UPDATE auth_session_durable SET derniere_utilisation = ? WHERE id = ?')
                ->execute([$maintenant, (int)$ligne['id']]);
        }
        return ['compte_id' => $id, 'facteurs' => (string)$ligne['facteurs']];
    }
}

if (!function_exists('durable_fermer')) {
    /** Ce navigateur n'est plus de confiance : sa ligne est supprimée, le cookie effacé. */
    function durable_fermer(): void
    {
        $brut = (string)($_COOKIE[durable_nom_cookie()] ?? '');
        if ($brut !== '') {
            bd()->prepare('DELETE FROM auth_session_durable WHERE selecteur = ?')->execute([explode(':', $brut)[0]]);
        }
        durable_cookie_poser('', 0);
    }
}

if (!function_exists('durables_lister')) {
    /** Les navigateurs de confiance de ce compte, du plus récent au plus ancien (page Sécurité). */
    function durables_lister(int $id): array
    {
        $req = bd()->prepare('SELECT id, appareil, facteurs, cree_le, expire_le, derniere_utilisation
                              FROM auth_session_durable WHERE compte_id = ? AND expire_le > ?
                              ORDER BY derniere_utilisation DESC');
        $req->execute([$id, maintenant()]);
        return $req->fetchAll();
    }
}

if (!function_exists('durable_revoquer')) {
    /** « Retirer » un navigateur de confiance : une ligne, et seulement si elle appartient bien à ce compte. */
    function durable_revoquer(int $ligne, int $id): void
    {
        bd()->prepare('DELETE FROM auth_session_durable WHERE id = ? AND compte_id = ?')->execute([$ligne, $id]);
        journaliser('session_durable_revoquee', 'utilisateur', $id, '', $id);
    }
}

if (!function_exists('durable_revoquer_tout')) {
    /**
     * Tous les navigateurs de confiance du compte (mot de passe ou facteur changé, compte
     * désactivé…) ; $sauf_celui_ci : sauf le navigateur de la requête, quand c'est la personne
     * elle-même qui agit.
     */
    function durable_revoquer_tout(int $id, bool $sauf_celui_ci = false): void
    {
        $selecteur = $sauf_celui_ci ? explode(':', (string)($_COOKIE[durable_nom_cookie()] ?? ''))[0] : '';
        $req = bd()->prepare('DELETE FROM auth_session_durable WHERE compte_id = ? AND selecteur <> ?');
        $req->execute([$id, $selecteur]);
        if ($req->rowCount() > 0) journaliser('session_durable_revoquee', 'utilisateur', $id, 'toutes');
    }
}
