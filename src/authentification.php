<?php
/**
 * socle-php — authentification : premier facteur (mot de passe ou lien), second facteur
 * (TOTP, code de secours), session, vérification renforcée, réinitialisation.
 *
 * La cible commune des projets (spécification d'octobre 2026, règles simplifiées par Brice le
 * 1er novembre, D17) : première connexion par lien, puis mot de passe ; second facteur
 * obligatoire pour les rôles déclarés, facultatif pour les autres ; session fermée après une
 * heure d'inactivité ; navigateur de confiance 30 jours (le code n'y est plus redemandé, le mot
 * de passe si). Plus de « rester connecté », plus de vérification renforcée sur les pages : la
 * vérification récente (15 min) ne garde que la sécurité du compte (mot de passe, second
 * facteur, clés). Les briques voisines font chacune une chose : mot_de_passe.php,
 * session_durable.php (navigateur de confiance), codes_secours.php, totp.php. Ce fichier les
 * enchaîne.
 *
 * Indépendant de la table des comptes : chaque projet a la sienne (utilisateur, utilisateurs,
 * administrateurs, compte…). Il la déclare, et tout ce qui est propre à l'authentification va
 * dans les tables auth_* du socle (voir sql/), rattachées par l'identifiant du compte :
 *   - AUTH_TABLE_COMPTES (« utilisateur » à défaut), AUTH_COLONNE_COURRIEL (« courriel »),
 *     AUTH_COLONNE_ACTIF (« actif » ; chaîne vide : pas de colonne, tout compte est actif) ;
 *   - ROLES_2FA_OBLIGATOIRE : rôles tenus au second facteur (['admin'] à défaut) ;
 *   - facultatif : AUTH_URL_SECOND_FACTEUR, AUTH_URL_ENROLEMENT, AUTH_URL_VERIFICATION (pages
 *     du projet, URL_BASE . '/second-facteur.php', '/securite.php', '/verification.php' à défaut),
 *     AUTH_INACTIVITE (3600 s), AUTH_DUREE_MAX (43200 s), AUTH_VERIFICATION_RECENTE (900 s).
 *
 * Règles figées ici :
 *   - la clé de session « utilisateur_id » n'est posée qu'une fois l'authentification COMPLÈTE :
 *     l'état « premier facteur passé, second attendu » vit sous « auth_en_attente » (10 min).
 *     Un module qui lit utilisateur_id tel quel (le calendrier lit la session du portail) ne
 *     laisse donc jamais entrer à mi-parcours ;
 *   - le lien remplace le mot de passe, jamais le second facteur ; le navigateur de confiance
 *     remplace le second facteur, jamais le mot de passe ;
 *   - limitation par ÉCHECS, en base, par compte et par appelant ; attente, jamais de
 *     verrouillage définitif (il se retournerait contre la victime) ;
 *   - un admin ne perd jamais l'accès à son compte : l'enrôlement obligatoire vient APRÈS la
 *     connexion, avec un délai de grâce (param « auth_echeance_2fa », 7 jours à défaut).
 */
declare(strict_types=1);

if (!function_exists('auth_table')) {
    /** [table, colonne d'adresse, colonne « actif »] des comptes du projet, noms SQL contrôlés. */
    function auth_table(): array
    {
        $noms = [defined('AUTH_TABLE_COMPTES') ? (string)AUTH_TABLE_COMPTES : 'utilisateur',
                 defined('AUTH_COLONNE_COURRIEL') ? (string)AUTH_COLONNE_COURRIEL : 'courriel',
                 defined('AUTH_COLONNE_ACTIF') ? (string)AUTH_COLONNE_ACTIF : 'actif'];
        foreach ($noms as $i => $nom) {
            if (($i < 2 || $nom !== '') && !preg_match('/^[a-z_][a-z0-9_]*$/', $nom)) {
                throw new LogicException("Nom de table ou de colonne invalide : $nom");
            }
        }
        return $noms;
    }
}

if (!function_exists('auth_compte')) {
    /** Le compte (ligne de la table du projet) de cet identifiant, ou null. */
    function auth_compte(int $id): ?array
    {
        [$table] = auth_table();
        $req = bd()->prepare("SELECT * FROM $table WHERE id = ?");
        $req->execute([$id]);
        return $req->fetch() ?: null;
    }
}

