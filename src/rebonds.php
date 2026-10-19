<?php
/**
 * socle-php — courriels non remis : les avis de non-remise (« rebonds ») lus dans la boîte d'envoi.
 *
 * Attend de l'application hôte :
 *   - bd(): PDO, maintenant()
 *   - la table `rebond` (voir sql/) ; facultatif, les colonnes `message_id` et `rebond_le` de
 *     `notification` (1.8) : la première rattache un avis au courriel exact, la seconde marque ce
 *     courriel « non remis ». Sans elles, le rapprochement se fait par l'adresse et la date, et
 *     rien n'est marqué.
 *   - ext-imap, et la constante REBONDS_IMAP_HOTE (secrets.php) : voir rebonds_reglages(). Le
 *     compte et le mot de passe sont ceux du SMTP, sauf constantes REBONDS_IMAP_* propres.
 *
 * Pourquoi ici plutôt qu'un paquet : la lecture IMAP est faite par ext-imap, et les paquets
 * d'analyse des avis encore trouvables ne sont plus maintenus. Ce qui reste — lire la partie
 * normalisée de l'avis (RFC 3464), dire si l'échec est définitif, retrouver le courriel parti
 * dans la file — tient en quelques fonctions et ne dépend d'aucun projet.
 *
 * Ce que le projet fait d'un échec lui appartient : rebonds_relever() rend les retours nouveaux
 * (le projet lève ses alertes, prévient son équipe), rebond_actif() dit si une adresse est en
 * échec définitif (le projet retient ses envois suivants), rebonds_lever() note qu'elle a été
 * vérifiée. Ce fichier n'écrit rien d'autre que `rebond` et `notification.rebond_le`.
 *
 * Deux gravités, d'après le code d'état (RFC 3463) :
 *   - « definitif » : code 5.x.x — boîte inexistante (5.1.1), domaine inconnu (5.1.2), refus (5.7.x)…
 *     Le courriel est perdu et le serveur ne réessaiera pas : l'adresse est à vérifier avant tout
 *     nouvel envoi ;
 *   - « temporaire » : code 4.x.x, ou simple avis de retard (Action: delayed) — boîte pleine,
 *     serveur injoignable. À surveiller ; rien à bloquer.
 * Un avis « failed » à code 4.x.x (le serveur a renoncé après plusieurs jours) marque le courriel
 * non remis, mais l'adresse reste en gravité temporaire : le problème n'était pas l'adresse.
 *
 * Lecture seule : la boîte est ouverte en OP_READONLY, les messages lus avec FT_PEEK — rien n'est
 * marqué lu, déplacé ni supprimé. Les personnes qui suivent la boîte voient les avis comme avant.
 *
 * PIÈGE — sur un mutualisé OVH, le nœud SSH bloque les connexions sortantes (IMAP comme SMTP) :
 * un essai lancé en session SSH échoue alors que le cron et le site, eux, sortent. Essayer depuis
 * le cron ou une page, jamais conclure depuis SSH.
 */
declare(strict_types=1);

/** Jours relus à chaque relevé : un avis déjà noté est sauté sans être téléchargé. */
const REBONDS_JOURS_DEFAUT = 10;

/** Critères de recherche des avis dans la boîte (chacun combiné à la date). */
const REBONDS_CRITERES = [
    'FROM "mailer-daemon"', 'FROM "postmaster"', 'SUBJECT "Undeliver"', 'SUBJECT "Delivery Status"',
    'SUBJECT "Delivery Failure"', 'SUBJECT "Returned mail"', 'SUBJECT "failure notice"',
    'SUBJECT "Mail delivery failed"', 'SUBJECT "non remis"', 'SUBJECT "Non distribu"',
];

// ---------------------------------------------------------------------------------------
// Réglages et lecture de la boîte
// ---------------------------------------------------------------------------------------

/**
 * Réglages IMAP, ou null si la lecture n'est pas configurée. Hôte : REBONDS_IMAP_HOTE (vide ou
 * absent = rien n'est lu). Compte : REBONDS_IMAP_UTILISATEUR, à défaut SMTP_UTILISATEUR — les avis
 * reviennent à l'adresse d'enveloppe, celle du compte qui s'authentifie en SMTP. Mot de passe :
 * REBONDS_IMAP_MOT_DE_PASSE, à défaut SMTP_MOT_DE_PASSE. Port 993 (TLS implicite), boîte INBOX.
 * @return array{hote:string, port:int, utilisateur:string, mot_de_passe:string, boite:string}|null
 */
