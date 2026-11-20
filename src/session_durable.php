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
 * « Rester connecté » revient en 2.5 (D18, révision de D17 par Brice le 20 novembre 2026), en
 * OPTION : rien ne change pour un projet qui ne déclare pas AUTH_RESTER_CONNECTE (voir
 * authentification.php). Même table, cookie distinct (NOM_SESSION . '_appareil') : une ligne
 * « appareil » porte sa durée d'inactivité (colonne `inactivite`, 2.5) et rouvre la session tant
 * qu'elle sert ; une ligne de confiance (inactivite NULL) ne rouvre jamais rien. Un projet qui
 * active l'option ajoute la colonne : ALTER TABLE auth_session_durable ADD COLUMN inactivite INT NULL.
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

if (!function_exists('appareil_nom_cookie')) {
    /** Cookie « Rester connecté » (2.5), distinct de celui de la confiance ; même préfixe __Host- en HTTPS. */
    function appareil_nom_cookie(): string
    {
        return (requete_https() ? '__Host-' : '') . (defined('NOM_SESSION') ? (string)NOM_SESSION : 'socle_session') . '_appareil';
    }
}

if (!function_exists('durable_cookie_poser')) {
    /**
     * Pose (ou efface, $expire = 0) le cookie ; $_COOKIE suit, pour la suite de la requête.
     * $nom : le cookie de la confiance à défaut, appareil_nom_cookie() pour « rester connecté ».
     */
    function durable_cookie_poser(string $valeur, int $expire, string $nom = ''): void
    {
        $nom = $nom !== '' ? $nom : durable_nom_cookie();
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
        durable_inserer($id, $facteurs, time() + durable_duree(), null, durable_nom_cookie());
        journaliser('session_durable_ouverte', 'utilisateur', $id, '', $id);
    }
}

