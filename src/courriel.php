<?php
/**
 * socle-php — file d'envoi de courriels, avec reprise et gabarit HTML.
 *
 * Attend de l'application hôte :
 *   - bd(): PDO, maintenant(), h()
 *   - constantes EMAIL_EXPEDITEUR, et éventuellement EMAIL_EXPEDITEUR_NOM, MARQUE_NOM,
 *     CONTACT_COURRIEL, MARQUE_BLEU, MARQUE_BLEU_CLAIR (encadrés), MARQUE_ACCENT (boutons d'accent)
 *   - facultatif, l'identité visuelle de ses courriels (2.2) : constante COURRIEL_THEME et/ou fonction
 *     courriel_theme_projet() — logo, couleurs, police, pied ; voir courriel_theme()
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

if (!function_exists('socle_param')) {
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
}

if (!function_exists('filer_courriel')) {
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
}

if (!function_exists('annuler_envois')) {
    /** Annule les envois encore en attente pour un objet : reprogrammation, annulation. */
    function annuler_envois(string $objet_type, int $objet_id): void
    {
        bd()->prepare("UPDATE notification SET etat = 'annule'
                       WHERE objet_type = ? AND objet_id = ? AND etat = 'en_attente'")
            ->execute([$objet_type, $objet_id]);
    }
}

if (!function_exists('courriel_smtp')) {
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
}

if (!function_exists('expediteur_technique')) {
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
}

if (!function_exists('courriel_adresses')) {
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
}

if (!function_exists('expedier_courriel')) {
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
        $texte = trim((string)($options['texte'] ?? '')) !== '' ? (string)$options['texte'] : courriel_texte($corps, $options);
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
}

if (!function_exists('courriel_message_id')) {
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
}

if (!function_exists('courriel_noter_message_id')) {
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
}

if (!function_exists('socle_colonne')) {
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
}

if (!function_exists('typographie_fr')) {
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
}

if (!function_exists('courriel_images')) {
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
                'alt'     => (string)($options[$nom . '_alt'] ?? ($nom === 'logo' ? courriel_theme()['marque'] : '')),
            ];
        }
        return $images;
    }
}

if (!function_exists('courriel_texte')) {
    /**
     * Version texte d'un message : le corps sans son balisage (voir courriel_html()). Un corps
     * sans balisage ressort tel quel, à la typographie près.
     *
     * Avec $options (2.2), le pied du message HTML suit, sous la marque de signature « -- » :
     * marque, site, adresse, phrase du pied et contact, comme dans la version HTML. C'est la forme
     * qu'expedier_courriel() envoie ; sans $options, le corps seul, comme avant.
     */
    function courriel_texte(string $corps, ?array $options = null): string
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
        $texte = implode("\n\n", $blocs);
        if ($options !== null) {
            $p = courriel_pied($options);
            $lignes = [$p['marque'] . ($p['site_url'] !== '' ? ' · ' . $p['site_url'] : '')];
            if ($p['adresse'] !== '') $lignes[] = $p['adresse'];
            if ($p['pied'] !== '') $lignes[] = $p['pied'];
            if ($p['contact'] !== '') $lignes[] = 'Contact : ' . $p['contact'];
            $texte .= "\n\n-- \n" . implode("\n", $lignes);
        }
        return typographie_fr($texte);
    }
}

