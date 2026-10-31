<?php
/**
 * socle-php — mot de passe : règles, hachage, vérification.
 *
 * Attend : bd(), les tables auth_compte et tentative (voir sql/), et pour le contrôle des
 * fuites un accès sortant HTTPS depuis les pages web (jamais depuis une session SSH sur un
 * mutualisé OVH : le nœud SSH n'a pas de sortie réseau).
 *
 * Décisions (spécification commune d'octobre 2026, recommandations NIST SP 800-63B rév. 4) :
 *   - 12 caractères au moins, 128 au plus, tous caractères acceptés (espaces, accents, emoji),
 *     AUCUNE règle de composition (« une majuscule, un chiffre… ») et pas d'expiration ;
 *   - refus des suites et répétitions évidentes, du mot de passe égal à l'adresse ou au nom
 *     du site, et des mots de passe déjà publiés dans des fuites (Have I Been Pwned, par
 *     k-anonymat : seuls les 5 premiers caractères d'une empreinte SHA-1 sortent du serveur ;
 *     si le service ne répond pas, on accepte). Une liste locale des mots de passe courants
 *     ne servirait à rien au-delà de 12 caractères : ils en ont presque tous moins ;
 *   - Argon2id (présent sur les hébergements OVH), bcrypt à défaut ; re-hachage à la
 *     connexion quand les paramètres montent (password_needs_rehash).
 *
 * Le mot de passe vit dans auth_compte, jamais dans la table des comptes du projet : le
 * projet garde la sienne telle quelle (voir authentification.php).
 */
declare(strict_types=1);

if (!function_exists('mdp_erreur')) {
    /**
     * Le motif de refus d'un nouveau mot de passe, en français, ou null s'il convient.
     * $courriel : l'adresse du compte, que le mot de passe ne doit pas reprendre.
     */
    function mdp_erreur(string $mdp, string $courriel = ''): ?string
    {
        $n = mb_strlen($mdp);
        if ($n < 12) return 'Le mot de passe doit compter au moins 12 caractères.';
        if ($n > 128) return 'Le mot de passe ne doit pas dépasser 128 caractères.';
        $simple = mb_strtolower(preg_replace('/\s+/u', '', $mdp) ?? '');
        if (count(array_unique(mb_str_split($simple))) < 4) return 'Ce mot de passe est trop répétitif.';
        foreach (['01234567890123456789', 'abcdefghijklmnopqrstuvwxyz', 'azertyuiopqsdfghjklmwxcvbn',
                  'qwertyuiopasdfghjklzxcvbnm'] as $suite) {
            $tour = str_repeat($suite, 3);
            if (str_contains($tour, $simple) || str_contains(strrev($tour), $simple)) {
                return 'Ce mot de passe est une suite trop simple.';
            }
        }
        $courriel = mb_strtolower(trim($courriel));
        $interdits = array_filter([$courriel, strstr($courriel, '@', true) ?: '',
                                   mb_strtolower(preg_replace('/\s+/u', '', socle_param('MARQUE_NOM', '')) ?? '')]);
        if (in_array($simple, $interdits, true)) return 'Le mot de passe ne doit pas reprendre votre adresse ni le nom du site.';
        if (mdp_compromis($mdp)) {
            return 'Ce mot de passe figure dans des fuites de données publiées : choisissez-en un autre.';
        }
        return null;
    }
}

if (!function_exists('mdp_compromis')) {
    /**
     * Vrai si le mot de passe figure dans Have I Been Pwned. Désactivable par la constante
     * AUTH_VERIFIER_FUITES = false (tests, poste sans réseau). Faux si le service ne répond pas.
     */
    function mdp_compromis(string $mdp): bool
    {
        if (defined('AUTH_VERIFIER_FUITES') && !AUTH_VERIFIER_FUITES) return false;
        $sha = strtoupper(sha1($mdp));
        $corps = mdp_fuites_interroger(substr($sha, 0, 5));
        return $corps !== null && mdp_fuites_contient($corps, substr($sha, 5));
    }
}

