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
