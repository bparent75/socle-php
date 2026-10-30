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
              'journal', 'cron', 'flash', 'validation', 'image', 'csv', 'ical', 'porte_demo', 'totp'] as $brique) {
        require __DIR__ . "/src/$brique.php";
    }
    // qrcode.php a besoin de bacon/bacon-qr-code : sans Composer, ses contrôles sont ignorés.
    if (class_exists(\BaconQrCode\Encoder\Encoder::class)) require __DIR__ . '/src/qrcode.php';
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