if (!function_exists('mdp_fuites_interroger')) {
    /** La liste des suffixes connus pour ce préfixe d'empreinte (5 caractères), ou null (délai de 3 s). */
    function mdp_fuites_interroger(string $prefixe): ?string
    {
        $url = 'https://api.pwnedpasswords.com/range/' . rawurlencode($prefixe);
        // Add-Padding : la réponse est complétée de faux suffixes, sa taille ne trahit rien.
        $contexte = stream_context_create(['http' => [
            'timeout' => 3, 'ignore_errors' => false,
            'header'  => "User-Agent: socle-php\r\nAdd-Padding: true\r\n",
        ]]);
        $corps = @file_get_contents($url, false, $contexte);
        return is_string($corps) && $corps !== '' ? $corps : null;
    }
}

if (!function_exists('mdp_fuites_contient')) {
    /** Lecture de la réponse « SUFFIXE:NOMBRE » par ligne ; les lignes de bourrage valent 0. */
    function mdp_fuites_contient(string $corps, string $suffixe): bool
    {
        foreach (preg_split('/\R/', $corps) ?: [] as $ligne) {
            [$s, $nombre] = array_pad(explode(':', trim($ligne), 2), 2, '0');
            if (strtoupper($s) === strtoupper($suffixe) && (int)$nombre > 0) return true;
        }
        return false;
    }
}

if (!function_exists('mdp_hacher')) {
    /** Empreinte Argon2id (bcrypt si PHP n'a pas Argon2). */
    function mdp_hacher(string $mdp): string
    {
        return password_hash($mdp, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }
}

if (!function_exists('mdp_defini')) {
    /** Le compte a-t-il un mot de passe ? */
    function mdp_defini(int $id): bool
    {
        return (string)(auth_donnees($id)['mot_de_passe'] ?? '') !== '';
    }
}

if (!function_exists('mdp_verifier')) {
    /**
     * Le mot de passe est-il celui du compte ? Faux si le compte n'en a pas, ou n'existe pas
     * ($id = 0) — avec la même durée de calcul que pour un vrai compte (pas d'énumération
     * par le temps de réponse). Re-hache au passage si les paramètres ont monté.
     */
    function mdp_verifier(int $id, string $mdp): bool
    {
        static $factice = null;
        $empreinte = $id > 0 ? (string)(auth_donnees($id)['mot_de_passe'] ?? '') : '';
        if ($empreinte === '') {
            $factice ??= mdp_hacher('aucun-compte-' . bin2hex(random_bytes(8)));
            password_verify($mdp, $factice);
            return false;
        }
        if (!password_verify($mdp, $empreinte)) return false;
        if (password_needs_rehash($empreinte, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            bd()->prepare('UPDATE auth_compte SET mot_de_passe = ? WHERE compte_id = ?')->execute([mdp_hacher($mdp), $id]);
        }
        return true;
    }
}

if (!function_exists('mdp_definir')) {
    /**
     * Pose ou change le mot de passe (déjà contrôlé par mdp_erreur()). Ferme toutes les autres
     * sessions du compte, durables comprises ; un CHANGEMENT (pas la première pose) est signalé
     * par courriel. La personne connectée garde la sienne.
     */
    function mdp_definir(int $id, string $mdp): void
    {
        $changement = mdp_defini($id);
        bd()->prepare('UPDATE auth_compte SET mot_de_passe = ?, mdp_defini_le = ? WHERE compte_id = ?')
            ->execute([mdp_hacher($mdp), maintenant(), $id]);
        auth_fermer_autres_sessions($id);
        journaliser($changement ? 'mot_de_passe_change' : 'mot_de_passe_defini', 'utilisateur', $id, '', $id);
        if ($changement) {
            auth_aviser($id, 'Votre mot de passe a été changé',
                "Le mot de passe de votre compte vient d'être changé, et vos appareils restés connectés ont été déconnectés.");
        }
    }
}