if (!function_exists('durable_inserer')) {
    /**
     * Interne : une ligne et son cookie « sélecteur:validateur ». $inactivite null : confiance (la
     * colonne n'est pas nommée, un projet resté en 2.3 ne l'a pas) ; sinon « rester connecté ».
     * Rend l'identifiant de la ligne.
     */
    function durable_inserer(int $id, string $facteurs, int $expire, ?int $inactivite, string $nom): int
    {
        $selecteur = jeton_aleatoire(16);
        $validateur = jeton_aleatoire(32);
        $colonne = $inactivite !== null ? ', inactivite' : '';
        $valeurs = [$id, $selecteur, hacher_jeton($validateur), $facteurs,
                    durable_appareil((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), maintenant(),
                    gmdate('Y-m-d\TH:i:s\Z', $expire), maintenant(), maintenant()];
        if ($inactivite !== null) $valeurs[] = $inactivite;
        bd()->prepare("INSERT INTO auth_session_durable (compte_id, selecteur, validateur_empreinte, facteurs, appareil,
                                                         cree_le, expire_le, derniere_utilisation, derniere_rotation$colonne)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?" . ($inactivite !== null ? ', ?' : '') . ')')
            ->execute($valeurs);
        $ligne = (int)bd()->lastInsertId();
        durable_cookie_poser($selecteur . ':' . $validateur, $expire, $nom);
        return $ligne;
    }
}

if (!function_exists('appareil_ouvrir')) {
    /**
     * « Rester connecté » (2.5) : cet appareil rouvre la session de ce compte tant qu'il sert au moins
     * une fois par $inactivite secondes, et au plus $duree secondes à compter d'ici. Remplace l'appareil
     * que ce navigateur portait déjà. Rend l'identifiant de la ligne (à garder en session : voir
     * auth_session_controler()).
     */
    function appareil_ouvrir(int $id, string $facteurs, int $inactivite, int $duree): int
    {
        appareil_fermer();
        $ligne = durable_inserer($id, $facteurs, time() + $duree, max(60, $inactivite), appareil_nom_cookie());
        journaliser('appareil_ouvert', 'utilisateur', $id, '', $id);
        return $ligne;
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
        $ligne = durable_ligne_reprendre(durable_nom_cookie(), false);
        return $ligne === null ? null : ['compte_id' => (int)$ligne['compte_id'], 'facteurs' => (string)$ligne['facteurs']];
    }
}

if (!function_exists('appareil_reprendre')) {
    /**
     * Le cookie « rester connecté » de ce navigateur désigne-t-il un appareil valable (ni expiré, ni
     * inactif depuis plus que sa durée, compte actif) ? Rend la ligne (cookie renouvelé au besoin), ou
     * null (cookie effacé). N'ouvre pas la session : voir auth_session_controler().
     */
    function appareil_reprendre(): ?array
    {
        return durable_ligne_reprendre(appareil_nom_cookie(), true);
    }
}

if (!function_exists('durable_ligne_reprendre')) {
    /** Interne : la ligne désignée par ce cookie, vérifiée (voir durable_reprendre(), appareil_reprendre()). */
    function durable_ligne_reprendre(string $nom, bool $appareil): ?array
    {
        $brut = (string)($_COOKIE[$nom] ?? '');
        if ($brut === '') return null;
        if (!preg_match('/^([A-Za-z0-9_-]{20,30}):([A-Za-z0-9_-]{40,50})$/', $brut, $m)) {
            durable_cookie_poser('', 0, $nom);
            return null;
        }
        $req = bd()->prepare('SELECT * FROM auth_session_durable WHERE selecteur = ?');
        $req->execute([$m[1]]);
        $ligne = $req->fetch();
        // Une ligne de l'autre nature (confiance présentée comme appareil, ou l'inverse) ne vaut rien ici.
        if (!$ligne || $appareil !== ((int)($ligne['inactivite'] ?? 0) > 0)) { durable_cookie_poser('', 0, $nom); return null; }

        $id = (int)$ligne['compte_id'];
        if (!hash_equals((string)$ligne['validateur_empreinte'], hacher_jeton($m[2]))) {
            // Renouvelé il y a moins de deux minutes : un autre onglet a présenté l'ancien cookie
            // en même temps. Pas un vol ; on ne touche ni à la ligne ni au cookie (la réponse de
            // l'autre onglet pose le bon).
            if ((string)$ligne['derniere_rotation'] > gmdate('Y-m-d\TH:i:s\Z', time() - 120)) return null;
            bd()->prepare('DELETE FROM auth_session_durable WHERE id = ?')->execute([(int)$ligne['id']]);
            durable_cookie_poser('', 0, $nom);
            journaliser('session_durable_volee', 'utilisateur', $id, (string)$ligne['appareil']);
            if ($appareil) {
                auth_aviser($id, 'Appareil déconnecté',
                    "Le cookie « rester connecté » d'un appareil de votre compte (" . $ligne['appareil'] . ") a été "
                    . "présenté avec une clé fausse : il a peut-être été copié. Cet appareil est déconnecté par "
                    . "précaution ; il suffit de vous y reconnecter.");
            } else {
                auth_aviser($id, 'Navigateur de confiance retiré',
                    "Le cookie d'un navigateur de confiance de votre compte (" . $ligne['appareil'] . ") a été présenté "
                    . "avec une clé fausse : il a peut-être été copié. Ce navigateur n'est plus de confiance par "
                    . "précaution ; le code de double authentification y sera simplement redemandé.");
            }
            return null;
        }
        $compte = auth_compte($id);
        $inactif = $appareil && (string)$ligne['derniere_utilisation'] < gmdate('Y-m-d\TH:i:s\Z', time() - (int)$ligne['inactivite']);
        if ((string)$ligne['expire_le'] <= maintenant() || $inactif || !$compte || !auth_compte_actif($compte)) {
            bd()->prepare('DELETE FROM auth_session_durable WHERE id = ?')->execute([(int)$ligne['id']]);
            durable_cookie_poser('', 0, $nom);
            return null;
        }

        $maintenant = maintenant();
        if ((string)$ligne['derniere_rotation'] < gmdate('Y-m-d\TH:i:s\Z', time() - 86400)) {
            $validateur = jeton_aleatoire(32);
            bd()->prepare('UPDATE auth_session_durable SET validateur_empreinte = ?, derniere_rotation = ?,
                           derniere_utilisation = ? WHERE id = ?')
                ->execute([hacher_jeton($validateur), $maintenant, $maintenant, (int)$ligne['id']]);
            durable_cookie_poser($m[1] . ':' . $validateur, (int)strtotime((string)$ligne['expire_le']), $nom);
        } else {
            bd()->prepare('UPDATE auth_session_durable SET derniere_utilisation = ? WHERE id = ?')
                ->execute([$maintenant, (int)$ligne['id']]);
        }
        $ligne['compte_id'] = $id;
        return $ligne;
    }
}

if (!function_exists('appareil_controler')) {
    /**
     * Une session rouverte ou ouverte avec « rester connecté » vaut ce que vaut sa ligne : encore là
     * (« Retirer », mot de passe changé…), pas expirée, servie depuis moins que sa durée d'inactivité.
     * L'usage est noté au plus une fois par minute. Rend false si la session doit se fermer.
     */
    function appareil_controler(int $ligne, int $id): bool
    {
        $req = bd()->prepare('SELECT expire_le, derniere_utilisation, inactivite FROM auth_session_durable
                              WHERE id = ? AND compte_id = ?');
        $req->execute([$ligne, $id]);
        $l = $req->fetch();
        if (!$l || (int)$l['inactivite'] <= 0 || (string)$l['expire_le'] <= maintenant()) return false;
        $t = time();
        $derniere = (string)$l['derniere_utilisation'];
        if ($derniere < gmdate('Y-m-d\TH:i:s\Z', $t - (int)$l['inactivite'])) return false;
        if ($derniere < gmdate('Y-m-d\TH:i:s\Z', $t - 60)) {
            bd()->prepare('UPDATE auth_session_durable SET derniere_utilisation = ? WHERE id = ?')->execute([maintenant(), $ligne]);
        }
        return true;
    }
}

if (!function_exists('durable_fermer')) {
    /**
     * Ce navigateur n'est plus de confiance : sa ligne est supprimée, le cookie effacé. $nom :
     * appareil_nom_cookie() pour ne plus y rester connecté (voir appareil_fermer()).
     */
    function durable_fermer(string $nom = ''): void
    {
        $nom = $nom !== '' ? $nom : durable_nom_cookie();
        $brut = (string)($_COOKIE[$nom] ?? '');
        if ($brut !== '') {
            bd()->prepare('DELETE FROM auth_session_durable WHERE selecteur = ?')->execute([explode(':', $brut)[0]]);
        }
        durable_cookie_poser('', 0, $nom);
    }
}

if (!function_exists('appareil_fermer')) {
    /** Ce navigateur ne reste plus connecté (déconnexion) ; sa confiance, s'il en a une, demeure. */
    function appareil_fermer(): void
    {
        durable_fermer(appareil_nom_cookie());
    }
}

if (!function_exists('durables_lister')) {
    /**
     * Les navigateurs de confiance de ce compte, du plus récent au plus ancien (page Sécurité). Avec
     * « rester connecté » (AUTH_RESTER_CONNECTE), les appareils aussi : colonne `inactivite` non nulle.
     */
    function durables_lister(int $id): array
    {
        $colonne = defined('AUTH_RESTER_CONNECTE') ? ', inactivite' : '';
        $req = bd()->prepare("SELECT id, appareil, facteurs, cree_le, expire_le, derniere_utilisation$colonne
                              FROM auth_session_durable WHERE compte_id = ? AND expire_le > ?
                              ORDER BY derniere_utilisation DESC");
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
     * Tous les navigateurs de confiance du compte, et ses appareils où il reste connecté (mot de passe
     * ou facteur changé, compte désactivé…) ; $sauf_celui_ci : sauf ceux du navigateur de la requête,
     * quand c'est la personne elle-même qui agit.
     */
    function durable_revoquer_tout(int $id, bool $sauf_celui_ci = false): void
    {
        $confiance = $sauf_celui_ci ? explode(':', (string)($_COOKIE[durable_nom_cookie()] ?? ''))[0] : '';
        $appareil = $sauf_celui_ci ? explode(':', (string)($_COOKIE[appareil_nom_cookie()] ?? ''))[0] : '';
        $req = bd()->prepare('DELETE FROM auth_session_durable WHERE compte_id = ? AND selecteur <> ? AND selecteur <> ?');
        $req->execute([$id, $confiance, $appareil]);
        if ($req->rowCount() > 0) journaliser('session_durable_revoquee', 'utilisateur', $id, 'toutes');
    }
}
