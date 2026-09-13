<?php
/**
 * socle-php — rideau par mot de passe sur un site en construction.
 *
 * Attend de l'application hôte : SEL_SITE, limiter(), empreinte_appelant(),
 * demarrer_session(), requete_https(), h().
 * Le mot de passe vient d'une constante MOT_DE_PASSE_DEMO définie HORS DÉPÔT.
 *
 * Pourquoi pas un BasicAuth dans .htaccess : ce fichier est versionné, la protection
 * partirait dans le dépôt et chez le client. Ici, le mot de passe vit dans les secrets,
 * et la protection disparaît en retirant la constante — sans toucher au code.
 *
 * Ce n'est PAS un contrôle d'accès : c'est un rideau devant une vitrine. Les droits
 * réels de l'application restent entièrement à sa charge.
 *
 * À appeler en fin de fichier de configuration, pour couvrir tous les points d'entrée :
 *     if (PHP_SAPI !== 'cli') porte_demo();
 */
declare(strict_types=1);

function demonstration_verrouillee(): bool
{
    return defined('MOT_DE_PASSE_DEMO') && MOT_DE_PASSE_DEMO !== '';
}

/** Vrai si le site doit être indexable. À interroger avant d'émettre un plan du site. */
function site_indexable(): bool
{
    if (demonstration_verrouillee()) return false;
    return !defined('SITE_OUVERT') || (bool)constant('SITE_OUVERT');
}

function porte_demo(array $options = []): void
{
    if (!demonstration_verrouillee()) return;

    // robots.txt reste lisible sans mot de passe : un robot qui reçoit 401 sur ce
    // fichier ne peut pas lire la consigne d'exclusion qu'il contient.
    foreach (array_merge(['/robots.txt'], $options['ouverts'] ?? []) as $chemin) {
        if (str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), $chemin)) return;
    }

    demarrer_session();
    $attendu = hash_hmac('sha256', (string)MOT_DE_PASSE_DEMO, SEL_SITE);
    if (($_COOKIE['acces_demo'] ?? '') !== '' && hash_equals($attendu, (string)$_COOKIE['acces_demo'])) return;

    $erreur = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['mot_de_passe_demo'])) {
        if (!limiter('demo-' . empreinte_appelant(), 20, 900)) {
            $erreur = 'Trop de tentatives. Réessayez dans quinze minutes.';
        } elseif (hash_equals((string)MOT_DE_PASSE_DEMO, (string)$_POST['mot_de_passe_demo'])) {
            setcookie('acces_demo', $attendu, [
                'expires'  => time() + 30 * 86400,
                'path'     => '/',
                'secure'   => requete_https(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $vers = (string)($_SERVER['REQUEST_URI'] ?? '/');
            if (!str_starts_with($vers, '/') || str_starts_with($vers, '//')) $vers = '/';
            header('Location: ' . $vers);
            exit;
        } else {
            $erreur = 'Mot de passe incorrect.';
        }
    }

    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');

    $titre  = $options['titre'] ?? 'Site de démonstration';
    $texte  = $options['texte'] ?? 'Ce site est en cours de construction. Il n’est pas ouvert au public.';
    $css    = $options['css'] ?? '';
    // HTML libre inséré en tête de la carte : un logo, typiquement. Fourni par le
    // projet, donc jamais une donnée utilisateur.
    $entete = $options['entete_html'] ?? '';
    ?><!doctype html>
<html lang="fr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Accès réservé</title>
<?php if ($css): ?><link rel="stylesheet" href="<?= h($css) ?>"><?php endif; ?>
<style>body{font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;
padding:1.5rem;background:#EDF1F5;color:#16222E}main{max-width:26rem;width:100%;background:#fff;
border:1px solid #C8D5DF;border-radius:12px;padding:1.5rem}input{width:100%;min-height:44px;padding:.55rem .7rem;
font:inherit;border:1.5px solid #C8D5DF;border-radius:8px;box-sizing:border-box}
button{min-height:44px;padding:.6rem 1.15rem;border:0;border-radius:999px;background:#054570;color:#fff;
font:inherit;font-weight:600;cursor:pointer}.err{background:#F8E0DE;border:1px solid #E3B0AC;color:#A3301B;
padding:.8rem;border-radius:8px;margin-bottom:1rem}</style>
</head><body><main>
  <?= $entete ?>
  <h1 style="font-size:1.4rem;margin-top:0"><?= h($titre) ?></h1>
  <p style="color:#4C5C6A"><?= h($texte) ?></p>
  <?php if ($erreur !== ''): ?><div class="err" role="alert"><?= h($erreur) ?></div><?php endif; ?>
  <form method="post">
    <p><label for="mdp-demo"><strong>Mot de passe d’accès</strong></label></p>
    <input type="password" id="mdp-demo" name="mot_de_passe_demo" required autofocus autocomplete="current-password">
    <p><button type="submit">Entrer</button></p>
  </form>
</main></body></html><?php
    exit;
}
