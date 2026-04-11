# CartoFLU — Spécification technique API d'interconnexion (niveau avancé)

> Public visé : ingénieurs backend, architectes API, DevOps/SRE.
>
> Ce document décrit **le contrat attendu par le client CartoFLU** pour l'interconnexion multi-entités, ainsi que les recommandations pour une implémentation robuste et évolutive.

---

## 1) Vue d'ensemble du flux

### 1.1 Architecture côté client

Le client web n'appelle pas directement l'API distante :

1. `index.php` (JavaScript) envoie une requête `POST` à `interconnect-api.php` (local).
2. `interconnect-api.php` relaie vers l'API distante configurée (`config.json > interconnect-api-url`).
3. La réponse distante est renvoyée au front.

### 1.2 Actions utilisées par le client

Le client envoie uniquement deux actions :

- `publish` : publication événementielle d'ouverture / mise à jour duplicative / clôture.
- `list-active` : lecture des opérations actives distantes.

---

## 2) Contrat HTTP côté API distante

## 2.1 Endpoint unique (actuel)

Le proxy local envoie vers **une URL unique** :

- `POST https://<host>/interconnect` (exemple)

`Content-Type: application/json`

Payload générique :

```json
{
  "action": "publish|list-active",
  "envelope": { "...": "..." }
}
```

### 2.2 Réponses minimales attendues

- Pour `publish` : tout JSON valide avec code HTTP 2xx.
- Pour `list-active` : JSON contenant `operations` de type tableau.

Exemple :

```json
{
  "ok": true,
  "operations": []
}
```

---

## 3) Schéma `envelope` envoyé par le client

## 3.1 Tronc commun

```json
{
  "version": 1,
  "kind": "operation-interconnect | operation-interconnect-query",
  "app": "CartoFLU",
  "updatedAt": "2026-04-11T10:15:00.000Z",
  "entity": {
    "name": "ADRASEC 25",
    "departement": "25"
  },
  "source": {
    "path": "/",
    "mode": "connected | degraded",
    "syncSource": "operation-opened-immediate | operation-form-updated | operation-closed-immediate | ..."
  },
  "payload": {}
}
```

---

## 4) Action `publish`

## 4.1 Payload effectif envoyé

```json
{
  "action": "publish",
  "envelope": {
    "version": 1,
    "kind": "operation-interconnect",
    "app": "CartoFLU",
    "updatedAt": "2026-04-11T10:15:00.000Z",
    "entity": {
      "name": "ADRASEC 25",
      "departement": "25"
    },
    "source": {
      "path": "/",
      "mode": "connected",
      "syncSource": "operation-opened-immediate"
    },
    "payload": {
      "eventType": "operation-opened | op-active-duplicate | operation-closed",
      "operationForm": {
        "name": "Nom opération",
        "ref": "2604110001",
        "type": "SATER",
        "aircraftRegistration": "",
        "exercise": "non",
        "sizing": "Départemental",
        "openingAuthority": "oui",
        "openingAuthorityLabel": "Ouverture COD ?"
      },
      "opActiveDuplicate": {
        "version": 1,
        "kind": "op-active",
        "app": "CartoFLU",
        "active": true,
        "updatedAt": "2026-04-11T10:15:00.000Z",
        "operation": {},
        "operationForm": {},
        "rollcall": {},
        "session": {},
        "sync": {}
      },
      "operation": {
        "name": "Nom opération",
        "ref": "2604110001",
        "type": "SATER",
        "exercise": "non",
        "sizing": "Départemental",
        "openingAuthority": "oui",
        "openingAuthorityLabel": "Ouverture COD ?",
        "mode": "connected",
        "createdAt": "2026-04-11T10:14:00.000Z"
      }
    }
  }
}
```

## 4.2 Sémantique événementielle

- `operation-opened` : première publication d'ouverture.
- `op-active-duplicate` : snapshot complet de duplication (mise à jour continue).
- `operation-closed` : publication de clôture.

> Recommandation : stocker les événements en append-only (event store) + matérialiser une vue `active_operations`.

---

## 5) Action `list-active`

## 5.1 Payload de requête

```json
{
  "action": "list-active",
  "envelope": {
    "version": 1,
    "kind": "operation-interconnect-query",
    "app": "CartoFLU",
    "updatedAt": "2026-04-11T10:16:00.000Z",
    "entity": {
      "name": "ADRASEC 25",
      "departement": "25"
    },
    "source": {
      "path": "/",
      "mode": "connected",
      "syncSource": "join-operation"
    },
    "payload": {
      "excludeEntity": "ADRASEC 25",
      "excludeDepartement": "25"
    }
  }
}
```

## 5.2 Réponse recommandée

```json
{
  "ok": true,
  "operations": [
    {
      "ref": "2604110007",
      "name": "SATER secteur Est",
      "entityName": "ADRASEC 39",
      "departement": "39",
      "operation": {
        "ref": "2604110007",
        "name": "SATER secteur Est",
        "type": "SATER",
        "createdAt": "2026-04-11T08:45:00.000Z"
      },
      "updatedAt": "2026-04-11T10:15:59.000Z"
    }
  ]
}
```

Le client consomme en priorité :

- `ref` ou `operation.ref`
- `name` ou `operation.name`
- `entityName` ou `entity.name`
- `departement` ou `entity.departement`

---

## 6) Modèle de données serveur conseillé

## 6.1 Tables minimales (SQL)

