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

titre('Habillage par message (1.7)');
filer_courriel('o@example.invalid', 'Habillé', 'Corps', null, 'cle-opt', null, '', null, false, null, null, null,
    ['apercu' => 'Aperçu', 'accent' => ['https://exemple.invalid/a']]);
$o = json_decode((string)$pdo->query("SELECT options FROM notification WHERE cle_unicite = 'cle-opt'")->fetchColumn(), true);
verifie('options propres au message mémorisées en file',
    ($o['apercu'] ?? '') === 'Aperçu' && ($o['accent'][0] ?? '') === 'https://exemple.invalid/a');
verifie('sans options, la colonne reste vide',
    $pdo->query("SELECT options FROM notification WHERE cle_unicite = 'cle-1'")->fetchColumn() === null);
$id1 = courriel_message_id('Robot@Example.invalid');
verifie('identifiant de message au domaine de l’expéditeur, unique',
    (bool)preg_match('/^<[0-9a-f]{32}@example\.invalid>$/', $id1) && $id1 !== courriel_message_id('robot@example.invalid'));
verifie('identifiant de message : adresse sans domaine exploitable, PHPMailer garde le sien',
    courriel_message_id('sans-arobase') === '' && courriel_message_id('x@localhost') === '' && courriel_message_id('x@exa_mple.invalid') === '');
verifie('un nom d’expéditeur avec retour à la ligne est refusé',
    !expedier_courriel('h@example.invalid', 'x', 'y', null, null, null, ['expediteur_nom' => "Nom\r\nBcc: z@example.invalid"])['ok']);

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

titre('Gabarit habillé (1.7)');
$corps = "Bonjour Léa,\n\n# 1. Connectez-vous\n\nAller sur le campus : https://exemple.invalid/connexion\n\n"
       . "Votre offre comprend :\n- le module sur *Titre* ;\n- 2 corrections.\nNote sous la liste.\n\n"
       . "Choisir : https://exemple.invalid/choix\n\n"
       . "> Petite note avec [un lien](https://exemple.invalid/l) et **gras**.\n\n---\n\nFin !";
$html = courriel_html('Bienvenue, Léa !', $corps, 'Ouvrir', [
    'accent' => ['https://exemple.invalid/choix'], 'apercu' => 'Trois étapes', 'bandeau' => 'MARQUE',
    'pied' => 'Pied propre au message.', 'contact' => 'familles@example.invalid']);
verifie('bouton à libellé', str_contains($html, '>Aller sur le campus</a>')
    && str_contains($html, 'href="https://exemple.invalid/connexion"'));
verifie('un bouton à libellé ne répète pas son adresse en clair', substr_count($html, 'exemple.invalid/connexion') === 1);
verifie('bouton d’accent pour l’adresse désignée, bleu pour les autres',
    str_contains($html, 'background:#E74310"><a href="https://exemple.invalid/choix"')
    && str_contains($html, 'background:#054570"><a href="https://exemple.invalid/connexion"'));
verifie('intertitre', str_contains($html, '1. Connectez-vous</h2>') && !str_contains($html, '# 1.'));
verifie('encadré à puces : titre, deux puces, note', substr_count($html, '<li ') === 2
    && str_contains($html, 'Votre offre comprend') && str_contains($html, 'Note sous la liste.'));
verifie('italique, gras et lien dans le texte', str_contains($html, '<em>Titre</em>')
    && str_contains($html, '<strong>gras</strong>') && str_contains($html, '<a href="https://exemple.invalid/l"'));
verifie('note discrète et filet', str_contains($html, '<hr') && !str_contains($html, '&gt; Petite'));
verifie('typographie française : espace insécable avant « ! »',
    str_contains($html, "Fin\u{00A0}!") && str_contains($html, "Léa\u{00A0}!</h1>"));
verifie('texte d’aperçu présent mais masqué', str_contains($html, 'display:none') && str_contains($html, 'Trois étapes'));
verifie('pied et contact propres au message', str_contains($html, 'Pied propre au message.')
    && str_contains($html, 'mailto:familles@example.invalid') && !str_contains($html, 'aucune donnée personnelle'));
verifie('seules les adresses http(s) deviennent des liens',
    !str_contains(courriel_html('x', '[clic](javascript:alert(1))'), 'href="javascript'));
verifie('le contenu des puces est échappé', str_contains(courriel_html('x', "Titre\n- <b>x</b>"), '&lt;b&gt;'));
verifie('une phrase terminée par une adresse ne devient pas un bouton',
    !str_contains(courriel_html('x', 'Pour en savoir plus, voyez ici : https://exemple.invalid/p'), 'border-radius:999px;background'));
$texte = courriel_texte($corps);
verifie('version texte : balisage retiré, adresses gardées', str_contains($texte, "\n\n1. Connectez-vous\n\n")
    && str_contains($texte, 'un lien (https://exemple.invalid/l) et gras') && str_contains($texte, "- le module sur Titre\u{00A0};")
    && !str_contains($texte, '---') && !str_contains($texte, '> ')
    && str_contains($texte, "Aller sur le campus\u{00A0}: https://exemple.invalid/connexion"));
verifie('version texte d’un corps sans balisage : inchangée',
    courriel_texte("Bonjour,\n\nhttps://exemple.invalid/x") === "Bonjour,\n\nhttps://exemple.invalid/x");
// Une image d'un pixel, écrite dans le répertoire temporaire puis effacée.
$png = sys_get_temp_dir() . '/socle-essai-' . bin2hex(random_bytes(4)) . '.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
$images = courriel_images(['logo' => $png, 'illustration' => __FILE__]);
verifie('image locale retenue, fichier qui n’est pas une image écarté', isset($images['logo'])
    && !isset($images['illustration']) && $images['logo']['type'] === 'image/png' && $images['logo']['hauteur'] === 130);
verifie('ni adresse distante ni flux comme image',
    courriel_images(['logo' => 'https://exemple.invalid/logo.png', 'illustration' => 'data://image/png;base64,AAAA']) === []);
$html = courriel_html('x', 'y', 'Ouvrir', ['logo' => $png, 'illustration' => $png, 'bandeau' => 'MARQUE']);
verifie('logo et illustration en pièces jointes en ligne, jamais distants',
    str_contains($html, 'src="cid:logo@courriel"') && str_contains($html, 'src="cid:illustration@courriel"')
    && !preg_match('#<img[^>]+src="https?:#', $html) && str_contains($html, '>MARQUE</td>'));
verifie('aperçu dans un navigateur : images en data:',
    str_contains(courriel_html('x', 'y', 'Ouvrir', ['logo' => $png, 'src_images' => 'data']), 'src="data:image/png;base64,'));
unlink($png);
verifie('logo absent : le bandeau retombe sur le nom, sans échec',
    str_contains(courriel_html('x', 'y', 'Ouvrir', ['logo' => $png, 'bandeau' => 'MARQUE']), '>MARQUE</td>'));

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
