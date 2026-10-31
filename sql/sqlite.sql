-- socle-php — tables nécessaires aux briques, en SQLite.
-- Les dates sont en UTC, format AAAA-MM-JJTHH:MM:SSZ.

CREATE TABLE IF NOT EXISTS tentative (
    id      INTEGER PRIMARY KEY,
    cle     TEXT NOT NULL,
    cree_le TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_tentative ON tentative(cle, cree_le);

CREATE TABLE IF NOT EXISTS notification (
    id              INTEGER PRIMARY KEY,
    utilisateur_id  INTEGER,
    destinataire    TEXT NOT NULL,
    sujet           TEXT NOT NULL,
    corps           TEXT NOT NULL,
    etat            TEXT NOT NULL DEFAULT 'en_attente'
                     CHECK (etat IN ('en_attente','envoye','echec','annule')),
    tentatives      INTEGER NOT NULL DEFAULT 0,
    derniere_erreur TEXT NOT NULL DEFAULT '',
    envoyer_apres   TEXT NOT NULL,
    envoye_le       TEXT,
    objet_type      TEXT NOT NULL DEFAULT '',
    objet_id        INTEGER,
    cle_unicite     TEXT UNIQUE,          -- empêche le doublon de rappel
    expediteur      TEXT,                 -- « De » propre au message, sinon EMAIL_EXPEDITEUR
    repondre_a      TEXT,                 -- Reply-To propre au message, sinon CONTACT_COURRIEL
    copie           TEXT,                 -- Cc propre au message, une ou plusieurs adresses (1.6)
    options         TEXT,                 -- habillage propre au message, en JSON (1.7)
    message_id      TEXT,                 -- en-tête Message-ID du message remis (1.8)
    rebond_le       TEXT,                 -- non remis : date de l'avis d'échec (1.8, voir rebond)
    cree_le         TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_notif_file ON notification(etat, envoyer_apres);

CREATE TABLE IF NOT EXISTS journal (
    id             INTEGER PRIMARY KEY,
    utilisateur_id INTEGER,
    action         TEXT NOT NULL,
    objet_type     TEXT NOT NULL DEFAULT '',
    objet_id       INTEGER,
    detail         TEXT NOT NULL DEFAULT '',
    cree_le        TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_journal ON journal(cree_le);

-- Avis de non-remise relevés dans la boîte d'envoi (1.8, src/rebonds.php) : une ligne par avis et par
-- adresse en échec. Jamais supprimée ; « levée » quand quelqu'un a vérifié que l'adresse est bonne.
-- Pas d'index sur les nouvelles colonnes de notification ici : une base plus ancienne ne les a pas
-- encore au moment où ce fichier s'exécute. Le projet les ajoute (ALTER TABLE), index compris.
CREATE TABLE IF NOT EXISTS rebond (
    id              INTEGER PRIMARY KEY,
    cle             TEXT NOT NULL,       -- Message-ID de l'avis (à défaut, numéro dans la boîte)
    adresse         TEXT NOT NULL DEFAULT '', -- adresse en échec ('' : avis non compris)
    gravite         TEXT NOT NULL,       -- definitif (5.x.x), temporaire (4.x.x, retard), inconnu
    non_remis       INTEGER NOT NULL DEFAULT 0, -- 1 : le serveur a renoncé ; 0 : simple retard
    statut          TEXT NOT NULL DEFAULT '', -- code RFC 3463, ex. 5.1.1
    diagnostic      TEXT NOT NULL,        -- réponse du serveur distant, abrégée
    notification_id INTEGER,              -- le courriel rapproché, s'il est connu
    recu_le         TEXT NOT NULL,
    releve_le       TEXT NOT NULL,
    leve_le         TEXT,                 -- adresse vérifiée : l'échec ne bloque plus
    leve_par        INTEGER,
    UNIQUE (cle, adresse)
);
CREATE INDEX IF NOT EXISTS idx_rebond_adresse ON rebond(adresse, gravite);

-- Paramètres modifiables par l'équipe (src/parametres.php) : param() / param_definir().
-- Les liens signés révocables (signature_revocable, src/securite.php) y rangent leur version.
CREATE TABLE IF NOT EXISTS config (
    cle    TEXT PRIMARY KEY,
    valeur TEXT NOT NULL DEFAULT ''
);

-- Connexion par lien magique et rôles (src/connexion.php). « admin » a tous les droits ;
-- les autres valeurs de role appartiennent au projet (« equipe », « compta »…).
CREATE TABLE IF NOT EXISTS utilisateur (
    id                 INTEGER PRIMARY KEY,
    courriel           TEXT NOT NULL UNIQUE COLLATE NOCASE,
    prenom             TEXT NOT NULL DEFAULT '',
    nom                TEXT NOT NULL DEFAULT '',
    role               TEXT NOT NULL DEFAULT 'equipe',
    actif              INTEGER NOT NULL DEFAULT 1,
    derniere_connexion TEXT,
    cree_le            TEXT NOT NULL
);

-- Jetons de lien magique : hachés (jamais en clair), usage unique, à durée courte.
CREATE TABLE IF NOT EXISTS jeton_connexion (
    id             INTEGER PRIMARY KEY,
    utilisateur_id INTEGER NOT NULL REFERENCES utilisateur(id),
    empreinte      TEXT NOT NULL UNIQUE,
    expire_le      TEXT NOT NULL,
    utilise_le     TEXT,
    cree_le        TEXT NOT NULL
);

-- Authentification (2.2, src/authentification.php et voisins). Indépendant de la table des
-- comptes du projet (AUTH_TABLE_COMPTES) : compte_id est l'identifiant d'une de ses lignes.
CREATE TABLE IF NOT EXISTS auth_compte (
    compte_id        INTEGER PRIMARY KEY,
    mot_de_passe     TEXT,                -- empreinte Argon2id (password_hash), jamais le mot de passe
    mdp_defini_le    TEXT,
    totp_chiffre     TEXT,                -- secret TOTP chiffré par CLE_CHIFFREMENT (« v1:… »)
    totp_actif_le    TEXT,                -- NULL : pas d'application d'authentification
    totp_dernier_pas INTEGER,             -- anti-rejeu : dernier pas de 30 s accepté
    poignee_webauthn TEXT,                -- identifiant opaque du compte pour les clés d'accès
    echeance_2fa     TEXT,                -- fin du délai de grâce d'enrôlement (rôles tenus)
    sessions_depuis  TEXT                 -- sessions ouvertes avant cette date : fermées
);

-- Codes de secours : empreintes HMAC, usage unique.
CREATE TABLE IF NOT EXISTS auth_code_secours (
    id         INTEGER PRIMARY KEY,
    compte_id  INTEGER NOT NULL,
    empreinte  TEXT NOT NULL,
    cree_le    TEXT NOT NULL,
    utilise_le TEXT
);
CREATE INDEX IF NOT EXISTS idx_auth_code_secours ON auth_code_secours(compte_id, empreinte);

-- Clés d'accès (WebAuthn) : clé publique, rien de secret.
CREATE TABLE IF NOT EXISTS auth_cle_acces (
    id                   INTEGER PRIMARY KEY,
    compte_id            INTEGER NOT NULL,
    identifiant          TEXT NOT NULL UNIQUE,   -- credential ID, base64url
    source               TEXT NOT NULL,          -- JSON de la source (clé publique COSE, compteur…)
    nom                  TEXT NOT NULL DEFAULT '',
    sauvegardee          INTEGER NOT NULL DEFAULT 0, -- clé synchronisée (iCloud, Google…)
    cree_le              TEXT NOT NULL,
    derniere_utilisation TEXT
);
CREATE INDEX IF NOT EXISTS idx_auth_cle_acces ON auth_cle_acces(compte_id);

-- « Rester connecté » : une ligne par appareil (sélecteur en clair, empreinte du validateur).
CREATE TABLE IF NOT EXISTS auth_session_durable (
    id                   INTEGER PRIMARY KEY,
    compte_id            INTEGER NOT NULL,
    selecteur            TEXT NOT NULL UNIQUE,
    validateur_empreinte TEXT NOT NULL,
    facteurs             TEXT NOT NULL DEFAULT '',
    appareil             TEXT NOT NULL DEFAULT '',
    cree_le              TEXT NOT NULL,
    expire_le            TEXT NOT NULL,
    derniere_utilisation TEXT,
    derniere_rotation    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_auth_session_durable ON auth_session_durable(compte_id);
