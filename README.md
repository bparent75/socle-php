# socle-php

Briques PHP réutilisables d'un projet à l'autre.

**PHP 8.2+, sans framework.** Ce dépôt ne contient que ce qui encode une façon de
travailler et qu'aucun paquet existant ne fait à notre place.

## ⚠ Dépôt public : ce qui n'y entre jamais

Ce qui est publié ici l'est **définitivement** — un contenu retiré survit dans les clones,
les forks et les caches. N'y mettez donc jamais :

- un secret, une clé, un jeton, un mot de passe, un sel de site, même « de test » ;
- un identifiant d'hébergement, un login SSH, un nom de serveur, un chemin personnel ;
- une adresse électronique réelle (les exemples prennent `@example.invalid`) ;
- une donnée d'un projet client : nom de client, contenu, capture, jeu de données.

Le socle ne lit aucun secret lui-même : il reçoit `SEL_SITE` et les autres valeurs de
l'application hôte, et ne s'en sert que dans des `hash_hmac`. Rien n'est jamais imprimé
ni journalisé.

**Un crochet de pré-commit refuse ce qui ressemble à une fuite.** À installer après chaque
clone — git ne le fait pas tout seul :

```bash
git config core.hooksPath .githooks
```

Une ligne relue et légitime peut porter le marqueur `socle:vu` pour être acceptée. Les
clés privées, les clés SSH et les jetons de service ne sont **jamais** exemptables.

## Ce qui n'est PAS ici, et pourquoi

Depuis la décision du 13 septembre 2026 — *« si un bon code existe, autant l'utiliser »* —
les briques standard passent par Composer :

| Besoin | Paquet à utiliser |
|---|---|
| QR code | `bacon/bacon-qr-code` — enveloppé par `src/qrcode.php`, voir ci-dessous |
| PDF | `dompdf/dompdf` ou `mpdf/mpdf` |
| Envoi de courriel | `phpmailer/phpmailer` |
| Second facteur TOTP | `src/totp.php` (1.9) — soixante lignes éprouvées sur Ipesup Orientation ; `spomky-labs/otphp` ajouterait trois paquets pour le même service |
| Tableur | `phpoffice/phpspreadsheet` |

N'ajoutez ici que ce qui n'a pas d'équivalent, et dites pourquoi dans l'en-tête du fichier.

**Une enveloppe de dix lignes autour d'un paquet reste légitime** quand elle évite de
réécrire le même raccordement dans chaque projet : c'est le cas de `src/qrcode.php`, qui
laisse à `bacon/bacon-qr-code` toute l'arithmétique et ne garde que le rendu SVG accessible.

## Contenu

