<?php
/**
 * socle-php — validation de formulaire : contrôle, conversion et messages en français.
 *
 * Attend de l'application hôte : h(), date_simple_fr() et heure_fr() (src/formats.php). Rien d'autre.
 *
 *   $f = formulaire_valider($_POST, [
 *       'nom'      => ['requis', 'max:120'],
 *       'courriel' => ['requis', 'courriel'],
 *       'tarif'    => ['requis', 'montant', 'max:2000'],      // 12,50 → 1250 centimes
 *       'seance'   => ['date', 'min:2026-09-01'],             // 05/11/2026 → 2026-11-05
 *       'niveau'   => ['requis', 'parmi:debutant,confirme'],
 *       'jours'    => ['liste', 'parmi:lundi,mardi,jeudi'],   // cases à cocher name="jours[]"
 *       'accord'   => ['case', 'requis'],
 *       'code'     => ['motif:/^[A-Z]{3}$/', 'message' => 'Trois lettres capitales.'],
 *       'age'      => ['entier', fn($v) => $v !== null && $v < 3 ? 'Dès 3 ans.' : null],
 *   ]);
 *   if ($f['erreurs'] === []) enregistrer($f['valeurs']);
 *
 * Un type au plus par champ (texte par défaut) : courriel, entier, decimal, montant, date,
 * heure, telephone, code_postal, url, case, liste. Contraintes : requis, min:n, max:n
 * (longueur d'un texte, valeur d'un nombre — en euros pour un montant —, borne d'une date ou
 * d'une heure, nombre d'éléments d'une liste), parmi:a,b,c, motif:/regex/, et toute fonction
 * fn($valeur, $donnees): ?string qui rend un message d'erreur ou null.
 *
 * Les valeurs rendues sont nettoyées et typées : texte rogné, courriel en minuscules, entier
 * en int, décimal en float, montant en centimes (int), date en AAAA-MM-JJ, heure en HH:MM,
 * téléphone français en « 01 23 45 67 89 », case en bool, liste en tableau de chaînes. Un
 * champ facultatif laissé vide vaut '' s'il est textuel, null s'il est typé (nombre, date,
 * heure), [] pour une liste. Les champs absents des règles ne sont pas rendus : on n'écrit
 * jamais en base un champ que le formulaire n'était pas censé envoyer.
 */
declare(strict_types=1);

const SOCLE_TYPES_FORMULAIRE = ['texte', 'courriel', 'entier', 'decimal', 'montant', 'date', 'heure',
                                'telephone', 'code_postal', 'url', 'case', 'liste'];

if (!function_exists('formulaire_valider')) {
    /** Rend ['valeurs' => [champ => valeur], 'erreurs' => [champ => message]] — un message par champ, le premier. */
    function formulaire_valider(array $donnees, array $regles): array
    {
        $valeurs = [];
        $erreurs = [];
        foreach ($regles as $champ => $liste) {
            $liste = (array) $liste;
            $message = isset($liste['message']) ? (string) $liste['message'] : null;
            unset($liste['message']);
            $type = 'texte';
            $contraintes = [];
            foreach ($liste as $r) {
                if (is_string($r) && in_array($r, SOCLE_TYPES_FORMULAIRE, true)) $type = $r;
                else $contraintes[] = $r;
            }
            [$valeur, $erreur] = formulaire_champ($donnees[$champ] ?? null, $type, $contraintes, $donnees);
            $valeurs[$champ] = $valeur;
            if ($erreur !== null) $erreurs[$champ] = $message ?? $erreur;
        }
        return ['valeurs' => $valeurs, 'erreurs' => $erreurs];
    }
}