function rebonds_reglages(): ?array
{
    $valeur = fn(string $c): string => defined($c) ? trim((string)constant($c)) : '';
    $hote = $valeur('REBONDS_IMAP_HOTE');
    if ($hote === '' || !function_exists('imap_open')) return null;
    $utilisateur = $valeur('REBONDS_IMAP_UTILISATEUR') !== '' ? $valeur('REBONDS_IMAP_UTILISATEUR') : $valeur('SMTP_UTILISATEUR');
    $mot_de_passe = defined('REBONDS_IMAP_MOT_DE_PASSE') && (string)REBONDS_IMAP_MOT_DE_PASSE !== ''
        ? (string)REBONDS_IMAP_MOT_DE_PASSE : (defined('SMTP_MOT_DE_PASSE') ? (string)SMTP_MOT_DE_PASSE : '');
    if ($utilisateur === '' || $mot_de_passe === '') return null;
    return ['hote' => $hote, 'port' => defined('REBONDS_IMAP_PORT') ? (int)REBONDS_IMAP_PORT : 993,
            'utilisateur' => $utilisateur, 'mot_de_passe' => $mot_de_passe,
            'boite' => $valeur('REBONDS_IMAP_BOITE') !== '' ? $valeur('REBONDS_IMAP_BOITE') : 'INBOX'];
}

/** La lecture des avis est-elle possible (extension, hôte, compte) ? Le banc d'essai l'est toujours. */
function rebonds_configure(): bool
{
    return isset($GLOBALS['rebonds_boite']) || rebonds_reglages() !== null;
}

/**
 * Lit dans la boîte les avis reçus depuis $jours jours. Lecture seule (OP_READONLY, FT_PEEK).
 * L'en-tête est lu d'abord : un avis dont l'identifiant figure dans $connus n'est pas téléchargé.
 * Remplaçable par $GLOBALS['rebonds_boite'] (banc d'essai), appelé avec ($jours, $max).
 * Le message d'erreur ne cite jamais le mot de passe.
 * @param array<string, mixed> $connus identifiants déjà notés, en clés
 * @return array{messages: list<array{cle:string, brut:string}>, erreur: ?string}
 */
function rebonds_boite_lire(int $jours, int $max = 200, array $connus = []): array
{
    if (isset($GLOBALS['rebonds_boite']) && is_callable($GLOBALS['rebonds_boite'])) {
        return ($GLOBALS['rebonds_boite'])($jours, $max);
    }
    $r = rebonds_reglages();
    if ($r === null) return ['messages' => [], 'erreur' => 'lecture des avis non configurée (REBONDS_IMAP_HOTE, ext-imap)'];
    $chemin = '{' . $r['hote'] . ':' . $r['port'] . '/imap/ssl}' . $r['boite'];
    // Un serveur muet ne doit pas retenir la tâche planifiée : attente bornée.
    imap_timeout(IMAP_OPENTIMEOUT, 15);
    imap_timeout(IMAP_READTIMEOUT, 30);
    $c = @imap_open($chemin, $r['utilisateur'], $r['mot_de_passe'], OP_READONLY, 1);
    if ($c === false) {
        $erreurs = imap_errors() ?: [];
        $detail = $erreurs !== [] ? (string)end($erreurs) : '';
        return ['messages' => [], 'erreur' => 'connexion IMAP impossible'
            . ($detail !== '' ? ' : ' . mb_substr(str_replace($r['mot_de_passe'], '***', $detail), 0, 150) : '')];
    }
    $depuis = gmdate('d-M-Y', time() - max(1, $jours) * 86400);
    $uids = [];
    foreach (REBONDS_CRITERES as $critere) {
        $trouves = @imap_search($c, 'SINCE "' . $depuis . '" ' . $critere, SE_UID);
        if (is_array($trouves)) foreach ($trouves as $uid) $uids[(int)$uid] = true;
    }
    ksort($uids);
    $validite = (int)(@imap_status($c, $chemin, SA_UIDVALIDITY)->uidvalidity ?? 0);
    $messages = [];
    foreach (array_slice(array_keys($uids), -$max) as $uid) {
        $entete = (string)@imap_fetchheader($c, $uid, FT_UID);
        $cle = rebond_identifiant($entete) ?: 'imap-' . $validite . '-' . $uid . '@' . $r['hote'];
        if (isset($connus[$cle])) continue;
        $brut = @imap_fetchbody($c, $uid, '', FT_UID | FT_PEEK);
        if (is_string($brut) && $brut !== '') $messages[] = ['cle' => $cle, 'brut' => $brut];
    }
    imap_errors();   // vide les avertissements accumulés (sinon émis à la fermeture)
    imap_close($c);
    return ['messages' => $messages, 'erreur' => null];
}

