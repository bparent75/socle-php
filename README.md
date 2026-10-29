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
| `src/formats.php` | Français : dates (« lundi 21 septembre 2026 à 14h30 »), montants, adresses lisibles, échappement |
| `src/securite.php` | Session et HTTPS derrière un proxy, jeton CSRF, limitation des tentatives, jetons hachés, liens signés |
| `src/courriel.php` | File d'envoi avec reprise, gabarit HTML, et le garde-fou des envois refusés en session SSH |
| `src/rebonds.php` | Courriels non remis (1.8) : avis de non-remise lus en IMAP sans rien marquer lu, échec définitif (5.x.x) ou temporaire (4.x.x), rapprochés du courriel parti, adresse en échec jusqu'à vérification |
| `src/journal.php` | Journal d'audit |
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
| Fonction `param(string $cle, string $defaut)` | Consultée avant les constantes : permet de rendre une valeur modifiable par l'équipe (adresse de contact, nom de marque) au lieu de la figer |
| Constante `NOM_SESSION` | Nom du cookie de session, sans avoir à le passer à chaque appel |
| Constantes `MARQUE_NOM`, `MARQUE_BLEU`, `CONTACT_COURRIEL` | Habillage des courriels |
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
   { "repositories": [{ "type": "vcs", "url": "https://github.com/bparent75/socle-php.git" }],
     "require": { "bparent75/socle-php": "^1.0" } }
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

## Tests

```bash
php tests.php
```

115 contrôles (107 sans `bacon/bacon-qr-code`) sur une base SQLite en mémoire : formats, signatures, limitation des
tentatives, unicité et annulation dans la file, garde-fou d'envoi en session SSH,
échappement et habillage du gabarit (boutons, encadrés, images intégrées), avis de non-remise (analyse, gravité,
rapprochement, boîte simulée), bornage du journal, porte de démonstration, QR code.
Aucun courriel envoyé ; seule une image d'un pixel est écrite dans le répertoire temporaire, puis effacée.

## Provenance

Extrait d'un site d'orientation scolaire, où chaque brique tourne en production et est
couverte par des tests. Voir l'historique de ce dépôt pour les corrections apportées depuis.