if (!function_exists('formulaire_champ')) {
    /** Contrôle d'un champ : rend [valeur convertie, message d'erreur ou null]. */
    function formulaire_champ(mixed $brut, string $type, array $contraintes, array $donnees = []): array
    {
        $requis = in_array('requis', $contraintes, true);

        if ($type === 'case') {
            $coche = $brut !== null && $brut !== '' && $brut !== '0' && $brut !== false;
            return [$coche, $requis && !$coche ? 'Cette case doit être cochée.' : null];
        }

        if ($type === 'liste') {
            if ($brut !== null && !is_array($brut)) return [[], 'Valeur invalide.'];
            $v = [];
            foreach ((array) $brut as $element) {
                if (is_array($element)) return [[], 'Valeur invalide.'];
                $element = trim(str_replace("\0", '', (string) $element));
                if ($element !== '') $v[] = $element;
            }
            if ($v === []) return [[], $requis ? 'Choisissez au moins une option.' : null];
            foreach ($contraintes as $c) {
                [$nom, $p] = is_string($c) ? array_pad(explode(':', $c, 2), 2, '') : ['', ''];
                $e = match (true) {
                    $c instanceof Closure                         => $c($v, $donnees),
                    $nom === 'min' && count($v) < (int) $p        => "Choisissez au moins $p options.",
                    $nom === 'max' && count($v) > (int) $p        => "Choisissez au plus $p options.",
                    $nom === 'parmi' && array_diff($v, explode(',', $p)) !== [] => 'Choix invalide.',
                    default                                       => null,
                };
                if ($e !== null) return [$v, $e];
            }
            return [$v, null];
        }

        if (is_array($brut)) return [null, 'Valeur invalide.'];
        $s = trim(str_replace("\0", '', (string) $brut));
        $textuel = in_array($type, ['texte', 'courriel', 'telephone', 'code_postal', 'url'], true);
        if ($s === '') return [$textuel ? '' : null, $requis ? 'Ce champ est obligatoire.' : null];

        $compact = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $s);
        $v = null;
        $erreur = null;
        switch ($type) {
            case 'courriel':
                $v = mb_strtolower($s);
                if (filter_var($v, FILTER_VALIDATE_EMAIL) === false) $erreur = 'Adresse électronique invalide.';
                break;
            case 'entier':
                if (preg_match('/^[+-]?\d{1,18}$/', $compact)) $v = (int) $compact;
                else $erreur = 'Nombre entier attendu.';
                break;
            case 'decimal':
                $n = str_replace(',', '.', $compact);
                if (preg_match('/^[+-]?\d+(\.\d+)?$/', $n)) $v = (float) $n;
                else $erreur = 'Nombre attendu.';
                break;
            case 'montant':
                // Lu comme une chaîne, jamais par un float : 0,29 € donne bien 29 centimes.
                if (preg_match('/^(\d{1,12})(?:[.,](\d{1,2}))?€?$/u', $compact, $m)) {
                    $v = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
                } else {
                    $erreur = 'Montant invalide (exemple : 12,50).';
                }
                break;
            case 'date':
                if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $s, $m)) [$a, $mo, $j] = [$m[1], $m[2], $m[3]];
                elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) [$a, $mo, $j] = [$m[3], $m[2], $m[1]];
                if (isset($a) && checkdate((int) $mo, (int) $j, (int) $a)) $v = sprintf('%04d-%02d-%02d', $a, $mo, $j);
                else $erreur = 'Date invalide (jj/mm/aaaa).';
                break;
            case 'heure':
                if (preg_match('/^(\d{1,2})(?:[:h](\d{2})?)?$/i', $compact, $m) && (int) $m[1] < 24 && (int) ($m[2] ?? 0) < 60) {
                    $v = sprintf('%02d:%02d', $m[1], $m[2] ?? 0);
                } else {
                    $erreur = 'Heure invalide (exemple : 14h30).';
                }
                break;
            case 'telephone':
                $t = preg_replace('/[\s.\-()\x{00A0}\x{202F}]+/u', '', $s);
                if (preg_match('/^(?:\+33|0033|0)([1-9]\d{8})$/', $t, $m)) $v = trim(chunk_split('0' . $m[1], 2, ' '));
                elseif (preg_match('/^\+[1-9]\d{7,14}$/', $t)) $v = $t;
                else $erreur = 'Numéro de téléphone invalide.';
                break;
            case 'code_postal':
                $v = $compact;
                if (!preg_match('/^\d{5}$/', $v)) $erreur = 'Code postal invalide (cinq chiffres).';
                break;
            case 'url':
                $v = $s;
                if (filter_var($v, FILTER_VALIDATE_URL) === false
                    || !in_array(strtolower((string) parse_url($v, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                    $erreur = 'Adresse web invalide (https://…).';
                }
                break;
            default:
                $v = $s;
        }
        if ($erreur !== null) return [$textuel ? $s : null, $erreur];

        $numerique = in_array($type, ['entier', 'decimal', 'montant'], true);
        foreach ($contraintes as $c) {
            if ($c instanceof Closure) {
                $e = $c($v, $donnees);
                if ($e !== null) return [$v, (string) $e];
                continue;
            }
            [$nom, $p] = array_pad(explode(':', (string) $c, 2), 2, '');
            $e = null;
            if ($nom === 'min' || $nom === 'max') {
                $bas = $nom === 'min';
                if ($numerique) {
                    $borne = $type === 'montant' ? (int) round((float) $p * 100) : (float) $p;
                    if ($bas ? $v < $borne : $v > $borne) {
                        $e = ($bas ? 'Valeur minimale : ' : 'Valeur maximale : ') . str_replace('.', ',', $p) . ($type === 'montant' ? ' €' : '') . '.';
                    }
                } elseif ($type === 'date' || $type === 'heure') {
                    if ($bas ? strcmp($v, $p) < 0 : strcmp($v, $p) > 0) {
                        $libelle = $type === 'date' ? date_simple_fr($p) : heure_fr($p);
                        $e = ($bas ? 'Au plus tôt : ' : 'Au plus tard : ') . $libelle . '.';
                    }
                } elseif ($bas ? mb_strlen($v) < (int) $p : mb_strlen($v) > (int) $p) {
                    $e = ($bas ? 'Au moins ' : 'Au plus ') . $p . ' caractères.';
                }
            } elseif ($nom === 'parmi' && !in_array((string) $v, explode(',', $p), true)) {
                $e = 'Choix invalide.';
            } elseif ($nom === 'motif' && preg_match($p, (string) $v) !== 1) {
                $e = 'Format invalide.';
            }
            if ($e !== null) return [$v, $e];
        }
        return [$v, null];
    }
}

if (!function_exists('formulaire_erreur')) {
    /** Le message d'erreur d'un champ, à placer sous lui (rien si le champ est valide). */
    function formulaire_erreur(array $erreurs, string $champ): string
    {
        if (!isset($erreurs[$champ])) return '';
        return '<p class="erreur-champ" id="erreur-' . h(formulaire_id($champ)) . '">' . h($erreurs[$champ]) . '</p>';
    }
}

if (!function_exists('formulaire_aria')) {
    /**
     * Les attributs à poser sur le champ en erreur, pour que le lecteur d'écran lise le message :
     *   <input name="nom"<?= formulaire_aria($f['erreurs'], 'nom') ?>>
     */
    function formulaire_aria(array $erreurs, string $champ): string
    {
        if (!isset($erreurs[$champ])) return '';
        return ' aria-invalid="true" aria-describedby="erreur-' . h(formulaire_id($champ)) . '"';
    }
}

if (!function_exists('formulaire_id')) {
    /** Identifiant HTML tiré d'un nom de champ (« jours[] » → « jours »). */
    function formulaire_id(string $champ): string
    {
        return trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $champ), '-') ?: 'champ';
    }
}
