<?php
/**
 * socle-php — second facteur TOTP (RFC 6238).
 *
 * Codes à six chiffres, pas de 30 secondes, HMAC-SHA1 : ce que lisent toutes les
 * applications d'authentification (Google Authenticator, 2FAS, Bitwarden…).
 * L'enrôlement se fait en affichant qr_svg(totp_uri(...)) : le secret est fabriqué
 * et dessiné sur le serveur, il ne sort jamais de l'hébergement.
 *
 * Pourquoi pas spomky-labs/otphp ? Ce code, écrit et éprouvé sur Ipesup Orientation
 * (second facteur obligatoire de l'équipe), tient en soixante lignes sans dépendance ;
 * le paquet en ajouterait trois pour le même service (six chiffres, 30 s, SHA-1).
 * La décision du 13 septembre 2026 prévoit ce cas : un paquet disproportionné se
 * remplace, en le disant — c'est dit ici.
 *
 * Chaque fonction est gardée par function_exists : Ipesup Orientation porte encore
 * sa propre copie dans son config.php et doit pouvoir monter de version du socle
 * sans collision (puis retirer sa copie à l'occasion).
 */
declare(strict_types=1);

if (!function_exists('base32_decoder')) {
    /** Décode une chaîne base32 (RFC 4648, sans bourrage) ; espaces et minuscules tolérés. */
    function base32_decoder(string $s): string
    {
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s) ?? '');
        $bits = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $p = strpos($alpha, $s[$i]);
            if ($p === false) continue;
            $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $octet) {
            if (strlen($octet) === 8) $out .= chr((int)bindec($octet));
        }
        return $out;
    }
}

if (!function_exists('totp_secret_nouveau')) {
    /** Secret neuf : 32 caractères base32 (160 bits), à garder côté serveur. */
    function totp_secret_nouveau(): string
    {
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $s = '';
        for ($i = 0; $i < 32; $i++) $s .= $alpha[random_int(0, 31)];
        return $s;
    }
}

if (!function_exists('totp_code')) {
    /** Le code à six chiffres d'un pas de 30 secondes donné (pas = intdiv(time(), 30)). */
    function totp_code(string $secret, int $pas): string
    {
        $cle = base32_decoder($secret);
        $bin = pack('N*', 0, $pas);
        $hash = hash_hmac('sha1', $bin, $cle, true);
        $offset = ord($hash[19]) & 0x0f;
        $valeur = ((ord($hash[$offset]) & 0x7f) << 24) | ((ord($hash[$offset + 1]) & 0xff) << 16)
                | ((ord($hash[$offset + 2]) & 0xff) << 8) | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string)($valeur % 1000000), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('totp_verifier')) {
    /** Tolère une fenêtre de ±1 pas de 30 s (décalage d'horloge du téléphone). */
    function totp_verifier(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) return false;
        $pas = intdiv(time(), 30);
        for ($d = -1; $d <= 1; $d++) {
            if (hash_equals(totp_code($secret, $pas + $d), $code)) return true;
        }
        return false;
    }
}

if (!function_exists('totp_uri')) {
    /**
     * Adresse otpauth:// à passer à qr_svg(). $compte est ce que la personne verra
     * dans son application (son adresse électronique, en général).
     */
    function totp_uri(string $compte, string $secret, string $emetteur): string
    {
        // RFC 3986 (%20, pas +) : certaines applications ne décodent pas le +.
        $parametres = http_build_query(
            ['secret' => $secret, 'issuer' => $emetteur, 'digits' => 6, 'period' => 30],
            '', '&', PHP_QUERY_RFC3986);
        return 'otpauth://totp/' . rawurlencode($emetteur . ':' . $compte) . '?' . $parametres;
    }
}

// ---------------------------------------------------------------------------
// Second facteur d'un compte (2.2) : secret chiffré, anti-rejeu, enrôlement confirmé.
// Attend la table auth_compte (voir sql/) et la constante CLE_CHIFFREMENT (secrets.php) :
// 64 caractères hexadécimaux (32 octets), DISTINCTE de SEL_SITE — pouvoir changer l'une
// sans l'autre. Le secret doit rester lisible (le serveur recalcule les codes) : il ne peut
// pas être haché, il est chiffré. Une fuite de la base seule ne livre donc aucun secret.
// AES-256-GCM d'OpenSSL (chiffrement authentifié), présent sur tous les PHP visés, y compris
// le PHP-WASM des vérifications locales, qui n'a pas sodium ; le chiffré est lié au compte
// (données associées) : recopié sur un autre compte, il ne se déchiffre plus.
// ---------------------------------------------------------------------------

if (!function_exists('cle_chiffrement')) {
    /** La clé de 32 octets de CLE_CHIFFREMENT ; erreur explicite si elle manque ou est mal formée. */
    function cle_chiffrement(): string
    {
        $hex = defined('CLE_CHIFFREMENT') ? (string)CLE_CHIFFREMENT : '';
        if (!preg_match('/^[0-9a-f]{64}$/i', $hex)) {
            throw new RuntimeException('CLE_CHIFFREMENT manquante ou mal formée (64 caractères hexadécimaux, '
                . 'à tirer par bin2hex(random_bytes(32)) et à poser dans secrets.php).');
        }
        return (string)hex2bin($hex);
    }
}