if (!function_exists('auth_compte_par_courriel')) {
    /** Le compte portant cette adresse (casse ignorée), ou null. */
    function auth_compte_par_courriel(string $courriel): ?array
    {
        [$table, $colonne] = auth_table();
        $req = bd()->prepare("SELECT * FROM $table WHERE LOWER($colonne) = ?");
        $req->execute([mb_strtolower(trim($courriel))]);
        return $req->fetch() ?: null;
    }
}

if (!function_exists('auth_compte_actif')) {
    function auth_compte_actif(array $compte): bool
    {
        $colonne = auth_table()[2];
        return $colonne === '' || (int)($compte[$colonne] ?? 0) === 1;
    }
}

if (!function_exists('auth_donnees')) {
    /** La ligne auth_compte de ce compte (mot de passe, TOTP…), créée vide au besoin. */
    function auth_donnees(int $id): array
    {
        bd()->prepare(sql_insert_ignore() . ' INTO auth_compte (compte_id) VALUES (?)')->execute([$id]);
        $req = bd()->prepare('SELECT * FROM auth_compte WHERE compte_id = ?');
        $req->execute([$id]);
        return $req->fetch();
    }
}

if (!function_exists('auth_duree')) {
    /** Durées réglables par constante (secondes), lues à l'appel. */
    function auth_duree(string $nom): int
    {
        $defauts = ['AUTH_INACTIVITE' => 3600, 'AUTH_DUREE_MAX' => 43200, 'AUTH_VERIFICATION_RECENTE' => 900];
        return defined($nom) ? max(60, (int)constant($nom)) : $defauts[$nom];
    }
}

if (!function_exists('auth_facteurs_forts')) {
    /**
     * Vrai si la chaîne de facteurs (« mdp+totp », « lien+cle », « mdp+confiance »…) contient un
     * second facteur, ou la confiance d'un navigateur qui en a passé un.
     */
    function auth_facteurs_forts(string $facteurs): bool
    {
        return (bool)array_intersect(explode('+', $facteurs), ['totp', 'cle', 'secours', 'confiance']);
    }
}

if (!function_exists('auth_a_second_facteur')) {
    /** Le compte a-t-il un second facteur actif (application TOTP ou clé d'accès) ? */
    function auth_a_second_facteur(int $id): bool
    {
        if (auth_donnees($id)['totp_actif_le'] !== null) return true;
        $req = bd()->prepare('SELECT COUNT(*) FROM auth_cle_acces WHERE compte_id = ?');
        $req->execute([$id]);
        return (int)$req->fetchColumn() > 0;
    }
}

if (!function_exists('auth_aviser')) {
    /**
     * Courriel d'avis de sécurité à la personne (urgent, adresse technique). Rien si le compte
     * n'a pas d'adresse valable (identifiant de connexion qui n'est pas une adresse : Tristan).
     */
    function auth_aviser(int $id, string $sujet, string $corps): void
    {
        $compte = auth_compte($id);
        $courriel = $compte ? trim((string)($compte[auth_table()[1]] ?? '')) : '';
        if (filter_var($courriel, FILTER_VALIDATE_EMAIL) === false) return;
        filer_courriel($courriel, $sujet, "Bonjour,\n\n" . $corps
            . "\n\nSi vous n'êtes pas à l'origine de cette action, prévenez immédiatement un administrateur.",
            $id, '', null, 'securite', $id, true, expediteur_technique());
    }
}

// ---------------------------------------------------------------------------
// Connexion : premier facteur, second facteur, ouverture
// ---------------------------------------------------------------------------