// ---------------------------------------------------------------------------------------
// Analyse d'un avis
// ---------------------------------------------------------------------------------------

/**
 * Déplie les lignes d'en-tête continuées (RFC 5322 : une ligne qui commence par une espace prolonge
 * la précédente). Seule une ligne non vide est prolongée : une ligne faite d'espaces reste un
 * séparateur de blocs.
 */
function rebond_deplier(string $texte): string
{
    return (string)preg_replace('/(?<=\S)[ \t]*\r?\n[ \t]+(?=\S)/', ' ', $texte);
}

/** L'identifiant (Message-ID) d'un bloc d'en-têtes, ou ''. */
function rebond_identifiant(string $entete): string
{
    $entete = rebond_deplier($entete);
    return preg_match('/^Message-ID:\s*(<[^<>\s]+>)/mi', $entete, $m) ? mb_substr($m[1], 0, 190) : '';
}

/**
 * Gravité d'un échec : 'definitif', 'temporaire', ou '' si l'avis annonce une remise réussie
 * (Action: delivered, relayed, expanded — ce n'est pas un échec).
 */
function rebond_gravite(string $action, string $statut, string $diagnostic = ''): string
{
    $action = strtolower(trim($action));
    if (in_array($action, ['delivered', 'relayed', 'expanded'], true)) return '';
    if ($action === 'delayed') return 'temporaire';
    $classe = $statut !== '' ? $statut[0] : (preg_match('/\b([45])\d\d\b/', $diagnostic, $m) ? $m[1] : '');
    if ($classe === '4') return 'temporaire';
    return 'definitif';   // 5.x.x, ou « failed » sans code : le serveur a renoncé
}

/** Réponse d'un serveur distant, en une ligne courte : préfixes « smtp; » et codes répétés retirés. */
function rebond_diagnostic_court(string $diagnostic): string
{
    $d = (string)preg_replace('/^\s*(smtp|x-postfix|x-unix);\s*/i', '', $diagnostic);
    // « 550-5.1.1 The email account… 550-5.1.1 double-checking… » : le code se répète à chaque ligne.
    $d = (string)preg_replace('/\b[245]\d\d[ -](?:[245]\.\d{1,3}\.\d{1,3}\s+)?/', '', $d);
    $d = trim((string)preg_replace('/\s+/', ' ', strip_tags($d)));
    return mb_substr($d, 0, 300);
}

/**
 * Analyse un avis de non-remise brut. Lit d'abord la partie normalisée (RFC 3464) : un bloc par
 * destinataire (Final-Recipient, Action, Status, Diagnostic-Code). À défaut — avis à l'ancienne,
 * en texte libre —, retient les adresses citées qui figurent parmi $connues (les destinataires
 * récents, jamais l'expéditeur) et le premier code d'état du texte.
 * Le courriel d'origine se reconnaît à son identifiant, cité dans la copie de ses en-têtes que
 * porte l'avis (Message-ID du corps, à ne pas confondre avec celui de l'avis lui-même).
 * @param array<string, mixed> $connues adresses en minuscules, en clés
 * @return array{cle:string, recu_le:?string, origine:list<string>, destinataires:list<array{adresse:string,
 *               gravite:string, non_remis:bool, statut:string, diagnostic:string}>}
 */
