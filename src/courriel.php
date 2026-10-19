<?php
/**
 * socle-php — file d'envoi de courriels, avec reprise et gabarit HTML.
 *
 * Attend de l'application hôte :
 *   - bd(): PDO, maintenant(), h()
 *   - constantes EMAIL_EXPEDITEUR, et éventuellement EMAIL_EXPEDITEUR_NOM, MARQUE_NOM,
 *     CONTACT_COURRIEL, MARQUE_BLEU, MARQUE_BLEU_CLAIR (encadrés), MARQUE_ACCENT (boutons d'accent)
 *   - facultatif, les quatre constantes SMTP_* (hôte, port, utilisateur, mot de passe) — voir
 *     courriel_smtp() — pour envoyer par SMTP (PHPMailer requis) au lieu du mail() de l'hébergement
 *   - facultatif, EMAIL_EXPEDITEUR_TECHNIQUE : adresse « ne pas répondre » réservée aux messages
 *     purement techniques (lien de connexion) ; EMAIL_EXPEDITEUR reste l'adresse humaine, celle
 *     à laquelle on répond — voir expediteur_technique()
 *   - facultatif, les colonnes `repondre_a` et `copie` de la table, pour un Reply-To ou un Cc
 *     propre à un message (socle 1.5)
 *   - facultatif, la colonne `options` de la table, pour l'habillage propre à un message :
 *     texte d'aperçu, logo et illustration intégrés, boutons d'accent, pied (socle 1.7, voir
 *     courriel_html())
 *   - facultatif, la colonne `message_id` de la table : l'identifiant (en-tête Message-ID) de
 *     chaque message remis y est noté, pour rapprocher un avis de non-remise du courriel exact
 *     (socle 1.8, voir rebonds.php)
 *   - la table `notification` (voir sql/)
 *   - phpmailer/phpmailer, sinon repli en texte seul
 *
 * Pourquoi ici plutôt qu'un paquet : PHPMailer envoie un message, il ne gère ni file,
 * ni reprise, ni visibilité des échecs pour l'équipe. Ce sont ces trois choses-là —
 * plus deux pièges d'hébergement mutualisé — que ce fichier apporte.
 *
 * PIÈGE N°1 — sur un mutualisé OVH, sendmail REFUSE les envois lancés depuis une
 * session SSH interactive (« User ... is not allowed to submit mail ») alors que
 * mail() renvoie quand même « succès ». Traiter la file dans ce contexte marque les
 * messages comme envoyés sans qu'ils partent : ils sont perdus. D'où file_traiter()
 * qui refuse de s'exécuter en session interactive.
 *
 * PIÈGE N°3 — le mail() d'un mutualisé part de l'IP de l'hébergeur, sans signature DKIM.
 * Si le SPF du domaine expéditeur ne cite pas cet hébergeur, Yahoo jette le message sans
 * même le classer en spam (constaté sur atea.info, 29/09/2026). D'où le mode SMTP : envoyer
 * par le serveur de courrier du domaine lui-même (Google Workspace, OVH…), qui signe.
 *
 * PIÈGE N°2 — la tâche planifiée d'un mutualisé peut ne tourner qu'une fois par heure.
 * Un lien de connexion valable 30 minutes doit donc partir immédiatement, depuis la
 * requête web : c'est le rôle du paramètre $urgent.
 */
declare(strict_types=1);

const SOCLE_MAX_TENTATIVES = 5;
const SOCLE_LOT_ENVOI = 50;
/** Taille maximale d'une image intégrée à un message (logo, illustration). */
const SOCLE_IMAGE_MAX_OCTETS = 1048576;

/**
 * Valeur de configuration, dans l'ordre : la fonction param() du projet si elle existe,
 * puis la constante, puis le défaut.
 *
 * Le détour par param() permet à un projet de rendre ces valeurs modifiables par son
 * équipe — une adresse de contact, un nom de marque — sans figer une constante.
 */
function socle_param(string $constante, string $defaut = ''): string
{
    if (function_exists('param')) {
        $v = param(strtolower($constante), '');
        if ($v !== '') return $v;
    }
    return defined($constante) ? (string)constant($constante) : $defaut;
}