if (!function_exists('auth_premier_facteur')) {
    /**
     * Premier facteur passé ($facteur : « mdp » ou « lien ») : ouvre la session, ou la met en
     * attente du second facteur si le compte en a un — sauf depuis un navigateur de confiance de
     * ce compte, qui l'en dispense. Rend « connecte » ou « second_facteur ». Un projet qui garde
     * sa propre connexion par lien l'appelle après avoir consommé le jeton.
     * $durable : sans effet depuis la 2.3 (plus de « rester connecté ») ; la confiance se
     * demande au second facteur (auth_second_facteur).
     */
    function auth_premier_facteur(int $id, string $facteur, bool $durable = false): string
    {
        if (auth_a_second_facteur($id)) {
            if (auth_navigateur_de_confiance($id)) {
                auth_terminer($id, $facteur . '+confiance');
                return 'connecte';
            }
            demarrer_session();
            $_SESSION['auth_en_attente'] = ['id' => $id, 'facteur' => $facteur, 'depuis' => time(), 'durable' => false];
            return 'second_facteur';
        }
        auth_terminer($id, $facteur);
        return 'connecte';
    }
}

if (!function_exists('auth_navigateur_de_confiance')) {
    /** Ce navigateur est-il de confiance pour ce compte (second facteur passé ici depuis moins de 30 jours) ? */
    function auth_navigateur_de_confiance(int $id): bool
    {
        $confiance = durable_reprendre();
        return $confiance !== null && $confiance['compte_id'] === $id && auth_facteurs_forts($confiance['facteurs']);
    }
}

if (!function_exists('auth_mot_de_passe')) {
    /**
     * Connexion par adresse et mot de passe. Rend « connecte », « second_facteur », « refus »
     * (même réponse pour une adresse inconnue, un compte désactivé, sans mot de passe ou un
     * mauvais mot de passe) ou « limite » (5 échecs par compte, 20 par appelant, en 15 min).
     */
    function auth_mot_de_passe(string $courriel, string $mdp, bool $durable = false): string
    {
        $cle_compte = 'mdp-courriel-' . substr(hash_hmac('sha256', mb_strtolower(trim($courriel)), SEL_SITE), 0, 16);
        $cle_appelant = 'mdp-ip-' . empreinte_appelant();
        if (echecs_depasses($cle_compte, 5, 900) || echecs_depasses($cle_appelant, 20, 900)) return 'limite';

        $compte = auth_compte_par_courriel($courriel);
        $id = $compte ? (int)$compte['id'] : 0;
        // mdp_verifier() calcule un hachage même sans compte : même durée de réponse.
        if (!mdp_verifier($id, $mdp) || !$compte || !auth_compte_actif($compte)) {
            $echecs = echec_noter($cle_compte);
            echec_noter($cle_appelant);
            if ($id > 0) journaliser('echec_mot_de_passe', 'utilisateur', $id);
            if ($id > 0 && $echecs === 10) {
                auth_aviser($id, 'Tentatives de connexion à votre compte',
                    "Dix essais de mot de passe ont échoué sur votre compte depuis hier. Si ce n'est pas vous, "
                    . "changez votre mot de passe et activez la double authentification.");
            }
            return 'refus';
        }
        return auth_premier_facteur($id, 'mdp', $durable);
    }
}

if (!function_exists('auth_en_attente')) {
    /** L'état « premier facteur passé, second attendu » (10 minutes au plus), ou null. */
    function auth_en_attente(): ?array
    {
        demarrer_session();
        $attente = $_SESSION['auth_en_attente'] ?? null;
        if (!is_array($attente) || time() - (int)$attente['depuis'] > 600) {
            unset($_SESSION['auth_en_attente']);
            return null;
        }
        return $attente;
    }
}

if (!function_exists('auth_second_facteur')) {
    /**
     * Second facteur de la connexion en attente : $mode « totp » (code de l'application),
     * « secours » (code de secours) ou « cle » ($code : la réponse JSON du navigateur, après
     * cle_options_connexion($id)). $confiance : ce navigateur ne redemandera plus le code pendant
     * 30 jours (case « Ne plus demander sur ce navigateur », cochée par défaut). Rend « connecte »,
     * « refus », « limite » ou « expire » (plus de connexion en attente : repasser par le premier
     * facteur).
     */
    function auth_second_facteur(string $mode, string $code, bool $confiance = false): string
    {
        $attente = auth_en_attente();
        if ($attente === null) return 'expire';
        $id = (int)$attente['id'];
        $cle = 'second-facteur-' . $id;
        if (echecs_depasses($cle, 5, 900)) return 'limite';
        $ok = auth_facteur_verifier($id, $mode, $code);
        if (!$ok) {
            if (echec_noter($cle) === 5) {
                auth_aviser($id, 'Codes de connexion refusés',
                    "Cinq codes de double authentification ont été refusés sur votre compte, après un mot de passe "
                    . "ou un lien valable. Si ce n'est pas vous, changez votre mot de passe sans attendre.");
            }
            journaliser('echec_second_facteur', 'utilisateur', $id);
            return 'refus';
        }
        auth_terminer($id, $attente['facteur'] . '+' . $mode, $confiance);
        return 'connecte';
    }
}