function rebond_analyser(string $brut, array $connues = []): array
{
    $morceaux = preg_split("/\r?\n\r?\n/", $brut, 2) ?: [$brut];
    $entete = rebond_deplier($morceaux[0]);
    $corps = rebond_deplier($morceaux[1] ?? '');
    $recu_le = null;
    // « Mon, 19 Oct 2026 11:53:20 +0200 (CEST) » : le commentaire final gêne strtotime().
    if (preg_match('/^Date:\s*(.+)$/mi', $entete, $m)
        && ($ts = strtotime(trim((string)preg_replace('/\s*\([^)]*\)\s*$/', '', trim($m[1]))))) !== false) {
        $recu_le = gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
    $origine = [];
    if (preg_match_all('/^(?:Message-ID|X-Original-Message-ID|Original-Message-ID):\s*(<[^<>\s]+>)/mi', $corps, $m)) {
        $origine = array_values(array_unique($m[1]));
    }

    $destinataires = [];
    foreach (preg_split('/\r?\n[ \t]*\r?\n/', $corps) ?: [] as $bloc) {
        if (!preg_match('/^Final-Recipient:\s*(?:[a-z0-9-]+;)?\s*<?([^\s<>;]+@[^\s<>;]+?)>?\s*$/mi', $bloc, $m)) continue;
        $champ = fn(string $nom): string => preg_match('/^' . $nom . ':\s*(.*)$/mi', $bloc, $c) ? trim($c[1]) : '';
        $statut = preg_match('/^([245]\.\d{1,3}\.\d{1,3})/', $champ('Status'), $s) ? $s[1] : '';
        $action = strtolower($champ('Action'));
        $diagnostic = $champ('Diagnostic-Code');
        $gravite = rebond_gravite($action, $statut, $diagnostic);
        if ($gravite === '') continue;
        $adresse = mb_strtolower($m[1]);
        $destinataires[$adresse] = ['adresse' => $adresse, 'gravite' => $gravite, 'non_remis' => $action !== 'delayed',
                                    'statut' => $statut, 'diagnostic' => rebond_diagnostic_court($diagnostic)];
    }

    // Avis sans partie normalisée : les adresses connues citées dans le texte.
    if ($destinataires === [] && !preg_match('/^Action:\s*(delivered|relayed|expanded)/mi', $corps)) {
        $texte = str_contains(strtolower($brut), 'quoted-printable') ? quoted_printable_decode($corps) : $corps;
        $statut = preg_match('/\b([45]\.\d{1,3}\.\d{1,3})\b/', $texte, $s) ? $s[1] : '';
        $retard = (bool)preg_match('/\b(delayed|will (?:be )?retr|retardé|nouvel essai|réessa)/i', $texte);
        $gravite = $retard && $statut === '' ? 'temporaire' : rebond_gravite('failed', $statut);
        $diagnostic = preg_match('/^.*(does not exist|doesn\'t exist|user unknown|unknown user|no such user|not found|'
                                 . 'n\'existe pas|introuvable|inconnu|rejected|refused|mailbox (?:full|unavailable)|'
                                 . 'quota|boîte pleine).*$/mi', $texte, $d) ? rebond_diagnostic_court($d[0]) : '';
        if (preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $texte, $m)) {
            foreach ($m[0] as $e) {
                $e = mb_strtolower($e);
                if (isset($connues[$e])) {
                    $destinataires[$e] = ['adresse' => $e, 'gravite' => $gravite, 'non_remis' => !$retard,
                                          'statut' => $statut, 'diagnostic' => $diagnostic];
                }
            }
        }
    }
    return ['cle' => rebond_identifiant($morceaux[0]), 'recu_le' => $recu_le, 'origine' => $origine,
            'destinataires' => array_values($destinataires)];
}

// ---------------------------------------------------------------------------------------
// Relevé, rapprochement, état d'une adresse
// ---------------------------------------------------------------------------------------

/**
 * Relève les avis des derniers jours ($jours = 0 : REBONDS_JOURS, sinon REBONDS_JOURS_DEFAUT) et
 * rapproche chacun du courriel parti : celui dont l'avis cite l'identifiant (colonne message_id),
 * à défaut le dernier courriel parti vers l'adresse (destinataire ou copie) avant l'avis. Un
 * courriel non remis reçoit notification.rebond_le (si la colonne existe). Chaque avis n'est
 * traité qu'une fois (clé = son identifiant). Ne journalise rien : le projet le fait, en nombres.
 * Les adresses d'envoi du projet (EMAIL_EXPEDITEUR, compte SMTP) ne sont jamais prises pour une
 * adresse en échec.
 * @return array{lus:int, nouveaux:int, definitifs:int, temporaires:int, rapproches:int, incompris:int,
 *               erreur:?string, retours:list<array<string, mixed>>}
 */