if (!function_exists('totp_chiffrer')) {
    /** « v1: » + base64(nonce 12 ‖ étiquette 16 ‖ chiffré), lié au compte. Le préfixe permet une rotation de clé. */
    function totp_chiffrer(string $secret, int $id): string
    {
        $nonce = random_bytes(12);
        $chiffre = openssl_encrypt($secret, 'aes-256-gcm', cle_chiffrement(), OPENSSL_RAW_DATA, $nonce, $etiquette, "totp|$id", 16);
        if ($chiffre === false) throw new RuntimeException('Chiffrement du secret TOTP impossible.');
        return 'v1:' . base64_encode($nonce . $etiquette . $chiffre);
    }
}

if (!function_exists('totp_dechiffrer')) {
    /** Le secret en clair, ou null (chiffré altéré, autre clé, autre compte). */
    function totp_dechiffrer(string $chiffre, int $id): ?string
    {
        if (!str_starts_with($chiffre, 'v1:')) return null;
        $brut = base64_decode(substr($chiffre, 3), true);
        if ($brut === false || strlen($brut) <= 28) return null;
        $clair = openssl_decrypt(substr($brut, 28), 'aes-256-gcm', cle_chiffrement(), OPENSSL_RAW_DATA,
                                 substr($brut, 0, 12), substr($brut, 12, 16), "totp|$id");
        return $clair === false ? null : $clair;
    }
}

if (!function_exists('totp_pas_valide')) {
    /** Le pas (intdiv(time(), 30)) auquel ce code correspond, à ±1 pas près, ou null. */
    function totp_pas_valide(string $secret, string $code): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) return null;
        $pas = intdiv(time(), 30);
        for ($d = -1; $d <= 1; $d++) {
            if (hash_equals(totp_code($secret, $pas + $d), $code)) return $pas + $d;
        }
        return null;
    }
}

if (!function_exists('totp_enroler')) {
    /**
     * Active l'application d'authentification du compte, une fois le premier code vérifié
     * ($secret : celui montré en QR code, gardé en session d'ici là). Rend les dix codes de
     * secours si c'est le PREMIER second facteur du compte (à montrer une fois), [] sinon,
     * ou null si le code est faux. Les sessions durables du compte sont fermées.
     */
    function totp_enroler(int $id, string $secret, string $code): ?array
    {
        $pas = totp_pas_valide($secret, $code);
        if ($pas === null) return null;
        $premier = !auth_a_second_facteur($id);
        bd()->prepare('UPDATE auth_compte SET totp_chiffre = ?, totp_actif_le = ?, totp_dernier_pas = ? WHERE compte_id = ?')
            ->execute([totp_chiffrer($secret, $id), maintenant(), $pas, $id]);
        durable_revoquer_tout($id);
        journaliser('2fa_activee', 'utilisateur', $id, 'totp', $id);
        auth_aviser($id, 'Double authentification activée',
            "Une application d'authentification vient d'être associée à votre compte.");
        return $premier ? codes_secours_generer($id) : [];
    }
}

if (!function_exists('totp_verifier_compte')) {
    /**
     * Le code est-il bon pour ce compte ? Refuse un code déjà accepté (même pas de 30 s ou
     * plus ancien) : un code intercepté ne ressert pas. L'enregistrement du pas est atomique.
     */
    function totp_verifier_compte(int $id, string $code): bool
    {
        $donnees = auth_donnees($id);
        if ($donnees['totp_actif_le'] === null || $donnees['totp_chiffre'] === null) return false;
        $secret = totp_dechiffrer((string)$donnees['totp_chiffre'], $id);
        $pas = $secret === null ? null : totp_pas_valide($secret, $code);
        if ($pas === null) return false;
        $maj = bd()->prepare('UPDATE auth_compte SET totp_dernier_pas = ?
                              WHERE compte_id = ? AND (totp_dernier_pas IS NULL OR totp_dernier_pas < ?)');
        $maj->execute([$pas, $id, $pas]);
        return $maj->rowCount() === 1;
    }
}

if (!function_exists('totp_retirer')) {
    /**
     * Retire l'application d'authentification (depuis la page Sécurité, après vérification
     * renforcée). Refusé — faux — si c'est le dernier second facteur d'un compte qui y est tenu.
     */
    function totp_retirer(int $id, bool $tenu): bool
    {
        $cles = bd()->prepare('SELECT COUNT(*) FROM auth_cle_acces WHERE compte_id = ?');
        $cles->execute([$id]);
        if ($tenu && (int)$cles->fetchColumn() === 0) return false;
        bd()->prepare('UPDATE auth_compte SET totp_chiffre = NULL, totp_actif_le = NULL, totp_dernier_pas = NULL
                       WHERE compte_id = ?')->execute([$id]);
        durable_revoquer_tout($id);
        journaliser('2fa_retiree', 'utilisateur', $id, 'totp', $id);
        auth_aviser($id, 'Double authentification retirée',
            "L'application d'authentification de votre compte vient d'être retirée.");
        return true;
    }
}

if (!function_exists('totp_reprendre_secret')) {
    /**
     * Migration : reprend un secret déjà enrôlé dans l'ancien stockage d'un projet (en clair,
     * base32), chiffré ici. Personne n'a à rescanner de QR code. Ne génère pas de codes de
     * secours (à proposer à la prochaine visite de la page Sécurité).
     */
    function totp_reprendre_secret(int $id, string $secret): void
    {
        auth_donnees($id);
        bd()->prepare('UPDATE auth_compte SET totp_chiffre = ?, totp_actif_le = COALESCE(totp_actif_le, ?) WHERE compte_id = ?')
            ->execute([totp_chiffrer(strtoupper(trim($secret)), $id), maintenant(), $id]);
    }
}