if (!function_exists('auth_facteur_verifier')) {
    /** Un second facteur du compte : « totp », « secours » ou « cle » (si les clés d'accès sont installées). */
    function auth_facteur_verifier(int $id, string $mode, string $valeur): bool
    {
        return match ($mode) {
            'totp'    => totp_verifier_compte($id, $valeur),
            'secours' => code_secours_utiliser($id, $valeur),
            'cle'     => function_exists('webauthn_disponible') && webauthn_disponible() && cle_verifier($valeur, $id) === $id,
            default   => false,
        };
    }
}

if (!function_exists('auth_session_renforcer')) {
    /**
     * La personne connectée vient de prouver un second facteur hors connexion (enrôlement d'une
     * application ou d'une clé) : sa session le compte désormais, et ce navigateur devient de
     * confiance — le code ne lui est pas redemandé dès la connexion suivante.
     */
    function auth_session_renforcer(int $id, string $mode): void
    {
        if ((int)($_SESSION['utilisateur_id'] ?? 0) !== $id) return;
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) session_regenerate_id(true);
        if (!auth_facteurs_forts((string)($_SESSION['auth_facteurs'] ?? ''))) {
            $_SESSION['auth_facteurs'] = ($_SESSION['auth_facteurs'] ?? 'mdp') . '+' . $mode;
        }
        $_SESSION['auth_verifie_le'] = time();
        durable_ouvrir($id, (string)$_SESSION['auth_facteurs']);
    }
}

if (!function_exists('auth_ouvrir_session')) {
    /** Pose la session d'un compte authentifié (identifiant régénéré). Interne : voir auth_terminer(). */
    function auth_ouvrir_session(int $id, string $facteurs, bool $verifie_maintenant): void
    {
        demarrer_session();
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) session_regenerate_id(true);
        unset($_SESSION['auth_en_attente']);
        $_SESSION['utilisateur_id'] = $id;
        $_SESSION['auth_facteurs'] = $facteurs;
        $_SESSION['auth_connecte_le'] = $_SESSION['auth_activite_le'] = time();
        if ($verifie_maintenant) $_SESSION['auth_verifie_le'] = time(); else unset($_SESSION['auth_verifie_le']);
    }
}

if (!function_exists('auth_terminer')) {
    /**
     * Authentification complète : session ouverte, connexion journalisée. $confiance : ce
     * navigateur devient de confiance — seulement si un second facteur vient d'être passé ici
     * (pas sur la foi d'une confiance existante, qui garde son échéance).
     */
    function auth_terminer(int $id, string $facteurs, bool $confiance = false): void
    {
        auth_ouvrir_session($id, $facteurs, auth_facteurs_forts($facteurs) && !str_contains($facteurs, 'confiance'));
        if ($confiance && auth_facteurs_forts($facteurs) && !str_contains($facteurs, 'confiance')) durable_ouvrir($id, $facteurs);
        journaliser('connexion', 'utilisateur', $id, $facteurs, $id);
        if (str_contains($facteurs, 'secours')) {
            $reste = codes_secours_restants($id);
            auth_aviser($id, 'Un code de secours a été utilisé',
                "Un code de secours vient de servir à ouvrir votre compte. Il vous en reste $reste."
                . ($reste < 3 ? " Pensez à en générer de nouveaux depuis la page Sécurité de votre compte." : ''));
        }
    }
}

