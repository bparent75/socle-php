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