if (!function_exists('courriel_theme')) {
    /**
     * Identité visuelle des courriels d'un projet (2.2) : marque, logo, couleurs, police, pied.
     *
     * Le socle n'impose aucune charte : chaque projet décrit la sienne. Sources, de la plus faible
     * à la plus forte (une clé absente garde la valeur de la source précédente) :
     *   1. l'habillage historique du socle (bandeau marine, bouton en pilule) ;
     *   2. les constantes MARQUE_NOM, MARQUE_BLEU, MARQUE_BLEU_CLAIR, MARQUE_ACCENT, CONTACT_COURRIEL
     *      (ou leur équivalent dans param(), voir socle_param()) ;
     *   3. la constante tableau COURRIEL_THEME du projet ;
     *   4. la fonction courriel_theme_projet(): array du projet, pour ce que l'équipe règle à l'écran
     *      (logo téléversé, couleur choisie) — appelée à chaque message, qu'elle reste légère.
     *
     * Clés :
     *   marque      nom affiché : texte de remplacement du logo, bandeau sans logo, pied ;
     *   logo        ['url' => adresse https ABSOLUE d'une image du site, 'largeur' => px affichés,
     *               'hauteur' => px affichés (facultatif : proportions du fichier), 'alt' => texte (défaut :
     *               la marque), 'url_sombre' => variante pour le mode sombre (facultative)] ;
     *   couleurs    bandeau, bandeau_texte, bouton, bouton_texte, accent, accent_texte, titre, lien, encre,
     *               doux, fond, carte, trait, encadre_fond, encadre_bord ;
     *   sombre      fond, carte, encre, doux, titre, lien, trait, encadre_fond, encadre_bord : les mêmes rôles en
     *               mode sombre. Le bandeau et les boutons, couleurs de marque, ne changent pas ;
     *   police      pile de polices, terminée par une police système ;
     *   police_web  ['nom' => …, 'url' => https://….woff2] : chargée par les messageries qui le permettent
     *               (Apple Mail, iOS) ; les autres prennent la pile ;
     *   pied        phrase du pied ; contact : adresse du pied ; site : ['url' => …, 'libelle' => …] ;
     *   adresse     ligne d'adresse postale du pied, facultative.
     *
     * Une couleur qui n'est pas un hexa (#abc, #aabbcc) et une adresse qui n'est pas en https sont
     * écartées au profit de la source précédente : un thème lu en base n'injecte rien dans le message.
     */
    function courriel_theme(): array
    {
        // Les constantes historiques peuvent venir de param(), donc de la base : vérifiées aussi.
        $couleur = fn(string $constante, string $defaut): string =>
            preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $v = socle_param($constante, $defaut)) ? $v : $defaut;
        $bleu = $couleur('MARQUE_BLEU', '#054570');
        $theme = [
            'marque'     => socle_param('MARQUE_NOM', 'Notification'),
            'logo'       => ['url' => '', 'largeur' => 0, 'hauteur' => 0, 'alt' => '', 'url_sombre' => ''],
            'couleurs'   => [
                'bandeau' => $bleu, 'bandeau_texte' => '#ffffff', 'bouton' => $bleu, 'bouton_texte' => '#ffffff',
                'accent' => $couleur('MARQUE_ACCENT', '#D03C0E'), 'accent_texte' => '#ffffff',
                'titre' => $bleu, 'lien' => '#1068B3', 'encre' => '#16222E', 'doux' => '#4C5C6A',
                'fond' => '#EDF1F5', 'carte' => '#ffffff', 'trait' => '#C8D5DF',
                'encadre_fond' => '#EEF5FA', 'encadre_bord' => $couleur('MARQUE_BLEU_CLAIR', $bleu),
            ],
            'sombre'     => [
                'fond' => '#0C1821', 'carte' => '#13232F', 'encre' => '#E7EDF2', 'doux' => '#9FB0BE',
                'titre' => '#9CC4E4', 'lien' => '#6AB1E8', 'trait' => '#2B4255',
                'encadre_fond' => '#173349', 'encadre_bord' => '#6AB1E8',
            ],
            'police'     => 'Helvetica,Arial,sans-serif',
            'police_web' => ['nom' => '', 'url' => ''],
            'pied'       => 'Message automatique. Il ne contient aucune donnée personnelle du dossier suivi.',
            'contact'    => socle_param('CONTACT_COURRIEL', defined('EMAIL_EXPEDITEUR') ? (string)EMAIL_EXPEDITEUR : ''),
            'site'       => ['url' => '', 'libelle' => ''],
            'adresse'    => '',
        ];
        $sources = [];
        if (defined('COURRIEL_THEME') && is_array(constant('COURRIEL_THEME'))) $sources[] = constant('COURRIEL_THEME');
        if (function_exists('courriel_theme_projet')) $sources[] = courriel_theme_projet();
        foreach ($sources as $source) $theme = courriel_theme_fusionner($theme, $source);
        return $theme;
    }
}

