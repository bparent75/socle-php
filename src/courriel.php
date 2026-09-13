<?php
/**
 * socle-php — file d'envoi de courriels, avec reprise et gabarit HTML.
 *
 * Attend de l'application hôte :
 *   - bd(): PDO, maintenant(), h()
 *   - constantes EMAIL_EXPEDITEUR, et éventuellement EMAIL_EXPEDITEUR_NOM, MARQUE_NOM,
 *     CONTACT_COURRIEL, MARQUE_BLEU
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
 * PIÈGE N°2 — la tâche planifiée d'un mutualisé peut ne tourner qu'une fois par heure.
 * Un lien de connexion valable 30 minutes doit donc partir immédiatement, depuis la
 * requête web : c'est le rôle du paramètre $urgent.
 */
declare(strict_types=1);

const SOCLE_MAX_TENTATIVES = 5;
const SOCLE_LOT_ENVOI = 50;

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
 */
function filer_courriel(string $destinataire, string $sujet, string $corps,
                        ?int $utilisateur_id = null, string $cle_unicite = '',
                        ?string $envoyer_apres = null, string $objet_type = '', ?int $objet_id = null,
                        bool $urgent = false): bool
{
    try {
        bd()->prepare('INSERT INTO notification (utilisateur_id, destinataire, sujet, corps, envoyer_apres,
                                                 objet_type, objet_id, cle_unicite, cree_le)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$utilisateur_id, $destinataire, $sujet, $corps,
                       $envoyer_apres ?? maintenant(), $objet_type, $objet_id,
                       $cle_unicite !== '' ? $cle_unicite : null, maintenant()]);
        $id = (int)bd()->lastInsertId();
    } catch (PDOException) {
        return false;   // doublon volontairement ignoré (clé d'unicité)
    }

    if ($urgent && PHP_SAPI !== 'cli' && $envoyer_apres === null) {
        $r = expedier_courriel($destinataire, $sujet, $corps);
        bd()->prepare('UPDATE notification SET etat = ?, tentatives = 1, envoye_le = ?, derniere_erreur = ?
                       WHERE id = ?')
            ->execute([$r['ok'] ? 'envoye' : 'en_attente', $r['ok'] ? maintenant() : null, $r['erreur'], $id]);
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
 * Remet un message au serveur de courrier.
 *
 * ATTENTION : « vrai » signifie que le message a été accepté, pas qu'il est arrivé.
 * Ne jamais présenter ce retour comme une preuve de réception.
 *
 * @return array{ok:bool, erreur:string}
 */
function expedier_courriel(string $destinataire, string $sujet, string $corps): array
{
    $html = courriel_html($sujet, $corps);
    $nom_expediteur = socle_param('EMAIL_EXPEDITEUR_NOM', 'Notification');
    $contact = socle_param('CONTACT_COURRIEL', EMAIL_EXPEDITEUR);

    if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isMail();
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->setFrom(EMAIL_EXPEDITEUR, $nom_expediteur);
            $mail->addAddress($destinataire);
            if ($contact !== EMAIL_EXPEDITEUR) $mail->addReplyTo($contact);
            $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
            $mail->Subject = $sujet;
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $corps;
            $mail->send();
            return ['ok' => true, 'erreur' => ''];
        } catch (Throwable $e) {
            return ['ok' => false, 'erreur' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    $entetes = "From: $nom_expediteur <" . EMAIL_EXPEDITEUR . ">\r\n"
             . "Reply-To: $contact\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0\r\n"
             . "Auto-Submitted: auto-generated";
    $sujet_encode = '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], '', $sujet)) . '?=';
    $ok = @mail($destinataire, $sujet_encode, wordwrap($corps, 78, "\r\n"), $entetes);
    return ['ok' => (bool)$ok, 'erreur' => $ok ? '' : 'mail() a renvoyé une erreur'];
}

/**
 * Met en forme un message. Le texte reste la version de référence : le HTML en dérive,
 * sans jamais rien ajouter au fond. Une ligne qui ne contient qu'une adresse devient un
 * bouton — c'est la seule interprétation faite du contenu.
 *
 * Sans image distante, volontairement : beaucoup de messageries les bloquent, et une
 * image hébergée par le site serait inaccessible tant qu'il est fermé.
 */
function courriel_html(string $sujet, string $corps, string $libelle_bouton = 'Ouvrir'): string
{
    $bleu = socle_param('MARQUE_BLEU', '#054570');
    $marque = socle_param('MARQUE_NOM', 'Notification');
    $contact = socle_param('CONTACT_COURRIEL', EMAIL_EXPEDITEUR);
    $police = 'Helvetica,Arial,sans-serif';
    $blocs = '';

    foreach (preg_split("/\r?\n\r?\n/", trim($corps)) ?: [] as $bloc) {
        $bloc = trim($bloc);
        if ($bloc === '') continue;
        if (preg_match('#^https?://\S+$#', $bloc)) {
            $blocs .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0">'
                    . '<tr><td style="border-radius:999px;background:' . $bleu . '">'
                    . '<a href="' . h($bloc) . '" style="display:inline-block;padding:14px 28px;font-family:'
                    . $police . ';font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;'
                    . 'border-radius:999px">' . h($libelle_bouton) . '</a></td></tr></table>'
                    . '<p style="margin:0 0 18px;font-family:' . $police . ';font-size:13px;line-height:1.5;'
                    . 'color:#4C5C6A">Si le bouton ne fonctionne pas, copiez cette adresse dans votre '
                    . 'navigateur :<br><span style="word-break:break-all">' . h($bloc) . '</span></p>';
            continue;
        }
        $blocs .= '<p style="margin:0 0 18px;font-family:' . $police . ';font-size:16px;line-height:1.6;'
                . 'color:#16222E">' . nl2br(h($bloc)) . '</p>';
    }

    return '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
         . '<meta name="viewport" content="width=device-width,initial-scale=1">'
         . '<title>' . h($sujet) . '</title></head><body style="margin:0;padding:0;background:#EDF1F5">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
         . ' style="background:#EDF1F5;padding:28px 12px"><tr><td align="center">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
         . ' style="max-width:580px;background:#ffffff;border-radius:12px;overflow:hidden">'
         . '<tr><td style="background:' . $bleu . ';padding:22px 30px;font-family:' . $police . ';'
         . 'font-size:19px;font-weight:bold;color:#ffffff">' . h($marque) . '</td></tr>'
         . '<tr><td style="padding:30px"><h1 style="margin:0 0 20px;font-family:' . $police . ';'
         . 'font-size:21px;line-height:1.3;color:' . $bleu . '">' . h($sujet) . '</h1>' . $blocs . '</td></tr>'
         . '<tr><td style="padding:18px 30px 26px;border-top:1px solid #C8D5DF;font-family:' . $police . ';'
         . 'font-size:12px;line-height:1.6;color:#4C5C6A">Message automatique. '
         . 'Il ne contient aucune donnée personnelle du dossier suivi.<br>Contact : '
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
        $r = expedier_courriel($n['destinataire'], $n['sujet'], $n['corps']);
        if ($r['ok']) {
            $pdo->prepare("UPDATE notification SET etat = 'envoye', envoye_le = ?, tentatives = tentatives + 1
                           WHERE id = ?")->execute([maintenant(), $n['id']]);
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
