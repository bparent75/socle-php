<?php
/**
 * socle-php — codes de secours du second facteur (téléphone perdu, clé oubliée).
 *
 * Attend : bd(), SEL_SITE, la table auth_code_secours (voir sql/).
 *
 * Dix codes de la forme XXXXX-XXXXX (alphabet sans caractères ambigus : ni I, O, 0 ni 1),
 * soit 50 bits chacun : assez pour se passer d'Argon2, ils sont stockés en empreinte HMAC
 * (hacher_jeton). Montrés UNE seule fois, à la génération ; chacun ne sert qu'une fois
 * (consommation atomique) ; en générer de nouveaux annule les anciens.
 */
declare(strict_types=1);

if (!function_exists('code_secours_normaliser')) {
    /** Majuscules, sans espaces ni tirets : « abcde-fghjk » et « ABCDE FGHJK » se valent. */
    function code_secours_normaliser(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }
}

if (!function_exists('codes_secours_generer')) {
    /** Dix codes neufs (les anciens sont annulés), à montrer tout de suite et une seule fois. */
    function codes_secours_generer(int $id): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        bd()->prepare('DELETE FROM auth_code_secours WHERE compte_id = ?')->execute([$id]);
        $ins = bd()->prepare('INSERT INTO auth_code_secours (compte_id, empreinte, cree_le) VALUES (?, ?, ?)');
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $code = '';
            for ($j = 0; $j < 10; $j++) $code .= $alphabet[random_int(0, 31)];
            $codes[] = substr($code, 0, 5) . '-' . substr($code, 5);
            $ins->execute([$id, hacher_jeton($code), maintenant()]);
        }
        journaliser('codes_secours_generes', 'utilisateur', $id, '', $id);
        return $codes;
    }
}

if (!function_exists('code_secours_utiliser')) {
    /** Vrai si le code est l'un des codes encore valables du compte ; il est alors consommé. */
    function code_secours_utiliser(int $id, string $code): bool
    {
        $code = code_secours_normaliser($code);
        if (strlen($code) !== 10) return false;
        $maj = bd()->prepare('UPDATE auth_code_secours SET utilise_le = ?
                              WHERE compte_id = ? AND empreinte = ? AND utilise_le IS NULL');
        $maj->execute([maintenant(), $id, hacher_jeton($code)]);
        if ($maj->rowCount() !== 1) return false;
        journaliser('code_secours_utilise', 'utilisateur', $id, '', $id);
        return true;
    }
}

if (!function_exists('codes_secours_restants')) {
    function codes_secours_restants(int $id): int
    {
        $req = bd()->prepare('SELECT COUNT(*) FROM auth_code_secours WHERE compte_id = ? AND utilise_le IS NULL');
        $req->execute([$id]);
        return (int)$req->fetchColumn();
    }
}