if (!function_exists('couleur_melange')) {
    /**
     * Mélange de deux couleurs hexa, comme color-mix(in srgb, $couleur $part, $avec) en CSS (2.2) : les
     * déclinaisons d'une charte (fond voilé, texte accentué) calculées comme sa feuille de style les
     * calcule, pour un courriel, qui n'a pas color-mix. $part de 0 à 1. Rend $couleur telle quelle si
     * l'une des deux n'est pas un hexa.
     */
    function couleur_melange(string $couleur, string $avec, float $part): string
    {
        $rvb = function (string $hexa): ?array {
            if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $hexa, $m)) return null;
            $h = strlen($m[1]) === 3 ? preg_replace('/(.)/', '$1$1', $m[1]) : $m[1];
            return array_map('hexdec', str_split((string)$h, 2));
        };
        [$a, $b] = [$rvb($couleur), $rvb($avec)];
        if ($a === null || $b === null) return $couleur;
        $part = max(0.0, min(1.0, $part));
        return '#' . implode('', array_map(fn(int $x, int $y): string =>
            sprintf('%02x', (int)round($x * $part + $y * (1 - $part))), $a, $b));
    }
}

if (!function_exists('courriel_theme_fusionner')) {
    /**
     * Pose sur $theme les valeurs valables de $source : mêmes clés, même type ; couleurs en hexa,
     * adresses en https, nom de police web en lettres simples. Ce qui ne passe pas est ignoré.
     */
    function courriel_theme_fusionner(array $theme, array $source): array
    {
        $hexa = fn($v): bool => is_string($v) && preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $v) === 1;
        $https = fn($v): bool => is_string($v) && preg_match('#^https://[^\s"\'<>()\\\\]+$#', $v) === 1;
        foreach (['marque', 'pied', 'contact', 'adresse'] as $cle) {
            if (is_string($source[$cle] ?? null) && !preg_match('/[\r\n<>]/', $source[$cle])) $theme[$cle] = trim($source[$cle]);
        }
        // La pile de polices entre telle quelle dans les attributs style : lettres, espaces, virgules, apostrophes.
        if (is_string($source['police'] ?? null) && preg_match('/^[\w ,\'-]+$/u', $source['police'])) $theme['police'] = trim($source['police']);
        foreach (['couleurs', 'sombre'] as $groupe) {
            foreach ((array)($source[$groupe] ?? []) as $cle => $valeur) {
                if (array_key_exists($cle, $theme[$groupe]) && $hexa($valeur)) $theme[$groupe][$cle] = $valeur;
            }
        }
        $logo = (array)($source['logo'] ?? []);
        foreach (['url', 'url_sombre'] as $cle) {
            if (array_key_exists($cle, $logo) && ($logo[$cle] === '' || $https($logo[$cle]))) $theme['logo'][$cle] = $logo[$cle];
        }
        foreach (['largeur', 'hauteur'] as $cle) {
            if (is_int($logo[$cle] ?? null) && $logo[$cle] >= 0 && $logo[$cle] <= 600) $theme['logo'][$cle] = $logo[$cle];
        }
        if (is_string($logo['alt'] ?? null)) $theme['logo']['alt'] = trim($logo['alt']);
        $web = (array)($source['police_web'] ?? []);
        if (is_string($web['nom'] ?? null) && preg_match('/^[\w -]{1,40}$/u', $web['nom']) && $https($web['url'] ?? null)) {
            $theme['police_web'] = ['nom' => $web['nom'], 'url' => $web['url']];
        }
        $site = (array)($source['site'] ?? []);
        if (($site['url'] ?? null) === '' || $https($site['url'] ?? null)) {
            $theme['site']['url'] = $site['url'];
            $theme['site']['libelle'] = is_string($site['libelle'] ?? null) ? trim($site['libelle']) : '';
        }
        return $theme;
    }
}

if (!function_exists('courriel_pied')) {
    /**
     * Le contenu du pied, commun aux versions HTML et texte : le thème, puis les options du message
     * (`pied`, `contact`, 1.7). Le libellé du site retombe sur son adresse sans « https:// ».
     *
     * @return array{marque:string, site_url:string, site_libelle:string, adresse:string, pied:string, contact:string}
     */
    function courriel_pied(array $options = []): array
    {
        $t = courriel_theme();
        $url = $t['site']['url'];
        return [
            'marque'       => $t['marque'],
            'site_url'     => $url,
            'site_libelle' => $t['site']['libelle'] !== '' ? $t['site']['libelle'] : rtrim((string)preg_replace('#^https://#', '', $url), '/'),
            'adresse'      => $t['adresse'],
            'pied'         => trim((string)($options['pied'] ?? '')) !== '' ? trim((string)$options['pied']) : $t['pied'],
            'contact'      => trim((string)($options['contact'] ?? '')) !== '' ? trim((string)$options['contact']) : $t['contact'],
        ];
    }
}