| Fichier | Rôle |
|---|---|
| `src/formats.php` | Français : dates (« lundi 21 septembre 2026 à 14h30 »), heures toujours avec leurs minutes (« 19h00 », `heure_fr`, 2.1), montants en centimes (négatifs compris), nombres (`nombre_fr`, 2.0), accord (`accorder`), initiales, retrait d'accents, adresses lisibles, échappement |
| `src/securite.php` | Session et HTTPS derrière un proxy, jeton CSRF, limitation des tentatives, jetons hachés, liens signés à expiration, liens signés **révocables par version** (`signature_revocable`, 2.0) |
| `src/connexion.php` | Connexion par lien magique et rôles (2.0) : `utilisateur_courant()`, `exiger_connexion()`, `exiger_admin()`, amorçage des admins — voir plus bas |
| `src/parametres.php` | `param()` / `param_definir()` : valeurs modifiables par l'équipe, table `config` (2.0) |
| `src/bd.php` | SQL portable SQLite/MySQL : `sql_insert_ignore()`, `sql_sur_doublon()`, et la migration additive `colonne_assurer()` (2.0) |
| `src/csv.php` | Export CSV pour Excel en français (2.0) : BOM UTF-8, séparateur `;`, cellules-formules neutralisées |
| `src/ical.php` | Export iCal RFC 5545 (2.0) : heures locales Europe/Paris, pliage qui respecte l'UTF-8 |
| `src/courriel.php` | File d'envoi avec reprise, gabarit HTML, et le garde-fou des envois refusés en session SSH |
| `src/rebonds.php` | Courriels non remis (1.8) : avis de non-remise lus en IMAP sans rien marquer lu, échec définitif (5.x.x) ou temporaire (4.x.x), rapprochés du courriel parti, adresse en échec jusqu'à vérification |
| `src/journal.php` | Journal d'audit |
| `src/cron.php` | Surveillance de la tâche planifiée (2.1) : témoin de passage `cron_temoin()`, et `cron_surveiller()` qui écrit à l'équipe si elle se tait — voir plus bas |
| `src/flash.php` | Messages flash (2.1) : `flash('ok', '…')` avant une redirection, `afficher_flashs()` sur la page suivante |
| `src/validation.php` | Validation de formulaire (2.1) : `formulaire_valider()` contrôle, convertit (montant en centimes, date en AAAA-MM-JJ…) et rend les messages en français ; `formulaire_erreur()` / `formulaire_aria()` pour les afficher accessibles |
| `src/image.php` | Image téléversée (2.1) : `image_recevoir($_FILES['photo'], $dossier)` — type lu dans le contenu, réencodage GD (métadonnées et charges glissées éliminées), réduction, nom tiré au hasard |
| `src/qrcode.php` | `qr_svg()` : un QR code en SVG en ligne, accessible. L'encodage vient de `bacon/bacon-qr-code` |
| `src/porte_demo.php` | Rideau par mot de passe sur un site en construction, et interdiction d'indexation |
| `src/totp.php` | Second facteur TOTP, RFC 6238 (1.9) : secret, code à six chiffres, vérification à fenêtre ±30 s, adresse `otpauth://` à donner à `qr_svg()`. Fonctions gardées par `function_exists` tant qu'Ipesup Orientation porte sa propre copie |
| `sql/` | Les tables dont ces briques ont besoin, en SQLite et en MySQL |

Chaque fichier indique en tête ce qu'il attend de l'application hôte (fonction `bd()`,
constante `SEL_SITE`, etc.). Aucun n'impose de structure de projet.

### Points d'extension

Le socle s'adapte au projet plutôt que l'inverse :