/**
 * Met un courriel en file.
 *
 * $cle_unicite empêche le doublon — un rappel posé deux fois, par exemple.
 * $urgent expédie tout de suite, sans attendre la tâche planifiée ; en cas d'échec le
 * message reste en file et sera repris normalement.
 *
 * $repondre_a remplace CONTACT_COURRIEL dans le Reply-To de ce message (la personne qui l'a
 * rédigé, par exemple) ; $copie met en Cc une adresse, ou plusieurs séparées par des virgules
 * (1.6 : les autres adresses d'une même famille, par exemple). Les deux demandent les colonnes
 * `repondre_a` et `copie` de la table (voir sql/) ; tant qu'on ne s'en sert pas, elles ne
 * sont pas écrites, et un projet qui ne les a pas encore créées continue de fonctionner.
 *
 * $options (1.7) habille ce message : texte d'aperçu, logo, illustration, boutons d'accent,
 * pied, nom d'expéditeur (voir courriel_html()). Elles sont gardées en JSON dans la colonne
 * `options`, écrite seulement quand on s'en sert, comme les deux précédentes. Les images y
 * figurent par leur chemin sur le serveur : elles ne sont lues qu'au moment de l'envoi.
 */
function filer_courriel(string $destinataire, string $sujet, string $corps,
                        ?int $utilisateur_id = null, string $cle_unicite = '',
                        ?string $envoyer_apres = null, string $objet_type = '', ?int $objet_id = null,
                        bool $urgent = false, ?string $expediteur = null,
                        ?string $repondre_a = null, ?string $copie = null, ?array $options = null): bool
{
    $colonnes = ['utilisateur_id' => $utilisateur_id, 'destinataire' => $destinataire, 'sujet' => $sujet,
                 'corps' => $corps, 'envoyer_apres' => $envoyer_apres ?? maintenant(),
                 'objet_type' => $objet_type, 'objet_id' => $objet_id,
                 'cle_unicite' => $cle_unicite !== '' ? $cle_unicite : null, 'expediteur' => $expediteur];
    if ($repondre_a !== null) $colonnes['repondre_a'] = $repondre_a;
    if ($copie !== null) $colonnes['copie'] = $copie;
    if ($options !== null && $options !== []) {
        $json = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;
        $colonnes['options'] = $json;
    }
    $colonnes['cree_le'] = maintenant();
    try {
        bd()->prepare('INSERT INTO notification (' . implode(', ', array_keys($colonnes)) . ')
                       VALUES (' . implode(', ', array_fill(0, count($colonnes), '?')) . ')')
            ->execute(array_values($colonnes));
        $id = (int)bd()->lastInsertId();
    } catch (PDOException) {
        return false;   // doublon volontairement ignoré (clé d'unicité)
    }

    if ($urgent && PHP_SAPI !== 'cli' && $envoyer_apres === null) {
        $r = expedier_courriel($destinataire, $sujet, $corps, $expediteur, $repondre_a, $copie, $options);
        bd()->prepare('UPDATE notification SET etat = ?, tentatives = 1, envoye_le = ?, derniere_erreur = ?
                       WHERE id = ?')
            ->execute([$r['ok'] ? 'envoye' : 'en_attente', $r['ok'] ? maintenant() : null, $r['erreur'], $id]);
        if ($r['ok']) courriel_noter_message_id($id, $r['message_id'] ?? '');
    }
    return true;
}

/** Annule les envois encore en attente pour un objet : reprogrammation, annulation. */
function annuler_envois(string $objet_type, int $objet_id): void
{
    bd()->prepare("UPDATE notification SET etat = 'annule'
                   WHERE objet_type = ? AND objet_id = ? AND etat = 'en_attente'")
        ->execute([$objet_type, $objet_id]);
}

/**
 * Réglages SMTP du projet, ou null si l'envoi reste confié au mail() de l'hébergement.
 *
 * Les quatre constantes viennent de secrets.php ; il suffit que l'hôte soit vide ou absent
 * pour rester en mail(). Le port décide du chiffrement : 465 = TLS implicite (SMTPS),
 * tout autre port = STARTTLS.
 *
 * @return array{hote:string, port:int, utilisateur:string, mot_de_passe:string, chiffrement:string}|null
 */
function courriel_smtp(): ?array
{
    $hote = defined('SMTP_HOTE') ? trim((string)constant('SMTP_HOTE')) : '';
    if ($hote === '') return null;
    $port = defined('SMTP_PORT') ? (int)constant('SMTP_PORT') : 587;
    return [
        'hote'         => $hote,
        'port'         => $port,
        'utilisateur'  => defined('SMTP_UTILISATEUR') ? (string)constant('SMTP_UTILISATEUR') : '',
        'mot_de_passe' => defined('SMTP_MOT_DE_PASSE') ? (string)constant('SMTP_MOT_DE_PASSE') : '',
        'chiffrement'  => $port === 465 ? 'ssl' : 'tls',
    ];
}

/**
 * Adresse « ne pas répondre », pour les seuls messages techniques (lien de connexion…).
 *
 * Tout ce qui s'adresse à un humain et peut appeler une réponse part de EMAIL_EXPEDITEUR :
 * les gens regardent le champ « De », pas le Reply-To, et « noreply » leur dit de se taire.
 * Sans EMAIL_EXPEDITEUR_TECHNIQUE, retombe sur EMAIL_EXPEDITEUR.
 */
function expediteur_technique(): string
{
    $adresse = defined('EMAIL_EXPEDITEUR_TECHNIQUE') ? trim((string)constant('EMAIL_EXPEDITEUR_TECHNIQUE')) : '';
    return $adresse !== '' ? $adresse : EMAIL_EXPEDITEUR;
}

/**
 * Découpe une liste d'adresses (séparées par des virgules ou des points-virgules) en
 * adresses propres, sans doublon (casse ignorée), sans celles de $exclure (le destinataire
 * principal, typiquement). Rend null si l'une d'elles est invalide : mieux vaut un envoi
 * refusé et visible qu'une copie perdue en silence.
 *
 * @param string[] $exclure
 * @return string[]|null
 */
function courriel_adresses(string $liste, array $exclure = []): ?array
{
    $vues = [];
    foreach ($exclure as $e) $vues[mb_strtolower(trim($e))] = true;
    $adresses = [];
    foreach (preg_split('/[,;]/', $liste) as $morceau) {
        $adresse = trim($morceau);
        if ($adresse === '') continue;
        if (filter_var($adresse, FILTER_VALIDATE_EMAIL) === false) return null;
        $cle = mb_strtolower($adresse);
        if (isset($vues[$cle])) continue;
        $vues[$cle] = true;
        $adresses[] = $adresse;
    }
    return $adresses;
}

/**
 * Remet un message au serveur de courrier.
 *
 * ATTENTION : « vrai » signifie que le message a été accepté, pas qu'il est arrivé.
 * Ne jamais présenter ce retour comme une preuve de réception.
 *
 * $expediteur remplace EMAIL_EXPEDITEUR pour ce message (null = défaut). En SMTP, l'adresse
 * doit être acceptée par le compte qui s'authentifie (alias ou « envoyer en tant que »),
 * sans quoi le serveur réécrit le champ « De ».
 *
 * $repondre_a remplace CONTACT_COURRIEL dans le Reply-To ; $copie met en Cc une adresse ou
 * plusieurs, séparées par des virgules (doublons et destinataire lui-même retirés, voir
 * courriel_adresses()). Une adresse de copie invalide fait échouer l'envoi plutôt que de
 * partir sans elle. Le pied du message garde l'adresse de contact, sauf option `contact`.
 *
 * $options (1.7) : habillage du message, voir courriel_html(), plus `expediteur_nom`, qui
 * remplace EMAIL_EXPEDITEUR_NOM. Le logo et l'illustration partent en pièces jointes en ligne
 * (cid:) : ils s'affichent même quand la messagerie bloque les images distantes.
 *
 * Le retour porte l'identifiant du message remis (en-tête Message-ID, 1.8) : un avis de non-remise
 * le cite, ce qui le rattache au courriel exact. Chaîne vide si le message n'est pas parti.
 *
 * @return array{ok:bool, erreur:string, message_id:string}
 */
function expedier_courriel(string $destinataire, string $sujet, string $corps, ?string $expediteur = null,
                           ?string $repondre_a = null, ?string $copie = null, ?array $options = null): array
{
    $options ??= [];
    $html = courriel_html($sujet, $corps, 'Ouvrir', $options);
    $texte = courriel_texte($corps);
    $nom_expediteur = trim((string)($options['expediteur_nom'] ?? '')) !== ''
                    ? trim((string)$options['expediteur_nom']) : socle_param('EMAIL_EXPEDITEUR_NOM', 'Notification');
    $de = trim((string)$expediteur) !== '' ? trim((string)$expediteur) : EMAIL_EXPEDITEUR;
    $contact = trim((string)$repondre_a) !== '' ? trim((string)$repondre_a)
             : socle_param('CONTACT_COURRIEL', EMAIL_EXPEDITEUR);
    // Une adresse ou un nom d'en-tête ne contient jamais de retour à la ligne (injection d'en-têtes).
    if (preg_match('/[\r\n]/', $contact . (string)$copie . $nom_expediteur)) {
        return ['ok' => false, 'erreur' => 'adresse de réponse, de copie ou nom d\'expéditeur invalide', 'message_id' => ''];
    }
    $copies = courriel_adresses((string)$copie, [$destinataire]);
    if ($copies === null) return ['ok' => false, 'erreur' => 'adresse de copie invalide', 'message_id' => ''];
    $smtp = courriel_smtp();

    if ($smtp !== null && !class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        return ['ok' => false, 'erreur' => 'SMTP configuré mais phpmailer/phpmailer absent', 'message_id' => ''];
    }

    if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            if ($smtp !== null) {
                $mail->isSMTP();
                $mail->Host       = $smtp['hote'];
                $mail->Port       = $smtp['port'];
                $mail->SMTPSecure = $smtp['chiffrement'];
                $mail->SMTPAuth   = $smtp['utilisateur'] !== '';
                $mail->Username   = $smtp['utilisateur'];
                $mail->Password   = $smtp['mot_de_passe'];
                $mail->Timeout    = 20;
            } else {
                $mail->isMail();
            }
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->setFrom($de, $nom_expediteur);
            // Identifiant du message au domaine de l'expéditeur (1.7.1) : sinon PHPMailer prend le nom
            // du serveur : un message signé pour un domaine porterait l'identifiant d'un autre.
            if (($identifiant = courriel_message_id($de)) !== '') $mail->MessageID = $identifiant;
            $mail->addAddress($destinataire);
            if ($contact !== $de) $mail->addReplyTo($contact);
            foreach ($copies as $adresse) $mail->addCC($adresse);
            $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
            $mail->Subject = $sujet;
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $texte;
            foreach (courriel_images($options) as $image) {
                $mail->addEmbeddedImage($image['chemin'], $image['cid'], basename($image['chemin']), 'base64', $image['type']);
            }
            $mail->send();
            return ['ok' => true, 'erreur' => '', 'message_id' => (string)$mail->getLastMessageID()];
        } catch (Throwable $e) {
            return ['ok' => false, 'erreur' => mb_substr($e->getMessage(), 0, 200), 'message_id' => ''];
        }
    }

    // Identifiant posé ici plutôt que par sendmail : il est alors connu, et noté dans la file.
    $identifiant = courriel_message_id($de);
    $entetes = "From: $nom_expediteur <$de>\r\n"
             . ($identifiant !== '' ? "Message-ID: $identifiant\r\n" : '')
             . "Reply-To: $contact\r\n"
             . ($copies !== [] ? 'Cc: ' . implode(', ', $copies) . "\r\n" : '')
             . "Content-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0\r\n"
             . "Auto-Submitted: auto-generated";
    $sujet_encode = '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], '', $sujet)) . '?=';
    $ok = @mail($destinataire, $sujet_encode, wordwrap($texte, 78, "\r\n"), $entetes);
    return ['ok' => (bool)$ok, 'erreur' => $ok ? '' : 'mail() a renvoyé une erreur', 'message_id' => $ok ? $identifiant : ''];
}

/**
 * Identifiant de message (en-tête Message-ID) au domaine de l'expéditeur : « <aléa@domaine> ».
 * Un identifiant au nom du serveur d'hébergement, alors que le message est signé pour le domaine de
 * l'expéditeur, est un décalage que relèvent les filtres anti-spam. Chaîne vide si l'adresse n'a pas
 * de domaine exploitable : PHPMailer garde alors le sien.
 */
function courriel_message_id(string $expediteur): string
{
    $domaine = mb_strtolower(trim((string)substr($expediteur, (int)strrpos($expediteur, '@') + 1)));
    if (!str_contains($expediteur, '@') || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/', $domaine)) {
        return '';
    }
    return '<' . bin2hex(random_bytes(16)) . '@' . $domaine . '>';
}

/**
 * Note l'identifiant d'un message remis dans la file (1.8), si la table a la colonne `message_id`.
 * Un projet qui ne l'a pas encore ajoutée continue de fonctionner : rien n'est écrit.
 */
function courriel_noter_message_id(int $id, string $message_id): void
{
    $message_id = trim($message_id);
    if ($message_id === '' || !socle_colonne('notification', 'message_id')) return;
    bd()->prepare('UPDATE notification SET message_id = ? WHERE id = ?')->execute([mb_substr($message_id, 0, 250), $id]);
}

/**
 * La table a-t-elle cette colonne ? Réponse gardée pour la durée de la requête. Sert aux colonnes
 * facultatives (1.8) : une base créée par un socle plus ancien ne les a pas tant que le projet ne
 * les a pas ajoutées, et le socle doit alors s'en passer plutôt que d'échouer. SQLite et MySQL.
 */
function socle_colonne(string $table, string $colonne): bool
{
    static $connues = [];
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $table . $colonne)) return false;
    $cle = $table . '.' . $colonne;
    if (!isset($connues[$cle])) {
        try {
            bd()->query("SELECT $colonne FROM $table LIMIT 0");
            $connues[$cle] = true;
        } catch (PDOException) {
            $connues[$cle] = false;
        }
    }
    return $connues[$cle];
}