function rebonds_relever(int $jours = 0): array
{
    $bilan = ['lus' => 0, 'nouveaux' => 0, 'definitifs' => 0, 'temporaires' => 0, 'rapproches' => 0,
              'incompris' => 0, 'erreur' => null, 'retours' => []];
    if (!rebonds_configure()) {
        $bilan['erreur'] = 'lecture des avis non configurée (REBONDS_IMAP_HOTE, ext-imap)';
        return $bilan;
    }
    if ($jours <= 0) $jours = defined('REBONDS_JOURS') ? max(1, (int)REBONDS_JOURS) : REBONDS_JOURS_DEFAUT;

    $connus = array_fill_keys(bd()->query('SELECT DISTINCT cle FROM rebond')->fetchAll(PDO::FETCH_COLUMN), true);
    $lecture = rebonds_boite_lire($jours, 200, $connus);
    if ($lecture['erreur'] !== null) { $bilan['erreur'] = $lecture['erreur']; return $bilan; }
    $bilan['lus'] = count($lecture['messages']);
    if ($lecture['messages'] === []) return $bilan;

    // Les courriels partis sur la période (et un mois avant) : adresses connues, et rapprochement.
    $propres = [];
    foreach (['EMAIL_EXPEDITEUR', 'SMTP_UTILISATEUR', 'REBONDS_IMAP_UTILISATEUR'] as $c) {
        if (defined($c) && trim((string)constant($c)) !== '') $propres[mb_strtolower(trim((string)constant($c)))] = true;
    }
    $avec_id = socle_colonne('notification', 'message_id');
    $req = bd()->prepare('SELECT id, destinataire, ' . (socle_colonne('notification', 'copie') ? 'copie' : "'' AS copie")
                         . ', envoye_le FROM notification WHERE etat = ? AND envoye_le >= ? ORDER BY envoye_le DESC, id DESC');
    $req->execute(['envoye', gmdate('Y-m-d\TH:i:s\Z', time() - ($jours + 30) * 86400)]);
    $recents = [];
    foreach ($req->fetchAll(PDO::FETCH_ASSOC) as $n) {
        foreach (array_merge([(string)$n['destinataire']], preg_split('/[,;]/', (string)$n['copie']) ?: []) as $a) {
            $a = mb_strtolower(trim($a));
            if ($a !== '' && !isset($propres[$a])) $recents[$a][] = ['id' => (int)$n['id'], 'envoye_le' => (string)$n['envoye_le']];
        }
    }
    $par_id = $avec_id ? bd()->prepare('SELECT id FROM notification WHERE message_id = ? ORDER BY id DESC LIMIT 1') : null;
    $marquer = socle_colonne('notification', 'rebond_le')
        ? bd()->prepare('UPDATE notification SET rebond_le = ? WHERE id = ? AND rebond_le IS NULL') : null;
    $inserer = bd()->prepare('INSERT INTO rebond (cle, adresse, gravite, non_remis, statut, diagnostic, notification_id,
                                                  recu_le, releve_le) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

    foreach ($lecture['messages'] as $message) {
        $a = rebond_analyser($message['brut'], $recents);
        $cle = $a['cle'] !== '' ? $a['cle'] : $message['cle'];
        if (isset($connus[$cle])) continue;
        $connus[$cle] = true;
        $bilan['nouveaux']++;
        $recu_le = $a['recu_le'] ?? maintenant();
        $destinataires = array_values(array_filter($a['destinataires'], fn($d) => !isset($propres[$d['adresse']])));
        if ($destinataires === []) {
            // Avis non compris : gardé pour ne pas le relire, et compté pour qu'on aille voir.
            $inserer->execute([$cle, '', 'inconnu', 0, '', '', null, $recu_le, maintenant()]);
            $bilan['incompris']++;
            continue;
        }
        // Le courriel exact, si l'avis cite son identifiant et que la file l'a noté.
        $exact = null;
        if ($par_id !== null) {
            foreach ($a['origine'] as $id_origine) {
                $par_id->execute([$id_origine]);
                if (($trouve = $par_id->fetchColumn()) !== false) { $exact = (int)$trouve; break; }
            }
        }
        foreach ($destinataires as $d) {
            $notification_id = $exact;
            if ($notification_id === null) {
                // Le dernier courriel parti vers l'adresse avant l'avis (à une heure près : horloges).
                $limite = gmdate('Y-m-d\TH:i:s\Z', (int)strtotime($recu_le) + 3600);
                foreach ($recents[$d['adresse']] ?? [] as $n) {
                    if ($n['envoye_le'] <= $limite) { $notification_id = $n['id']; break; }
                }
            }
            if ($d['non_remis'] && $notification_id !== null && $marquer !== null) {
                $marquer->execute([$recu_le, $notification_id]);
                if ($marquer->rowCount() > 0) $bilan['rapproches']++;
            }
            try {
                $inserer->execute([$cle, $d['adresse'], $d['gravite'], $d['non_remis'] ? 1 : 0, $d['statut'],
                                   $d['diagnostic'], $notification_id, $recu_le, maintenant()]);
            } catch (PDOException) {
                continue;   // même avis, même adresse : déjà noté
            }
            $bilan[$d['gravite'] === 'definitif' ? 'definitifs' : 'temporaires']++;
            $bilan['retours'][] = $d + ['id' => (int)bd()->lastInsertId(), 'cle' => $cle,
                                        'notification_id' => $notification_id, 'recu_le' => $recu_le];
        }
    }
    return $bilan;
}

