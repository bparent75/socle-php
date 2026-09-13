# socle-php

Briques PHP réutilisables d'un projet à l'autre.

**PHP 8.2+, sans framework.** Ce dépôt ne contient que ce qui encode une façon de
travailler et qu'aucun paquet existant ne fait à notre place.

## Ce qui n'est PAS ici, et pourquoi

Depuis la décision du 13 septembre 2026 — *« si un bon code existe, autant l'utiliser »* —
les briques standard passent par Composer :

| Besoin | Paquet à utiliser |
|---|---|
| QR code | `bacon/bacon-qr-code` |
| PDF | `dompdf/dompdf` ou `mpdf/mpdf` |
| Envoi de courriel | `phpmailer/phpmailer` |
| Second facteur TOTP | `spomky-labs/otphp` |
| Tableur | `phpoffice/phpspreadsheet` |

N'ajoutez ici que ce qui n'a pas d'équivalent, et dites pourquoi dans l'en-tête du fichier.

## Contenu

| Fichier | Rôle |
|---|---|
| `src/formats.php` | Français : dates (« lundi 21 septembre 2026 à 14h30 »), montants, adresses lisibles, échappement |
| `src/securite.php` | Session et HTTPS derrière un proxy, jeton CSRF, limitation des tentatives, jetons hachés, liens signés |
| `src/courriel.php` | File d'envoi avec reprise, gabarit HTML, et le garde-fou des envois refusés en session SSH |
| `src/journal.php` | Journal d'audit |
| `src/porte_demo.php` | Rideau par mot de passe sur un site en construction, et interdiction d'indexation |
| `sql/` | Les tables dont ces briques ont besoin, en SQLite et en MySQL |

Chaque fichier indique en tête ce qu'il attend de l'application hôte (fonction `bd()`,
constante `SEL_SITE`, etc.). Aucun n'impose de structure de projet.

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
