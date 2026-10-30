/* socle-php — clés d'accès côté navigateur (2.2), à copier dans les actifs du projet.

   Le serveur fabrique les options (cle_options_creation(), cle_options_connexion()) et vérifie la
   réponse (cle_enregistrer(), cle_verifier(), auth_cle_acces()) ; ce fichier ne fait que passer
   de l'un à l'autre. Les champs binaires voyagent en base64url : les navigateurs récents les
   convertissent eux-mêmes (parseCreationOptionsFromJSON, toJSON), les autres par les fonctions
   ci-dessous. Aucune dépendance, aucun chargement depuis un autre site.

   Usage :
     const json = await socleCle.creer(optionsJson);           // enregistrement → POST au serveur
     const json = await socleCle.obtenir(optionsJson);         // connexion ou second facteur
     socleCle.autoremplir(optionsJson, json => envoyer(json)); // clé proposée dans le champ adresse
                                                                 // (autocomplete="username webauthn")
   Chaque fonction rend null si la personne annule ou si l'appareil n'a pas de clé. */
(function () {
  'use strict';

  const enOctets = (texte) => {
    const b64 = texte.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((texte.length + 3) % 4);
    return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0)).buffer;
  };
  const enTexte = (tampon) => btoa(String.fromCharCode(...new Uint8Array(tampon)))
    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

  const versCreation = (o) => {
    if (window.PublicKeyCredential && PublicKeyCredential.parseCreationOptionsFromJSON) {
      return PublicKeyCredential.parseCreationOptionsFromJSON(o);
    }
    return { ...o, challenge: enOctets(o.challenge), user: { ...o.user, id: enOctets(o.user.id) },
             excludeCredentials: (o.excludeCredentials || []).map((c) => ({ ...c, id: enOctets(c.id) })) };
  };
  const versConnexion = (o) => {
    if (window.PublicKeyCredential && PublicKeyCredential.parseRequestOptionsFromJSON) {
      return PublicKeyCredential.parseRequestOptionsFromJSON(o);
    }
    return { ...o, challenge: enOctets(o.challenge),
             allowCredentials: (o.allowCredentials || []).map((c) => ({ ...c, id: enOctets(c.id) })) };
  };
  const enJson = (cred) => {
    if (typeof cred.toJSON === 'function') return JSON.stringify(cred.toJSON());
    const r = cred.response;
    const reponse = { clientDataJSON: enTexte(r.clientDataJSON) };
    if (r.attestationObject) {
      reponse.attestationObject = enTexte(r.attestationObject);
      if (r.getTransports) reponse.transports = r.getTransports();
    } else {
      reponse.authenticatorData = enTexte(r.authenticatorData);
      reponse.signature = enTexte(r.signature);
      if (r.userHandle) reponse.userHandle = enTexte(r.userHandle);
    }
    return JSON.stringify({ id: cred.id, rawId: enTexte(cred.rawId), type: cred.type, response: reponse,
                            clientExtensionResults: cred.getClientExtensionResults ? cred.getClientExtensionResults() : {},
                            authenticatorAttachment: cred.authenticatorAttachment || undefined });
  };
  const options = (json) => (typeof json === 'string' ? JSON.parse(json) : json);
  // Annulation, délai dépassé, aucune clé : pas une erreur à montrer, la page propose l'autre moyen.
  const silencieux = (e) => { if (e && e.name !== 'NotAllowedError' && e.name !== 'AbortError') console.warn(e); return null; };

  let attenteAutoremplissage = null;

  window.socleCle = {
    disponible: () => !!(window.PublicKeyCredential && navigator.credentials),

    async creer(json) {
      if (attenteAutoremplissage) attenteAutoremplissage.abort();
      try {
        return enJson(await navigator.credentials.create({ publicKey: versCreation(options(json)) }));
      } catch (e) { return silencieux(e); }
    },

    async obtenir(json) {
      if (attenteAutoremplissage) attenteAutoremplissage.abort();
      try {
        return enJson(await navigator.credentials.get({ publicKey: versConnexion(options(json)) }));
      } catch (e) { return silencieux(e); }
    },

    /** Propose les clés du site dans l'autoremplissage du champ adresse, si le navigateur sait le faire. */
    async autoremplir(json, rappel) {
      if (!window.PublicKeyCredential || !PublicKeyCredential.isConditionalMediationAvailable
          || !(await PublicKeyCredential.isConditionalMediationAvailable())) return;
      attenteAutoremplissage = new AbortController();
      try {
        const cred = await navigator.credentials.get({ publicKey: versConnexion(options(json)),
                                                       mediation: 'conditional', signal: attenteAutoremplissage.signal });
        if (cred) rappel(enJson(cred));
      } catch (e) { silencieux(e); }
    },
  };
})();