/**
 * Typographie française d'un texte brut : espace insécable avant « : ; ! ? » » et après « « ».
 * Seules les espaces ordinaires sont remplacées : une adresse (« https:// », « ?id=3 ») n'a
 * pas d'espace devant ces signes et n'est donc jamais touchée.
 */
function typographie_fr(string $texte): string
{
    $texte = preg_replace('/ ([:;!?»])/u', "\u{00A0}$1", $texte) ?? $texte;
    return str_replace('« ', "«\u{00A0}", $texte);
}

/**
 * Les images d'un message (1.7) : `logo` (bandeau) et `illustration` (pleine largeur sous le
 * bandeau), données par le chemin d'un fichier LOCAL, PNG, JPEG ou GIF, d'un mégaoctet au plus.
 * Une image absente, illisible ou douteuse est écartée sans faire échouer l'envoi : le bandeau
 * retombe alors sur le nom de la marque. Jamais d'adresse distante ni de flux (data:, http:…).
 * Largeur d'affichage : options `logo_largeur` (130 par défaut) et `illustration_largeur` (580) ;
 * la hauteur suit les proportions du fichier. Textes de remplacement : `logo_alt`, `illustration_alt`.
 *
 * @return array<string, array{chemin:string, cid:string, type:string, largeur:int, hauteur:int, alt:string}>
 */
