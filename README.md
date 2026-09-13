# socle-php

Briques PHP réutilisables d'un projet à l'autre.

**PHP 8.2+, sans framework.** Ce dépôt ne contient que ce qui encode une façon de
travailler et qu'aucun paquet existant ne fait à notre place.

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
| `porte_demo(['entete_html' => …, 'css' => …, 'titre' => …])` | Logo et feuille de style du projet sur l'écran d'accès |

### Consommation par Composer et clés de déploiement

Un serveur ne peut associer qu'**une** clé de déploiement par dépôt GitHub. Pour qu'une
machine lise à la fois son projet et ce socle, il faut donc deux clés et deux alias dans
son `~/.ssh/config` — d'où l'URL en `git@github-socle:` dans les `composer.json` qui en
dépendent. Toute machine qui installe doit avoir cet alias.

## Utilisation

Trois façons, par ordre de préférence :

1. **Composer**, si le dépôt est accessible à la machine qui installe :
   ```json
   { "repositories": [{ "type": "vcs", "url": "git@github.com:bparent75/socle-php.git" }],
     "require": { "bparent75/socle-php": "^1.0" } }
   ```
   Sur mutualisé OVH, cela suppose une clé de déploiement dédiée à ce dépôt.

2. **Sous-module git** : `git submodule add git@github.com:bparent75/socle-php.git socle`

3. **Copie**, en notant la version reprise dans le `CLAUDE.md` du projet. Le plus simple,
   et acceptable tant que le socle bouge peu — mais il faut alors répercuter les
   corrections à la main.

## Tests

```bash
php tests.php
```

45 contrôles sur une base SQLite en mémoire : formats, signatures, limitation des
tentatives, unicité et annulation dans la file, garde-fou d'envoi en session SSH,
échappement du gabarit, bornage du journal, porte de démonstration.
Aucun fichier touché sur disque, aucun courriel envoyé.

## Provenance

Extrait d'un projet client, où chaque brique tourne en production et est couverte par
des tests. Voir l'historique de ce dépôt pour les corrections apportées depuis.
