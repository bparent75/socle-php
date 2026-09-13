<?php
/**
 * socle-php — session, CSRF, limitation des tentatives, jetons et liens signés.
 *
 * Attend de l'application hôte :
 *   - une fonction bd(): PDO
 *   - une constante SEL_SITE : chaîne aléatoire longue, jamais modifiée ensuite
 *   - la table `tentative` (voir sql/)
 *
 * Pourquoi ici : ce sont des décisions de sécurité constantes d'un projet à l'autre
 * — cookie de session correct derrière le proxy d'un mutualisé, jetons jamais stockés
 * en clair, liens signés à durée courte. Les paquets existants supposent un framework.
 */
declare(strict_types=1);

/**
 * Vrai si la requête est servie en HTTPS.
 *
 * Sur un mutualisé, PHP est derrière un proxy : $_SERVER['HTTPS'] peut être absent
 * alors que le visiteur est bien en HTTPS. Sans cette détection, le cookie de session
 * ne porte pas l'attribut Secure — ou le porte à tort en développement local.
 */
function requete_https(): bool
{
    if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    $hote = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (str_starts_with($hote, 'localhost') || str_starts_with($hote, '127.0.0.1')) return false;
    return defined('URL_BASE') && str_starts_with(URL_BASE, 'https://');
}

/**
 * Ouvre la session. Sans effet en ligne de commande, et sans effet si des en-têtes
 * ont déjà été envoyés : tenter malgré tout ne produirait que des avertissements.
 */
/**
 * $nom est facultatif : à défaut, la constante NOM_SESSION du projet est utilisée.
 * Cela évite d'avoir à passer le nom à chaque appel, et donc de l'oublier quelque part —
 * une session ouverte sous deux noms différents déconnecte l'utilisateur au hasard.
 */
function demarrer_session(?string $nom = null): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $nom ??= defined('NOM_SESSION') ? (string)NOM_SESSION : 'socle_session';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => requete_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name($nom);
    session_start();
}

// ---------------------------------------------------------------------------
// Jetons et signatures
// ---------------------------------------------------------------------------

function jeton_aleatoire(int $octets = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($octets)), '+/', '-_'), '=');
}

/** Un jeton n'est jamais stocké en clair : c'est son empreinte qui va en base. */
function hacher_jeton(string $jeton): string
{
    return hash_hmac('sha256', $jeton, SEL_SITE);
}

/** Signature d'une URL temporaire : lien de téléchargement, lecture de média. */
function signer(string $charge, int $expire_ts): string
{
    return hash_hmac('sha256', $charge . '|' . $expire_ts, SEL_SITE);
}

function signature_valide(string $charge, int $expire_ts, string $signature): bool
{
    if ($expire_ts < time()) return false;
    return hash_equals(signer($charge, $expire_ts), $signature);
}

// ---------------------------------------------------------------------------
// Limitation des tentatives
// ---------------------------------------------------------------------------

/**
 * Vrai si l'action est autorisée ; enregistre la tentative au passage.
 * À appeler sur toute action devinable : connexion, envoi de lien, formulaire public.
 */
function limiter(string $cle, int $max, int $secondes): bool
{
    $pdo = bd();
    $pdo->prepare('DELETE FROM tentative WHERE cree_le < ?')
        ->execute([(new DateTimeImmutable('-1 day', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')]);
    $depuis = (new DateTimeImmutable("-$secondes seconds", new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $req = $pdo->prepare('SELECT COUNT(*) FROM tentative WHERE cle = ? AND cree_le >= ?');
    $req->execute([$cle, $depuis]);
    if ((int)$req->fetchColumn() >= $max) return false;
    $pdo->prepare('INSERT INTO tentative (cle, cree_le) VALUES (?, ?)')->execute([$cle, maintenant()]);
    return true;
}

/** Empreinte stable de l'appelant, pour la limitation. Jamais l'adresse IP en clair. */
function empreinte_appelant(): string
{
    return substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . SEL_SITE), 0, 16);
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

function csrf_jeton(): string
{
    demarrer_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = jeton_aleatoire(24);
    return $_SESSION['csrf'];
}

function csrf_champ(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_jeton()) . '">';
}

/** À appeler en tête de TOUT traitement de formulaire. */
function verifier_csrf(): void
{
    demarrer_session();
    $recu = $_POST['csrf'] ?? '';
    if (!is_string($recu) || $recu === '' || !hash_equals($_SESSION['csrf'] ?? '', $recu)) {
        http_response_code(400);
        exit('Formulaire expiré. Revenez en arrière et réessayez.');
    }
}

// ---------------------------------------------------------------------------
// En-têtes
// ---------------------------------------------------------------------------

/** En-têtes de toute page privée : ni indexation, ni cache, ni cadre. */
function entetes_prives(): void
{
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('Referrer-Policy: same-origin');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
}