function courriel_images(array $options): array
{
    $types = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_GIF => 'image/gif'];
    $images = [];
    foreach (['logo' => 130, 'illustration' => 580] as $nom => $largeur_defaut) {
        $chemin = is_string($options[$nom] ?? null) ? $options[$nom] : '';
        if ($chemin === '' || str_contains($chemin, '://') || !is_file($chemin) || !is_readable($chemin)
            || filesize($chemin) > SOCLE_IMAGE_MAX_OCTETS) continue;
        $infos = @getimagesize($chemin);
        if ($infos === false || !isset($types[$infos[2]]) || $infos[0] <= 0 || $infos[1] <= 0) continue;
        $largeur = max(1, (int)($options[$nom . '_largeur'] ?? $largeur_defaut));
        $images[$nom] = [
            'chemin'  => $chemin,
            'cid'     => $nom . '@courriel',
            'type'    => $types[$infos[2]],
            'largeur' => $largeur,
            'hauteur' => max(1, (int)round($largeur * $infos[1] / $infos[0])),
            'alt'     => (string)($options[$nom . '_alt'] ?? ($nom === 'logo' ? socle_param('MARQUE_NOM', '') : '')),
        ];
    }
    return $images;
}

/**
 * Version texte d'un message : le corps sans son balisage (voir courriel_html()). Un corps
 * sans balisage ressort tel quel, à la typographie près.
 */