if (!function_exists('courriel_ligne_html')) {
    /**
     * Un morceau de texte en HTML : échappé, typographie française, puis le balisage en ligne
     * — [texte](https://…) devient un lien, **texte** du gras, *texte* de l'italique (un titre
     * d'œuvre). Seules les adresses http(s) deviennent des liens. $lien : couleur des liens (2.2).
     */
    function courriel_ligne_html(string $texte, string $lien = '#1068B3'): string
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
            '<a href="' . h($liens[(int)$m[1]][1]) . '" class="c-lien" style="color:' . h($lien) . '">'
            . $emphase(h(typographie_fr($liens[(int)$m[1]][0]))) . '</a>', $html) ?? $html;
    }
}

if (!function_exists('courriel_bouton')) {
    /**
     * Un bouton en pilule, lisible partout : le lien porte lui-même fond et marges, et Outlook pour
     * Windows, qui ignore les marges d'un lien, reçoit des espaces invisibles qui lui donnent la même
     * taille (technique publiée par Mark Robbins, « Good Email Code »).
     */
    function courriel_bouton(string $url, string $libelle, string $fond, string $texte, string $police, string $marge): string
    {
        $style = 'background:' . $fond . ';border-radius:999px;color:' . $texte . ';display:inline-block;font-family:' . $police
               . ';font-size:16px;font-weight:bold;line-height:20px;padding:14px 28px;text-decoration:none;mso-padding-alt:0;'
               . 'text-underline-color:' . $fond;
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:' . $marge . '">'
             . '<tr><td bgcolor="' . $fond . '" style="border-radius:999px;background:' . $fond . '">'
             . '<a href="' . h($url) . '" class="c-bouton" style="' . $style . '">'
             . '<!--[if mso]><i style="mso-font-width:150%;mso-text-raise:21pt" hidden>&emsp;</i><![endif]-->'
             . '<span style="mso-text-raise:10.5pt">' . h(typographie_fr($libelle)) . '</span>'
             . '<!--[if mso]><i style="mso-font-width:150%" hidden>&emsp;&#8203;</i><![endif]-->'
             . '</a></td></tr></table>';
    }
}

