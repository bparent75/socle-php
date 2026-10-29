/* socle-php — exécute un script « ligne de commande » d'un projet sous PHP-WASM,
   pour vérification locale sur un poste sans PHP.

   Usage, depuis n'importe quel projet :
       RACINE="$(pwd)" node chemin/vers/socle/outils/executer-cli.mjs tests.php [args…]
   Depuis le socle lui-même, RACINE est facultatif (défaut : le dossier parent d'outils/).

   Le garde-fou PHP_SAPI et l'absence de $argv sont traités dans une COPIE temporaire :
   le code du dépôt n'est jamais modifié.

   Seule la racine du projet est montée dans la machine virtuelle. Ne PAS revenir à
   useHostFilesystem() : monter tout le disque suspend le chargement indéfiniment
   (panne du 16 septembre 2026, élucidée le 29 octobre 2026). */
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ICI = path.dirname(fileURLToPath(import.meta.url));
const RACINE = process.env.RACINE ?? path.dirname(ICI);
const VERSION = process.env.VERSION_PHP ?? '8.3';
const cible = process.argv[2];
const args = process.argv.slice(3);

if (!cible || !fs.existsSync(`${RACINE}/${cible}`)) {
  console.error(`Usage : RACINE=/chemin/du/projet node executer-cli.mjs script.php [args…]`);
  console.error(`Introuvable : ${RACINE}/${cible ?? ''}`);
  process.exit(2);
}

let src = fs.readFileSync(`${RACINE}/${cible}`, 'utf8');
// Neutralise le seul garde-fou incompatible avec le harnais, sans rien retirer d'autre.
src = src.replace(/PHP_SAPI !== 'cli'/g, 'false');
const argvPhp = `$argv = [${[cible, ...args].map((a) => `'${a.replace(/'/g, "\\'")}'`).join(', ')}]; $argc = ${args.length + 1};`;
src = src.replace('declare(strict_types=1);', `declare(strict_types=1);\n${argvPhp}`);

const tmp = `${RACINE}/.cli-temporaire.php`;
fs.writeFileSync(tmp, src);
let code = 1;
try {
  // processId obligatoire depuis @php-wasm/node 3.x (verrouillage de fichiers).
  const id = await loadNodeRuntime(VERSION, { emscriptenOptions: { processId: 1 } });
  const php = new PHP(id);
  php.mkdir('/projet');
  await php.mount('/projet', createNodeFsMountHandler(RACINE));
  php.chdir('/projet');
  const r = await php.run({ scriptPath: `/projet/${path.basename(tmp)}`, env: { QUERY_STRING: '' } });
  process.stdout.write(r.text);
  if (r.errors) process.stderr.write(r.errors);
  code = r.exitCode;
} catch (e) {
  console.error(e.response ? new TextDecoder().decode(e.response.bytes) : (e.stack ?? e.message));
} finally {
  fs.unlinkSync(tmp);
}
// Sortie explicite : la machine virtuelle garde la boucle d'événements de node vivante
// même une fois le script fini — sans cet exit, le processus ne rend jamais la main
// (c'était le vrai visage de la « panne du 16 septembre 2026 »).
process.exit(code);
