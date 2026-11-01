<?php
/**
 * socle-php — clés d'accès (passkeys, WebAuthn) : Touch ID, Face ID, Windows Hello, clé physique,
 * gestionnaire de mots de passe.
 *
 * Active seulement si le projet a installé web-auth/webauthn-lib (composer require
 * "web-auth/webauthn-lib:~5.3") : une quinzaine de paquets, que les projets sans clés d'accès
 * n'ont pas à porter. C'est le moteur de laravel/passkeys (compta) : une faille corrigée l'est
 * partout. lbuchs/WebAuthn, plus léger, a été écarté : plus de version depuis juillet 2024
 * (spécification d'authentification commune, octobre 2026, §11).
 *
 * Attend : URL_BASE, les tables auth_compte et auth_cle_acces (voir sql/), la session ; côté
 * navigateur, actifs/webauthn.js. Facultatif : WEBAUTHN_RP_ID (l'hôte d'URL_BASE à défaut) et
 * WEBAUTHN_ORIGINES (l'origine d'URL_BASE à défaut).
 *
 * Décisions :
 *   - rpId = l'hôte exact du site, pas le domaine parent : une clé ne vaut que là où elle a
 *     été créée ;
 *   - vérification de l'utilisateur EXIGÉE (empreinte, visage, code de l'appareil) : une clé
 *     d'accès vaut alors à elle seule mot de passe + second facteur (décision D3) ;
 *   - clé « découvrable » (résidente) : connexion sans saisir son adresse ;
 *   - attestation « none » : on accepte tous les appareils et gestionnaires ;
 *   - défi aléatoire de 32 octets, gardé en session, à usage unique, 5 minutes ;
 *   - la poignée de compte (user handle) est aléatoire : jamais l'identifiant ni l'adresse.
 */
declare(strict_types=1);

if (!function_exists('webauthn_disponible')) {
    /** La bibliothèque est-elle installée ? Sinon, aucune page ne doit proposer de clé d'accès. */
    function webauthn_disponible(): bool
    {
        return class_exists(\Webauthn\PublicKeyCredentialCreationOptions::class);
    }
}

if (!function_exists('webauthn_rp_id')) {
    function webauthn_rp_id(): string
    {
        return defined('WEBAUTHN_RP_ID') ? (string)WEBAUTHN_RP_ID : (string)parse_url(URL_BASE, PHP_URL_HOST);
    }
}

if (!function_exists('webauthn_serialiseur')) {
    /** Le sérialiseur de la bibliothèque (JSON ⇄ objets WebAuthn). */
    function webauthn_serialiseur(): \Symfony\Component\Serializer\SerializerInterface
    {
        static $s = null;
        return $s ??= (new \Webauthn\Denormalizer\WebauthnSerializerFactory(
            \Webauthn\AttestationStatement\AttestationStatementSupportManager::create()))->create();
    }
}

if (!function_exists('webauthn_json')) {
    /** Un objet WebAuthn en JSON, champs vides omis (ce qu'attend le navigateur). */
    function webauthn_json(object $objet): string
    {
        return webauthn_serialiseur()->serialize($objet, 'json', [
            \Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            \Symfony\Component\Serializer\Encoder\JsonEncode::OPTIONS => JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ]);
    }
}

if (!function_exists('webauthn_ceremonies')) {
    /** [création, connexion] : les suites de contrôles de la bibliothèque, origines autorisées posées. */
    function webauthn_ceremonies(): array
    {
        static $c = null;
        if ($c !== null) return $c;
        $fabrique = new \Webauthn\CeremonyStep\CeremonyStepManagerFactory();
        $origines = defined('WEBAUTHN_ORIGINES') ? (array)WEBAUTHN_ORIGINES
                  : [parse_url(URL_BASE, PHP_URL_SCHEME) . '://' . parse_url(URL_BASE, PHP_URL_HOST)
                     . (parse_url(URL_BASE, PHP_URL_PORT) ? ':' . parse_url(URL_BASE, PHP_URL_PORT) : '')];
        $fabrique->setAllowedOrigins($origines);
        return $c = [$fabrique->creationCeremony(), $fabrique->requestCeremony()];
    }
}