function courriel_texte(string $corps): string
{
    $blocs = [];
    foreach (preg_split("/\r?\n\r?\n/", trim($corps)) ?: [] as $bloc) {
        $bloc = trim($bloc);
        if ($bloc === '' || $bloc === '---') continue;
        if (str_starts_with($bloc, '# ')) $bloc = substr($bloc, 2);
        elseif (str_starts_with($bloc, '> ')) $bloc = preg_replace('/^> ?/m', '', $bloc) ?? $bloc;
        $bloc = preg_replace('#\[([^\]\n]+)\]\((https?://[^\s)]+)\)#u', '$1 ($2)', $bloc) ?? $bloc;
        $bloc = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '$1', $bloc) ?? $bloc;
        $bloc = preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/u', '$1', $bloc) ?? $bloc;
        $blocs[] = $bloc;
    }
    return typographie_fr(implode("\n\n", $blocs));
}

/**
 * Un morceau de texte en HTML : échappé, typographie française, puis le balisage en ligne
 * — [texte](https://…) devient un lien, **texte** du gras, *texte* de l'italique (un titre
 * d'œuvre). Seules les adresses http(s) deviennent des liens.
 */
function courriel_ligne_html(string $texte): string
{
    $liens = [];
    $texte = preg_replace_callback('#\[([^\]\n]+)\]\((https?://[^\s)]+)\)#u', function (array $m) use (&$liens): string {
        $liens[] = [$m[1], $m[2]];
        return "\u{E000}" . (count($liens) - 1) . "\u{E001}";
    }, $texte) ?? $texte;
    $emphase = function (string $html): string {
        $html = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $html) ?? $html;
        return preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/u', '<em>$1</em>', $html) ?? $html;
    };
    $html = nl2br($emphase(h(typographie_fr($texte))), false);
    return preg_replace_callback("#\u{E000}(\d+)\u{E001}#u", fn(array $m): string =>
        '<a href="' . h($liens[(int)$m[1]][1]) . '" style="color:#1068B3">'
        . $emphase(h(typographie_fr($liens[(int)$m[1]][0]))) . '</a>', $html) ?? $html;
}