if (!function_exists('auth_session_controler')) {
    /**
     * À appeler une fois par requête, avant de lire la session (dans utilisateur_courant() du
     * projet) : ferme la session inactive depuis 1 h ou ouverte depuis 12 h. Rend l'identifiant
     * du compte connecté, ou 0 (se reconnecter : le mot de passe, et le code seulement hors d'un
     * navigateur de confiance).
     */
    function auth_session_controler(): int
    {
        if (PHP_SAPI === 'cli' && !defined('AUTH_ESSAI')) return 0;
        demarrer_session();
        $id = (int)($_SESSION['utilisateur_id'] ?? 0);
        $t = time();
        if ($id > 0) {
            $activite = (int)($_SESSION['auth_activite_le'] ?? $t);
            $debut = (int)($_SESSION['auth_connecte_le'] ?? $t);
            $depuis = bd()->prepare('SELECT sessions_depuis FROM auth_compte WHERE compte_id = ?');
            $depuis->execute([$id]);
            $depuis = (string)$depuis->fetchColumn();
            if ($t - $activite <= auth_duree('AUTH_INACTIVITE') && $t - $debut <= auth_duree('AUTH_DUREE_MAX')
                && ($depuis === '' || $debut >= (int)strtotime($depuis))) {
                $_SESSION['auth_activite_le'] = $t;
                return $id;
            }
            foreach (['utilisateur_id', 'auth_facteurs', 'auth_connecte_le', 'auth_activite_le', 'auth_verifie_le'] as $cle) {
                unset($_SESSION[$cle]);
            }
        }
        return 0;
    }
}

if (!function_exists('auth_fermer_autres_sessions')) {
    /**
     * Ferme toutes les sessions de ce compte et retire la confiance de ses navigateurs, sauf la
     * session et le navigateur de la requête en cours si ce sont les siens : mot de passe
     * changé, second facteur réinitialisé, « Déconnecter tous les autres appareils ». Les
     * sessions PHP ne sont pas listées sur le serveur : on note la date, et
     * auth_session_controler() ferme toute session ouverte avant.
     */
    function auth_fermer_autres_sessions(int $id): void
    {
        auth_donnees($id);
        bd()->prepare('UPDATE auth_compte SET sessions_depuis = ? WHERE compte_id = ?')->execute([maintenant(), $id]);
        $soi = (int)($_SESSION['utilisateur_id'] ?? 0) === $id;
        durable_revoquer_tout($id, $soi);
        if ($soi) $_SESSION['auth_connecte_le'] = time();
    }
}

if (!function_exists('auth_deconnecter')) {
    /**
     * Déconnexion (depuis un POST avec jeton CSRF) : session détruite. Le navigateur reste de
     * confiance (le mot de passe sera redemandé, pas le code) ; on le retire dans le compte.
     */
    function auth_deconnecter(): void
    {
        demarrer_session();
        $id = (int)($_SESSION['utilisateur_id'] ?? 0);
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        if ($id > 0) journaliser('deconnexion', 'utilisateur', $id, '', $id);
    }
}

// ---------------------------------------------------------------------------
// Second facteur obligatoire, délai de grâce
// ---------------------------------------------------------------------------

if (!function_exists('auth_role_tenu_2fa')) {
    /** Ce rôle (ou l'un de ces rôles) est-il tenu au second facteur ? */
    function auth_role_tenu_2fa(string|array $roles): bool
    {
        $tenus = defined('ROLES_2FA_OBLIGATOIRE') ? (array)ROLES_2FA_OBLIGATOIRE : ['admin'];
        return (bool)array_intersect((array)$roles, $tenus);
    }
}

if (!function_exists('auth_echeance_2fa')) {
    /**
     * Date limite d'enrôlement de ce compte. Fixée au premier contrôle, jamais avant : au plus
     * tard des deux, le réglage commun « auth_echeance_2fa » (posé au déploiement, J + 30) et
     * aujourd'hui + « auth_delai_grace_jours » (7 à défaut, pour un admin nommé plus tard).
     */
    function auth_echeance_2fa(int $id): string
    {
        $echeance = auth_donnees($id)['echeance_2fa'];
        if ($echeance !== null) return (string)$echeance;
        $delai = max(0, (int)param('auth_delai_grace_jours', '7'));
        $echeance = max(param('auth_echeance_2fa', ''), gmdate('Y-m-d\TH:i:s\Z', time() + $delai * 86400));
        bd()->prepare('UPDATE auth_compte SET echeance_2fa = ? WHERE compte_id = ?')->execute([$echeance, $id]);
        return $echeance;
    }
}