if (!function_exists('b64url')) {
    function b64url(string $binaire): string
    {
        return rtrim(strtr(base64_encode($binaire), '+/', '-_'), '=');
    }
}

if (!function_exists('webauthn_poignee')) {
    /** La poignée (user handle) du compte : 32 octets aléatoires, créés au premier besoin. */
    function webauthn_poignee(int $id): string
    {
        $poignee = (string)(auth_donnees($id)['poignee_webauthn'] ?? '');
        if ($poignee === '') {
            $poignee = b64url(random_bytes(32));
            bd()->prepare('UPDATE auth_compte SET poignee_webauthn = ? WHERE compte_id = ? AND poignee_webauthn IS NULL')
                ->execute([$poignee, $id]);
            $poignee = (string)auth_donnees($id)['poignee_webauthn'];
        }
        return (string)base64_decode(strtr($poignee, '-_', '+/'), true);
    }
}

if (!function_exists('webauthn_defi_garder')) {
    /** Garde les options en session (5 min, usage unique) et rend leur JSON pour le navigateur. */
    function webauthn_defi_garder(string $cle, object $options, ?int $id): string
    {
        demarrer_session();
        $json = webauthn_json($options);
        $_SESSION[$cle] = ['options' => $json, 'compte' => $id, 'depuis' => time()];
        return $json;
    }
}

if (!function_exists('webauthn_defi_reprendre')) {
    /** Les options gardées (retirées de la session : un défi ne sert qu'une fois), ou null. */
    function webauthn_defi_reprendre(string $cle, string $classe, ?int $id): ?object
    {
        demarrer_session();
        $garde = $_SESSION[$cle] ?? null;
        unset($_SESSION[$cle]);
        if (!is_array($garde) || time() - (int)$garde['depuis'] > 300) return null;
        if ($id !== null && (int)$garde['compte'] !== $id) return null;
        return webauthn_serialiseur()->deserialize((string)$garde['options'], $classe, 'json');
    }
}

// ---------------------------------------------------------------------------
// Enregistrement d'une clé (page Sécurité, personne connectée)
// ---------------------------------------------------------------------------

if (!function_exists('cle_options_creation')) {
    /**
     * Options d'enregistrement pour navigator.credentials.create() (voir actifs/webauthn.js).
     * $nom : ce que la personne verra dans son gestionnaire (son adresse, en général).
     */
    function cle_options_creation(int $id, string $nom, string $nom_affiche = ''): string
    {
        $exclure = [];
        $req = bd()->prepare('SELECT identifiant FROM auth_cle_acces WHERE compte_id = ?');
        $req->execute([$id]);
        foreach ($req->fetchAll(PDO::FETCH_COLUMN) as $identifiant) {
            $exclure[] = \Webauthn\PublicKeyCredentialDescriptor::create('public-key',
                (string)base64_decode(strtr((string)$identifiant, '-_', '+/'), true));
        }
        $options = \Webauthn\PublicKeyCredentialCreationOptions::create(
            \Webauthn\PublicKeyCredentialRpEntity::create(socle_param('MARQUE_NOM', webauthn_rp_id()), webauthn_rp_id()),
            \Webauthn\PublicKeyCredentialUserEntity::create($nom, webauthn_poignee($id), $nom_affiche !== '' ? $nom_affiche : $nom),
            random_bytes(32),
            // Ed25519, ES256 (presque tous les appareils), RS256 (Windows Hello ancien).
            [\Webauthn\PublicKeyCredentialParameters::createPk(-8), \Webauthn\PublicKeyCredentialParameters::createPk(-7),
             \Webauthn\PublicKeyCredentialParameters::createPk(-257)],
            \Webauthn\AuthenticatorSelectionCriteria::create(
                userVerification: \Webauthn\AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: \Webauthn\AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED),
            \Webauthn\PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $exclure,
            300000,
        );
        return webauthn_defi_garder('webauthn_creation', $options, $id);
    }
}