/**
 * Met en forme un message. Le texte reste la version de référence : le HTML en dérive,
 * sans jamais rien ajouter au fond. Le corps se découpe en blocs séparés par une ligne vide :
 *
 *   https://…                    une adresse seule devient un bouton ($libelle_bouton) ;
 *   Libellé : https://…          un libellé suivi d'une adresse, sur une ligne : un bouton qui
 *                                porte ce libellé (1.7) ;
 *   # Titre                      un intertitre ;
 *   Titre :                      un bloc où des lignes commencent par « - » devient un encadré
 *   - élément ;                  à puces : la ou les lignes d'avant en font le titre, celles
 *   - élément.                   d'après une note ;
 *   Note
 *   > texte                      une note discrète, en petit ;
 *   ---                          un filet de séparation.
 *
 * Dans le texte : [texte](https://…), **gras**, *italique*. courriel_texte() retire ce
 * balisage pour la version texte du message.
 *
 * Options (1.7, toutes facultatives) :
 *   apercu        texte d'aperçu, montré par la boîte de réception sous l'objet, masqué dans le message ;
 *   logo          chemin d'une image locale, à gauche du bandeau (voir courriel_images()) ;
 *   bandeau       texte du bandeau (défaut : MARQUE_NOM) ; à droite du logo s'il y en a un ;
 *   illustration  chemin d'une image locale, pleine largeur sous le bandeau ;
 *   accent        adresses dont le bouton prend la couleur d'accent (MARQUE_ACCENT) ;
 *   pied          phrase du pied de page (défaut : la mention d'absence de données de dossier) ;
 *   contact       adresse du pied (défaut : CONTACT_COURRIEL) ;
 *   src_images    « cid » (défaut, pour l'envoi) ou « data » (aperçu dans un navigateur, qui
 *                 n'affiche pas les pièces jointes en ligne).
 *
 * Sans image distante, volontairement : beaucoup de messageries les bloquent, et une
 * image hébergée par le site serait inaccessible tant qu'il est fermé. Les images de la 1.7
 * voyagent dans le message lui-même.
 */
