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
    foreach (['formats', 'securite', 'bd', 'parametres', 'connexion', 'courriel', 'rebonds',
              'journal', 'cron', 'flash', 'validation', 'image', 'csv', 'ical', 'porte_demo', 'totp',
              'authentification', 'mot_de_passe', 'session_durable', 'codes_secours', 'texte_enrichi', 'vue_agenda'] as $brique) {
        require __DIR__ . "/src/$brique.php";
    }
    // qrcode.php a besoin de bacon/bacon-qr-code : sans Composer, ses contrôles sont ignorés.
    if (class_exists(\BaconQrCode\Encoder\Encoder::class)) require __DIR__ . '/src/qrcode.php';
    require __DIR__ . '/src/webauthn.php';   // inactive sans web-auth/webauthn-lib (webauthn_disponible())
}

require __DIR__ . '/outils/banc.php';   // verifie(), titre(), bilan()

titre('Formats');
verifie('échappement', h('<b>"x"</b>') === '&lt;b&gt;&quot;x&quot;&lt;/b&gt;');
verifie('texte en paragraphes', str_contains(texte("un\n\ndeux"), '<p>un</p><p>deux</p>'));
verifie('date complète', date_fr('2026-09-21T12:30:00Z', 'complet', new DateTimeZone('Europe/Paris'))
        === 'lundi 21 septembre 2026 à 14h30');
verifie('heure pile avec ses minutes (« 14h00 », décision 4.4)',
        date_fr('2026-09-21T12:00:00Z', 'heure', new DateTimeZone('Europe/Paris')) === '14h00'
        && date_fr('2026-09-21T07:05:00Z') === 'lundi 21 septembre 2026 à 9h05');
verifie('heure d’horaire : « 19h00 », « 9h30 », colonne TIME acceptée',
        heure_fr('19:00') === '19h00' && heure_fr('09:30') === '9h30' && heure_fr('18:45:00') === '18h45'
        && heure_fr('0:00') === '0h00');
verifie('heure illisible : vide', heure_fr(null) === '' && heure_fr('') === '' && heure_fr('25:00') === ''
        && heure_fr('19h') === '');
verifie('date de calendrier', date_simple_fr('2026-01-05') === '5 janvier 2026');
verifie('montant', euros(108000) === '1 080 €' && euros(30050) === '300,50 €');
verifie('montant nul', euros(null) === 'Sur devis');
verifie('montant négatif (avoir, remboursement)', euros(-1050) === '-10,50 €' && euros(-50) === '-0,50 €'
        && euros(-200000) === '-2 000 €');
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
verifie('bouton à libellé', str_contains($html, '>Aller sur le campus</span>')
    && str_contains($html, 'href="https://exemple.invalid/connexion"'));