if (!function_exists('cle_enregistrer')) {
    /**
     * Vérifie la réponse du navigateur et enregistre la clé sous ce nom (« iPhone de Brice »).
     * Rend les dix codes de secours si c'est le PREMIER second facteur du compte (à montrer une
     * fois), [] sinon, ou null si la réponse est refusée (défi expiré, origine, signature…).
     */
    function cle_enregistrer(int $id, string $reponse, string $nom): ?array
    {
        try {
            $options = webauthn_defi_reprendre('webauthn_creation', \Webauthn\PublicKeyCredentialCreationOptions::class, $id);
            if ($options === null) return null;
            $credential = webauthn_serialiseur()->deserialize($reponse, \Webauthn\PublicKeyCredential::class, 'json');
            if (!$credential->response instanceof \Webauthn\AuthenticatorAttestationResponse) return null;
            $record = \Webauthn\AuthenticatorAttestationResponseValidator::create(webauthn_ceremonies()[0])
                ->check($credential->response, $options, webauthn_rp_id());
        } catch (\Throwable $e) {
            journaliser('echec_cle_acces', 'utilisateur', $id, 'enregistrement : ' . mb_substr($e->getMessage(), 0, 150), $id);
            return null;
        }
        $premier = !auth_a_second_facteur($id);
        bd()->prepare('INSERT INTO auth_cle_acces (compte_id, identifiant, source, nom, sauvegardee, cree_le)
                       VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, b64url($record->publicKeyCredentialId), webauthn_json($record),
                       mb_substr(trim($nom) !== '' ? trim($nom) : 'Clé d’accès', 0, 100),
                       $record->backupEligible ? 1 : 0, maintenant()]);
        durable_revoquer_tout($id);
        auth_session_renforcer($id, 'cle');
        journaliser('2fa_activee', 'utilisateur', $id, 'cle', $id);
        auth_aviser($id, 'Clé d’accès ajoutée', "Une clé d’accès (« $nom ») vient d’être ajoutée à votre compte.");
        return $premier ? codes_secours_generer($id) : [];
    }
}

// ---------------------------------------------------------------------------
// Connexion par clé
// ---------------------------------------------------------------------------

if (!function_exists('cle_options_connexion')) {
    /**
     * Options pour navigator.credentials.get(). Sans $id : connexion sans adresse (le navigateur
     * propose les clés du site). Avec $id : seulement les clés de ce compte (second facteur,
     * vérification renforcée, ou site qui partage son hôte avec un autre — portail et famille).
     */
    function cle_options_connexion(?int $id = null): string
    {
        $permises = [];
        if ($id !== null) {
            $req = bd()->prepare('SELECT identifiant FROM auth_cle_acces WHERE compte_id = ?');
            $req->execute([$id]);
            foreach ($req->fetchAll(PDO::FETCH_COLUMN) as $identifiant) {
                $permises[] = \Webauthn\PublicKeyCredentialDescriptor::create('public-key',
                    (string)base64_decode(strtr((string)$identifiant, '-_', '+/'), true));
            }
        }
        $options = \Webauthn\PublicKeyCredentialRequestOptions::create(random_bytes(32), webauthn_rp_id(), $permises,
            \Webauthn\PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED, 300000);
        return webauthn_defi_garder('webauthn_connexion', $options, $id);
    }
}

