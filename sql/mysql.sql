-- socle-php — tables nécessaires aux briques, en MySQL — vérifier les longueurs de clé avant usage.
-- Les dates sont en UTC, format AAAA-MM-JJTHH:MM:SSZ.

CREATE TABLE IF NOT EXISTS tentative (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    cle     VARCHAR(190) NOT NULL,
    cree_le TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_tentative ON tentative(cle, cree_le);

CREATE TABLE IF NOT EXISTS notification (
    id              INT AUTO_INCREMENT PRIMARY KEY,
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
    cle_unicite     VARCHAR(190) UNIQUE,          -- empêche le doublon de rappel
    expediteur      VARCHAR(190),                 -- « De » propre au message, sinon EMAIL_EXPEDITEUR
    repondre_a      VARCHAR(190),                 -- Reply-To propre au message, sinon CONTACT_COURRIEL
    copie           TEXT,                         -- Cc propre au message, une ou plusieurs adresses (1.6)
    options         TEXT,                         -- habillage propre au message, en JSON (1.7)
    message_id      VARCHAR(250),                 -- en-tête Message-ID du message remis (1.8)
    rebond_le       TEXT,                         -- non remis : date de l'avis d'échec (1.8, voir rebond)
    cree_le         TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_notif_file ON notification(etat, envoyer_apres);

CREATE TABLE IF NOT EXISTS journal (
    id             INT AUTO_INCREMENT PRIMARY KEY,
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
    id              INT AUTO_INCREMENT PRIMARY KEY,
    cle             VARCHAR(190) NOT NULL,       -- Message-ID de l'avis (à défaut, numéro dans la boîte)
    adresse         VARCHAR(190) NOT NULL DEFAULT '', -- adresse en échec ('' : avis non compris)
    gravite         VARCHAR(20) NOT NULL,       -- definitif (5.x.x), temporaire (4.x.x, retard), inconnu
    non_remis       INTEGER NOT NULL DEFAULT 0, -- 1 : le serveur a renoncé ; 0 : simple retard
    statut          VARCHAR(20) NOT NULL DEFAULT '', -- code RFC 3463, ex. 5.1.1
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
    cle    VARCHAR(100) PRIMARY KEY,
    valeur TEXT NOT NULL
);

-- Connexion par lien magique et rôles (src/connexion.php). « admin » a tous les droits ;
-- les autres valeurs de role appartiennent au projet (« equipe », « compta »…).
CREATE TABLE IF NOT EXISTS utilisateur (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    courriel           VARCHAR(190) NOT NULL UNIQUE,
    prenom             VARCHAR(100) NOT NULL DEFAULT '',
    nom                VARCHAR(100) NOT NULL DEFAULT '',
    role               VARCHAR(20)  NOT NULL DEFAULT 'equipe',
    actif              TINYINT(1)   NOT NULL DEFAULT 1,
    derniere_connexion TEXT,
    cree_le            TEXT NOT NULL
);

-- Jetons de lien magique : hachés (jamais en clair), usage unique, à durée courte.
CREATE TABLE IF NOT EXISTS jeton_connexion (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id INT NOT NULL,
    empreinte      CHAR(64) NOT NULL UNIQUE,
    expire_le      TEXT NOT NULL,
    utilise_le     TEXT,
    cree_le        TEXT NOT NULL,
    CONSTRAINT fk_jeton_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateur(id)
);

-- Authentification (2.2, src/authentification.php et voisins). Indépendant de la table des
-- comptes du projet (AUTH_TABLE_COMPTES) : compte_id est l'identifiant d'une de ses lignes.
CREATE TABLE IF NOT EXISTS auth_compte (
    compte_id        INT PRIMARY KEY,
    mot_de_passe     VARCHAR(255),        -- empreinte Argon2id (password_hash), jamais le mot de passe
    mdp_defini_le    VARCHAR(20),
    totp_chiffre     VARCHAR(255),        -- secret TOTP chiffré par CLE_CHIFFREMENT (« v1:… »)
    totp_actif_le    VARCHAR(20),         -- NULL : pas d'application d'authentification
    totp_dernier_pas BIGINT,              -- anti-rejeu : dernier pas de 30 s accepté
    poignee_webauthn VARCHAR(64),         -- identifiant opaque du compte pour les clés d'accès
    echeance_2fa     VARCHAR(20)          -- fin du délai de grâce d'enrôlement (rôles tenus)
);

-- Codes de secours : empreintes HMAC, usage unique.
CREATE TABLE IF NOT EXISTS auth_code_secours (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    compte_id  INT NOT NULL,
    empreinte  CHAR(64) NOT NULL,
    cree_le    VARCHAR(20) NOT NULL,
    utilise_le VARCHAR(20),
    INDEX idx_auth_code_secours (compte_id, empreinte)
);

-- Clés d'accès (WebAuthn) : clé publique, rien de secret.
CREATE TABLE IF NOT EXISTS auth_cle_acces (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    compte_id            INT NOT NULL,
    identifiant          VARCHAR(255) NOT NULL UNIQUE,  -- credential ID, base64url
    source               TEXT NOT NULL,                 -- JSON de la source (clé publique COSE, compteur…)
    nom                  VARCHAR(100) NOT NULL DEFAULT '',
    sauvegardee          TINYINT(1) NOT NULL DEFAULT 0, -- clé synchronisée (iCloud, Google…)
    cree_le              VARCHAR(20) NOT NULL,
    derniere_utilisation VARCHAR(20),
    INDEX idx_auth_cle_acces (compte_id)
);

-- « Rester connecté » : une ligne par appareil (sélecteur en clair, empreinte du validateur).
CREATE TABLE IF NOT EXISTS auth_session_durable (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    compte_id            INT NOT NULL,
    selecteur            VARCHAR(32) NOT NULL UNIQUE,
    validateur_empreinte CHAR(64) NOT NULL,
    facteurs             VARCHAR(40) NOT NULL DEFAULT '',
    appareil             VARCHAR(60) NOT NULL DEFAULT '',
    cree_le              VARCHAR(20) NOT NULL,
    expire_le            VARCHAR(20) NOT NULL,
    derniere_utilisation VARCHAR(20),
    derniere_rotation    VARCHAR(20) NOT NULL,
    INDEX idx_auth_session_durable (compte_id)
);