if (!function_exists('auth_etat_2fa')) {
    /**
     * Où en est la session courante vis-à-vis du second facteur, pour ce compte et ce(s) rôle(s) :
     *   « ok »             rien à exiger (rôle non tenu, ou session ouverte avec second facteur) ;
     *   « second_facteur » le compte en a un mais la session a été ouverte sans (lien seul avant
     *                      l'activation…) : le redemander ;
     *   « grace »          aucun second facteur, délai d'enrôlement en cours : bandeau, rien de bloqué ;
     *   « enrolement »     aucun second facteur, délai dépassé : pages protégées fermées jusqu'à
     *                      l'enrôlement, qui reste ouvert (jamais de compte verrouillé).
     */
    function auth_etat_2fa(int $id, string|array $roles): string
    {
        if (!auth_role_tenu_2fa($roles)) return 'ok';
        $a_facteur = auth_a_second_facteur($id);
        if ($a_facteur && auth_facteurs_forts((string)($_SESSION['auth_facteurs'] ?? ''))) return 'ok';
        if ($a_facteur) return 'second_facteur';
        return auth_echeance_2fa($id) > maintenant() ? 'grace' : 'enrolement';
    }
}

if (!function_exists('exiger_2fa_si_obligatoire')) {
    /**
     * En tête des pages d'un rôle tenu au second facteur. Redirige vers le second facteur (la
     * connexion repasse en attente) ou vers l'enrôlement ; ne fait rien pendant le délai de grâce.
     */
    function exiger_2fa_si_obligatoire(int $id, string|array $roles): void
    {
        $etat = auth_etat_2fa($id, $roles);
        if ($etat === 'ok' || $etat === 'grace') return;
        if ($etat === 'second_facteur') {
            $_SESSION['auth_en_attente'] = ['id' => $id, 'facteur' => (string)($_SESSION['auth_facteurs'] ?? 'lien'),
                                            'depuis' => time(), 'durable' => false];
            unset($_SESSION['utilisateur_id']);
            $_SESSION['auth_retour'] = auth_chemin_courant();
            header('Location: ' . (defined('AUTH_URL_SECOND_FACTEUR') ? (string)AUTH_URL_SECOND_FACTEUR : URL_BASE . '/second-facteur.php'));
            exit;
        }
        header('Location: ' . (defined('AUTH_URL_ENROLEMENT') ? (string)AUTH_URL_ENROLEMENT : URL_BASE . '/securite.php'));
        exit;
    }
}

// ---------------------------------------------------------------------------
// Vérification récente : sécurité du compte (mot de passe, second facteur, clés), réinitialisation
// du second facteur d'un autre. Depuis la 2.3 (D17), plus sur les pages de données.
// ---------------------------------------------------------------------------

if (!function_exists('auth_chemin_courant')) {
    /** Le chemin demandé, interne au site (jamais une URL complète : pas de redirection ouverte). */
    function auth_chemin_courant(): string
    {
        $chemin = (string)($_SERVER['REQUEST_URI'] ?? '/');
        return str_starts_with($chemin, '/') && !str_starts_with($chemin, '//') ? $chemin : '/';
    }
}

if (!function_exists('auth_verification_recente')) {
    /** Le mot de passe ou un second facteur a-t-il été passé depuis moins de $secondes ? */
    function auth_verification_recente(?int $secondes = null): bool
    {
        $secondes ??= auth_duree('AUTH_VERIFICATION_RECENTE');
        $le = (int)($_SESSION['auth_verifie_le'] ?? 0);
        return $le > 0 && time() - $le <= $secondes;
    }
}

