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
| Second facteur TOTP | `spomky-labs/otphp` |
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
| `src/journal.php` | Journal d'audit |
| `src/qrcode.php` | `qr_svg()` : un QR code en SVG en ligne, accessible. L'encodage vient de `bacon/bacon-qr-code` |
| `src/porte_demo.php` | Rideau par mot de passe sur un site en construction, et interdiction d'indexation |
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

## Tests

```bash
php tests.php
```

53 contrôles sur une base SQLite en mémoire : formats, signatures, limitation des
tentatives, unicité et annulation dans la file, garde-fou d'envoi en session SSH,
échappement du gabarit, bornage du journal, porte de démonstration, QR code.
Aucun fichier touché sur disque, aucun courriel envoyé.

## Provenance

Extrait d'un site d'orientation scolaire, où chaque brique tourne en production et est
couverte par des tests. Voir l'historique de ce dépôt pour les corrections apportées depuis.