| Attente | Effet |
|---|---|
| Fonction `param(string $cle, string $defaut)` | Consultée avant les constantes : permet de rendre une valeur modifiable par l'équipe (adresse de contact, nom de marque) au lieu de la figer. Depuis la 2.0 le socle la fournit (`src/parametres.php`, table `config`) ; un projet qui a la sienne la garde (gardes `function_exists`) |
| Constante `NOM_SESSION` | Nom du cookie de session, sans avoir à le passer à chaque appel |
| Constante `CHEMIN_SESSION` (2.0) | Chemin du cookie de session (« /calendrier/ » pour un site en sous-répertoire) ; « / » à défaut. Aussi en second paramètre de `demarrer_session()` |
| Constante `ADMINS_AMORCAGE` (2.0) | Comptes administrateurs créés s'ils n'existent pas (`amorcer_admins()`) : liste d'adresses, ou tableau adresse => nom. Les adresses en `example.invalid` (modèle recopié) sont ignorées |
| Constantes `DUREE_LIEN_MAGIQUE`, `URL_CONNEXION` (2.0) | Durée du lien de connexion (1800 s à défaut) et page qui le reçoit (`URL_BASE . '/connexion.php'` à défaut) |
| Constantes `MARQUE_NOM`, `MARQUE_BLEU`, `CONTACT_COURRIEL` | Habillage des courriels |
| Constante `ALERTE_COURRIEL` (2.1) | Destinataire des alertes de `cron_surveiller()` ; `CONTACT_COURRIEL` à défaut |
| Constantes `MARQUE_ACCENT`, `MARQUE_BLEU_CLAIR` (1.7) | Couleur des boutons d'accent (défaut `#E74310`) et filet des encadrés (défaut : `MARQUE_BLEU`) |
| Constante `EMAIL_EXPEDITEUR_TECHNIQUE` | Adresse « ne pas répondre » des seuls messages techniques (`expediteur_technique()`, à passer en paramètre `$expediteur` de `filer_courriel`) ; `EMAIL_EXPEDITEUR` reste l'adresse humaine |
| Colonnes `repondre_a`, `copie` (1.5) | Reply-To et Cc propres à un message (deux derniers paramètres de `filer_courriel`), par exemple la personne de l'équipe qui a rédigé un envoi groupé. Depuis la 1.6, `copie` accepte plusieurs adresses séparées par des virgules (une famille dans un seul message) : passer la colonne en `TEXT`. Facultatives : sans elles, la file fonctionne comme avant tant qu'on ne les utilise pas |
| Colonne `options` (1.7) | Habillage propre à un message (dernier paramètre de `filer_courriel`, en JSON) : texte d'aperçu masqué, logo et illustration **intégrés au message** (pièces jointes en ligne `cid:`, fichiers locaux PNG/JPEG/GIF d'1 Mo au plus), boutons de couleur d'accent, texte du bandeau, pied et adresse de contact du pied, nom d'expéditeur. Facultative, comme les deux précédentes. Détail dans l'en-tête de `courriel_html()`. Depuis la 1.7.1, l'identifiant du message (Message-ID) porte le domaine de l'expéditeur, plus le nom du serveur |
| Balisage du corps (1.7) | Le texte reste la référence ; quelques conventions l'habillent en HTML et disparaissent de la version texte (`courriel_texte()`) : `Libellé : https://…` seul sur sa ligne = bouton qui porte ce libellé, `# ` = intertitre, lignes `- ` = encadré à puces (titre avant, note après), `> ` = note discrète, `---` = filet, et dans le texte `[texte](https://…)`, `**gras**`, `*italique*`. Typographie française (espaces insécables) appliquée au rendu |
| Colonnes `message_id`, `rebond_le` (1.8) | L'identifiant (Message-ID) de chaque message remis est noté dans `message_id` ; un avis de non-remise qui le cite retrouve ainsi le courriel exact, marqué dans `rebond_le`. Facultatives : sans elles, rien n'est noté et le rapprochement se fait par l'adresse et la date. **Sur une base existante, le projet les ajoute lui-même** (`ALTER TABLE notification ADD COLUMN …`), avec un index sur `message_id` : `sql/` ne le fait pas, il s'exécute avant les migrations du projet |
| Constante `REBONDS_IMAP_HOTE` (1.8) | Active la lecture des avis de non-remise (`rebonds_relever()`, à appeler par la tâche planifiée). Compte et mot de passe : ceux du SMTP, sauf `REBONDS_IMAP_UTILISATEUR` / `REBONDS_IMAP_MOT_DE_PASSE` ; `REBONDS_IMAP_PORT` (993), `REBONDS_IMAP_BOITE` (INBOX), `REBONDS_JOURS` (10 jours relus). Extension `imap` requise |
| Constantes `SMTP_HOTE`, `SMTP_PORT`, `SMTP_UTILISATEUR`, `SMTP_MOT_DE_PASSE` | Envoi par SMTP (PHPMailer) au lieu du `mail()` de l'hébergement — indispensable quand le SPF du domaine ne cite pas l'hébergeur ; port 465 = TLS implicite, sinon STARTTLS |
| `porte_demo(['entete_html' => …, 'css' => …, 'titre' => …])` | Logo et feuille de style du projet sur l'écran d'accès |

### Pourquoi ce dépôt est public

Un projet qui dépend d'un dépôt privé n'est pas transmissible : `composer install` échoue
chez qui n'y a pas accès. Le socle ne contient ni secret ni logique métier — le rendre
public est ce qui garde transférables les projets qui s'en servent.

## Utilisation

Trois façons, par ordre de préférence :

1. **Composer**. Le dépôt étant public, aucune clé n'est nécessaire :
   ```json
   { "repositories": [{ "type": "vcs", "url": "https://github.com/bparent75/socle-php.git",
                        "no-api": true }],
     "require": { "bparent75/socle-php": "^2.1" } }
   ```

2. **Sous-module git** : `git submodule add https://github.com/bparent75/socle-php.git socle`

3. **Copie**, en notant la version reprise dans le `CLAUDE.md` du projet. Le plus simple,
   et acceptable tant que le socle bouge peu — mais il faut alors répercuter les
   corrections à la main.

## Courriels non remis (1.8)