function courriel_html(string $sujet, string $corps, string $libelle_bouton = 'Ouvrir', array $options = []): string
{
    $bleu = socle_param('MARQUE_BLEU', '#054570');
    $bleu_clair = socle_param('MARQUE_BLEU_CLAIR', $bleu);
    $accent = socle_param('MARQUE_ACCENT', '#E74310');
    $marque = trim((string)($options['bandeau'] ?? '')) !== '' ? (string)$options['bandeau'] : socle_param('MARQUE_NOM', 'Notification');
    $contact = trim((string)($options['contact'] ?? '')) !== '' ? (string)$options['contact'] : socle_param('CONTACT_COURRIEL', EMAIL_EXPEDITEUR);
    $pied = trim((string)($options['pied'] ?? '')) !== '' ? (string)$options['pied']
          : 'Message automatique. Il ne contient aucune donnée personnelle du dossier suivi.';
    $adresses_accent = array_map('strval', is_array($options['accent'] ?? null) ? $options['accent'] : []);
    $images = courriel_images($options);
    $src = function (array $image) use ($options): string {
        if (($options['src_images'] ?? 'cid') === 'data') {
            return 'data:' . $image['type'] . ';base64,' . base64_encode((string)file_get_contents($image['chemin']));
        }
        return 'cid:' . $image['cid'];
    };
    $police = 'Helvetica,Arial,sans-serif';
    $style_texte = 'font-family:' . $police . ';color:#16222E;';
    $blocs = '';

    foreach (preg_split("/\r?\n\r?\n/", trim($corps)) ?: [] as $bloc) {
        $bloc = trim($bloc);
        if ($bloc === '') continue;

        // Bouton : une adresse seule, ou « Libellé : adresse » sur une ligne.
        $libelle = null;
        if (preg_match('#^https?://\S+$#', $bloc)) {
            $url = $bloc;
        } elseif (preg_match('#^([^\n:.,;!?]{1,50}?) ?: (https?://\S+)$#u', $bloc, $m)) {
            [$libelle, $url] = [trim($m[1]), $m[2]];
        } else {
            $url = null;
        }
        if ($url !== null) {
            $fond = in_array($url, $adresses_accent, true) ? $accent : $bleu;
            $blocs .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:'
                    . ($libelle === null ? '26px 0' : '6px 0 20px') . '">'
                    . '<tr><td style="border-radius:999px;background:' . $fond . '">'
                    . '<a href="' . h($url) . '" style="display:inline-block;padding:14px 28px;font-family:'
                    . $police . ';font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;'
                    . 'border-radius:999px">' . h(typographie_fr($libelle ?? $libelle_bouton)) . '</a></td></tr></table>';
            // Le lien en clair accompagne le bouton sans libellé (un lien de connexion, souvent) ;
            // un bouton qui dit où il mène s'en passe, la version texte garde l'adresse.
            if ($libelle === null) {
                $blocs .= '<p style="margin:0 0 18px;font-family:' . $police . ';font-size:13px;line-height:1.5;'
                        . 'color:#4C5C6A">Si le bouton ne fonctionne pas, copiez cette adresse dans votre '
                        . 'navigateur :<br><span style="word-break:break-all">' . h($url) . '</span></p>';
            }
            continue;
        }

        if ($bloc === '---') {
            $blocs .= '<hr style="border:0;border-top:1px solid #C8D5DF;margin:26px 0 16px">';
            continue;
        }
        if (str_starts_with($bloc, '# ')) {
            $blocs .= '<h2 style="margin:26px 0 8px;' . $style_texte . 'font-size:17px;line-height:1.4;font-weight:bold;'
                    . 'color:' . $bleu . '">' . courriel_ligne_html(substr($bloc, 2)) . '</h2>';
            continue;
        }
        if (str_starts_with($bloc, '> ')) {
            $blocs .= '<p style="margin:0 0 16px;font-family:' . $police . ';font-size:14px;line-height:1.55;color:#4C5C6A">'
                    . courriel_ligne_html(preg_replace('/^> ?/m', '', $bloc) ?? $bloc) . '</p>';
            continue;
        }

        // Encadré à puces : des lignes « - … », un titre avant, une note après.
        $lignes = preg_split('/\r?\n/', $bloc) ?: [];
        if (preg_grep('/^- /', $lignes) !== []) {
            $titre = $puces = $note = [];
            foreach ($lignes as $ligne) {
                if (str_starts_with($ligne, '- ')) { $puces[] = substr($ligne, 2); continue; }
                if ($puces === []) $titre[] = $ligne; else $note[] = $ligne;
            }
            $blocs .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 22px">'
                    . '<tr><td style="background:#EEF5FA;border-left:4px solid ' . $bleu_clair . ';border-radius:8px;padding:14px 18px">'
                    . ($titre !== [] ? '<p style="margin:0 0 6px;' . $style_texte . 'font-size:15px;line-height:1.5;font-weight:bold;color:'
                                       . $bleu . '">' . courriel_ligne_html(implode("\n", $titre)) . '</p>' : '')
                    . '<ul style="margin:0;padding-left:20px">'
                    . implode('', array_map(fn(string $p): string => '<li style="margin:3px 0;' . $style_texte
                        . 'font-size:15px;line-height:1.5">' . courriel_ligne_html($p) . '</li>', $puces))
                    . '</ul>'
                    . ($note !== [] ? '<p style="margin:10px 0 0;' . $style_texte . 'font-size:14px;line-height:1.5">'
                                      . courriel_ligne_html(implode("\n", $note)) . '</p>' : '')
                    . '</td></tr></table>';
            continue;
        }

        $blocs .= '<p style="margin:0 0 18px;' . $style_texte . 'font-size:16px;line-height:1.6">'
                . courriel_ligne_html($bloc) . '</p>';
    }

    // Bandeau : le logo et le nom de la marque, ou le nom seul.
    if (isset($images['logo'])) {
        $logo = $images['logo'];
        $bandeau = '<tr><td style="background:' . $bleu . ';padding:16px 30px">'
                 . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                 . '<td style="vertical-align:middle"><img src="' . h($src($logo)) . '" width="' . $logo['largeur']
                 . '" height="' . $logo['hauteur'] . '" alt="' . h($logo['alt']) . '" style="display:block;border:0"></td>'
                 . '<td align="right" style="vertical-align:middle;font-family:' . $police . ';font-size:15px;font-weight:bold;'
                 . 'letter-spacing:.14em;color:#ffffff">' . h(trim((string)($options['bandeau'] ?? ''))) . '</td>'
                 . '</tr></table></td></tr>';
    } else {
        $bandeau = '<tr><td style="background:' . $bleu . ';padding:22px 30px;font-family:' . $police . ';'
                 . 'font-size:19px;font-weight:bold;color:#ffffff">' . h($marque) . '</td></tr>';
    }
    if (isset($images['illustration'])) {
        $illustration = $images['illustration'];
        $bandeau .= '<tr><td style="padding:0;line-height:0;font-size:0"><img src="' . h($src($illustration)) . '" width="'
                  . $illustration['largeur'] . '" height="' . $illustration['hauteur'] . '" alt="' . h($illustration['alt'])
                  . '" style="display:block;width:100%;max-width:' . $illustration['largeur'] . 'px;height:auto;border:0"></td></tr>';
    }
    // Texte d'aperçu : lu par la boîte de réception, invisible dans le message. Les espaces
    // insécables qui suivent empêchent la messagerie d'y accoler le début du corps.
    $apercu = trim((string)($options['apercu'] ?? ''));
    $preentete = $apercu === '' ? '' : '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;'
               . 'opacity:0;overflow:hidden;mso-hide:all">' . h(typographie_fr($apercu)) . str_repeat('&#847;&zwnj;&nbsp;', 40) . '</div>';

    return '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
         . '<meta name="viewport" content="width=device-width,initial-scale=1">'
         . '<title>' . h($sujet) . '</title></head><body style="margin:0;padding:0;background:#EDF1F5">' . $preentete
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
         . ' style="background:#EDF1F5;padding:28px 12px"><tr><td align="center">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
         . ' style="max-width:580px;background:#ffffff;border-radius:12px;overflow:hidden">'
         . $bandeau
         . '<tr><td style="padding:30px"><h1 style="margin:0 0 20px;font-family:' . $police . ';'
         . 'font-size:21px;line-height:1.3;color:' . $bleu . '">' . h(typographie_fr($sujet)) . '</h1>' . $blocs . '</td></tr>'
         . '<tr><td style="padding:18px 30px 26px;border-top:1px solid #C8D5DF;font-family:' . $police . ';'
         . 'font-size:12px;line-height:1.6;color:#4C5C6A">' . h($pied) . '<br>Contact : '
         . '<a href="mailto:' . h($contact) . '" style="color:#1068B3">' . h($contact) . '</a>'
         . '</td></tr></table></td></tr></table></body></html>';
}