verifie('un bouton à libellé ne répète pas son adresse en clair', substr_count($html, 'exemple.invalid/connexion') === 1);
verifie('bouton d’accent pour l’adresse désignée, bleu pour les autres',
    str_contains($html, 'background:#D03C0E"><a href="https://exemple.invalid/choix"')
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

titre('Thème des courriels (2.2)');
$html = courriel_html('Sans thème', 'Corps.');
verifie('sans thème : bandeau historique, aucune image, rien d’une autre charte',
    !str_contains($html, '<img') && str_contains($html, 'background:#054570;padding:22px') && str_contains($html, '>Essai Socle</td>'));
verifie('largeur 600 px, fixée aussi pour Outlook', str_contains($html, 'max-width:600px')
    && str_contains($html, '<!--[if mso]><table role="presentation" width="600"'));
verifie('mode sombre déclaré, avec ses couleurs et la reprise Outlook.com',
    str_contains($html, '<meta name="color-scheme" content="light dark">') && str_contains($html, '@media (prefers-color-scheme:dark){.c-fond{background:#0C1821!important}')
    && str_contains($html, '[data-ogsc] .c-encre{'));
define('COURRIEL_THEME', [
    'marque' => 'Maison Essai',
    'logo' => ['url' => 'https://exemple.invalid/logo.png', 'largeur' => 180, 'hauteur' => 50],
    'couleurs' => ['bandeau' => '#0b0b0d', 'bouton' => '#ec3013', 'lien' => '#aa1100', 'titre' => 'red;background:url(x)',
                   'encre' => '#123', 'trait' => 'expression(1)'],
    'sombre' => ['fond' => '#000000'],
    'police' => 'Archivo,Arial,sans-serif',
    'police_web' => ['nom' => 'Archivo', 'url' => 'https://exemple.invalid/archivo.woff2'],
    'site' => ['url' => 'https://exemple.invalid/', 'libelle' => ''],
    'adresse' => '1 rue de l’Essai, 75000 Paris',
    'pied' => 'Pied du thème.',
]);
$corps = "Bonjour,\n\nVoir [la page](https://exemple.invalid/p).\n\nhttps://exemple.invalid/aller";
$html = courriel_html('Avec thème', $corps);
verifie('logo du thème : adresse https absolue, dimensions, texte de remplacement = la marque',
    str_contains($html, '<img src="https://exemple.invalid/logo.png" width="180" height="50" alt="Maison Essai"')
    && !str_contains($html, 'cid:'));
verifie('couleurs du thème : bandeau, bouton, liens, texte',
    str_contains($html, 'bgcolor="#0b0b0d"') && str_contains($html, 'background:#ec3013"><a href="https://exemple.invalid/aller"')
    && str_contains($html, 'style="color:#aa1100">la page</a>') && str_contains($html, 'color:#123;'));
verifie('couleur douteuse écartée au profit de la précédente', !str_contains($html, 'url(x)') && !str_contains($html, 'expression(')
    && str_contains($html, 'color:#054570">Avec thème</h1>') && str_contains($html, 'border-top:1px solid #C8D5DF'));
verifie('police du thème et police web déclarée', str_contains($html, 'font-family:Archivo,Arial,sans-serif')
    && str_contains($html, "@font-face{font-family:'Archivo'") && str_contains($html, 'src:url(https://exemple.invalid/archivo.woff2)'));
verifie('couleur sombre du thème', str_contains($html, '.c-fond{background:#000000!important}'));
verifie('pied du thème : marque, site sans « https:// », adresse, phrase, contact',
    str_contains($html, '<strong>Maison Essai</strong> · <a href="https://exemple.invalid/"') && str_contains($html, '>exemple.invalid</a>')
    && str_contains($html, '1 rue de l’Essai') && str_contains($html, 'Pied du thème.') && str_contains($html, 'mailto:contact@example.invalid'));
$texte = courriel_texte($corps, []);
verifie('version texte : le même pied, sous « -- »', str_contains($texte, "https://exemple.invalid/aller\n\n-- \nMaison Essai · https://exemple.invalid/\n")
    && str_contains($texte, "Pied du thème.\nContact\u{00A0}: contact@example.invalid") && str_contains($texte, 'la page (https://exemple.invalid/p)'));
verifie('version texte sans options : le corps seul, comme avant', !str_contains(courriel_texte($corps), '-- '));
$GLOBALS['theme_essai'] = ['couleurs' => ['bouton' => '#00873A'], 'logo' => ['url' => 'http://exemple.invalid/clair.png', 'alt' => 'Logo Essai',
                                         'url_sombre' => 'https://exemple.invalid/sombre.png']];
if (!function_exists('courriel_theme_projet')) {
    function courriel_theme_projet(): array { return $GLOBALS['theme_essai']; }
}
$html = courriel_html('Projet', 'https://exemple.invalid/aller');
verifie('courriel_theme_projet() passe devant la constante', str_contains($html, 'background:#00873A"><a href=')
    && str_contains($html, 'bgcolor="#0b0b0d"'));
verifie('logo en http refusé (le précédent reste), variante sombre en https acceptée',
    str_contains($html, 'src="https://exemple.invalid/logo.png"') && !str_contains($html, 'http://exemple.invalid/clair.png')
    && str_contains($html, 'class="logo-sombre"') && str_contains($html, 'src="https://exemple.invalid/sombre.png"')
    && str_contains($html, 'alt="Logo Essai"') && str_contains($html, '.logo-clair{display:none!important}'));
$GLOBALS['theme_essai'] = ['police' => 'Arial" onload="x', 'logo' => ['url' => 'https://exemple.invalid/a.png"onerror=x'],
                           'site' => ['url' => 'javascript:alert(1)']];
$html = courriel_html('Projet', 'Texte');
verifie('police, logo et site douteux écartés', !str_contains($html, 'onload') && !str_contains($html, 'onerror')
    && !str_contains($html, 'javascript:') && str_contains($html, 'font-family:Archivo,Arial,sans-serif'));
$png = sys_get_temp_dir() . '/socle-essai-' . bin2hex(random_bytes(4)) . '.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
$html = courriel_html('x', 'y', 'Ouvrir', ['logo' => $png]);
unlink($png);
verifie('le logo intégré d’un message (1.7) remplace celui du thème', str_contains($html, 'src="cid:logo@courriel"')
    && !str_contains($html, 'exemple.invalid/logo.png'));
$html = courriel_gabarit('Contenu maison', '<p>Déjà <b>mis en forme</b></p>', ['apercu' => 'Aperçu', 'pied' => 'Pied du message.']);
verifie('courriel_gabarit() habille un contenu HTML fourni', str_contains($html, '<p>Déjà <b>mis en forme</b></p>')
    && str_contains($html, 'Contenu maison</h1>') && str_contains($html, 'Pied du message.') && str_contains($html, 'bgcolor="#0b0b0d"'));
$GLOBALS['theme_essai'] = [];
verifie('couleur_melange() comme color-mix : rouge vers le noir, vers le blanc, hexa court',
    couleur_melange('#ec3013', '#000000', .82) === '#c22710' && couleur_melange('#ec3013', '#fff', .07) === '#fef1ee'
    && couleur_melange('#000', '#ffffff', .5) === '#808080' && couleur_melange('rouge', '#fff', .5) === 'rouge');

titre('Courriels non remis');
verifie('colonne présente reconnue, colonne absente refusée sans erreur',
    socle_colonne('notification', 'message_id') && !socle_colonne('notification', 'colonne_absente')
    && !socle_colonne('notification; DROP', 'x'));
filer_courriel('eleve@example.invalid', 'Bienvenue', 'Corps', null, 'rebond-1');
$n1 = (int)bd()->query("SELECT id FROM notification WHERE cle_unicite = 'rebond-1'")->fetchColumn();
courriel_noter_message_id($n1, '<origine1@example.invalid>');
bd()->exec("UPDATE notification SET etat = 'envoye', envoye_le = '" . gmdate('Y-m-d\TH:i:s\Z', time() - 7200) . "' WHERE id = $n1");
verifie('identifiant du message remis noté dans la file',
    bd()->query("SELECT message_id FROM notification WHERE id = $n1")->fetchColumn() === '<origine1@example.invalid>');
filer_courriel('autre@example.invalid', 'Lien', 'Corps', null, 'rebond-2', null, '', null, false, null, null, 'parent@example.invalid');
$n2 = (int)bd()->query("SELECT id FROM notification WHERE cle_unicite = 'rebond-2'")->fetchColumn();
bd()->exec("UPDATE notification SET etat = 'envoye', envoye_le = '" . gmdate('Y-m-d\TH:i:s\Z', time() - 3600) . "' WHERE id = $n2");

// Un avis à la mode Postfix (relais d'envoi) pour un refus de Google : partie normalisée, copie des
// en-têtes d'origine. Tout est fictif.
$date = gmdate('D, d M Y H:i:s', time() - 600) . ' +0000 (UTC)';
$avis = "Return-Path: <>\r\nDate: $date\r\nFrom: MAILER-DAEMON@example.invalid (Mail Delivery System)\r\n"
    . "Subject: Undelivered Mail Returned to Sender\r\nTo: essai@example.invalid\r\nMessage-ID:\r\n <avis1@example.invalid>\r\n"
    . "Content-Type: multipart/report; report-type=delivery-status;\r\n\tboundary=\"B\"\r\n\r\n"
    . "--B\r\nContent-Type: text/plain\r\n\r\nI'm sorry to have to inform you that your message could not be delivered.\r\n\r\n"
    . "--B\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; relais.example.invalid\r\n"
    . "Arrival-Date: $date\r\n\r\nFinal-Recipient: rfc822; Eleve@example.invalid\r\nOriginal-Recipient: rfc822;eleve@example.invalid\r\n"
    . "Action: failed\r\nStatus: 5.1.1\r\nRemote-MTA: dns; mx.example.invalid\r\nDiagnostic-Code: smtp; 550-5.1.1 The email account that you tried\r\n"
    . "    to reach does not exist. Please try 550-5.1.1 double-checking the\r\n    recipient's email address. 550 5.1.1 gsmtp\r\n\r\n"
    . "--B\r\nContent-Type: text/rfc822-headers\r\n\r\nFrom: Essai <essai@example.invalid>\r\nTo: eleve@example.invalid\r\n"
    . "Message-ID: <origine1@example.invalid>\r\nSubject: Bienvenue\r\n\r\n--B--\r\n";
$a = rebond_analyser($avis);
verifie('avis : identifiant propre, distinct de celui du courriel d’origine',
    $a['cle'] === '<avis1@example.invalid>' && $a['origine'] === ['<origine1@example.invalid>']);
$d = $a['destinataires'][0] ?? [];
verifie('avis : adresse en échec, code 5.1.1, échec définitif, non remis', count($a['destinataires']) === 1
    && $d['adresse'] === 'eleve@example.invalid' && $d['statut'] === '5.1.1' && $d['gravite'] === 'definitif' && $d['non_remis']);
verifie('avis : diagnostic sur une ligne, codes répétés retirés',
    $d['diagnostic'] === "The email account that you tried to reach does not exist. Please try double-checking the recipient's email address. gsmtp");
verifie('avis : date de réception lue malgré le commentaire (UTC)', $a['recu_le'] !== null && abs(strtotime($a['recu_le']) - (time() - 600)) < 5);

$retard = "Date: $date\r\nMessage-ID: <avis2@example.invalid>\r\nSubject: Delivery Status Notification (Delay)\r\n\r\n"
    . "Final-Recipient: rfc822; lente@example.invalid\r\nAction: delayed\r\nStatus: 4.4.7\r\n  \r\n"   // ligne d'espaces : sépare
    . "Final-Recipient: rfc822; abandon@example.invalid\r\nAction: failed\r\nStatus: 4.4.1\r\n\r\n"
    . "Final-Recipient: rfc822; bonne@example.invalid\r\nAction: delivered\r\nStatus: 2.0.0\r\n";
$a = rebond_analyser($retard);
$par = array_column($a['destinataires'], null, 'adresse');
verifie('retard 4.x.x : temporaire, pas encore non remis', ($par['lente@example.invalid']['gravite'] ?? '') === 'temporaire'
    && ($par['lente@example.invalid']['non_remis'] ?? true) === false);
verifie('remise réussie ignorée', !isset($par['bonne@example.invalid']));
verifie('abandon sur un code 4.x.x : non remis, mais l’adresse reste en gravité temporaire',
    ($par['abandon@example.invalid']['gravite'] ?? '') === 'temporaire' && ($par['abandon@example.invalid']['non_remis'] ?? false) === true);
verifie('gravité : code absent mais réponse 550 = définitif ; delivered = pas un échec',
    rebond_gravite('failed', '', 'smtp; 550 no such user') === 'definitif' && rebond_gravite('delivered', '2.0.0') === '');

$libre = "Date: $date\r\nMessage-ID: <avis3@example.invalid>\r\nSubject: failure notice\r\n\r\n"
    . "Sorry, we were unable to deliver your message to the following address.\r\n\r\n"
    . "<parent@example.invalid>:\r\nRemote host said: 550 5.2.1 user unknown\r\n\r\n"
    . "--- Below this line is a copy of the message.\r\nFrom: essai@example.invalid\r\nTo: inconnue@example.invalid\r\n";
$a = rebond_analyser($libre, ['parent@example.invalid' => true, 'essai@example.invalid' => true]);
verifie('avis en texte libre : seules les adresses connues, code relevé dans le texte',
    count($a['destinataires']) === 2 && $a['destinataires'][0]['adresse'] === 'parent@example.invalid'
    && $a['destinataires'][0]['statut'] === '5.2.1' && $a['destinataires'][0]['diagnostic'] === 'Remote host said: user unknown');

verifie('lecture non configurée sans hôte IMAP', rebonds_reglages() === null && !rebonds_configure()
    && rebonds_relever()['erreur'] !== null);
$incompris = "Date: $date\r\nMessage-ID: <avis4@example.invalid>\r\nSubject: Undeliverable\r\n\r\nRien d'exploitable.\r\n";
$GLOBALS['rebonds_boite'] = fn(int $jours, int $max): array => ['erreur' => null, 'messages' => [
    ['cle' => 'imap-1', 'brut' => $avis], ['cle' => 'imap-2', 'brut' => $libre], ['cle' => 'imap-3', 'brut' => $incompris],
    ['cle' => 'imap-4', 'brut' => $avis]]];
$b = rebonds_relever();
verifie('relevé : 3 avis nouveaux (le doublon sauté), 2 adresses en échec, 1 avis non compris',
    $b['erreur'] === null && $b['lus'] === 4 && $b['nouveaux'] === 3 && $b['definitifs'] === 2 && $b['incompris'] === 1
    && count($b['retours']) === 2, json_encode($b));
verifie('l’adresse d’envoi du projet n’est jamais prise pour une adresse en échec',
    !in_array('essai@example.invalid', array_column($b['retours'], 'adresse'), true));
$r = array_column($b['retours'], null, 'adresse');
verifie('rapprochement exact par l’identifiant du message', ($r['eleve@example.invalid']['notification_id'] ?? 0) === $n1);
verifie('rapprochement par l’adresse en copie, à défaut d’identifiant', ($r['parent@example.invalid']['notification_id'] ?? 0) === $n2);
verifie('courriels marqués non remis', $b['rapproches'] === 2
    && (int)bd()->query("SELECT COUNT(*) FROM notification WHERE rebond_le IS NOT NULL AND id IN ($n1, $n2)")->fetchColumn() === 2);
$b2 = rebonds_relever();
verifie('second relevé : rien de nouveau', $b2['nouveaux'] === 0 && $b2['retours'] === []);
verifie('adresse en échec définitif reconnue, casse ignorée ; une autre non',
    (rebond_actif('ELEVE@example.invalid')['statut'] ?? '') === '5.1.1' && rebond_actif('autre@example.invalid') === null);
verifie('échecs d’une liste d’adresses', array_keys(rebonds_actifs(['parent@example.invalid', 'autre@example.invalid']))
    === ['parent@example.invalid']);
verifie('adresse vérifiée : l’échec ne bloque plus, rien n’est supprimé', rebonds_lever('eleve@example.invalid', 7) === 1
    && rebond_actif('eleve@example.invalid') === null && count(rebonds_de('eleve@example.invalid')) === 1);
verifie('libellés des codes', rebond_libelle('5.1.1') === 'boîte inexistante' && rebond_libelle('5.7.26') === 'refusé par le serveur du destinataire'
    && rebond_libelle('4.4.7', 'temporaire') === 'remise retardée' && rebond_libelle('', 'definitif') === 'non remis');
unset($GLOBALS['rebonds_boite']);

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

titre('Paramètres (table config)');
verifie('défaut quand la clé manque', param('clef-inconnue', 'repli') === 'repli');
param_definir('couleur', 'bleu');
verifie('valeur écrite puis relue aussitôt (cache invalidé)', param('couleur') === 'bleu');
param_definir('couleur', 'rouge');
verifie('valeur remplacée', param('couleur') === 'rouge');
verifie('socle_param() consulte param() avant les constantes', socle_param('COULEUR', 'x') === 'rouge');

titre('SQL portable et migrations additives');
verifie('base de test reconnue comme SQLite', !bd_mysql());
verifie('insert ignoré selon le moteur', sql_insert_ignore() === 'INSERT OR IGNORE');
verifie('upsert selon le moteur',
    sql_sur_doublon('cle', ['valeur', 'maj_le']) === 'ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur, maj_le = excluded.maj_le');
colonne_assurer(bd(), 'journal', 'colonne_essai', "TEXT NOT NULL DEFAULT ''");
colonne_assurer(bd(), 'journal', 'colonne_essai', "TEXT NOT NULL DEFAULT ''");
$colonnes = array_column(bd()->query('PRAGMA table_info(journal)')->fetchAll(), 'name');
verifie('colonne ajoutée, ajout rejouable sans erreur', count(array_keys($colonnes, 'colonne_essai', true)) === 1);
try { colonne_assurer(bd(), 'journal; DROP TABLE journal', 'x', 'TEXT'); $refuse = false; }
catch (InvalidArgumentException) { $refuse = true; }
verifie('nom de table invalide refusé', $refuse);

titre('Connexion par lien magique et rôles');
define('ADMINS_AMORCAGE', ['chef@example.org', 'binome@example.org' => 'Binôme',
                           'modele@example.invalid']);
amorcer_admins();
amorcer_admins();   // rejouable
verifie('admins amorcés une seule fois, modèle example.invalid ignoré',
    (int)bd()->query("SELECT COUNT(*) FROM utilisateur WHERE role = 'admin'")->fetchColumn() === 2);
verifie('nom repris de la forme adresse => nom',
    bd()->query("SELECT nom FROM utilisateur WHERE courriel = 'binome@example.org'")->fetchColumn() === 'Binôme');
bd()->exec("UPDATE utilisateur SET actif = 0 WHERE courriel = 'chef@example.org'");
amorcer_admins();
verifie('un compte désactivé ne renaît pas à l\'amorçage',
    (int)bd()->query("SELECT actif FROM utilisateur WHERE courriel = 'chef@example.org'")->fetchColumn() === 0);
bd()->exec("UPDATE utilisateur SET actif = 1 WHERE courriel = 'chef@example.org'");

verifie('durée du lien : 30 minutes par défaut', duree_lien_magique() === 1800);
verifie('adresse invalide : réponse identique, rien d\'envoyé', lien_magique_envoyer('pas-une-adresse')
    && (int)bd()->query('SELECT COUNT(*) FROM jeton_connexion')->fetchColumn() === 0);
verifie('adresse inconnue : réponse identique, rien d\'envoyé', lien_magique_envoyer('personne@example.org')
    && (int)bd()->query('SELECT COUNT(*) FROM jeton_connexion')->fetchColumn() === 0);
verifie('adresse connue : lien envoyé', lien_magique_envoyer('CHEF@example.org')
    && (int)bd()->query('SELECT COUNT(*) FROM jeton_connexion')->fetchColumn() === 1);
$corps = (string)bd()->query("SELECT corps FROM notification WHERE objet_type = 'connexion'
                              ORDER BY id DESC LIMIT 1")->fetchColumn();
preg_match('/jeton=([A-Za-z0-9_-]+)/', $corps, $m);
$jeton_magique = $m[1] ?? '';
verifie('le courriel porte le lien et sa durée', $jeton_magique !== '' && str_contains($corps, '30 minutes'));
verifie('le jeton n\'est jamais en clair en base',
    bd()->query('SELECT COUNT(*) FROM jeton_connexion WHERE empreinte = ' . bd()->quote($jeton_magique))->fetchColumn() === 0
    && bd()->query('SELECT empreinte FROM jeton_connexion ORDER BY id DESC LIMIT 1')->fetchColumn() === hacher_jeton($jeton_magique));
$u = lien_magique_consommer($jeton_magique);
verifie('jeton consommé : utilisateur rendu, dernière connexion notée',
    ($u['courriel'] ?? '') === 'chef@example.org'
    && bd()->query("SELECT derniere_connexion FROM utilisateur WHERE courriel = 'chef@example.org'")->fetchColumn() !== null);
verifie('un lien ne sert qu\'une fois', lien_magique_consommer($jeton_magique) === null);
verifie('jeton inconnu refusé', lien_magique_consommer('nimporte-quoi') === null);
// Jeton expiré : posé à la main avec une échéance passée.
$jeton_perime = jeton_aleatoire();
bd()->prepare('INSERT INTO jeton_connexion (utilisateur_id, empreinte, expire_le, cree_le) VALUES (?, ?, ?, ?)')
    ->execute([1, hacher_jeton($jeton_perime), gmdate('Y-m-d\TH:i:s\Z', time() - 60), maintenant()]);
verifie('jeton expiré refusé', lien_magique_consommer($jeton_perime) === null);
// Compte désactivé après l'envoi du lien : le lien ne vaut plus rien.
lien_magique_envoyer('binome@example.org');
preg_match('/jeton=([A-Za-z0-9_-]+)/', (string)bd()->query("SELECT corps FROM notification WHERE objet_type = 'connexion'
    ORDER BY id DESC LIMIT 1")->fetchColumn(), $m);
bd()->exec("UPDATE utilisateur SET actif = 0 WHERE courriel = 'binome@example.org'");
verifie('compte désactivé : son lien ne vaut plus rien', lien_magique_consommer($m[1] ?? '') === null);
bd()->exec("UPDATE utilisateur SET actif = 1 WHERE courriel = 'binome@example.org'");
for ($i = 0; $i < 5; $i++) $encore = lien_magique_envoyer('abuse@example.org');
verifie('limitation par adresse : la sixième demande est bloquée, et dite',
    $encore && !lien_magique_envoyer('abuse@example.org'));
verifie('est_admin selon le rôle', est_admin(['role' => 'admin']) && !est_admin(['role' => 'equipe']) && !est_admin());
verifie('deux admins actifs : aucun n\'est le dernier', !dernier_admin_actif(1) && !dernier_admin_actif(2));
bd()->exec("UPDATE utilisateur SET actif = 0 WHERE courriel = 'binome@example.org'");
verifie('un seul admin actif : lui seul est le dernier', dernier_admin_actif(1) && !dernier_admin_actif(2));
bd()->exec("UPDATE utilisateur SET actif = 1 WHERE courriel = 'binome@example.org'");

titre('Export CSV pour Excel');
$flux = fopen('php://memory', 'w+');
$n = csv_ecrire($flux, ['Nom', 'Détail'], [['Léa', '=1+2'], ['Zoé;bis', '10,5']]);
rewind($flux);
$csv = stream_get_contents($flux);
fclose($flux);
verifie('BOM UTF-8 en tête', str_starts_with($csv, "\u{FEFF}"));
verifie('séparateur point-virgule', str_contains($csv, "Nom;Détail"));
verifie('deux lignes écrites, champ à point-virgule protégé par des guillemets',
    $n === 2 && str_contains($csv, '"Zoé;bis"'));
verifie('cellule-formule neutralisée', str_contains($csv, "'=1+2") && csv_cellule('-2+3') === "'-2+3"
    && csv_cellule('@x') === "'@x" && csv_cellule('10,5') === '10,5' && csv_cellule('+33612345678') === "'+33612345678"
    && csv_cellule('-A1') === "'-A1" && csv_cellule("-2\n") === "'-2\n" && csv_cellule('--2') === "'--2");
verifie('montant négatif laissé en nombre', csv_cellule('-2') === '-2' && csv_cellule('-12,50') === '-12,50'
    && csv_cellule('-1 080,50') === '-1 080,50' && csv_cellule("-1\u{202F}080") === "-1\u{202F}080"
    && csv_cellule(-7.5) === '-7.5' && csv_cellule('-12 50') === "'-12 50");
$flux = fopen('php://memory', 'w+');
csv_ecrire($flux, ['x'], [['=brut']], ['proteger_formules' => false]);
rewind($flux);
verifie('protection débrayable quand la colonne l\'exige', str_contains(stream_get_contents($flux), "=brut")
    && !str_contains((string)stream_get_contents($flux, -1, 0), "'=brut"));
fclose($flux);

titre('Formats complémentaires');
verifie('nombre décimal à la française', nombre_fr(3.5) === '3,5' && nombre_fr(2.0) === '2'
    && nombre_fr(1234.56) === '1 234,56' && nombre_fr(1250.0) === '1 250');
verifie('sans décimales, les zéros de la partie entière restent', nombre_fr(40, 0) === '40' && nombre_fr(1000.4, 0) === '1 000');
verifie('accord du mot au nombre', accorder(1, 'atelier') === '1 atelier' && accorder(3, 'atelier') === '3 ateliers'
    && accorder(2, 'bac blanc', 'bacs blancs') === '2 bacs blancs');
verifie('initiales des deux premiers mots', initiales('Léa Dupont') === 'LD' && initiales('élise') === 'É'
    && initiales('Anne-Claire de la Tour') === 'AD' && initiales('  ') === '?');
verifie('accents retirés, casse conservée', sans_accents('Éléonore Ça œuvre, NOËL') === 'Eleonore Ca oeuvre, NOEL');

titre('Liens signés révocables');
$s = signature_revocable('dispo/7', 'dispo_version');
verifie('signature courte et stable', strlen($s) === 24 && $s === signature_revocable('dispo/7', 'dispo_version'));
verifie('signature acceptée', signature_revocable_valide('dispo/7', 'dispo_version', $s));
verifie('autre cible, signature vide ou clé différente : refusées',
    !signature_revocable_valide('dispo/8', 'dispo_version', $s)
    && !signature_revocable_valide('dispo/7', 'dispo_version', '')
    && !signature_revocable_valide('dispo/7', 'autre_version', $s));
signature_revoquer('dispo_version');
verifie('révocation : les liens distribués cessent de valoir, les nouveaux valent',
    !signature_revocable_valide('dispo/7', 'dispo_version', $s)
    && signature_revocable_valide('dispo/7', 'dispo_version', signature_revocable('dispo/7', 'dispo_version'))
    && param('dispo_version') === '2');
verifie('longueur au choix', strlen(signature_revocable('catalogue', 'catalogue_version', 32)) === 32);

titre('Export iCal');
$ics = ical_calendrier([
    ['uid' => 'e-1@example.org', 'debut' => '2026-11-05 18:00', 'fin' => '2026-11-05 19:30',
     'resume' => 'Séance 3, salle B; répétition', 'lieu' => 'Paris, salle B',
     'description' => "Ligne 1\nLigne 2", 'statut' => 'confirme'],
    ['uid' => 'e-2@example.org', 'debut' => '2026-11-12 18:00:00', 'fin' => '2026-11-12 19:30:00',
     'resume' => str_repeat('é', 60), 'statut' => 'provisoire'],
    ['uid' => 'e-3@example.org', 'debut' => '2026-11-19 18:00', 'fin' => '2026-11-19 19:30',
     'resume' => 'Relâche', 'statut' => 'annule'],
], ['nom' => 'Essai Socle']);
verifie('enveloppe VCALENDAR et fuseau Europe/Paris', str_starts_with($ics, "BEGIN:VCALENDAR\r\n")
    && str_ends_with($ics, "END:VCALENDAR\r\n") && str_contains($ics, 'TZID:Europe/Paris')
    && str_contains($ics, 'X-WR-CALNAME:Essai Socle'));
verifie('heures locales déclarées avec le fuseau',
    str_contains($ics, 'DTSTART;TZID=Europe/Paris:20261105T180000')
    && str_contains($ics, 'DTEND;TZID=Europe/Paris:20261112T193000'));
verifie('virgules, points-virgules et retours à la ligne échappés',
    str_contains($ics, 'SUMMARY:Séance 3\, salle B\; répétition')
    && str_contains($ics, 'DESCRIPTION:Ligne 1\nLigne 2') && str_contains($ics, 'LOCATION:Paris\, salle B'));
verifie('statuts traduits', str_contains($ics, 'STATUS:CONFIRMED') && str_contains($ics, 'STATUS:TENTATIVE')
    && str_contains($ics, 'STATUS:CANCELLED'));
$lignes_ics = explode("\r\n", $ics);
verifie('aucune ligne au-delà de 75 octets', max(array_map('strlen', $lignes_ics)) <= 75);
verifie('pliage sans couper un caractère en deux (contenu long intact une fois déplié)',
    str_contains(str_replace("\r\n ", '', $ics), str_repeat('é', 60)));

titre('Surveillance du cron');
$alertes = fn(): int => (int)bd()->query("SELECT COUNT(*) FROM notification WHERE objet_type = 'cron_alerte'")->fetchColumn();
$s = cron_situation('cron', 3600);
verifie('tâche jamais passée : en retard, sans date', cron_dernier_passage() === null && $s['en_retard']
    && $s['dernier'] === null && $s['silence'] === null);
verifie('jamais passée : pas d’alerte (copie locale, installation neuve)', !cron_surveiller('cron', 3600) && $alertes() === 0);
cron_temoin();
$s = cron_situation('cron', 3600);
verifie('témoin noté sous la clé déjà lue par les inscriptions',
    param('cron_dernier_passage') === cron_dernier_passage()
    && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string)cron_dernier_passage()) === 1);
verifie('passage récent : à l’heure, aucune alerte', !$s['en_retard'] && $s['silence'] <= 5 && !cron_surveiller('cron', 3600));
param_definir('cron_dernier_passage', '2026-01-10T08:00:00Z');
$s = cron_situation('cron', 3600);
verifie('silence mesuré et retard constaté', $s['en_retard'] && $s['silence'] > 3600);
verifie('silence : une alerte mise en file', cron_surveiller('cron', 3600) && $alertes() === 1);
$n = bd()->query("SELECT * FROM notification WHERE objet_type = 'cron_alerte'")->fetch();
verifie('alerte adressée au contact, datée en français',
    $n['destinataire'] === 'contact@example.invalid' && str_contains($n['sujet'], 'Tâche planifiée arrêtée')
    && str_contains($n['corps'], 'samedi 10 janvier 2026 à 9h00'));
verifie('une seule alerte par épisode de silence', !cron_surveiller('cron', 3600) && $alertes() === 1);
param_definir('cron_dernier_passage', '2026-02-01T08:00:00Z');   // la tâche a repris, puis s'est tue de nouveau
verifie('nouvel épisode : nouvelle alerte', cron_surveiller('cron', 3600) && $alertes() === 2);
cron_temoin('relances');
verifie('plusieurs tâches suivies séparément', cron_dernier_passage('relances') !== null
    && !cron_situation('relances', 60)['en_retard'] && cron_situation('cron', 3600)['en_retard']);
param_definir('rebonds_dernier_passage', '2026-01-10T08:00:00Z');
verifie('destinataire au choix', cron_surveiller('rebonds', 3600, 'veille@example.org')
    && bd()->query("SELECT destinataire FROM notification WHERE objet_type = 'cron_alerte' ORDER BY id DESC LIMIT 1")
           ->fetchColumn() === 'veille@example.org');

titre('Messages flash');
$_SESSION = [];
verifie('aucun message : rien à afficher', afficher_flashs() === '' && flashs() === []);
flash('ok', 'Inscription enregistrée.');
flash('erreur', '<b>Échec</b>');
$html = afficher_flashs();
verifie('messages affichés dans l’ordre, échappés',
    strpos($html, 'Inscription enregistrée.') < strpos($html, '&lt;b&gt;Échec&lt;/b&gt;') && !str_contains($html, '<b>'));
verifie('erreur annoncée aussitôt, le reste poliment',
    str_contains($html, 'class="flash flash-erreur" role="alert"') && str_contains($html, 'class="flash flash-ok" role="status"'));
verifie('affichés une seule fois', afficher_flashs() === '' && !isset($_SESSION['flash']));
flash('x" onclick="alert(1)', 'Piège');
verifie('type réduit à une classe CSS sûre', str_contains(afficher_flashs(), 'class="flash flash-xonclickalert1"'));
$_SESSION['flash'] = 'Ancien message';   // forme du site de Tristan : une simple chaîne
verifie('ancienne forme (texte seul) encore lue', flashs() === [['type' => 'ok', 'texte' => 'Ancien message']]);
$_SESSION['flash'] = 'Premier';
flash('info', 'Second');
verifie('ajout à une ancienne forme sans perte', count(flashs()) === 2);

titre('Validation de formulaire');
$f = formulaire_valider([
    'nom' => '  Élise Martin ', 'courriel' => ' Elise@Example.ORG ', 'tarif' => '1 080,5', 'petit' => '0,29',
    'seance' => '05/11/2026', 'debut' => '18h', 'tel' => '+33 6 12 34 56 78', 'cp' => '75006',
    'site' => 'https://example.org/page', 'accord' => 'on', 'jours' => ['lundi', ' jeudi ', ''],
    'age' => '12', 'note' => '14,5', 'intrus' => 'jamais rendu',
], [
    'nom' => ['requis', 'max:120'], 'courriel' => ['requis', 'courriel'], 'tarif' => ['montant'],
    'petit' => ['montant'], 'seance' => ['date', 'min:2026-09-01'], 'debut' => ['heure'], 'tel' => ['telephone'],
    'cp' => ['code_postal'], 'site' => ['url'], 'accord' => ['case', 'requis'],
    'jours' => ['liste', 'parmi:lundi,mardi,jeudi'], 'age' => ['entier', 'min:3'], 'note' => ['decimal', 'max:20'],
    'commentaire' => [], 'rappel' => ['date'], 'options' => ['liste'], 'newsletter' => ['case'],
]);
$v = $f['valeurs'];
verifie('formulaire valide : aucune erreur', $f['erreurs'] === []);
verifie('texte rogné, courriel en minuscules', $v['nom'] === 'Élise Martin' && $v['courriel'] === 'elise@example.org');
verifie('montant en centimes, lu sans float', $v['tarif'] === 108050 && $v['petit'] === 29);
verifie('date et heure normalisées', $v['seance'] === '2026-11-05' && $v['debut'] === '18:00');
verifie('téléphone français mis en forme', $v['tel'] === '06 12 34 56 78');
verifie('code postal, adresse web', $v['cp'] === '75006' && $v['site'] === 'https://example.org/page');
verifie('case cochée en booléen, liste nettoyée', $v['accord'] === true && $v['jours'] === ['lundi', 'jeudi']);
verifie('nombres typés', $v['age'] === 12 && $v['note'] === 14.5);
verifie('facultatifs vides : texte vide, null, liste vide, case décochée',
    $v['commentaire'] === '' && $v['rappel'] === null && $v['options'] === [] && $v['newsletter'] === false);
verifie('champ hors des règles jamais rendu', !array_key_exists('intrus', $v));
$f = formulaire_valider([
    'nom' => '   ', 'courriel' => 'pas-une-adresse', 'tarif' => '12,345', 'seance' => '31/02/2026',
    'debut' => '25h', 'tel' => '12345', 'cp' => '7500', 'site' => 'javascript:alert(1)', 'jours' => ['lundi', 'dimanche'],
    'age' => '2', 'note' => '21', 'code' => 'abc', 'titre' => str_repeat('é', 6), 'tableau' => ['x'],
    'avant' => '2026-08-31', 'cadeau' => '15',
], [
    'nom' => ['requis'], 'courriel' => ['courriel'], 'tarif' => ['montant'], 'seance' => ['date'], 'debut' => ['heure'],
    'tel' => ['telephone'], 'cp' => ['code_postal'], 'site' => ['url'], 'jours' => ['liste', 'parmi:lundi,mardi'],
    'age' => ['entier', 'min:3'], 'note' => ['decimal', 'max:20'], 'code' => ['motif:/^[A-Z]{3}$/', 'message' => 'Trois lettres capitales.'],
    'titre' => ['max:5'], 'tableau' => ['requis'], 'avant' => ['date', 'min:2026-09-01'], 'accord' => ['case', 'requis'],
    'cadeau' => ['montant', 'max:10'], 'liste_vide' => ['liste', 'requis'],
    'pair' => ['entier', fn($n) => $n !== null && $n % 2 ? 'Nombre pair attendu.' : null],
]);
$e = $f['erreurs'];
verifie('obligatoire', ($e['nom'] ?? '') === 'Ce champ est obligatoire.' && ($e['liste_vide'] ?? '') === 'Choisissez au moins une option.');
verifie('courriel, montant, date impossible, heure', isset($e['courriel'], $e['tarif'], $e['seance'], $e['debut']));
verifie('téléphone, code postal, adresse javascript: refusée', isset($e['tel'], $e['cp'], $e['site']));
verifie('choix hors liste refusé', ($e['jours'] ?? '') === 'Choix invalide.');
verifie('bornes de nombres et de montants', ($e['age'] ?? '') === 'Valeur minimale : 3.'
    && ($e['note'] ?? '') === 'Valeur maximale : 20.' && ($e['cadeau'] ?? '') === 'Valeur maximale : 10 €.');
verifie('message propre au champ', ($e['code'] ?? '') === 'Trois lettres capitales.');
verifie('longueur comptée en caractères, pas en octets', ($e['titre'] ?? '') === 'Au plus 5 caractères.');
verifie('tableau là où un texte est attendu', ($e['tableau'] ?? '') === 'Valeur invalide.');
verifie('date avant la borne', ($e['avant'] ?? '') === 'Au plus tôt : 1 septembre 2026.');
verifie('case requise', ($e['accord'] ?? '') === 'Cette case doit être cochée.');
verifie('règle sur mesure', formulaire_valider(['pair' => '7'], ['pair' => ['entier', fn($n) => $n % 2 ? 'Nombre pair attendu.' : null]])['erreurs']
    === ['pair' => 'Nombre pair attendu.']);
verifie('saisie fautive rendue telle quelle pour réaffichage', $f['valeurs']['courriel'] === 'pas-une-adresse');
verifie('message sous le champ, lié par aria',
    formulaire_erreur($e, 'nom') === '<p class="erreur-champ" id="erreur-nom">Ce champ est obligatoire.</p>'
    && formulaire_aria($e, 'nom') === ' aria-invalid="true" aria-describedby="erreur-nom"'
    && formulaire_erreur($e, 'absent') === '' && formulaire_aria($e, 'absent') === ''
    && formulaire_id('jours[]') === 'jours');

titre('Image téléversée');
if (!function_exists('imagecreatetruecolor')) {
    echo "  (ignoré : extension GD absente)\n";
} else {
    $dossier_img = sys_get_temp_dir() . '/socle-essai-' . bin2hex(random_bytes(4));
    $source_img = function (int $l, int $h, string $format, bool $transparent = false) use ($dossier_img): string {
        @mkdir($dossier_img . '/sources', 0775, true);
        $im = imagecreatetruecolor($l, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, $transparent ? imagecolorallocatealpha($im, 0, 0, 0, 127) : imagecolorallocate($im, 200, 30, 30));
        $chemin = $dossier_img . '/sources/' . bin2hex(random_bytes(4)) . '.bin';
        match ($format) { 'png' => imagepng($im, $chemin), 'jpeg' => imagejpeg($im, $chemin), 'gif' => imagegif($im, $chemin) };
        return $chemin;
    };
    $r = image_enregistrer($source_img(3000, 1500, 'png'), $dossier_img);
    verifie('grande image réduite dans sa boîte, ratio gardé', $r['ok'] && $r['largeur'] === 2000 && $r['hauteur'] === 1000
        && getimagesize($r['chemin'])[0] === 2000);
    verifie('nom tiré au hasard, extension du type réel', preg_match('/^[0-9a-f]{16}\.png$/', (string)$r['nom']) === 1
        && $r['type'] === 'image/png' && dirname($r['chemin']) === $dossier_img);
    $r = image_enregistrer($source_img(800, 600, 'jpeg'), $dossier_img, ['prefixe' => 'Portrait d’Élise']);
    verifie('petite image jamais agrandie', $r['ok'] && $r['largeur'] === 800 && $r['hauteur'] === 600);
    verifie('préfixe lisible passé par slug()', str_starts_with((string)$r['nom'], 'portrait-d-elise-') && str_ends_with($r['nom'], '.jpg'));
    $r = image_enregistrer($source_img(400, 400, 'png'), $dossier_img, ['format' => 'webp', 'max_largeur' => 100]);
    verifie('format imposé et boîte sur mesure', $r['ok'] && str_ends_with($r['nom'], '.webp')
        && getimagesize($r['chemin'])['mime'] === 'image/webp' && $r['largeur'] === 100);
    $r = image_enregistrer($source_img(50, 50, 'gif'), $dossier_img);
    verifie('GIF converti en PNG', $r['ok'] && $r['type'] === 'image/png');
    $piege = $source_img(60, 60, 'png');
    file_put_contents($piege, '<?php echo "charge"; ?>', FILE_APPEND);
    $r = image_enregistrer($piege, $dossier_img);
    verifie('charge glissée après l’image : éliminée au réencodage',
        $r['ok'] && !str_contains((string)file_get_contents($r['chemin']), '<?php'));
    $r = image_enregistrer($source_img(10, 10, 'png', true), $dossier_img, ['format' => 'jpeg']);
    $px = imagecolorsforindex($g = imagecreatefromjpeg($r['chemin']), imagecolorat($g, 5, 5));
    verifie('transparence → blanc en JPEG', $r['ok'] && $px['red'] > 250 && $px['green'] > 250 && $px['blue'] > 250);
    $r = image_enregistrer($source_img(10, 10, 'png', true), $dossier_img);
    $g = imagecreatefrompng($r['chemin']);
    verifie('transparence gardée en PNG', $r['ok'] && (imagecolorat($g, 5, 5) >> 24) === 127);
    $faux = $dossier_img . '/sources/faux.jpg';
    file_put_contents($faux, "Ceci n'est pas une image.");
    $svg = $dossier_img . '/sources/dessin.svg';
    file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    verifie('faux .jpg et SVG refusés', image_enregistrer($faux, $dossier_img)['erreur'] === 'Ce fichier n’est pas une image JPEG, PNG, WebP ou GIF.'
        && !image_enregistrer($svg, $dossier_img)['ok']);
    verifie('poids maximal', image_enregistrer($source_img(300, 300, 'png'), $dossier_img, ['max_octets' => 100])['erreur']
        === 'Fichier trop lourd (100 o au plus).');
    verifie('taille en pixels bornée avant décodage', image_enregistrer($source_img(100, 100, 'png'), $dossier_img,
        ['max_pixels' => 5000])['erreur'] === 'Image trop grande (5 000 pixels au plus).');
    verifie('envoi par formulaire : erreurs du navigateur traduites',
        image_recevoir([], $dossier_img)['erreur'] === 'Aucun fichier reçu.'
        && str_starts_with((string)image_recevoir(['error' => UPLOAD_ERR_INI_SIZE], $dossier_img)['erreur'], 'Fichier trop lourd')
        && image_recevoir(['error' => [0, 0]], $dossier_img)['erreur'] === 'Un seul fichier attendu.');
    verifie('fichier qui n’a pas été téléversé refusé (is_uploaded_file)',
        image_recevoir(['error' => UPLOAD_ERR_OK, 'tmp_name' => $piege], $dossier_img)['erreur'] === 'Fichier invalide.');
    $vider = function (string $d) use (&$vider): void {
        foreach (glob($d . '/*') ?: [] as $x) is_dir($x) ? $vider($x) : unlink($x);
        rmdir($d);
    };
    $vider($dossier_img);
}

titre('Second facteur TOTP');
// Vecteurs de la RFC 6238 (annexe B), réduits à six chiffres : secret ASCII
// « 12345678901234567890 », soit GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ en base32.
$cle_rfc = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
verifie('base32 décodé', base32_decoder($cle_rfc) === '12345678901234567890');
verifie('base32 : espaces et minuscules tolérés',
        base32_decoder('gezd gnbv gy3t qojq GEZD GNBV GY3T QOJQ') === '12345678901234567890');
verifie('vecteur RFC à T=59', totp_code($cle_rfc, intdiv(59, 30)) === '287082');
verifie('vecteur RFC à T=1111111109', totp_code($cle_rfc, intdiv(1111111109, 30)) === '081804');
verifie('vecteur RFC à T=1234567890', totp_code($cle_rfc, intdiv(1234567890, 30)) === '005924');
verifie('vecteur RFC à T=2000000000', totp_code($cle_rfc, intdiv(2000000000, 30)) === '279037');
// Le pas courant et le suivant restent acceptés même si l'horloge franchit un pas
// pendant le test ; le pas - 2, lui, n'est accepté ni avant ni après ce franchissement.
$pas = intdiv(time(), 30);
verifie('code du moment accepté', totp_verifier($cle_rfc, totp_code($cle_rfc, $pas)));
verifie('décalage d\'horloge d\'un pas toléré', totp_verifier($cle_rfc, totp_code($cle_rfc, $pas + 1)));
verifie('code périmé refusé', !totp_verifier($cle_rfc, totp_code($cle_rfc, $pas - 2)));
verifie('code saisi avec des espaces accepté',
        totp_verifier($cle_rfc, chunk_split(totp_code($cle_rfc, $pas), 3, ' ')));
verifie('code mal formé refusé', !totp_verifier($cle_rfc, '12345') && !totp_verifier($cle_rfc, 'abcdef'));
$s1 = totp_secret_nouveau();
verifie('secret neuf : 32 caractères base32', preg_match('/^[A-Z2-7]{32}$/', $s1) === 1);
verifie('deux secrets neufs diffèrent', $s1 !== totp_secret_nouveau());
$uri_totp = totp_uri('qui@example.invalid', $s1, 'Essai Socle');
verifie('adresse otpauth complète',
        str_starts_with($uri_totp, 'otpauth://totp/Essai%20Socle%3Aqui%40example.invalid?')
        && str_contains($uri_totp, $s1) && str_contains($uri_totp, 'issuer=Essai%20Socle')
        && str_contains($uri_totp, 'digits=6') && str_contains($uri_totp, 'period=30'));

// ---------------------------------------------------------------------------
// Authentification (2.2, règles simplifiées en 2.3) : mot de passe, second facteur du compte,
// navigateur de confiance, second facteur obligatoire, vérification récente. Comptes : la table utilisateur
// du socle (AUTH_TABLE_COMPTES par défaut). Aucun appel réseau (AUTH_VERIFIER_FUITES).
// ---------------------------------------------------------------------------
define('CLE_CHIFFREMENT', bin2hex(random_bytes(32)));
const AUTH_VERIFIER_FUITES = false;
const AUTH_ESSAI = true;   // auth_session_controler() agit aussi en ligne de commande
$_SESSION = [];
$creer_compte = function (string $courriel, string $role = 'equipe'): int {
    bd()->prepare('INSERT INTO utilisateur (courriel, role, actif, cree_le) VALUES (?, ?, 1, ?)')
        ->execute([$courriel, $role, maintenant()]);
    return (int)bd()->lastInsertId();
};
$avis = fn (string $sujet): int => (int)bd()->query('SELECT COUNT(*) FROM notification WHERE sujet = '
                                                    . bd()->quote($sujet))->fetchColumn();
$nouvelle_requete = function (): void { $_SESSION = []; };

titre('Mot de passe (2.2)');
verifie('trop court refusé', mdp_erreur('court') === 'Le mot de passe doit compter au moins 12 caractères.');
verifie('trop long refusé', mdp_erreur(str_repeat('ab1-', 33)) !== null);
verifie('suite refusée', mdp_erreur('abcdefghijklmn') !== null && mdp_erreur('123456789012') !== null
        && mdp_erreur('azertyuiopqs') !== null && mdp_erreur('nmlkjihgfedcba') !== null);
verifie('répétition refusée', mdp_erreur('aaaaaaaaaaaaaaab') !== null);
verifie('adresse reprise refusée', mdp_erreur('jeanne.dupont-1980', 'Jeanne.Dupont-1980@example.org') !== null);
verifie('phrase de passe acceptée, sans règle de composition',
        mdp_erreur('le chat dort sur la valise') === null && mdp_erreur('Ma 🦊 file à la gare de Lyon') === null);
verifie('fuites : suffixe connu reconnu, lignes de bourrage ignorées',
        mdp_fuites_contient("0018A45C4D1DEF81644B54AB7F969B88D65:3\r\n00D4F6E8FA6EECAD2A3AA415EEC418D38EC:0\n",
                            '0018a45c4d1def81644b54ab7f969b88d65')
        && !mdp_fuites_contient("00D4F6E8FA6EECAD2A3AA415EEC418D38EC:0\n", '00D4F6E8FA6EECAD2A3AA415EEC418D38EC'));
$c1 = $creer_compte('mdp@example.org');
$mdp = 'une longue phrase de passe';
verifie('sans mot de passe : refus, sans erreur', !mdp_defini($c1) && auth_mot_de_passe('mdp@example.org', $mdp) === 'refus');
mdp_definir($c1, $mdp);
$empreinte = (string)bd()->query("SELECT mot_de_passe FROM auth_compte WHERE compte_id = $c1")->fetchColumn();
verifie('empreinte en base, jamais le mot de passe', mdp_defini($c1) && $empreinte !== $mdp && password_verify($mdp, $empreinte));
verifie('première pose : aucun avis de changement', $avis('Votre mot de passe a été changé') === 0);
verifie('compte inconnu : faux, même chemin de calcul', !mdp_verifier(0, $mdp));
$nouvelle_requete();
verifie('mauvais mot de passe : refus, pas de session',
        auth_mot_de_passe('mdp@example.org', 'pas le bon mot de passe') === 'refus' && !isset($_SESSION['utilisateur_id']));
verifie('bon mot de passe, casse de l’adresse ignorée : connecté',
        auth_mot_de_passe('MDP@example.org', $mdp) === 'connecte' && ($_SESSION['utilisateur_id'] ?? 0) === $c1
        && ($_SESSION['auth_facteurs'] ?? '') === 'mdp' && !isset($_SESSION['auth_verifie_le']));
bd()->exec("UPDATE utilisateur SET actif = 0 WHERE id = $c1");
$nouvelle_requete();
verifie('compte désactivé : même refus', auth_mot_de_passe('mdp@example.org', $mdp) === 'refus');
bd()->exec("UPDATE utilisateur SET actif = 1 WHERE id = $c1");
for ($i = 0; $i < 3; $i++) auth_mot_de_passe('mdp@example.org', 'encore faux ' . $i);
verifie('5 échecs en 15 min : attente, même avec le bon mot de passe',
        auth_mot_de_passe('mdp@example.org', $mdp) === 'limite');
verifie('… une autre adresse n’est pas bloquée', auth_mot_de_passe('autre@example.org', 'x') === 'refus');
bd()->exec("DELETE FROM tentative WHERE cle LIKE 'mdp-%'");
mdp_definir($c1, 'un autre mot de passe assez long');
verifie('changement : avis par courriel', $avis('Votre mot de passe a été changé') === 1);

titre('Second facteur du compte (2.2)');
if (!function_exists('openssl_encrypt')) {
    echo "  (ignoré : extension openssl absente de ce PHP)\n";
} else {
    $s = totp_secret_nouveau();
    $chiffre = totp_chiffrer($s, $c1);
    verifie('secret chiffré, relu', str_starts_with($chiffre, 'v1:') && !str_contains($chiffre, $s)
            && totp_dechiffrer($chiffre, $c1) === $s && totp_chiffrer($s, $c1) !== $chiffre);
    verifie('chiffré altéré : null', totp_dechiffrer(substr($chiffre, 0, -4) . 'AAAA', $c1) === null
            && totp_dechiffrer('x', $c1) === null);
    verifie('chiffré recopié sur un autre compte : null', totp_dechiffrer($chiffre, $c1 + 1) === null);
    $p = intdiv(time(), 30);
    verifie('enrôlement : code faux refusé', totp_enroler($c1, $s, '000000') === null && !auth_a_second_facteur($c1));
    $nouvelle_requete();
    auth_terminer($c1, 'mdp');
    $codes = totp_enroler($c1, $s, totp_code($s, $p - 1)) ?? [];
    verifie('enrôlement pendant la session : elle compte le second facteur, rien à redemander',
            $_SESSION['auth_facteurs'] === 'mdp+totp' && auth_verification_recente() && auth_etat_2fa($c1, 'admin') === 'ok');
    verifie('… et ce navigateur devient de confiance', ($_COOKIE[durable_nom_cookie()] ?? '') !== ''
            && count(durables_lister($c1)) === 1);
    durable_fermer();
    verifie('enrôlement confirmé : dix codes de secours, une fois', count($codes) === 10
            && preg_match('/^[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}$/', $codes[0]) === 1 && auth_a_second_facteur($c1));
    verifie('… secret chiffré en base',
            !str_contains((string)bd()->query("SELECT totp_chiffre FROM auth_compte WHERE compte_id = $c1")->fetchColumn(), $s));
    verifie('… codes de secours hachés', (int)bd()->query('SELECT COUNT(*) FROM auth_code_secours WHERE empreinte = '
            . bd()->quote(code_secours_normaliser($codes[0])))->fetchColumn() === 0);
    $nouvelle_requete();
    verifie('mot de passe juste : second facteur attendu, utilisateur_id PAS posé',
            auth_mot_de_passe('mdp@example.org', 'un autre mot de passe assez long', true) === 'second_facteur'
            && !isset($_SESSION['utilisateur_id']) && auth_en_attente() !== null);
    verifie('code du pas déjà servi à l’enrôlement : refusé (anti-rejeu)',
            auth_second_facteur('totp', totp_code($s, $p - 1)) === 'refus');
    verifie('code du moment : connecté, mdp+totp, vérification récente',
            auth_second_facteur('totp', totp_code($s, $p), true) === 'connecte' && ($_SESSION['utilisateur_id'] ?? 0) === $c1
            && $_SESSION['auth_facteurs'] === 'mdp+totp' && auth_verification_recente());
    $cookie_durable = $_COOKIE[durable_nom_cookie()] ?? '';
    verifie('« ne plus demander sur ce navigateur » : navigateur de confiance', $cookie_durable !== '');
    $nouvelle_requete();
    verifie('navigateur de confiance : le mot de passe suffit, sans code ni vérification récente',
            auth_mot_de_passe('mdp@example.org', 'un autre mot de passe assez long') === 'connecte'
            && $_SESSION['auth_facteurs'] === 'mdp+confiance' && !auth_verification_recente()
            && auth_etat_2fa($c1, 'admin') === 'ok');
    verifie('… sans prolonger la confiance', count(durables_lister($c1)) === 1);
    $nouvelle_requete();
    verifie('… le mot de passe, lui, reste exigé',
            auth_mot_de_passe('mdp@example.org', 'pas le bon mot de passe') === 'refus' && !isset($_SESSION['utilisateur_id']));
    $nouvelle_requete();
    unset($_COOKIE[durable_nom_cookie()]);
    auth_mot_de_passe('mdp@example.org', 'un autre mot de passe assez long');
    verifie('case décochée : connecté, navigateur pas retenu',
            auth_second_facteur('totp', totp_code($s, $p + 1)) === 'connecte' && !isset($_COOKIE[durable_nom_cookie()])
            && count(durables_lister($c1)) === 1);
    bd()->exec("UPDATE auth_compte SET totp_dernier_pas = $p WHERE compte_id = $c1");   // le pas p+1 resservira plus bas
    $nouvelle_requete();
    auth_premier_facteur($c1, 'lien');
    verifie('le lien ne remplace pas le second facteur (hors navigateur de confiance)', !isset($_SESSION['utilisateur_id']) && auth_en_attente() !== null);
    verifie('même code rejoué : refusé', auth_second_facteur('totp', totp_code($s, $p)) === 'refus');
    verifie('code de secours, saisi en minuscules avec espace : connecté',
            auth_second_facteur('secours', strtolower(str_replace('-', ' ', $codes[0]))) === 'connecte'
            && $_SESSION['auth_facteurs'] === 'lien+secours');
    verifie('… il en reste neuf, avis envoyé', codes_secours_restants($c1) === 9 && $avis('Un code de secours a été utilisé') === 1);
    $nouvelle_requete();
    auth_premier_facteur($c1, 'lien');
    verifie('un code de secours ne sert qu’une fois', auth_second_facteur('secours', $codes[0]) === 'refus');
    bd()->prepare('INSERT INTO auth_code_secours (compte_id, empreinte, cree_le) VALUES (?, ?, ?)')
        ->execute([$c1, hacher_jeton('ABCD-EF23'), maintenant()]);
    verifie('code repris d’un ancien format (XXXX-XXXX) : accepté une fois', code_secours_utiliser($c1, 'abcdef23')
            && !code_secours_utiliser($c1, 'ABCD-EF23'));
    lien_magique_envoyer('mdp@example.org');
    preg_match('/jeton=([A-Za-z0-9_-]+)/', (string)bd()->query("SELECT corps FROM notification WHERE objet_type = 'connexion'
        ORDER BY id DESC LIMIT 1")->fetchColumn(), $m);
    $nouvelle_requete();
    $par_lien = lien_magique_utiliser($m[1] ?? '');
    verifie('lien du socle consommé sans ouvrir la session, puis second facteur exigé',
            $par_lien === $c1 && !isset($_SESSION['utilisateur_id'])
            && auth_premier_facteur($c1, 'lien') === 'second_facteur' && lien_magique_utiliser($m[1] ?? '') === null);
    $_SESSION['auth_en_attente']['depuis'] = time() - 601;
    verifie('attente de plus de 10 min : expirée', auth_second_facteur('totp', totp_code($s, $p + 1)) === 'expire');
    bd()->exec("DELETE FROM tentative WHERE cle LIKE 'second-facteur-%'");
    auth_premier_facteur($c1, 'lien');
    for ($i = 0; $i < 5; $i++) auth_second_facteur('totp', '000000');
    verifie('5 codes faux : attente, avis envoyé', auth_second_facteur('totp', totp_code($s, $p + 1)) === 'limite'
            && $avis('Codes de connexion refusés') === 1);
    bd()->exec("DELETE FROM tentative WHERE cle LIKE 'second-facteur-%'");

    titre('Navigateur de confiance (2.3)');
    [$selecteur, $validateur] = explode(':', $cookie_durable) + ['', ''];
    $ligne = bd()->query("SELECT * FROM auth_session_durable WHERE compte_id = $c1")->fetch();
    verifie('une ligne par appareil, validateur haché, 30 jours, facteurs retenus',
            $ligne['selecteur'] === $selecteur && $ligne['validateur_empreinte'] === hacher_jeton($validateur)
            && $ligne['facteurs'] === 'mdp+totp'
            && abs(strtotime($ligne['expire_le']) - strtotime($ligne['cree_le']) - 30 * 86400) < 5);
    verifie('cookie préfixé __Host- en HTTPS', str_starts_with(durable_nom_cookie(), '__Host-'));
    $nouvelle_requete();
    $_COOKIE[durable_nom_cookie()] = $cookie_durable;
    verifie('le cookie seul ne rouvre aucune session (plus de « rester connecté »)', auth_session_controler() === 0);
    auth_terminer($c1, 'mdp+totp');
    $_SESSION['auth_activite_le'] = time() - 3500;
    verifie('59 minutes d’inactivité : session gardée', auth_session_controler() === $c1);
    $_SESSION['auth_activite_le'] = time() - 3601;
    verifie('une heure d’inactivité : session fermée, même sur un navigateur de confiance',
            auth_session_controler() === 0 && !isset($_SESSION['utilisateur_id']));
    $_COOKIE[durable_nom_cookie()] = $selecteur . ':' . jeton_aleatoire(32);
    verifie('validateur faux juste après le renouvellement (autre onglet) : refus discret',
            durable_reprendre() === null && (int)bd()->query("SELECT COUNT(*) FROM auth_session_durable WHERE compte_id = $c1")->fetchColumn() === 1);
    bd()->exec("UPDATE auth_session_durable SET derniere_rotation = '2000-01-01T00:00:00Z' WHERE compte_id = $c1");
    $_COOKIE[durable_nom_cookie()] = $cookie_durable;
    $repris = durable_reprendre();
    $renouvele = $_COOKIE[durable_nom_cookie()] ?? '';
    verifie('validateur renouvelé une fois par jour', $repris !== null && $renouvele !== $cookie_durable
            && str_starts_with($renouvele, $selecteur . ':'));
    bd()->exec("UPDATE auth_session_durable SET derniere_rotation = '2000-01-01T00:00:00Z' WHERE compte_id = $c1");
    $_COOKIE[durable_nom_cookie()] = $cookie_durable;   // l'ancien, copié ailleurs
    verifie('ancien validateur présenté plus tard : vol présumé, ligne supprimée, avis',
            durable_reprendre() === null && $avis('Navigateur de confiance retiré') === 1
            && (int)bd()->query("SELECT COUNT(*) FROM auth_session_durable WHERE compte_id = $c1")->fetchColumn() === 0);
    durable_ouvrir($c1, 'mdp');
    bd()->exec("UPDATE auth_session_durable SET expire_le = '2000-01-01T00:00:00Z'");
    verifie('expirée : refusée et supprimée', durable_reprendre() === null
            && (int)bd()->query('SELECT COUNT(*) FROM auth_session_durable')->fetchColumn() === 0);
    durable_ouvrir($c1, 'mdp');
    bd()->exec("UPDATE utilisateur SET actif = 0 WHERE id = $c1");
    verifie('compte désactivé : refusée', durable_reprendre() === null);
    bd()->exec("UPDATE utilisateur SET actif = 1 WHERE id = $c1");
    durable_ouvrir($c1, 'mdp');
    durable_ouvrir($c1, 'mdp+totp');
    verifie('un navigateur ne porte qu’une confiance (la nouvelle remplace l’ancienne)', count(durables_lister($c1)) === 1);
    verifie('confiance d’un autre compte : sans valeur pour celui-ci', !auth_navigateur_de_confiance($c1 + 1000));
    durable_ouvrir($c1, 'mdp');
    verifie('ligne ouverte sans second facteur (« rester connecté » d’avant la 2.3) : sans valeur',
            !auth_navigateur_de_confiance($c1));
    durable_ouvrir($c1, 'mdp+totp');
    unset($_COOKIE[durable_nom_cookie()]);
    durable_ouvrir($c1, 'mdp+totp');
    verifie('deux navigateurs listés', count(durables_lister($c1)) === 2);
    $_SESSION['utilisateur_id'] = $c1;
    mdp_definir($c1, 'mot de passe changé par la personne');
    verifie('mot de passe changé par la personne : les autres navigateurs perdent la confiance, pas le sien',
            count(durables_lister($c1)) === 1 && auth_navigateur_de_confiance($c1));
    durable_fermer();
    verifie('« Retirer » ce navigateur : plus de confiance', count(durables_lister($c1)) === 0 && !isset($_COOKIE[durable_nom_cookie()]));
    $_COOKIE[durable_nom_cookie()] = 'mal:forme';
    verifie('cookie mal formé : ignoré et effacé', durable_reprendre() === null && !isset($_COOKIE[durable_nom_cookie()]));
    durable_ouvrir($c1, 'mdp+totp');
    $nouvelle_requete();
    mdp_definir($c1, 'encore un autre mot de passe');
    verifie('mot de passe changé hors de sa session : tous les navigateurs perdent la confiance', count(durables_lister($c1)) === 0);
    verifie('appareil reconnu', durable_appareil('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Version/18.0 Mobile Safari/604.1')
            === 'Safari · iOS');

    titre('Second facteur obligatoire, vérification récente (2.2, 2.3)');
    verifie('rôles tenus : admin par défaut', auth_role_tenu_2fa('admin') && !auth_role_tenu_2fa('equipe')
            && auth_role_tenu_2fa(['equipe', 'admin']));
    $a1 = $creer_compte('admin2fa@example.org', 'admin');
    $nouvelle_requete();
    auth_terminer($a1, 'lien');
    verifie('admin sans second facteur : délai de grâce de 7 jours, fixé au premier contrôle',
            auth_etat_2fa($a1, 'admin') === 'grace'
            && abs(strtotime(auth_echeance_2fa($a1)) - time() - 7 * 86400) < 5);
    bd()->exec("UPDATE auth_compte SET echeance_2fa = '2000-01-01T00:00:00Z' WHERE compte_id = $a1");
    verifie('délai dépassé : enrôlement exigé (la connexion, elle, reste ouverte)',
            auth_etat_2fa($a1, 'admin') === 'enrolement' && ($_SESSION['utilisateur_id'] ?? 0) === $a1);
    param_definir('auth_echeance_2fa', '2999-12-31T00:00:00Z');
    $a2 = $creer_compte('admin2fa-bis@example.org', 'admin');
    verifie('échéance commune posée au déploiement : reprise', auth_echeance_2fa($a2) === '2999-12-31T00:00:00Z');
    verifie('rôle non tenu : rien à exiger', auth_etat_2fa($a2, 'equipe') === 'ok');
    $nouvelle_requete();
    auth_terminer($c1, 'lien');
    verifie('compte avec second facteur, session ouverte sans : à redemander', auth_etat_2fa($c1, 'admin') === 'second_facteur');
    verifie('pas de vérification récente après un lien', !auth_verification_recente());
    verifie('vérification par le mot de passe, même avec un second facteur (2.3)',
            auth_verifier('mdp', 'encore un autre mot de passe') === 'ok' && auth_verification_recente()
            && auth_etat_2fa($c1, 'admin') === 'second_facteur');
    unset($_SESSION['auth_verifie_le']);
    verifie('vérification par code : accordée, 15 minutes', auth_verifier('totp', totp_code($s, intdiv(time(), 30) + 1)) === 'ok'
            && auth_verification_recente() && !auth_verification_recente(-1) && auth_etat_2fa($c1, 'admin') === 'ok');
    $_SESSION['auth_verifie_le'] = time() - 901;
    verifie('au-delà de 15 minutes : à refaire', !auth_verification_recente());
    $c2 = $creer_compte('famille@example.org', 'famille');
    mdp_definir($c2, 'le mot de passe de la famille');
    $nouvelle_requete();
    auth_terminer($c2, 'lien');
    verifie('sans second facteur : vérification par le mot de passe', auth_verifier('mdp', 'le mot de passe de la famille') === 'ok'
            && auth_verification_recente());
    $_SESSION['auth_retour'] = 'https://ailleurs.example/piege';
    $r1 = auth_retour();
    $_SESSION['auth_retour'] = '//ailleurs.example/piege';
    $r2 = auth_retour();
    $_SESSION['auth_retour'] = '/admin.php?page=paiements';
    verifie('retour interne seulement (pas de redirection ouverte)', $r1 === '/' && $r2 === '/'
            && auth_retour() === '/admin.php?page=paiements');
    verifie('retrait du dernier second facteur refusé à un compte tenu', !totp_retirer($c1, true) && auth_a_second_facteur($c1));
    durable_ouvrir($c1, 'mdp+totp');
    $nouvelle_requete();
    auth_terminer($c1, 'mdp+totp');
    $_SESSION['auth_connecte_le'] = time() - 5;
    $session_c1 = $_SESSION;                       // une session de c1, sur son appareil
    $nouvelle_requete();
    auth_terminer($a1, 'lien');                    // l'admin, sur le sien
    auth_reinitialiser_2fa($c1, $a1);
    verifie('réinitialisation : facteurs, codes et appareils retirés, avis envoyé',
            !auth_a_second_facteur($c1) && codes_secours_restants($c1) === 0 && count(durables_lister($c1)) === 0
            && $avis('Votre double authentification a été réinitialisée') === 1);
    verifie('… et sans nouveau délai de grâce', auth_etat_2fa($c1, 'admin') === 'enrolement');
    verifie('… l’admin qui l’a faite garde sa session', auth_session_controler() === $a1);
    $_SESSION = $session_c1;
    verifie('… les sessions ouvertes avant sont fermées (pas seulement les durables)', auth_session_controler() === 0);
    verifie('… journalisée avec l’admin qui l’a faite', (int)bd()->query("SELECT utilisateur_id FROM journal
            WHERE action = '2fa_reinitialisee' ORDER BY id DESC LIMIT 1")->fetchColumn() === $a1);
    $nouvelle_requete();
    unset($_COOKIE[durable_nom_cookie()]);
    auth_terminer($c2, 'mdp', true);
    verifie('sans second facteur, pas de navigateur de confiance', count(durables_lister($c2)) === 0);
    auth_terminer($c2, 'mdp+totp', true);
    auth_deconnecter();
    verifie('déconnexion : session vidée, le navigateur reste de confiance', $_SESSION === [] && count(durables_lister($c2)) === 1);
}

titre('Rester connecté, rôle par rôle (2.5, D18)');
$nouvelle_requete();
$oublier = function () { foreach ([durable_nom_cookie(), appareil_nom_cookie()] as $n) unset($_COOKIE[$n]); };
$oublier();
$appareils = fn (int $id): array => bd()->query("SELECT * FROM auth_session_durable WHERE inactivite IS NOT NULL AND compte_id = $id")->fetchAll();
$il_y_a = fn (int $s): string => gmdate('Y-m-d\TH:i:s\Z', time() - $s);
$e1 = $creer_compte('reste@example.org', 'equipe');
$mdp_e1 = 'la phrase de passe de l’équipe';
mdp_definir($e1, $mdp_e1);
verifie('option non déclarée : la case ne fait rien (D17)', auth_mot_de_passe('reste@example.org', $mdp_e1, true) === 'connecte'
        && !isset($_SESSION['auth_appareil']) && !isset($_COOKIE[appareil_nom_cookie()]) && $appareils($e1) === []);
define('AUTH_RESTER_CONNECTE', ['admin' => [5 * 3600, 12 * 3600], '*' => [30 * 86400, 90 * 86400]]);
verifie('politique : par rôle, « * » pour les autres', auth_rester_politique($e1) === [30 * 86400, 90 * 86400]);
$nouvelle_requete();
$ok = auth_mot_de_passe('reste@example.org', $mdp_e1, true) === 'connecte';
$l = $appareils($e1)[0] ?? [];
verifie('équipe, case cochée : appareil retenu, 30 jours d’inactivité, 90 au plus', $ok && (int)($_SESSION['auth_appareil'] ?? 0) === (int)($l['id'] ?? -1)
        && (int)$l['inactivite'] === 30 * 86400 && abs(strtotime($l['expire_le']) - time() - 90 * 86400) < 5
        && isset($_COOKIE[appareil_nom_cookie()]) && !isset($_COOKIE[durable_nom_cookie()]));
$_SESSION['auth_activite_le'] = time() - 2 * 3600;
$_SESSION['auth_connecte_le'] = time() - 13 * 3600;
verifie('… deux heures sans rien, treize depuis la connexion : la session tient', auth_session_controler() === $e1);
$nouvelle_requete();   // navigateur fermé : la session PHP est perdue, le cookie de l’appareil reste
verifie('navigateur rouvert : la session est rouverte par l’appareil, sans vérification récente',
        auth_session_controler() === $e1 && $_SESSION['auth_facteurs'] === 'mdp' && (int)$_SESSION['auth_appareil'] === (int)$l['id']
        && !auth_verification_recente());
bd()->exec("UPDATE auth_session_durable SET derniere_utilisation = '" . $il_y_a(31 * 86400) . "' WHERE id = " . (int)$l['id']);
verifie('… plus de 30 jours sans servir : la session se ferme', auth_session_controler() === 0);
$nouvelle_requete();
verifie('… et l’appareil ne rouvre plus rien (ligne et cookie effacés)', auth_session_controler() === 0
        && !isset($_COOKIE[appareil_nom_cookie()]) && $appareils($e1) === []);
auth_mot_de_passe('reste@example.org', $mdp_e1, true);
bd()->exec("UPDATE auth_session_durable SET derniere_utilisation = '" . $il_y_a(29 * 86400) . "' WHERE compte_id = $e1");
verifie('glissant : 29 jours sans servir, la session tient et l’usage est noté', auth_session_controler() === $e1
        && ($appareils($e1)[0]['derniere_utilisation'] ?? '') > $il_y_a(60));
bd()->exec("UPDATE auth_session_durable SET expire_le = '2000-01-01T00:00:00Z' WHERE compte_id = $e1");
verifie('au bout de 90 jours, même servi chaque jour : fermée', auth_session_controler() === 0);
$nouvelle_requete();
$oublier();
auth_mot_de_passe('reste@example.org', $mdp_e1, false);
$_SESSION['auth_activite_le'] = time() - 3601;
verifie('case décochée : une heure d’inactivité, comme avant', !isset($_SESSION['auth_appareil']) && auth_session_controler() === 0);

$a3 = $creer_compte('admin-reste@example.org', 'admin');
$mdp_a3 = 'la phrase de passe de l’administrateur';
mdp_definir($a3, $mdp_a3);
$nouvelle_requete();
$oublier();
$ok = auth_mot_de_passe('admin-reste@example.org', $mdp_a3, true) === 'connecte';
$l = $appareils($a3)[0] ?? [];
verifie('admin, case cochée : cinq heures d’inactivité, douze au plus', $ok && (int)$l['inactivite'] === 5 * 3600
        && abs(strtotime($l['expire_le']) - time() - 12 * 3600) < 5);
bd()->exec("UPDATE auth_session_durable SET derniere_utilisation = '" . $il_y_a(4 * 3600) . "' WHERE compte_id = $a3");
$_SESSION['auth_activite_le'] = time() - 4 * 3600;
verifie('… quatre heures d’atelier sans toucher au portail : la session tient', auth_session_controler() === $a3);
bd()->exec("UPDATE auth_session_durable SET derniere_utilisation = '" . $il_y_a(5 * 3600 + 60) . "' WHERE compte_id = $a3");
verifie('… cinq heures : fermée', auth_session_controler() === 0);

$nouvelle_requete();
$oublier();
auth_mot_de_passe('reste@example.org', $mdp_e1, true);
durable_revoquer((int)$_SESSION['auth_appareil'], $e1);
verifie('« Retirer » l’appareil depuis le compte : la session se ferme aussitôt', auth_session_controler() === 0);
$nouvelle_requete();
auth_mot_de_passe('reste@example.org', $mdp_e1, true);
auth_deconnecter();
verifie('déconnexion : l’appareil ne rouvre plus rien', !isset($_COOKIE[appareil_nom_cookie()]) && $appareils($e1) === []
        && auth_session_controler() === 0);
$nouvelle_requete();
auth_mot_de_passe('reste@example.org', $mdp_e1, true);
$cookie_a = $_COOKIE[appareil_nom_cookie()];
$nouvelle_requete();
$oublier();
auth_mot_de_passe('reste@example.org', $mdp_e1, true);   // un second appareil
auth_fermer_autres_sessions($e1);
verifie('« Déconnecter mes autres appareils » : le sien reste, l’autre part', count($appareils($e1)) === 1
        && (int)$appareils($e1)[0]['id'] === (int)$_SESSION['auth_appareil'] && auth_session_controler() === $e1);
$nouvelle_requete();
$_COOKIE[appareil_nom_cookie()] = $cookie_a;
verifie('… l’autre ne rouvre plus rien', auth_session_controler() === 0);
$nouvelle_requete();
$oublier();
auth_mot_de_passe('reste@example.org', $mdp_e1, true);
[$sel] = explode(':', (string)$_COOKIE[appareil_nom_cookie()]);
bd()->exec("UPDATE auth_session_durable SET derniere_rotation = '2000-01-01T00:00:00Z' WHERE selecteur = " . bd()->quote($sel));
$nouvelle_requete();
$_COOKIE[appareil_nom_cookie()] = $sel . ':' . jeton_aleatoire(32);
verifie('cookie d’appareil à la clé fausse : appareil déconnecté, personne prévenue', auth_session_controler() === 0
        && (int)bd()->query('SELECT COUNT(*) FROM auth_session_durable WHERE selecteur = ' . bd()->quote($sel))->fetchColumn() === 0
        && $avis('Appareil déconnecté') === 1);
$nouvelle_requete();
$oublier();
auth_terminer($e1, 'mdp+totp', true);
$_COOKIE[appareil_nom_cookie()] = $_COOKIE[durable_nom_cookie()];
verifie('une confiance présentée comme appareil ne rouvre rien', ($_SESSION = []) === [] && auth_session_controler() === 0);
$oublier();

if (function_exists('openssl_encrypt')) {
    $sa = totp_secret_nouveau();
    $nouvelle_requete();
    auth_terminer($a3, 'mdp');
    totp_enroler($a3, $sa, totp_code($sa, intdiv(time(), 30) - 1));
    $nouvelle_requete();
    $oublier();
    $r1 = auth_mot_de_passe('admin-reste@example.org', $mdp_a3, true);
    $r2 = auth_second_facteur('totp', totp_code($sa, intdiv(time(), 30)));
    verifie('avec un code : la case du premier écran est reprise, l’appareil retient les deux facteurs',
            $r1 === 'second_facteur' && $r2 === 'connecte' && ($appareils($a3)[0]['facteurs'] ?? '') === 'mdp+totp');
    $nouvelle_requete();
    verifie('… rouverte : le code n’est pas redemandé', auth_session_controler() === $a3 && auth_etat_2fa($a3, 'admin') === 'ok');
    $nouvelle_requete();
    $oublier();
    bd()->exec("DELETE FROM auth_session_durable WHERE compte_id = $a3");
    auth_mot_de_passe('admin-reste@example.org', $mdp_a3, true);
    auth_second_facteur('totp', totp_code($sa, intdiv(time(), 30) + 1), false, false);
    verifie('… case décochée à l’écran du code : rien de retenu', $appareils($a3) === [] && !isset($_SESSION['auth_appareil']));
}

titre('Clés d’accès, WebAuthn (2.2)');
if (!function_exists('webauthn_disponible') || !webauthn_disponible() || !function_exists('openssl_pkey_new')) {
    echo "  (ignoré : web-auth/webauthn-lib n'est pas installé — lancer composer install)\n";
} else {
    // Authentificateur logiciel : une clé EC P-256 d'OpenSSL et un encodeur CBOR minimal, pour
    // fabriquer les réponses qu'un téléphone ou un ordinateur renverrait au navigateur.
    $cbor_tete = fn (int $majeur, int $n): string => match (true) {
        $n < 24    => chr($majeur << 5 | $n),
        $n < 256   => chr($majeur << 5 | 24) . chr($n),
        $n < 65536 => chr($majeur << 5 | 25) . pack('n', $n),
        default    => chr($majeur << 5 | 26) . pack('N', $n),
    };
    $cbor = function (mixed $v) use (&$cbor, $cbor_tete): string {
        if (is_int($v)) return $v >= 0 ? $cbor_tete(0, $v) : $cbor_tete(1, -1 - $v);
        if (is_string($v)) return $cbor_tete(3, strlen($v)) . $v;                      // texte
        if (is_object($v)) return $cbor_tete(2, strlen($v->octets)) . $v->octets;      // octets
        $s = $cbor_tete(5, count($v));
        foreach ($v as $cle => $valeur) $s .= $cbor($cle) . $cbor($valeur);
        return $s;
    };
    $octets = fn (string $b): object => (object)['octets' => $b];
    $origine = 'https://exemple.invalid';
    $rp_hash = hash('sha256', 'exemple.invalid', true);
    $appareil = function () {
        $cle = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $ec = openssl_pkey_get_details($cle)['ec'];
        return ['privee' => $cle, 'x' => str_pad($ec['x'], 32, "\0", STR_PAD_LEFT),
                'y' => str_pad($ec['y'], 32, "\0", STR_PAD_LEFT), 'id' => random_bytes(16), 'compteur' => 0];
    };
    $creer = function (array $app, string $options_json, string $origine_vue) use ($cbor, $octets, $rp_hash): string {
        $o = json_decode($options_json, true);
        $client = json_encode(['type' => 'webauthn.create', 'challenge' => $o['challenge'], 'origin' => $origine_vue,
                               'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
        $cose = [1 => 2, 3 => -7, -1 => 1, -2 => $octets($app['x']), -3 => $octets($app['y'])];
        // Drapeaux : présence (UP), vérification (UV), clé synchronisable et synchronisée (BE, BS), données de clé (AT).
        $auth = $rp_hash . chr(0x01 | 0x04 | 0x08 | 0x10 | 0x40) . pack('N', 0) . str_repeat("\0", 16)
              . pack('n', strlen($app['id'])) . $app['id'] . $cbor($cose);
        $attestation = $cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => $octets($auth)]);
        return json_encode(['id' => b64url($app['id']), 'rawId' => b64url($app['id']), 'type' => 'public-key',
            'response' => ['clientDataJSON' => b64url($client), 'attestationObject' => b64url($attestation),
                           'transports' => ['internal']], 'clientExtensionResults' => new stdClass()]);
    };
    $signer = function (array &$app, string $options_json, string $poignee, $cle_de_signature = null) use ($rp_hash, $origine): string {
        $o = json_decode($options_json, true);
        $client = json_encode(['type' => 'webauthn.get', 'challenge' => $o['challenge'], 'origin' => $origine,
                               'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
        $auth = $rp_hash . chr(0x01 | 0x04 | 0x08 | 0x10) . pack('N', ++$app['compteur']);
        openssl_sign($auth . hash('sha256', $client, true), $signature, $cle_de_signature ?? $app['privee'], OPENSSL_ALGO_SHA256);
        return json_encode(['id' => b64url($app['id']), 'rawId' => b64url($app['id']), 'type' => 'public-key',
            'response' => ['clientDataJSON' => b64url($client), 'authenticatorData' => b64url($auth),
                           'signature' => b64url($signature), 'userHandle' => $poignee],
            'clientExtensionResults' => new stdClass()]);
    };

    $k = $creer_compte('cle@example.org', 'admin');
    $nouvelle_requete();
    auth_terminer($k, 'lien');
    $telephone = $appareil();
    $o_creation = cle_options_creation($k, 'cle@example.org');
    $oc = json_decode($o_creation, true);
    verifie('options d’enregistrement : hôte du site, clé découvrable, vérification exigée, attestation none',
            $oc['rp']['id'] === 'exemple.invalid' && $oc['authenticatorSelection']['residentKey'] === 'required'
            && $oc['authenticatorSelection']['userVerification'] === 'required' && $oc['attestation'] === 'none'
            && strlen((string)base64_decode(strtr($oc['challenge'], '-_', '+/'))) === 32);
    $poignee = $oc['user']['id'];
    verifie('poignée de compte aléatoire, ni l’identifiant ni l’adresse',
            strlen((string)base64_decode(strtr($poignee, '-_', '+/'))) === 32 && !str_contains($o_creation, '"id":"' . $k . '"'));
    $reponse = $creer($telephone, $o_creation, $origine);
    $codes_cle = cle_enregistrer($k, $reponse, 'iPhone d’essai');
    verifie('enregistrement vérifié : premier second facteur, dix codes de secours', is_array($codes_cle) && count($codes_cle) === 10
            && auth_a_second_facteur($k));
    verifie('… clé synchronisée notée, nommée', ($l = cles_lister($k)) && count($l) === 1 && (int)$l[0]['sauvegardee'] === 1
            && $l[0]['nom'] === 'iPhone d’essai');
    verifie('même réponse rejouée : refusée (défi consommé)', cle_enregistrer($k, $reponse, 'bis') === null);
    $autre = $appareil();
    verifie('origine d’un autre site : refusée',
            cle_enregistrer($k, $creer($autre, cle_options_creation($k, 'cle@example.org'), 'https://piege.example'), 'x') === null);
    verifie('une clé déjà enregistrée est exclue des nouvelles options',
            str_contains(cle_options_creation($k, 'cle@example.org'), b64url($telephone['id'])));
    unset($_SESSION['webauthn_creation']);

    $nouvelle_requete();
    $o_connexion = cle_options_connexion();
    verifie('connexion sans adresse : aucune clé imposée, vérification exigée',
            !str_contains($o_connexion, 'allowCredentials') || str_contains($o_connexion, '"allowCredentials":[]'));
    $assertion = $signer($telephone, $o_connexion, $poignee);
    verifie('connexion par clé seule : connecté, vaut les deux facteurs',
            auth_cle_acces($assertion) === 'connecte' && ($_SESSION['utilisateur_id'] ?? 0) === $k
            && $_SESSION['auth_facteurs'] === 'cle' && auth_verification_recente() && auth_etat_2fa($k, 'admin') === 'ok');
    verifie('… compteur et date d’usage enregistrés', cles_lister($k)[0]['derniere_utilisation'] !== null
            && str_contains((string)bd()->query("SELECT source FROM auth_cle_acces WHERE compte_id = $k")->fetchColumn(), '"counter":1'));
    $nouvelle_requete();
    verifie('même signature rejouée : refusée', auth_cle_acces($assertion) === 'refus' && !isset($_SESSION['utilisateur_id']));
    $nouvelle_requete();
    verifie('signature d’une autre clé : refusée',
            auth_cle_acces($signer($telephone, cle_options_connexion(), $poignee, $autre['privee'])) === 'refus');
    $nouvelle_requete();
    verifie('clé inconnue du site : refusée', auth_cle_acces($signer($autre, cle_options_connexion(), $poignee)) === 'refus');

    $nouvelle_requete();
    auth_premier_facteur($k, 'mdp');
    $o_second = cle_options_connexion($k);
    verifie('second facteur : seules les clés du compte sont proposées', str_contains($o_second, b64url($telephone['id'])));
    verifie('second facteur par clé : connecté, mdp+cle',
            auth_second_facteur('cle', $signer($telephone, $o_second, $poignee)) === 'connecte' && $_SESSION['auth_facteurs'] === 'mdp+cle');
    $_SESSION['auth_verifie_le'] = time() - 901;
    verifie('vérification renforcée par clé', auth_verifier('cle', $signer($telephone, cle_options_connexion($k), $poignee)) === 'ok'
            && auth_verification_recente());
    $ligne_cle = (int)cles_lister($k)[0]['id'];
    verifie('dernier second facteur d’un compte tenu : retrait refusé', !cle_retirer($ligne_cle, $k, true) && count(cles_lister($k)) === 1);
    verifie('compte non tenu : retrait accepté', cle_retirer($ligne_cle, $k, false) && cles_lister($k) === []
            && !auth_a_second_facteur($k));
}

titre('Texte enrichi, mini éditeur (2.4)');
$te_piege = '<div onclick="x()"><b style="color:red">Gras</b> et <i>penché</i> <a href="javascript:alert(1)">lien</a></div>'
          . '<script>alert(1)</script><style>p{}</style><img src=x onerror=alert(1)><ul><li class="a">un</li><li>deux &amp; trois</li></ul>'
          . '<h2>Titre</h2><p><br></p><p>&lt;script&gt; reste du texte</p><!-- note -->';
$te_net = texte_enrichi_nettoyer($te_piege);
verifie('gras, italique et listes gardés, sans aucun attribut ; script, style, image, lien et commentaire retirés',
        $te_net === '<p><strong>Gras</strong> et <em>penché</em> lien</p><ul><li>un</li><li>deux &amp; trois</li></ul>'
                  . '<p><strong>Titre</strong></p><p>&lt;script&gt; reste du texte</p>'
        && !preg_match('/\s[a-z-]+=|<(script|style|img|a)\b|alert/i', $te_net));
verifie('SVG, MathML, cadres et formulaires retirés avec leur contenu ; balise inconnue défaite, son texte gardé',
        texte_enrichi_nettoyer('<div>a<svg><script>alert(1)</script><text>s</text></svg><math><mi>m</mi></math>'
                               . '<iframe src="//x"></iframe><form><input value="v"><button>b</button></form>'
                               . '<span title="t">b</span><font color="red">c</font></div>') === '<p>abc</p>');
verifie('attribut glissé dans une balise gardée : retiré, imbrication conservée',
        texte_enrichi_nettoyer('<p onmouseover="x()" class="c">a <b id="i"><i style="s">b</i></b></p><ol start="9"><li value="3">c</li></ol>')
        === '<p>a <strong><em>b</em></strong></p><ol><li>c</li></ol>');
verifie('du balisage écrit en texte reste du texte échappé',
        texte_enrichi_nettoyer('<p>&lt;img src=x onerror=alert(1)&gt; &quot;guillemets&quot; l&#39;apostrophe</p>')
        === '<p>&lt;img src=x onerror=alert(1)&gt; &quot;guillemets&quot; l&apos;apostrophe</p>');
verifie('vide quand il ne reste aucun texte, accents et caractères spéciaux intacts',
        texte_enrichi_nettoyer('<p><br></p><p>&nbsp;</p><ul><li></li></ul>') === '' && texte_enrichi_nettoyer('') === ''
        && texte_enrichi_nettoyer('<p>Œuvre « étudiée » — l’été</p>') === '<p>Œuvre « étudiée » — l’été</p>'
        && texte_enrichi_vide('<p> </p>') && texte_enrichi_vide("<p>\u{202F}<br></p>") && !texte_enrichi_vide('<p>a</p>'));
verifie('paragraphe qui enveloppe une liste ou un bloc (Chrome) : défait',
        texte_enrichi_nettoyer('<p><ul><li>un</li><li>deux</li></ul></p><div><p>bloc</p></div>') === '<ul><li>un</li><li>deux</li></ul><p>bloc</p>');
verifie('texte brut d’avant l’éditeur : lignes en paragraphes, tirets en liste, échappé',
        texte_enrichi_html("Présentation des œuvres\nQuestion sur le commentaire\n\n- un point\n- un autre <script>")
            === '<p>Présentation des œuvres</p><p>Question sur le commentaire</p><ul><li>un point</li><li>un autre &lt;script&gt;</li></ul>'
        && texte_enrichi_html('<p>déjà <strong>enrichi</strong></p>') === '<p>déjà <strong>enrichi</strong></p>'
        && texte_enrichi_html('') === '' && texte_enrichi_depuis_texte("\r\n  \r\n") === '');
verifie('rechargé dans le textarea de l’éditeur : rien ne referme le champ',
        !str_contains(h(texte_enrichi_html('<p>x</p></textarea><script>alert(1)</script>')), '</textarea>'));
$te_js = (string)@file_get_contents(__DIR__ . '/actifs/mini-editeur.js');
$te_css = (string)@file_get_contents(__DIR__ . '/actifs/mini-editeur.css');
verifie('script et feuille de style du navigateur livrés, sans ressource distante',
        str_contains($te_js, 'textarea[data-mini-editeur]') && str_contains($te_css, '.mini-editeur-zone')
        && str_contains($te_css, '.texte-enrichi') && !preg_match('~https?://|@import|url\(~i', $te_js . $te_css));

titre('Vue d’agenda (2.6)');
$va_c = vue_agenda_couloirs([
    ['_debut' => 540, '_fin' => 600, 'id' => 'a'], ['_debut' => 570, '_fin' => 630, 'id' => 'b'], ['_debut' => 600, '_fin' => 660, 'id' => 'c'],
    ['_debut' => 720, '_fin' => 780, 'id' => 'd'],
]);
$va_par = array_column($va_c, null, 'id');
verifie('chevauchements : côte à côte dans leur seul groupe, un couloir libéré resservant',
        $va_par['a']['couloir'] === 0 && $va_par['b']['couloir'] === 1 && $va_par['c']['couloir'] === 0
        && $va_par['a']['couloirs'] === 2 && $va_par['c']['couloirs'] === 2 && $va_par['d']['couloirs'] === 1 && $va_par['d']['couloir'] === 0);
$va_s = vue_agenda_periode('semaine', '2026-12-03');
$va_m = vue_agenda_periode('mois', '2026-12-15');
verifie('périodes : la semaine du lundi au dimanche, le mois en semaines entières, titres en français',
        $va_s['du'] === '2026-11-30' && $va_s['au'] === '2026-12-06' && count($va_s['jours']) === 7
        && $va_s['titre'] === 'Semaine du 30 novembre au 6 décembre 2026' && $va_s['apres'] === '2026-12-07'
        && $va_m['du'] === '2026-11-30' && $va_m['au'] === '2027-01-03' && $va_m['titre'] === 'Décembre 2026'
        && vue_agenda_periode('semaine', '2027-03-03')['titre'] === 'Semaine du 1er au 7 mars 2027'
        && vue_agenda_periode('semaine', '2026-12-30')['titre'] === 'Semaine du 28 décembre 2026 au 3 janvier 2027');
$va_html = vue_agenda_semaine([
    ['jour' => '2026-12-01', 'debut' => '09:00', 'fin' => '10:00', 'titre' => 'Oral <b>', 'detail' => 'fiche', 'url' => '/oral?id=1&x=2', 'couleur' => '#1961AC'],
    ['jour' => '2026-12-01', 'debut' => '09:30', 'fin' => '10:30', 'titre' => 'Libre', 'html' => '<button>Retirer</button>', 'url' => '/x'],
    ['jour' => '2026-12-02', 'debut' => '07:00', 'fin' => '07:30', 'titre' => 'Tôt', 'couleur' => 'red'],
    ['jour' => '2026-12-02', 'debut' => '11:00', 'fin' => '10:00', 'titre' => 'Écarté'],
], ['aujourdhui' => '2026-12-01', 'maintenant' => '09:15', 'creer' => fn(string $j): ?string => $j === '2026-12-06' ? null : '#ajout',
    'marques' => ['2026-12-03' => 'Fermé <motif>'], 'px_heure' => 60, 'heure_min' => 8]);
verifie('semaine : blocs posés à leur heure, plage élargie au plus tôt, textes échappés, couleur invalide ignorée, fin avant début écartée',
        str_contains($va_html, 'top:120px;height:58px;left:calc(0% + 2px);width:calc(50% - 4px);--c:#1961AC;')   // 9h00-10h00, à partir de 7h00
        && str_contains($va_html, 'left:calc(50% + 2px)') && str_contains($va_html, '<span>7h00</span>') === false
        && str_contains($va_html, '>8h00</span>') && str_contains($va_html, 'Oral &lt;b&gt;') && !str_contains($va_html, 'Oral <b>')
        && str_contains($va_html, 'href="/oral?id=1&amp;x=2"') && !str_contains($va_html, 'Écarté') && !str_contains($va_html, '--c:red'));
verifie('semaine : un bloc avec un bouton est une boîte dont seul le titre est un lien ; aujourd’hui, l’heure, la marque, le clic',
        str_contains($va_html, '<div class="vue-agenda-bloc" ') && str_contains($va_html, '<a class="vue-agenda-lien" href="/x">')
        && str_contains($va_html, '<button>Retirer</button></div>') && str_contains($va_html, 'vue-agenda-tete aujourdhui')
        && str_contains($va_html, 'class="vue-agenda-maintenant" style="top:135px"') && str_contains($va_html, 'Fermé &lt;motif&gt;')
        && substr_count($va_html, 'data-creer="#ajout"') === 6 && str_contains($va_html, 'data-pas="15" data-px-heure="60"'));
$va_mois = vue_agenda_mois(array_map(fn(int $i): array => ['jour' => '2026-12-08', 'debut' => sprintf('%02d:00', 8 + $i), 'fin' => sprintf('%02d:30', 8 + $i), 'titre' => 'É' . $i], range(0, 5)),
    ['aujourdhui' => '2026-12-08', 'mois' => '2026-12', 'lien_jour' => fn(string $j): string => '?date=' . $j, 'max' => 4]);
verifie('mois : semaines entières, jours hors du mois estompés, quatre puces puis « + 2 autres », le 1er en exposant',
        substr_count($va_mois, '<tr>') === 6 && str_contains($va_mois, 'class="hors passe vide"') && substr_count($va_mois, 'vue-agenda-puce') === 4
        && str_contains($va_mois, '+ 2 autres') && str_contains($va_mois, '1<sup>er</sup>') && str_contains($va_mois, 'class="aujourdhui"'));
$va_js = (string)@file_get_contents(__DIR__ . '/actifs/vue-agenda.js');
$va_css = (string)@file_get_contents(__DIR__ . '/actifs/vue-agenda.css');
verifie('script et feuille de style de l’agenda livrés, sans ressource distante',
        str_contains($va_js, '.vue-agenda-colonne[data-creer]') && str_contains($va_js, 'showModal') && str_contains($va_css, '.vue-agenda-grille')
        && str_contains($va_css, '.vue-agenda-maintenant') && !preg_match('~https?://|@import|url\(~i', $va_js . $va_css));

$va_f = vue_agenda_semaine([
    ['jour' => '2026-12-01', 'debut' => '14:00', 'fin' => '16:00', 'titre' => 'Disponible', 'fond' => true, 'ouvrir' => '#dispo-1', 'etirable' => true],
    ['jour' => '2026-12-01', 'debut' => '14:00', 'fin' => '14:30', 'titre' => 'Oral', 'url' => '/oral?id=3'],
    ['jour' => '2026-12-01', 'debut' => '14:10', 'fin' => '14:40', 'titre' => 'Autre'],
], ['jours' => ['2026-12-01'], 'aujourdhui' => '2026-11-30', 'px_heure' => 60, 'heure_min' => 14, 'heure_max' => 17]);
verifie('2.7 : un fond pleine largeur hors des couloirs, qui s’ouvre au clic (bouton au clavier) et s’étire par sa poignée',
        str_contains($va_f, 'class="vue-agenda-bloc vue-agenda-fond vue-agenda-etirable" style="top:0px;height:118px;left:2px;width:calc(100% - 4px);padding-right:calc(60% + 2px);"')
        && str_contains($va_f, 'data-debut="14:00" data-fin="16:00" data-ouvrir="#dispo-1" role="button" tabindex="0"')
        && str_contains($va_f, '<span class="vue-agenda-poignee" aria-hidden="true"></span>')
        && str_contains($va_f, 'left:calc(40% + 2px);width:calc(30% - 4px)') && str_contains($va_f, 'left:calc(70% + 2px)')   // les deux autres se partagent la droite, le fond n'y compte pas
        && str_contains($va_f, 'padding-right:calc(60% + 2px)')
        && str_contains($va_f, 'data-heure-min="14" data-pas="15" data-px-heure="60"'));   // mesures posées même sans clic pour créer
$va_js2 = (string)@file_get_contents(__DIR__ . '/actifs/vue-agenda.js');
verifie('2.7 : le script crée au glisser, ouvre au clic, étire par la poignée',
        str_contains($va_js2, 'pointerdown') && str_contains($va_js2, '.vue-agenda-bloc[data-ouvrir]') && str_contains($va_js2, '.vue-agenda-etirable .vue-agenda-poignee')
        && str_contains($va_js2, "'&fin='"));

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

bilan();