Un serveur qui refuse un message (boîte inexistante, domaine inconnu…) renvoie un **avis de
non-remise** à l'adresse d'enveloppe, c'est-à-dire au compte SMTP. `rebonds_relever()` lit ces
avis dans cette boîte par IMAP, **en lecture seule** (rien n'est marqué lu, déplacé ni supprimé),
lit leur partie normalisée (RFC 3464 : `Final-Recipient`, `Action`, `Status`, `Diagnostic-Code`)
et note chaque échec dans la table `rebond`.

- **Gravité** : `definitif` pour un code 5.x.x (le serveur ne réessaiera pas : l'adresse est à
  vérifier), `temporaire` pour un code 4.x.x ou un avis de retard. Un abandon sur un code 4.x.x
  marque le courriel non remis sans mettre l'adresse en cause.
- **Rapprochement** : par l'identifiant du message d'origine, que l'avis cite et que la file a
  noté (`message_id`) ; à défaut, le dernier courriel parti vers l'adresse (destinataire ou copie).
- **Ce que le projet en fait** lui revient : `rebonds_relever()` rend les retours nouveaux (alerte,
  équipe prévenue), `rebond_actif($adresse)` dit si une adresse est en échec définitif (retenir les
  envois suivants), `rebonds_lever($adresse)` note qu'elle a été vérifiée. Le socle ne journalise
  rien et n'écrit que `rebond` et `notification.rebond_le`.
- **Le DNS ne suffit pas** : une adresse dont le domaine existe mais dont la boîte n'existe pas
  (5.1.1) ne se révèle que par un avis.
- **Piège OVH** : le nœud SSH d'un mutualisé ne sort pas (IMAP comme SMTP). Un essai de
  connexion se fait depuis la tâche planifiée ou une page, jamais depuis une session SSH.

## Connexion par lien magique et rôles (2.0)

La brique était réécrite dans cinq projets ; ses décisions sont figées dans
`src/connexion.php` : aucun mot de passe (un lien à usage unique, 30 minutes, envoyé
par courriel), jeton haché en base (jamais en clair), réponse identique que l'adresse
soit connue ou non, anti-abus **en base** par adresse et par appelant (jamais en
session), consommation **atomique** du jeton, identifiant de session régénéré.

Page de connexion type :

```php
if (($_POST['action'] ?? '') === 'demander') {
    verifier_csrf();
    $ok = lien_magique_envoyer((string)($_POST['courriel'] ?? ''));
    $message = $ok ? 'Si cette adresse est connue, un lien vient de lui être envoyé.'
                   : 'Trop de demandes : réessayez dans un quart d’heure.';
}
if (isset($_GET['jeton']) && lien_magique_consommer((string)$_GET['jeton']) !== null) {
    header('Location: index.php');
    exit;
}
```

Chaque page privée commence par `exiger_connexion()`, `exiger_role('equipe')` ou
`exiger_admin()` (le rôle `admin` passe partout ; les autres valeurs appartiennent au
projet). `dernier_admin_actif()` empêche de désactiver le dernier compte
d'administration. Un projet à rôles multiples ou par saison garde sa propre
implémentation : toutes ces fonctions sont gardées par `function_exists`.

## Cron, flash, formulaires, images (2.1)

**Heures : « 19h00 », jamais « 19h ».** Décision du 30 octobre 2026 (chantier 4.4) : les
minutes s'écrivent toujours, sur deux chiffres. `date_fr()` la suit depuis la 2.1 (« à 14h00 »
au lieu de « à 14h ») ; `heure_fr('19:00')` met en forme une heure d'horaire, sans fuseau,
comme les copies du calendrier et des inscriptions qu'elle a vocation à remplacer. Dans le
même lot : `euros()` écrit enfin un montant négatif (« -10,50 € », il donnait « -10,-50 € »),
et `nombre_fr($n, 0)` ne mange plus les zéros de la partie entière (« 40 », il donnait « 4 »).