if (!function_exists('exiger_verification_recente')) {
    /**
     * Avant de toucher à la sécurité du compte : sans vérification depuis 15 minutes, renvoie
     * vers la page de vérification du projet, qui appelle auth_verifier() puis revient ici
     * (auth_retour()). La fenêtre ne glisse pas : travailler sur la page ne la prolonge pas.
     */
    function exiger_verification_recente(?int $secondes = null): void
    {
        if (auth_verification_recente($secondes)) return;
        $_SESSION['auth_retour'] = auth_chemin_courant();
        header('Location: ' . (defined('AUTH_URL_VERIFICATION') ? (string)AUTH_URL_VERIFICATION : URL_BASE . '/verification.php'));
        exit;
    }
}

if (!function_exists('auth_verifier')) {
    /**
     * Vérification récente de la personne connectée : $mode « mdp » (le mot de passe, la règle
     * depuis la 2.3), ou « totp », « secours », « cle » (pour qui n'a pas de mot de passe). Rend
     * « ok », « refus », « limite » ou « non_connecte ».
     */
    function auth_verifier(string $mode, string $valeur): string
    {
        demarrer_session();
        $id = (int)($_SESSION['utilisateur_id'] ?? 0);
        if ($id <= 0) return 'non_connecte';
        $cle = 'verification-' . $id;
        if (echecs_depasses($cle, 5, 900)) return 'limite';
        $ok = $mode === 'mdp' ? mdp_verifier($id, $valeur)
                              : auth_facteur_verifier($id, $mode, $valeur);
        if (!$ok) {
            echec_noter($cle);
            journaliser('echec_verification', 'utilisateur', $id, $mode, $id);
            return 'refus';
        }
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) session_regenerate_id(true);
        $_SESSION['auth_verifie_le'] = time();
        if ($mode !== 'mdp' && !auth_facteurs_forts((string)($_SESSION['auth_facteurs'] ?? ''))) {
            $_SESSION['auth_facteurs'] = ($_SESSION['auth_facteurs'] ?? 'lien') . '+' . $mode;
        }
        journaliser('verification_renforcee', 'utilisateur', $id, $mode, $id);
        return 'ok';
    }
}

if (!function_exists('auth_retour')) {
    /** Où revenir après une vérification ou un second facteur : le chemin mémorisé, sinon « / ». */
    function auth_retour(): string
    {
        $chemin = (string)($_SESSION['auth_retour'] ?? '/');
        unset($_SESSION['auth_retour']);
        return str_starts_with($chemin, '/') && !str_starts_with($chemin, '//') ? $chemin : '/';
    }
}

// ---------------------------------------------------------------------------
// Réinitialisation (perte de tous les facteurs)
// ---------------------------------------------------------------------------

if (!function_exists('auth_reinitialiser_2fa')) {
    /**
     * Retire tous les seconds facteurs d'un compte (application, clés d'accès, codes de secours)
     * et ferme ses sessions, durables comprises. $par : l'admin qui agit (il doit lui-même avoir passé la
     * vérification renforcée), ou null depuis la ligne de commande — le recours du dernier
     * admin. La personne est prévenue ; si son rôle y est tenu, elle se réenrôle à la prochaine
     * connexion, sans nouveau délai de grâce.
     */
    function auth_reinitialiser_2fa(int $id, ?int $par): void
    {
        auth_donnees($id);
        bd()->prepare('UPDATE auth_compte SET totp_chiffre = NULL, totp_actif_le = NULL, totp_dernier_pas = NULL,
                       echeance_2fa = ? WHERE compte_id = ?')->execute([maintenant(), $id]);
        bd()->prepare('DELETE FROM auth_cle_acces WHERE compte_id = ?')->execute([$id]);
        bd()->prepare('DELETE FROM auth_code_secours WHERE compte_id = ?')->execute([$id]);
        auth_fermer_autres_sessions($id);
        journaliser('2fa_reinitialisee', 'utilisateur', $id, $par === null ? 'ligne de commande' : 'par un administrateur', $par);
        auth_aviser($id, 'Votre double authentification a été réinitialisée',
            "La double authentification de votre compte vient d'être réinitialisée, et vos appareils restés "
            . "connectés ont été déconnectés. À votre prochaine connexion, vous pourrez l'activer de nouveau.");
    }
}
