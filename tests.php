<?php
/**
 * socle-php — tests des briques.
 *   php tests.php
 * Base temporaire en mémoire : rien n'est touché sur disque.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Ligne de commande uniquement.\n"); }

// Sel tiré au hasard à chaque exécution : rien ici ne dépend d'une valeur stable d'un
// lancement à l'autre, et aucun secret en dur ne traîne dans un dépôt public.
define('SEL_SITE', bin2hex(random_bytes(32)));
const EMAIL_EXPEDITEUR = 'essai@example.invalid';
const EMAIL_EXPEDITEUR_NOM = 'Essai';
const MARQUE_NOM = 'Essai Socle';
const CONTACT_COURRIEL = 'contact@example.invalid';
const URL_BASE = 'https://exemple.invalid';

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(file_get_contents(__DIR__ . '/sql/sqlite.sql'));
function bd(): PDO { global $pdo; return $pdo; }

// Les fonctions du socle arrivent par deux chemins possibles, et les charger tous les
// deux les redéclarerait : soit l'autochargeur Composer (dépôt installé seul, ou hébergé
// dans le vendor/ d'un projet), soit les sources directement (dépôt cloné, sans Composer).
foreach ([__DIR__ . '/vendor/autoload.php', __DIR__ . '/../../autoload.php'] as $candidat) {
    if (is_file($candidat)) { require $candidat; break; }
}
if (!function_exists('h')) {
    foreach (['formats', 'securite', 'courriel', 'journal', 'porte_demo'] as $brique) {
        require __DIR__ . "/src/$brique.php";
    }
    // qrcode.php a besoin de bacon/bacon-qr-code : sans Composer, ses contrôles sont ignorés.
    if (class_exists(\BaconQrCode\Encoder\Encoder::class)) require __DIR__ . '/src/qrcode.php';
}

$reussis = 0; $echecs = [];
function verifie(string $nom, bool $ok, string $detail = ''): void {
    global $reussis, $echecs;
    if ($ok) { $reussis++; echo "  ok    $nom\n"; return; }
    $echecs[] = $nom; echo "  ÉCHEC $nom" . ($detail ? " — $detail" : '') . "\n";
}
function titre(string $t): void { echo "\n$t\n"; }

titre('Formats');
verifie('échappement', h('<b>"x"</b>') === '&lt;b&gt;&quot;x&quot;&lt;/b&gt;');
verifie('texte en paragraphes', str_contains(texte("un\n\ndeux"), '<p>un</p><p>deux</p>'));
verifie('date complète', date_fr('2026-09-21T12:30:00Z', 'complet', new DateTimeZone('Europe/Paris'))
        === 'lundi 21 septembre 2026 à 14h30');
verifie('heure ronde sans minutes', date_fr('2026-09-21T12:00:00Z', 'heure', new DateTimeZone('Europe/Paris')) === '14h');
verifie('date de calendrier', date_simple_fr('2026-01-05') === '5 janvier 2026');
verifie('montant', euros(108000) === '1 080 €' && euros(30050) === '300,50 €');
verifie('montant nul', euros(null) === 'Sur devis');
verifie('adresse sans apostrophe parasite', slug('Choisir ses spécialités au lycée') === 'choisir-ses-specialites-au-lycee');
verifie('apostrophe typographique', slug('Carnet d’exploration') === 'carnet-d-exploration');
verifie('majuscule accentuée', slug('À quoi bon') === 'a-quoi-bon');
verifie('adresse de repli', slug('!!!') === 'sans-titre');
verifie('liste', liste('a, b ,, c') === ['a','b','c']);
verifie('taille lisible', taille_lisible(1536) === '2 Ko' && taille_lisible(1572864) === '1,5 Mo');

titre('Sécurité');
$j = jeton_aleatoire();
verifie('jeton aléatoire non trivial', strlen($j) >= 40 && $j !== jeton_aleatoire());
verifie('jeton haché différent du jeton', hacher_jeton($j) !== $j && strlen(hacher_jeton($j)) === 64);
verifie('hachage stable', hacher_jeton($j) === hacher_jeton($j));
$exp = time() + 600;
verifie('signature valide acceptée', signature_valide('a:1', $exp, signer('a:1', $exp)));
verifie('signature expirée refusée', !signature_valide('a:1', time() - 5, signer('a:1', time() - 5)));
verifie('charge différente refusée', !signature_valide('a:2', $exp, signer('a:1', $exp)));
verifie('signature falsifiée refusée', !signature_valide('a:1', $exp, str_repeat('0', 64)));

titre('Limitation des tentatives');
$cle = 'essai-' . bin2hex(random_bytes(4));
for ($i = 0; $i < 3; $i++) verifie("tentative " . ($i + 1) . " autorisée", limiter($cle, 3, 3600));
verifie('quatrième tentative refusée', !limiter($cle, 3, 3600));
verifie('autre clé non affectée', limiter($cle . '-bis', 3, 3600));

titre('File de courriels');
verifie('mise en file', filer_courriel('x@example.invalid', 'Sujet', 'Corps', null, 'cle-1'));
verifie('doublon refusé par la clé d’unicité', !filer_courriel('x@example.invalid', 'Sujet', 'Corps', null, 'cle-1'));
verifie('un seul enregistrement',
        (int)bd()->query("SELECT COUNT(*) FROM notification WHERE cle_unicite = 'cle-1'")->fetchColumn() === 1);
filer_courriel('y@example.invalid', 'Rappel', 'Corps', null, '', null, 'rdv', 7);
annuler_envois('rdv', 7);
verifie('annulation des envois en attente',
        (int)bd()->query("SELECT COUNT(*) FROM notification WHERE objet_type='rdv' AND etat='en_attente'")->fetchColumn() === 0);
verifie('les annulés sont conservés, pas supprimés',
        (int)bd()->query("SELECT COUNT(*) FROM notification WHERE objet_type='rdv' AND etat='annule'")->fetchColumn() === 1);

titre('Garde-fou d’envoi en session interactive');
putenv('SSH_CONNECTION=1.2.3.4 22 5.6.7.8 22');
$r = file_traiter();
verifie('la file n’est pas consommée en session SSH', $r['envoyes'] === 0 && $r['ignores'] >= 1, $r['message']);
verifie('le message l’explique', str_contains($r['message'], 'SSH'));
putenv('SSH_CONNECTION');

titre('Expéditeur par message');
verifie('sans EMAIL_EXPEDITEUR_TECHNIQUE, l’adresse technique est l’expéditeur', expediteur_technique() === EMAIL_EXPEDITEUR);
filer_courriel('z@example.invalid', 'Lien', 'Corps', null, 'cle-exp', null, 'connexion', 1, false, 'robot@example.invalid');
verifie('l’expéditeur propre au message est mémorisé en file',
    $pdo->query("SELECT expediteur FROM notification WHERE cle_unicite = 'cle-exp'")->fetchColumn() === 'robot@example.invalid');
verifie('sans expéditeur propre, la colonne reste vide',
    $pdo->query("SELECT expediteur FROM notification WHERE cle_unicite = 'cle-1'")->fetchColumn() === null);

titre('Réponse et copie par message');
filer_courriel('f@example.invalid', 'Groupe', 'Corps', null, 'cle-rep', null, '', null, false, null,
    'auteur@example.invalid', 'auteur@example.invalid');
$ligne = $pdo->query("SELECT repondre_a, copie FROM notification WHERE cle_unicite = 'cle-rep'")->fetch();
verifie('Reply-To et Cc propres au message mémorisés en file',
    $ligne['repondre_a'] === 'auteur@example.invalid' && $ligne['copie'] === 'auteur@example.invalid');
verifie('sans eux, les colonnes restent vides',
    $pdo->query("SELECT repondre_a FROM notification WHERE cle_unicite = 'cle-exp'")->fetchColumn() === null);
$pdo->exec('ALTER TABLE notification RENAME TO notification_v15');
$pdo->exec('CREATE TABLE notification AS SELECT id, utilisateur_id, destinataire, sujet, corps, etat, tentatives,
            derniere_erreur, envoyer_apres, envoye_le, objet_type, objet_id, cle_unicite, expediteur, cree_le
            FROM notification_v15 WHERE 0');
verifie('une table sans ces colonnes accepte toujours les messages ordinaires',
    filer_courriel('g@example.invalid', 'Ancien', 'Corps'));
$pdo->exec('DROP TABLE notification');
$pdo->exec('ALTER TABLE notification_v15 RENAME TO notification');
verifie('copie multiple : découpée, dédoublonnée, sans le destinataire',
    courriel_adresses('a@example.invalid, B@example.invalid; b@example.invalid,, f@example.invalid', ['F@example.invalid'])
    === ['a@example.invalid', 'B@example.invalid']);
verifie('copie multiple : une adresse invalide refuse toute la liste',
    courriel_adresses('a@example.invalid, pas-une-adresse') === null);
verifie('copie vide : aucune adresse', courriel_adresses('') === []);
verifie('une copie invalide fait échouer l’envoi',
    !expedier_courriel('h@example.invalid', 'x', 'y', null, null, 'a@example.invalid, faux')['ok']);
filer_courriel('m@example.invalid', 'Famille', 'Corps', null, 'cle-fam', null, '', null, false, null, null,
    'p@example.invalid, e@example.invalid');
verifie('plusieurs adresses en copie mémorisées en file',
    $pdo->query("SELECT copie FROM notification WHERE cle_unicite = 'cle-fam'")->fetchColumn() === 'p@example.invalid, e@example.invalid');
verifie('une adresse de réponse avec retour à la ligne est refusée',
    !expedier_courriel('h@example.invalid', 'x', 'y', null, "a@example.invalid\r\nBcc: z@example.invalid")['ok']);

titre('Réglages SMTP');
verifie('sans SMTP_HOTE, envoi par mail()', courriel_smtp() === null);
define('SMTP_HOTE', 'smtp.example.invalid');
define('SMTP_PORT', 465);
define('SMTP_UTILISATEUR', 'robot@example.invalid');
define('SMTP_MOT_DE_PASSE', 'secret');
$smtp = courriel_smtp();
verifie('avec SMTP_HOTE, réglages lus', $smtp !== null && $smtp['hote'] === 'smtp.example.invalid' && $smtp['utilisateur'] === 'robot@example.invalid');
verifie('port 465 = TLS implicite', $smtp !== null && $smtp['chiffrement'] === 'ssl');

titre('Gabarit de courriel');
$html = courriel_html('Titre du message', "Bonjour,\n\nVoici le lien :\n\nhttps://exemple.invalid/aller\n\nMerci.");
verifie('le sujet apparaît comme titre', str_contains($html, 'Titre du message'));
verifie('l’adresse seule devient un bouton', str_contains($html, 'href="https://exemple.invalid/aller"'));
verifie('l’adresse est aussi donnée en clair', substr_count($html, 'exemple.invalid/aller') >= 2);
verifie('aucune image distante', !str_contains($html, '<img'));
verifie('le contenu est échappé', str_contains(courriel_html('x', '<script>alert(1)</script>'), '&lt;script&gt;'));
verifie('mention d’absence de données de dossier', str_contains($html, 'aucune donnée personnelle'));

titre('Journal d’audit');
journaliser('essai', 'objet', 42, 'detail', 7);
$l = bd()->query('SELECT * FROM journal ORDER BY id DESC LIMIT 1')->fetch();
verifie('action consignée', $l['action'] === 'essai' && (int)$l['objet_id'] === 42 && (int)$l['utilisateur_id'] === 7);
verifie('détail borné', strlen(journal_recent(1)[0]['detail']) <= 500);
journaliser('long', '', null, str_repeat('z', 900));
verifie('détail tronqué à 500', mb_strlen(bd()->query('SELECT detail FROM journal ORDER BY id DESC LIMIT 1')->fetchColumn()) === 500);

titre('Porte de démonstration');
verifie('non verrouillée sans constante', !demonstration_verrouillee());
verifie('site indexable par défaut', site_indexable());
define('MOT_DE_PASSE_DEMO', 'secret');
verifie('verrouillée avec la constante', demonstration_verrouillee());
verifie('site non indexable quand verrouillé', !site_indexable());

titre('QR code');
if (!function_exists('qr_svg')) {
    echo "  (ignoré : bacon/bacon-qr-code n'est pas installé — lancer composer install)\n";
} else {
    $uri = 'otpauth://totp/Exemple:qui@example.invalid?secret=JBSWY3DPEHPK3PXP&issuer=Exemple';
    $svg = qr_svg($uri, 200, 'Code d’activation');
    verifie('un SVG est produit', str_starts_with($svg, '<svg') && str_ends_with($svg, '</svg>'));
    verifie('taille demandée respectée', str_contains($svg, 'width="200" height="200"'));
    verifie('accessible aux lecteurs d’écran',
            str_contains($svg, 'role="img"') && str_contains($svg, 'aria-label="Code d’activation"'));
    verifie('le libellé est échappé', str_contains(qr_svg('x', 100, '<i>'), 'aria-label="&lt;i&gt;"'));
    verifie('des modules sont tracés', substr_count($svg, 'h1v1h-1z') > 100);
    // Marge de 4 modules de chaque côté : une version 2 (25 modules) donne 33.
    verifie('marge de 4 modules', (bool)preg_match('/viewBox="0 0 (\d+) \1"/', $svg, $m)
                                  && ((int)$m[1] - 8) % 4 === 1);
    // Le secret du second facteur est dans ce SVG : rien ne doit pouvoir sortir du serveur.
    // xmlns="http://www.w3.org/2000/svg" est un identifiant d'espace de noms, pas une requête.
    verifie('aucune ressource distante',
            !str_contains($svg, '<image') && !str_contains($svg, 'xlink:href')
            && !str_contains($svg, 'url(') && !str_contains($svg, '<script'));
    // Un contenu différent doit donner un code différent : garde-fou contre un cache
    // ou un paramètre ignoré.
    verifie('le contenu est bien encodé', qr_svg('un') !== qr_svg('deux'));
}

echo "\n" . str_repeat('-', 56) . "\n";
$total = $reussis + count($echecs);
if ($echecs) { echo "ÉCHEC : $reussis/$total\n"; foreach ($echecs as $e) echo "  - $e\n"; exit(1); }
echo "Tous les contrôles sont passés : $reussis/$total.\n";