**Surveillance du cron.** Une tâche planifiée de mutualisé peut s'arrêter sans bruit (chemin
PHP changé, tâche désactivée) : la file de courriels ne part plus, et on le découvre des
semaines après. En tête de `cron.php`, `cron_temoin()` note le passage (clé
`cron_dernier_passage` de la table `config`, celle que lisent déjà les écrans des inscriptions).
Dans une page que l'équipe ouvre souvent, `cron_surveiller('cron', 2 * 3600)` écrit à
`ALERTE_COURRIEL` si la tâche se tait depuis plus de deux heures : envoi immédiat (la file ne
partirait pas, puisque c'est le cron qui la traite), **une seule fois par épisode** de silence,
réclamé par un `UPDATE` conditionnel pour que deux requêtes simultanées n'écrivent pas deux
fois. Une tâche qui n'a jamais tourné n'alerte pas (copie locale) ; `cron_situation()` la dit
en retard, pour que l'écran affiche « jamais ». Plusieurs tâches se suivent par leur nom
(`cron_temoin('rebonds')`). Libris garde son suivi plus fin (début et fin de passage, au journal).

**Messages flash.** `flash($type, $texte)` puis `afficher_flashs()` : même forme que la copie
de la nouvelle identité ; une valeur texte seule (forme du site de Tristan) est encore lue.
Une erreur est annoncée par `role="alert"`, le reste par `role="status"`.

**Validation de formulaire.** Les règles tiennent sur une ligne par champ ; l'exemple complet
est en tête de `src/validation.php`. Ce qu'il faut savoir : un montant se lit comme une
chaîne et sort **en centimes** (`0,29` donne 29, jamais 28,999…) ; `min:`/`max:` portent sur
la longueur d'un texte, la valeur d'un nombre (en euros pour un montant), la borne d'une date ;
un champ absent des règles n'est **jamais** rendu (pas d'écriture en base d'un champ que le
formulaire n'était pas censé envoyer) ; la saisie fautive est rendue telle quelle pour être
réaffichée. Les noms sont préfixés `formulaire_` : `valider()` existe déjà ailleurs.

**Image téléversée.** `image_recevoir($_FILES['photo'] ?? [], $dossier, ['max_largeur' => 1200])`
rend `['ok', 'erreur', 'nom', 'chemin', 'largeur', 'hauteur', 'type']`. Le type est lu dans le
contenu (fileinfo et getimagesize doivent concorder) ; l'image est toujours décodée puis
réencodée par GD, ce qui élimine la position GPS d'une photo de téléphone comme un script
glissé dans un fichier polyglotte ; SVG refusé ; taille en pixels bornée **avant** décodage ;
photo redressée selon son drapeau EXIF ; nom tiré au hasard. `image_enregistrer()` fait le
même travail sur un fichier déjà présent (import, tests). Les inscriptions ont leur propre
`image_televersee()`, d'où un autre nom ici.

## Harnais de vérification locale : `outils/` (2.0)

Pour les postes sans PHP (PHP-WASM). Publié une fois ici, au lieu de trois copies
reliées par chemins relatifs entre dépôts :

```bash
(cd outils && npm install)      # une fois par clone (versions épinglées)
node outils/executer-cli.mjs tests.php                      # depuis le socle
RACINE="$(pwd)" node vendor/bparent75/socle-php/outils/executer-cli.mjs tests.php   # depuis un projet
node outils/lint.js src/*.php   # syntaxe seule (php-parser), rapide
```

`outils/banc.php` publie `verifie()`, `titre()` et `bilan()` pour les `tests.php` des
projets. Le lanceur ne monte **que la racine du projet** et sort explicitement — les
deux causes de la panne du 16 septembre 2026 (chargement suspendu par le montage du
disque entier, boucle d'événements jamais rendue). La vérification qui fait foi reste
`php tests.php` sur le serveur.

## Tests

```bash
php tests.php                           # sur un poste avec PHP
node outils/executer-cli.mjs tests.php  # sans PHP (PHP-WASM, voir ci-dessus)
```

235 contrôles (227 sans `bacon/bacon-qr-code`) sur une base SQLite en mémoire : formats, signatures, limitation des
tentatives, unicité et annulation dans la file, garde-fou d'envoi en session SSH,
échappement et habillage du gabarit (boutons, encadrés, images intégrées), avis de non-remise (analyse, gravité,
rapprochement, boîte simulée), bornage du journal, porte de démonstration, paramètres,
migrations additives, connexion par lien magique, export CSV, liens révocables, iCal,
surveillance du cron, messages flash, validation de formulaire, image téléversée, TOTP, QR code.
Aucun courriel envoyé ; seules quelques images d'essai sont écrites dans le répertoire temporaire, puis effacées.

## Provenance

Extrait d'un site d'orientation scolaire, où chaque brique tourne en production et est
couverte par des tests. Voir l'historique de ce dépôt pour les corrections apportées depuis.