/**
 * Le dernier échec définitif d'une adresse qui n'a pas été levé, ou null : tant qu'il existe,
 * l'adresse est à vérifier avant tout nouvel envoi (ou création de compte qui en provoquerait un).
 * @return array<string, mixed>|null
 */
function rebond_actif(string $adresse): ?array
{
    $adresse = mb_strtolower(trim($adresse));
    if ($adresse === '') return null;
    $r = bd()->prepare("SELECT * FROM rebond WHERE adresse = ? AND gravite = 'definitif' AND leve_le IS NULL
                        ORDER BY recu_le DESC, id DESC LIMIT 1");
    $r->execute([$adresse]);
    return $r->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Les échecs de plusieurs adresses d'un coup, pour une liste : [adresse => dernier échec définitif
 * non levé]. Les adresses sans échec n'y figurent pas.
 * @param list<string> $adresses
 * @return array<string, array<string, mixed>>
 */
function rebonds_actifs(array $adresses): array
{
    $adresses = array_values(array_unique(array_filter(array_map(fn($a) => mb_strtolower(trim((string)$a)), $adresses))));
    if ($adresses === []) return [];
    $actifs = [];
    foreach (array_chunk($adresses, 400) as $lot) {
        $r = bd()->prepare("SELECT * FROM rebond WHERE gravite = 'definitif' AND leve_le IS NULL
                            AND adresse IN (" . implode(',', array_fill(0, count($lot), '?')) . ')
                            ORDER BY recu_le, id');
        $r->execute($lot);
        foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $l) $actifs[$l['adresse']] = $l;   // le plus récent l'emporte
    }
    return $actifs;
}

/** Tous les avis d'une adresse, les plus récents d'abord (levés compris). */
function rebonds_de(string $adresse): array
{
    $r = bd()->prepare('SELECT * FROM rebond WHERE adresse = ? ORDER BY recu_le DESC, id DESC');
    $r->execute([mb_strtolower(trim($adresse))]);
    return $r->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * L'adresse a été vérifiée (la famille confirme qu'elle est bonne, l'erreur venait d'ailleurs) :
 * ses échecs cessent de bloquer. Rien n'est supprimé. Retour : le nombre d'avis levés.
 */
function rebonds_lever(string $adresse, ?int $utilisateur_id = null): int
{
    $r = bd()->prepare('UPDATE rebond SET leve_le = ?, leve_par = ? WHERE adresse = ? AND leve_le IS NULL');
    $r->execute([maintenant(), $utilisateur_id, mb_strtolower(trim($adresse))]);
    return $r->rowCount();
}

/** Ce que dit un code d'état, en quelques mots (RFC 3463). */
function rebond_libelle(string $statut, string $gravite = 'definitif'): string
{
    $libelles = [
        '5.1.1' => 'boîte inexistante', '5.1.2' => 'domaine inconnu', '5.1.3' => 'adresse mal formée',
        '5.1.6' => 'boîte déplacée', '5.1.10' => 'domaine sans messagerie', '5.2.1' => 'boîte désactivée',
        '5.2.2' => 'boîte pleine', '5.3.4' => 'message trop volumineux', '4.2.2' => 'boîte pleine',
    ];
    if (isset($libelles[$statut])) return $libelles[$statut];
    if (str_starts_with($statut, '5.7.')) return 'refusé par le serveur du destinataire';
    if (str_starts_with($statut, '5.4.')) return 'acheminement impossible';
    if (str_starts_with($statut, '4.')) return 'remise retardée';
    return match ($gravite) {
        'temporaire' => 'remise retardée',
        'inconnu'    => 'avis non compris',
        default      => 'non remis',
    };
}
