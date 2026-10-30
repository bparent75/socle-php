<?php
/**
 * socle-php — image téléversée : contrôle du type réel, réencodage et redimensionnement GD,
 * nom de fichier sûr.
 *
 * Attend de l'application hôte : slug(), taille_lisible() et nombre_fr() (src/formats.php), l'extension GD (fileinfo et exif
 * facultatives). Aucune table.
 *
 *   $r = image_recevoir($_FILES['photo'] ?? [], __DIR__ . '/data/photos', ['max_largeur' => 1200]);
 *   if (!$r['ok']) flash('erreur', $r['erreur']);
 *   else enregistrer_nom($r['nom']);   // « 3f9c0a1b2d4e5f60.jpg »
 *
 * Ce que la brique garantit, et pourquoi :
 *   - le type est lu dans le contenu (fileinfo et getimagesize doivent concorder), jamais dans
 *     le nom ni dans le type annoncé par le navigateur, que l'expéditeur choisit ;
 *   - l'image est toujours décodée puis réencodée par GD : ce qui n'est pas de l'image (script
 *     glissé dans un fichier « polyglotte », métadonnées EXIF dont la position GPS d'une photo
 *     de téléphone) ne survit pas. SVG refusé : c'est du code, pas une image ;
 *   - le nom est tiré au hasard (plus un préfixe passé par slug()) : rien de ce que l'expéditeur
 *     a écrit n'arrive sur le disque, ni « ../ », ni « .php », ni doublon qui écraserait un fichier ;
 *   - la taille en pixels est bornée AVANT décodage : une petite image de 30 000 × 30 000 pixels
 *     unis tient en quelques Ko et ferait exploser la mémoire du mutualisé ;
 *   - une photo de téléphone tournée par son drapeau EXIF est remise droite avant que ce
 *     drapeau ne disparaisse au réencodage.
 * Un GIF animé devient une image fixe (PNG).
 */
declare(strict_types=1);

/** Types acceptés, par type réel, avec l'extension donnée au fichier enregistré. */
const SOCLE_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

if (!function_exists('image_recevoir')) {
    /**
     * Reçoit une image envoyée par formulaire ($_FILES['champ']) et l'enregistre dans $dossier.
     * Rend ['ok' => bool, 'erreur' => ?string, 'nom' => ?string, 'chemin' => ?string,
     *       'largeur' => int, 'hauteur' => int, 'type' => ?string]. Options : voir image_enregistrer().
     * Le formulaire doit porter enctype="multipart/form-data".
     */
    function image_recevoir(array $fichier, string $dossier, array $options = []): array
    {
        $code = $fichier['error'] ?? UPLOAD_ERR_NO_FILE;
        if (is_array($code)) return image_echec('Un seul fichier attendu.');
        $plafond = taille_lisible((int) ($options['max_octets'] ?? 10 * 1048576));
        $erreur = match ((int) $code) {
            UPLOAD_ERR_OK                              => null,
            UPLOAD_ERR_NO_FILE                         => 'Aucun fichier reçu.',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE  => "Fichier trop lourd ($plafond au plus).",
            UPLOAD_ERR_PARTIAL                         => 'Envoi interrompu : recommencez.',
            default                                    => 'Envoi impossible (erreur du serveur).',
        };
        if ($erreur !== null) return image_echec($erreur);
        $source = (string) ($fichier['tmp_name'] ?? '');
        if ($source === '' || !is_uploaded_file($source)) return image_echec('Fichier invalide.');
        return image_enregistrer($source, $dossier, $options);
    }
}