if (!function_exists('cle_verifier')) {
    /**
     * Vérifie une réponse de navigator.credentials.get() : rend l'identifiant du compte, ou null.
     * $id : le compte attendu (second facteur, vérification), null pour une connexion sans
     * adresse. Le compteur de signatures et la date d'usage sont mis à jour.
     */
    function cle_verifier(string $reponse, ?int $id = null): ?int
    {
        try {
            $options = webauthn_defi_reprendre('webauthn_connexion', \Webauthn\PublicKeyCredentialRequestOptions::class, $id);
            if ($options === null) return null;
            $credential = webauthn_serialiseur()->deserialize($reponse, \Webauthn\PublicKeyCredential::class, 'json');
            if (!$credential->response instanceof \Webauthn\AuthenticatorAssertionResponse) return null;
            $req = bd()->prepare('SELECT * FROM auth_cle_acces WHERE identifiant = ?');
            $req->execute([b64url($credential->rawId)]);
            $ligne = $req->fetch();
            if (!$ligne || ($id !== null && (int)$ligne['compte_id'] !== $id)) return null;
            $compte = auth_compte((int)$ligne['compte_id']);
            if (!$compte || !auth_compte_actif($compte)) return null;
            $record = webauthn_serialiseur()->deserialize((string)$ligne['source'], \Webauthn\CredentialRecord::class, 'json');
            $record = \Webauthn\AuthenticatorAssertionResponseValidator::create(webauthn_ceremonies()[1])
                ->check($record, $credential->response, $options, webauthn_rp_id(), $credential->response->userHandle);
        } catch (\Throwable $e) {
            journaliser('echec_cle_acces', 'utilisateur', $id, 'connexion : ' . mb_substr($e->getMessage(), 0, 150), $id);
            return null;
        }
        bd()->prepare('UPDATE auth_cle_acces SET source = ?, sauvegardee = ?, derniere_utilisation = ? WHERE id = ?')
            ->execute([webauthn_json($record), $record->backupEligible ? 1 : 0, maintenant(), (int)$ligne['id']]);
        return (int)$ligne['compte_id'];
    }
}

if (!function_exists('auth_cle_acces')) {
    /**
     * Connexion complète par clé d'accès (bouton « Se connecter avec une clé d'accès », ou
     * autoremplissage du champ adresse). Rend « connecte », « refus » ou « limite » (30 essais
     * par quart d'heure et par appelant : une signature ne se devine pas, on ménage le serveur).
     */
    function auth_cle_acces(string $reponse, bool $confiance = false): string
    {
        if (!limiter('cle-ip-' . empreinte_appelant(), 30, 900)) return 'limite';
        $id = cle_verifier($reponse);
        if ($id === null) return 'refus';
        auth_terminer($id, 'cle', $confiance);
        return 'connecte';
    }
}

// ---------------------------------------------------------------------------
// Gestion (page Sécurité)
// ---------------------------------------------------------------------------

if (!function_exists('cles_lister')) {
    /** Les clés du compte : nom, synchronisée ou non, dates. */
    function cles_lister(int $id): array
    {
        $req = bd()->prepare('SELECT id, nom, sauvegardee, cree_le, derniere_utilisation FROM auth_cle_acces
                              WHERE compte_id = ? ORDER BY cree_le');
        $req->execute([$id]);
        return $req->fetchAll();
    }
}

if (!function_exists('cle_renommer')) {
    function cle_renommer(int $ligne, int $id, string $nom): void
    {
        bd()->prepare('UPDATE auth_cle_acces SET nom = ? WHERE id = ? AND compte_id = ?')
            ->execute([mb_substr(trim($nom), 0, 100), $ligne, $id]);
    }
}

if (!function_exists('cle_retirer')) {
    /**
     * Retire une clé (après vérification renforcée). Refusé — faux — si c'est le dernier
     * second facteur d'un compte qui y est tenu ($tenu).
     */
    function cle_retirer(int $ligne, int $id, bool $tenu): bool
    {
        $autres = bd()->prepare('SELECT COUNT(*) FROM auth_cle_acces WHERE compte_id = ? AND id <> ?');
        $autres->execute([$id, $ligne]);
        if ($tenu && (int)$autres->fetchColumn() === 0 && auth_donnees($id)['totp_actif_le'] === null) return false;
        $sup = bd()->prepare('DELETE FROM auth_cle_acces WHERE id = ? AND compte_id = ?');
        $sup->execute([$ligne, $id]);
        if ($sup->rowCount() !== 1) return false;
        durable_revoquer_tout($id, true);
        journaliser('2fa_retiree', 'utilisateur', $id, 'cle', $id);
        auth_aviser($id, 'Clé d’accès retirée', "Une clé d’accès vient d’être retirée de votre compte.");
        return true;
    }
}