/**
 * Vide la file. À appeler depuis la tâche planifiée, jamais depuis une session SSH.
 *
 * @return array{envoyes:int, echecs:int, ignores:int, message:string}
 */
function file_traiter(bool $forcer = false): array
{
    $pdo = bd();
    $interactive = getenv('SSH_CONNECTION') !== false || getenv('SSH_TTY') !== false;

    if ($interactive && !$forcer) {
        $req = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE etat = 'en_attente' AND envoyer_apres <= ?");
        $req->execute([maintenant()]);
        $n = (int)$req->fetchColumn();
        return ['envoyes' => 0, 'echecs' => 0, 'ignores' => $n,
                'message' => "session SSH interactive : $n message(s) laissé(s) intacts en file"];
    }

    $req = $pdo->prepare("SELECT * FROM notification WHERE etat = 'en_attente' AND envoyer_apres <= ?
                          ORDER BY envoyer_apres LIMIT " . SOCLE_LOT_ENVOI);
    $req->execute([maintenant()]);
    $envoyes = 0;
    $echecs = 0;

    foreach ($req->fetchAll() as $n) {
        $options = json_decode((string)($n['options'] ?? ''), true);
        $r = expedier_courriel($n['destinataire'], $n['sujet'], $n['corps'], $n['expediteur'] ?? null,
                              $n['repondre_a'] ?? null, $n['copie'] ?? null, is_array($options) ? $options : null);
        if ($r['ok']) {
            $pdo->prepare("UPDATE notification SET etat = 'envoye', envoye_le = ?, tentatives = tentatives + 1
                           WHERE id = ?")->execute([maintenant(), $n['id']]);
            courriel_noter_message_id((int)$n['id'], $r['message_id'] ?? '');
            $envoyes++;
            continue;
        }
        $tentatives = (int)$n['tentatives'] + 1;
        if ($tentatives >= SOCLE_MAX_TENTATIVES) {
            // Échec définitif : visible dans l'administration, jamais supprimé en silence.
            $pdo->prepare("UPDATE notification SET etat = 'echec', tentatives = ?, derniere_erreur = ?
                           WHERE id = ?")->execute([$tentatives, $r['erreur'], $n['id']]);
            $echecs++;
        } else {
            $report = (new DateTimeImmutable('+' . (5 * (2 ** ($tentatives - 1))) . ' minutes',
                        new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
            $pdo->prepare('UPDATE notification SET tentatives = ?, derniere_erreur = ?, envoyer_apres = ?
                           WHERE id = ?')->execute([$tentatives, $r['erreur'], $report, $n['id']]);
        }
    }
    return ['envoyes' => $envoyes, 'echecs' => $echecs, 'ignores' => 0,
            'message' => "remis au serveur de courrier : $envoyes, échecs définitifs : $echecs"];
}
