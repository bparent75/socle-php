/* socle-php — contrôle de syntaxe PHP sans PHP local (équivalent de php -l).
   Usage : node chemin/vers/socle/outils/lint.js fichier.php [autres…] */
const fs = require('fs');
const path = require('path');
const engine = require(path.join(__dirname, 'node_modules/php-parser'));
const parser = new engine({ parser: { extractDoc: false, suppressErrors: false, version: 803 },
                            ast: { withPositions: true } });
let ko = 0;
for (const f of process.argv.slice(2)) {
  const src = fs.readFileSync(f, 'utf8');
  try {
    const ast = parser.parseCode(src, f);
    const errs = (ast.errors || []);
    if (errs.length) {
      ko++;
      console.log(`✗ ${path.basename(f)}`);
      for (const e of errs.slice(0, 5)) console.log(`   ligne ${e.line}: ${e.message}`);
    } else {
      console.log(`✓ ${path.basename(f)}`);
    }
  } catch (e) {
    ko++;
    console.log(`✗ ${path.basename(f)} — ${e.message}`);
  }
}
process.exit(ko ? 1 : 0);