- `interconnect_events`
  - `id` (uuid)
  - `received_at` (timestamptz)
  - `entity_name` (text)
  - `departement` (text)
  - `event_type` (text)
  - `operation_ref` (text)
  - `payload_json` (jsonb)

- `active_operations`
  - `operation_ref` (pk)
  - `entity_name` (text)
  - `departement` (text)
  - `operation_name` (text)
  - `operation_json` (jsonb)
  - `last_snapshot_json` (jsonb)
  - `status` (active|closed)
  - `updated_at` (timestamptz)

## 6.2 Règles de projection

- `operation-opened` : upsert `active_operations` status=active.
- `op-active-duplicate` : upsert + refresh du snapshot.
- `operation-closed` : status=closed (ou suppression logique/TTL).

---

## 7) Idempotence, ordering, concurrence

## 7.1 Idempotence

Le client peut republier un snapshot similaire. Implémenter :

- clé d'idempotence dérivée :
  - `hash(entity_name + departement + operation_ref + event_type + updatedAt)`
- ou déduplication sur `(entity_name, operation_ref, updatedAt)`.

## 7.2 Ordering

Ne pas supposer un ordre réseau strict. Utiliser :

- `updatedAt` dans `envelope`
- fallback `received_at`
- logique "last write wins" paramétrable.

## 7.3 Conflits inter-entités

`operation_ref` peut potentiellement collisionner entre entités.
Clé métier recommandée :

- `(entity_name, departement, operation_ref)`.

---

## 8) Sécurité et durcissement

## 8.1 Authentification

Fortement recommandé :

- `Authorization: Bearer <token>`
- rotation token + expirations courtes
- éventuellement mTLS en environnement institutionnel.

> Le client actuel ne transmet pas nativement de header d'auth côté front.
> Deux options :
> 1) accepter réseau privé de confiance,
> 2) gérer les secrets côté `interconnect-api.php` (injecter header serveur-à-serveur).

## 8.2 Contrôles d'entrée

- limiter taille payload (ex: 1–2 MB max).
- valider JSON schema.
- filtrer/normaliser champs texte.
- appliquer rate limiting par IP/site.

## 8.3 Journalisation

- tracer `action`, `entity`, `departement`, `operation_ref`, latence, code retour.
- corrélation via `request_id`.

---

## 9) Versionnement et évolutivité

## 9.1 Version de contrat

Le client envoie `envelope.version = 1`.
Prévoir :

- compat ascendante (`v1` accepté durablement),
- ajout de champs permissif (ignore unknown fields),
- endpoint versionné ultérieurement (`/interconnect/v2`).

## 9.2 Stratégie de migration

1. Déployer serveur tolérant (`additionalProperties: true`).
2. Introduire champs nouveaux côté serveur en lecture seule.
3. Activer progressivement côté clients.

---

## 10) Performance et SLO

## 10.1 Cibles recommandées

- `publish` p95 < 300 ms (intra-région).
- `list-active` p95 < 500 ms.
- disponibilité API > 99.9%.

## 10.2 Mise en cache

- `list-active` peut être servi depuis vue matérialisée / Redis (TTL 2–5 s).
- invalidation sur réception d'un `publish`.

---

## 11) Exemples de JSON Schema (extraits)

## 11.1 Enveloppe

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "required": ["version", "kind", "app", "updatedAt", "entity", "source", "payload"],
  "properties": {
    "version": { "type": "integer", "minimum": 1 },
    "kind": { "type": "string" },
    "app": { "type": "string" },
    "updatedAt": { "type": "string", "format": "date-time" },
    "entity": {
      "type": "object",
      "required": ["name", "departement"],
      "properties": {
        "name": { "type": "string", "minLength": 1 },
        "departement": { "type": "string", "minLength": 1 }
      }
    }
  },
  "additionalProperties": true
}
```

---

## 12) Implémentation de référence (comportement)

## 12.1 Pseudo-code publish

```text
if action == "publish":
  validate envelope
  extract eventType + operationRef
  append interconnect_events
  project active_operations
  return 202/200 {"ok": true}
```

## 12.2 Pseudo-code list-active

```text
if action == "list-active":
  validate envelope
  read active_operations where status="active"
  optionally exclude entity/departement from envelope.payload
  return 200 {"ok": true, "operations": [...]}
```

---

## 13) Points d'attention spécifiques CartoFLU

- `opActiveDuplicate` peut contenir des structures volumineuses (`session.bearings`, etc.).
- la clôture logique est portée par `eventType = operation-closed`.
- le client filtre déjà localement les opérations de sa propre entité/département à l'affichage.
- timeouts client paramétrables via `interconnect-fetch-timeout-ms`.

---

## 14) Checklist de mise en production

- [ ] TLS actif + certificats valides.
- [ ] AuthN/AuthZ définies.
- [ ] Validation JSON + limites payload.
- [ ] Monitoring (latence, erreurs, saturation).
- [ ] Rétention events + politique RGPD.
- [ ] Stratégie de sauvegarde/restauration.
- [ ] Test de charge et chaos test réseau.
- [ ] Documentation OpenAPI publiée.

---

## 15) Extension recommandée (future-proof)

- Passage à des endpoints explicites (`/events`, `/operations/active`) tout en conservant le mode `action` pour rétrocompatibilité.
- Support WebSocket/SSE pour push temps réel des opérations actives.
- Signature HMAC des payloads (anti-altération).
- Ajout d'un champ `originInstanceId` pour distinguer plusieurs postes d'une même entité.
- Politique de TTL automatique des opérations actives sans heartbeat.
