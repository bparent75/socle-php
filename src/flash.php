<?php
/**
 * socle-php — messages flash : un message posé avant une redirection, affiché une fois
 * sur la page suivante (« Inscription enregistrée. »).
 *
 * Attend de l'application hôte : une session ouverte (demarrer_session()), h().
 *
 * Même forme que la copie de la nouvelle identité ATEA (flash($type, $texte), liste
 * dans $_SESSION['flash']) ; une valeur texte seule, comme la posait le site de Tristan,
 * est encore lue : un projet peut passer au socle sans perdre le message en attente.
 */
declare(strict_types=1);

if (!function_exists('flash')) {
    /** Pose un message pour la prochaine page. $type devient une classe CSS : ok, erreur, info… */
    function flash(string $type, string $texte): void
    {
        $f = $_SESSION['flash'] ?? [];
        if (!is_array($f)) $f = [['type' => 'ok', 'texte' => (string) $f]];
        $f[] = ['type' => $type, 'texte' => $texte];
        $_SESSION['flash'] = $f;
    }
}

if (!function_exists('flashs')) {
    /** Les messages en attente, retirés de la session : liste de ['type' => …, 'texte' => …]. */
    function flashs(): array
    {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        if (!is_array($f)) return $f === '' || $f === null ? [] : [['type' => 'ok', 'texte' => (string) $f]];
        return array_values(array_filter($f, fn($m) => is_array($m) && isset($m['texte'])));
    }
}

if (!function_exists('afficher_flashs')) {
    /**
     * Les messages en attente, en HTML (rien s'il n'y en a pas). Un message d'erreur est
     * annoncé aussitôt par les lecteurs d'écran (role="alert"), les autres poliment.
     */
    function afficher_flashs(): string
    {
        $html = '';
        foreach (flashs() as $m) {
            $type = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($m['type'] ?? 'ok'))) ?: 'ok';
            $role = $type === 'erreur' ? 'alert' : 'status';
            $html .= '<p class="flash flash-' . $type . '" role="' . $role . '">' . h((string) $m['texte']) . '</p>';
        }
        return $html !== '' ? '<div class="flashs">' . $html . '</div>' : '';
    }
}