if (!function_exists('courriel_html')) {
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
     * L'habillage (bandeau, logo, couleurs, pied) vient du thème du projet, voir courriel_theme(),
     * et la mise en page de courriel_gabarit().
     *
     * Options du message (1.7, toutes facultatives) :
     *   apercu        texte d'aperçu, montré par la boîte de réception sous l'objet, masqué dans le message ;
     *   logo          chemin d'une image locale, intégrée au message (voir courriel_images()) : remplace
     *                 le logo du thème pour ce message ;
     *   bandeau       texte du bandeau (défaut : la marque) ; à droite du logo s'il y en a un ;
     *   illustration  chemin d'une image locale, pleine largeur sous le bandeau ;
     *   accent        adresses dont le bouton prend la couleur d'accent ;
     *   pied          phrase du pied de page (défaut : celle du thème) ;
     *   contact       adresse du pied (défaut : celle du thème) ;
     *   src_images    « cid » (défaut, pour l'envoi) ou « data » (aperçu dans un navigateur, qui
     *                 n'affiche pas les pièces jointes en ligne) ;
     *   texte         version texte fournie, à la place de celle que courriel_texte() tire du corps.
     */
    function courriel_html(string $sujet, string $corps, string $libelle_bouton = 'Ouvrir', array $options = []): string
    {
        $t = courriel_theme();
        $c = $t['couleurs'];
        $police = $t['police'];
        $adresses_accent = array_map('strval', is_array($options['accent'] ?? null) ? $options['accent'] : []);
        $style_texte = 'font-family:' . $police . ';color:' . $c['encre'] . ';';
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
                $accent = in_array($url, $adresses_accent, true);
                $blocs .= courriel_bouton($url, $libelle ?? $libelle_bouton, $accent ? $c['accent'] : $c['bouton'],
                                          $accent ? $c['accent_texte'] : $c['bouton_texte'], $police,
                                          $libelle === null ? '26px 0' : '6px 0 20px');
                // Le lien en clair accompagne le bouton sans libellé (un lien de connexion, souvent) ;
                // un bouton qui dit où il mène s'en passe, la version texte garde l'adresse.
                if ($libelle === null) {
                    $blocs .= '<p class="c-doux" style="margin:0 0 18px;font-family:' . $police . ';font-size:13px;line-height:1.5;'
                            . 'color:' . $c['doux'] . '">Si le bouton ne fonctionne pas, copiez cette adresse dans votre '
                            . 'navigateur :<br><span style="word-break:break-all">' . h($url) . '</span></p>';
                }
                continue;
            }

            if ($bloc === '---') {
                $blocs .= '<hr class="c-trait" style="border:0;border-top:1px solid ' . $c['trait'] . ';margin:26px 0 16px">';
                continue;
            }
            if (str_starts_with($bloc, '# ')) {
                $blocs .= '<h2 class="c-titre" style="margin:26px 0 8px;' . $style_texte . 'font-size:17px;line-height:1.4;font-weight:bold;'
                        . 'color:' . $c['titre'] . '">' . courriel_ligne_html(substr($bloc, 2), $c['lien']) . '</h2>';
                continue;
            }
            if (str_starts_with($bloc, '> ')) {
                $blocs .= '<p class="c-doux" style="margin:0 0 16px;font-family:' . $police . ';font-size:14px;line-height:1.55;color:'
                        . $c['doux'] . '">' . courriel_ligne_html(preg_replace('/^> ?/m', '', $bloc) ?? $bloc, $c['lien']) . '</p>';
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
                        . '<tr><td class="c-encadre" style="background:' . $c['encadre_fond'] . ';border-left:4px solid ' . $c['encadre_bord']
                        . ';border-radius:8px;padding:14px 18px">'
                        . ($titre !== [] ? '<p class="c-titre" style="margin:0 0 6px;' . $style_texte . 'font-size:15px;line-height:1.5;font-weight:bold;color:'
                                           . $c['titre'] . '">' . courriel_ligne_html(implode("\n", $titre), $c['lien']) . '</p>' : '')
                        . '<ul style="margin:0;padding-left:20px">'
                        . implode('', array_map(fn(string $p): string => '<li class="c-encre" style="margin:3px 0;' . $style_texte
                            . 'font-size:15px;line-height:1.5">' . courriel_ligne_html($p, $c['lien']) . '</li>', $puces))
                        . '</ul>'
                        . ($note !== [] ? '<p class="c-encre" style="margin:10px 0 0;' . $style_texte . 'font-size:14px;line-height:1.5">'
                                          . courriel_ligne_html(implode("\n", $note), $c['lien']) . '</p>' : '')
                        . '</td></tr></table>';
                continue;
            }

            $blocs .= '<p class="c-encre" style="margin:0 0 18px;' . $style_texte . 'font-size:16px;line-height:1.6">'
                    . courriel_ligne_html($bloc, $c['lien']) . '</p>';
        }

        return courriel_gabarit($sujet, $blocs, $options);
    }
}