if (!function_exists('image_enregistrer')) {
    /**
     * Contrôle, réencode et enregistre l'image $source (fichier déjà sur le serveur) dans $dossier.
     * Options :
     *   max_octets  (10 Mo)       poids maximal du fichier reçu
     *   max_pixels  (40 000 000)  largeur × hauteur maximale avant décodage (mémoire du mutualisé)
     *   max_largeur (2000), max_hauteur (2000)  boîte où l'image est réduite, ratio gardé, jamais agrandie
     *   format      (null)        'jpeg', 'png' ou 'webp' imposé ; par défaut celui reçu (GIF → PNG)
     *   qualite     (85)          JPEG et WebP
     *   prefixe     ('')          début lisible du nom, passé par slug() (« portrait » → portrait-3f9c….jpg)
     */
    function image_enregistrer(string $source, string $dossier, array $options = []): array
    {
        if (!function_exists('imagecreatefromstring')) return image_echec('Traitement d’image indisponible sur ce serveur (extension GD).');
        $o = $options + ['max_octets' => 10 * 1048576, 'max_pixels' => 40_000_000, 'max_largeur' => 2000,
                         'max_hauteur' => 2000, 'format' => null, 'qualite' => 85, 'prefixe' => ''];

        $poids = is_file($source) ? (int) filesize($source) : 0;
        if ($poids === 0) return image_echec('Fichier vide ou introuvable.');
        if ($poids > $o['max_octets']) return image_echec('Fichier trop lourd (' . taille_lisible((int) $o['max_octets']) . ' au plus).');

        $infos = @getimagesize($source);
        $type = $infos['mime'] ?? '';
        if (function_exists('finfo_open')) {
            $reel = (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $source);
            if ($reel !== $type) $type = '';   // deux lectures du contenu en désaccord : on refuse
        }
        if (!isset(SOCLE_IMAGE_TYPES[$type])) return image_echec('Ce fichier n’est pas une image JPEG, PNG, WebP ou GIF.');
        [$l, $h] = [(int) $infos[0], (int) $infos[1]];
        if ($l < 1 || $h < 1) return image_echec('Image illisible ou endommagée.');
        if ($l * $h > $o['max_pixels']) {
            return image_echec('Image trop grande (' . nombre_fr((float) $o['max_pixels'], 0) . ' pixels au plus).');
        }

        $im = @imagecreatefromstring((string) file_get_contents($source));
        if ($im === false) return image_echec('Image illisible ou endommagée.');

        // Drapeau d'orientation des photos de téléphone : appliqué tant qu'il existe encore.
        if ($type === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($source);
            $angle = match ((int) ($exif['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
            if ($angle !== 0 && ($tourne = imagerotate($im, $angle, 0)) !== false) {
                $im = $tourne;
            }
        }
        [$l, $h] = [imagesx($im), imagesy($im)];

        $format = $o['format'] ?? match ($type) { 'image/jpeg' => 'jpeg', 'image/webp' => 'webp', default => 'png' };
        if (!in_array($format, ['jpeg', 'png', 'webp'], true)) return image_echec('Format de sortie inconnu.');

        // Toujours une copie en couleurs vraies : réduction ou non, c'est elle qu'on encode.
        $ratio = min(1, $o['max_largeur'] / $l, $o['max_hauteur'] / $h);
        [$nl, $nh] = [max(1, (int) round($l * $ratio)), max(1, (int) round($h * $ratio))];
        $sortie = imagecreatetruecolor($nl, $nh);
        if ($format === 'jpeg') {
            imagefill($sortie, 0, 0, imagecolorallocate($sortie, 255, 255, 255));   // transparence → blanc
        } else {
            imagealphablending($sortie, false);
            imagesavealpha($sortie, true);
            imagefill($sortie, 0, 0, imagecolorallocatealpha($sortie, 0, 0, 0, 127));
            imagealphablending($sortie, true);
        }
        imagecopyresampled($sortie, $im, 0, 0, 0, 0, $nl, $nh, $l, $h);
        imagesavealpha($sortie, $format !== 'jpeg');

        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            return image_echec('Dossier d’enregistrement impossible à créer.');
        }
        if (!is_writable($dossier)) return image_echec('Dossier d’enregistrement inaccessible en écriture.');
        $prefixe = trim((string) $o['prefixe']) !== '' ? slug((string) $o['prefixe']) . '-' : '';
        $nom = $prefixe . bin2hex(random_bytes(8)) . '.' . ($format === 'jpeg' ? 'jpg' : $format);
        $chemin = rtrim($dossier, '/') . '/' . $nom;
        $ecrit = match ($format) {
            'jpeg' => imagejpeg($sortie, $chemin, (int) $o['qualite']),
            'webp' => imagewebp($sortie, $chemin, (int) $o['qualite']),
            default => imagepng($sortie, $chemin, 6),
        };
        if (!$ecrit || !is_file($chemin)) {
            @unlink($chemin);
            return image_echec('Enregistrement de l’image impossible.');
        }
        return ['ok' => true, 'erreur' => null, 'nom' => $nom, 'chemin' => $chemin,
                'largeur' => $nl, 'hauteur' => $nh, 'type' => 'image/' . $format];
    }
}

if (!function_exists('image_echec')) {
    /** Résultat d'échec, de même forme qu'un succès. */
    function image_echec(string $erreur): array
    {
        return ['ok' => false, 'erreur' => $erreur, 'nom' => null, 'chemin' => null,
                'largeur' => 0, 'hauteur' => 0, 'type' => null];
    }
}