if (!function_exists('courriel_gabarit')) {
    /**
     * La mise en page d'un message (2.2) : bandeau au logo, titre, contenu, pied, aux couleurs du
     * thème (courriel_theme()). courriel_html() y verse le corps balisé ; un projet qui compose
     * lui-même son contenu HTML (déjà échappé) l'y verse aussi, et garde ainsi le même habillage.
     *
     * Règles de messagerie suivies : tableaux, styles en ligne, largeur 600 px (fixée pour Outlook,
     * qui ignore max-width), polices sûres pour Outlook, texte de remplacement du logo aux couleurs
     * du bandeau (les messageries qui bloquent les images affichent encore la marque).
     *
     * Logo : celui du thème est une image du site, en adresse https absolue — rien n'alourdit le
     * message. Il doit être un fichier statique ou une page laissée ouverte par la porte de
     * démonstration, sans quoi la messagerie ne peut pas le charger. Le logo d'un message (option
     * `logo`, 1.7) voyage, lui, dans le message (cid:).
     *
     * Mode sombre : déclaré (color-scheme), puis chaque rôle reçoit sa couleur sombre du thème —
     * Apple Mail et iOS par prefers-color-scheme, Outlook.com par ses attributs data-ogsc/ogsb.
     * Gmail inverse lui-même ; le bandeau et les boutons, couleurs de marque, ne bougent pas.
     */
    function courriel_gabarit(string $sujet, string $contenu, array $options = []): string
    {
        $t = courriel_theme();
        $c = $t['couleurs'];
        $s = $t['sombre'];
        $police = $t['police'];
        $p = courriel_pied($options);
        $images = courriel_images($options);
        $src = function (array $image) use ($options): string {
            if (($options['src_images'] ?? 'cid') === 'data') {
                return 'data:' . $image['type'] . ';base64,' . base64_encode((string)file_get_contents($image['chemin']));
            }
            return 'cid:' . $image['cid'];
        };
        $texte_bandeau = trim((string)($options['bandeau'] ?? ''));
        $style_alt = 'font-family:' . $police . ';font-size:20px;font-weight:bold;color:' . $c['bandeau_texte'] . ';';

        // Bandeau : le logo (du message, sinon du thème) et le texte du bandeau, ou la marque seule.
        $logo = '';
        if (isset($images['logo'])) {
            $l = $images['logo'];
            $logo = '<img src="' . h($src($l)) . '" width="' . $l['largeur'] . '" height="' . $l['hauteur'] . '" alt="'
                  . h($l['alt']) . '" style="display:block;border:0;' . $style_alt . '">';
        } elseif ($t['logo']['url'] !== '') {
            $l = $t['logo'];
            $largeur = $l['largeur'] > 0 ? $l['largeur'] : 160;
            $dimensions = 'width="' . $largeur . '"' . ($l['hauteur'] > 0 ? ' height="' . $l['hauteur'] . '"' : '');
            $style_img = 'display:block;border:0;outline:none;width:' . $largeur . 'px;max-width:100%;height:auto;' . $style_alt;
            $alt = h($l['alt'] !== '' ? $l['alt'] : $t['marque']);
            $logo = '<img src="' . h($l['url']) . '" ' . $dimensions . ' alt="' . $alt . '" class="logo-clair" style="' . $style_img . '">';
            if ($l['url_sombre'] !== '') {
                $logo .= '<!--[if !mso]><!--><div class="logo-sombre" style="display:none;max-height:0;overflow:hidden">'
                       . '<img src="' . h($l['url_sombre']) . '" ' . $dimensions . ' alt="' . $alt . '" style="' . $style_img . '"></div><!--<![endif]-->';
            }
        }
        if ($logo !== '') {
            $bandeau = '<tr><td bgcolor="' . $c['bandeau'] . '" class="c-pad" style="background:' . $c['bandeau'] . ';padding:20px 32px">'
                     . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                     . '<td style="vertical-align:middle">' . $logo . '</td>'
                     . ($texte_bandeau !== '' ? '<td align="right" style="vertical-align:middle;font-family:' . $police . ';font-size:15px;'
                         . 'font-weight:bold;letter-spacing:.14em;color:' . $c['bandeau_texte'] . '">' . h($texte_bandeau) . '</td>' : '')
                     . '</tr></table></td></tr>';
        } else {
            $bandeau = '<tr><td bgcolor="' . $c['bandeau'] . '" class="c-pad" style="background:' . $c['bandeau'] . ';padding:22px 32px;'
                     . 'font-family:' . $police . ';font-size:19px;font-weight:bold;color:' . $c['bandeau_texte'] . '">'
                     . h($texte_bandeau !== '' ? $texte_bandeau : $t['marque']) . '</td></tr>';
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

        // Pied : marque et site, adresse, phrase, contact — les mêmes lignes que la version texte.
        $lien_pied = fn(string $href, string $texte): string => '<a href="' . h($href) . '" class="c-lien" style="color:' . $c['lien'] . '">' . h($texte) . '</a>';
        $pied = '<strong>' . h($p['marque']) . '</strong>'
              . ($p['site_url'] !== '' ? ' · ' . $lien_pied($p['site_url'], $p['site_libelle']) : '')
              . ($p['adresse'] !== '' ? '<br>' . h($p['adresse']) : '')
              . ($p['pied'] !== '' ? '<br>' . h(typographie_fr($p['pied'])) : '')
              . ($p['contact'] !== '' ? '<br>Contact : ' . $lien_pied('mailto:' . $p['contact'], $p['contact']) : '');

        // Feuilles séparées : une messagerie qui en rejette une (Gmail écarte ce qu'il ne lit pas)
        // garde les autres. Les styles en ligne restent la base ; ces règles ne font qu'ajuster.
        $sombre = '.c-fond{background:' . $s['fond'] . '!important}.c-carte{background:' . $s['carte'] . '!important}'
                . '.c-encre{color:' . $s['encre'] . '!important}.c-doux{color:' . $s['doux'] . '!important}'
                . '.c-titre{color:' . $s['titre'] . '!important}.c-lien{color:' . $s['lien'] . '!important}'
                . '.c-trait{border-color:' . $s['trait'] . '!important}'
                . '.c-encadre{background:' . $s['encadre_fond'] . '!important;border-color:' . $s['encadre_bord'] . '!important}';
        $ogsc = '[data-ogsc] .c-encre{color:' . $s['encre'] . '!important}[data-ogsc] .c-doux{color:' . $s['doux'] . '!important}'
              . '[data-ogsc] .c-titre{color:' . $s['titre'] . '!important}[data-ogsc] .c-lien{color:' . $s['lien'] . '!important}'
              . '[data-ogsb] .c-fond{background:' . $s['fond'] . '!important}[data-ogsb] .c-carte{background:' . $s['carte'] . '!important}'
              . '[data-ogsb] .c-encadre{background:' . $s['encadre_fond'] . '!important}';
        $feuilles = '<style>:root{color-scheme:light dark;supported-color-schemes:light dark}'
                  . 'a.c-bouton:hover{opacity:.9}'
                  . '@media (max-width:620px){.c-pad{padding-left:20px!important;padding-right:20px!important}.c-ext{padding:12px 6px!important}}</style>'
                  . '<style>@media (prefers-color-scheme:dark){' . $sombre
                  . ($t['logo']['url_sombre'] !== '' ? '.logo-clair{display:none!important}.logo-sombre{display:block!important;max-height:none!important}' : '')
                  . '}</style>'
                  . '<style>' . $ogsc . '</style>';
        if ($t['police_web']['url'] !== '') {
            $feuilles .= '<style>@font-face{font-family:\'' . $t['police_web']['nom'] . '\';font-style:normal;font-weight:100 900;'
                       . 'src:url(' . $t['police_web']['url'] . ') format(\'woff2\')}</style>';
        }

        return '<!doctype html><html lang="fr" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">'
             . '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
             . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
             . '<meta name="x-apple-disable-message-reformatting">'
             . '<title>' . h($sujet) . '</title>'
             . '<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>'
             . '<style>body,table,td,p,a,li,h1,h2,span{font-family:Arial,sans-serif!important}</style><![endif]-->'
             . $feuilles . '</head>'
             . '<body class="c-fond" style="margin:0;padding:0;background:' . $c['fond'] . '">' . $preentete
             . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="c-fond" bgcolor="' . $c['fond'] . '"'
             . ' style="background:' . $c['fond'] . '"><tr><td align="center" class="c-ext" style="padding:28px 12px">'
             . '<!--[if mso]><table role="presentation" width="600" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->'
             . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="c-carte" bgcolor="' . $c['carte'] . '"'
             . ' style="max-width:600px;background:' . $c['carte'] . ';border-radius:12px;overflow:hidden">'
             . $bandeau
             . '<tr><td class="c-pad" style="padding:32px"><h1 class="c-titre" style="margin:0 0 20px;font-family:' . $police . ';'
             . 'font-size:22px;line-height:1.3;color:' . $c['titre'] . '">' . h(typographie_fr($sujet)) . '</h1>' . $contenu . '</td></tr>'
             . '<tr><td class="c-pad c-doux c-trait" style="padding:18px 32px 26px;border-top:1px solid ' . $c['trait'] . ';font-family:' . $police . ';'
             . 'font-size:12px;line-height:1.6;color:' . $c['doux'] . '">' . $pied . '</td></tr>'
             . '</table>'
             . '<!--[if mso]></td></tr></table><![endif]-->'
             . '</td></tr></table></body></html>';
    }
}

if (!function_exists('file_traiter')) {
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
}
