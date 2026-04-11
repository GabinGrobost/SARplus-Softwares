# CartoFLU — Manuel d’ingénierie pour construire le serveur API d’interconnexion

> **Audience** : architectes logiciels, ingénieurs backend senior/staff, SRE, sécurité, DBA.  
> **Objet** : ce document est **exclusivement dédié à la conception, l’implémentation et l’exploitation** d’un serveur API compatible CartoFLU (multi-entités, multi-sites, résilient et évolutif).

---

## Sommaire

1. Objectifs et périmètre serveur  
2. Contrat fonctionnel exact attendu par les clients  
3. Architecture de référence (monolithe, modulaires, event-driven)  
4. Spécification HTTP/JSON complète  
5. Validation des payloads et gouvernance de schéma  
6. Modélisation de données (SQL + NoSQL)  
7. Pipeline d’ingestion d’événements et projection `active_operations`  
8. Idempotence, ordering, concurrence et cohérence  
9. Sécurité (AuthN/AuthZ, hardening, conformité)  
10. Observabilité, métriques, logs, traces, alerting  
11. Performance, capacité, dimensionnement et SLO/SLA  
12. Déploiement (single-node → HA multi-région)  
13. Plan de tests (unitaires, contrats, charge, chaos, sec)  
14. Plan de migration/versionnement/protocole d’évolution  
15. Runbooks d’exploitation et incidents majeurs  
16. OpenAPI et exemples d’implémentation  
17. Annexes (JSON Schema, DDL SQL, checklist prod)

---

## 1) Objectifs et périmètre serveur

### 1.1 Objectifs métier

Le serveur API interconnecte des instances CartoFLU déployées sur des postes / réseaux distincts pour :

- recevoir des publications d’événements opérationnels (`publish`) ;
- maintenir une vision cohérente des opérations **actives** ;
- servir une liste d’opérations distantes (`list-active`) ;
- tolérer des clients hétérogènes et versions différentes.

### 1.2 Hors périmètre

- authentification utilisateur CartoFLU (UI) ;
- messagerie temps réel opérateur-opérateur (chat/voix) ;
- stockage média volumineux (photos/vidéo).  

---

## 2) Contrat fonctionnel exact attendu par les clients

Le client CartoFLU envoie vers un proxy local, qui appelle une URL distante unique. Côté serveur distant, le contrat v1 est :

- méthode : `POST`
- body JSON :

```json
{
  "action": "publish | list-active",
  "envelope": { "...": "..." }
}
```

Le client s’attend à :

- `publish` → réponse HTTP 2xx + JSON valide ;
- `list-active` → réponse HTTP 2xx + `{ "operations": [] }`.

> Contrat de robustesse recommandé : toujours renvoyer `{ "ok": boolean, "requestId": "..." }`.

---

## 3) Architecture de référence

## 3.1 Variante A — Monolithe transactionnel (MVP robuste)

Composants :

- API HTTP (REST minimal) ;
- base PostgreSQL ;
- worker interne pour projections ;
- cache Redis (optionnel).

Avantages : simplicité, time-to-market rapide, opérations faciles.

## 3.2 Variante B — Event-driven (échelle / audit avancé)

Composants :

- API Gateway + Service Ingest ;
- bus (Kafka/NATS/RabbitMQ) ;
- service de projection `active_operations` ;
- service de requête ;
- data lake/audit.

Avantages : scalabilité horizontale, replay, analytics, DR.

## 3.3 Recommandation pragmatique

- Démarrer en A avec patterns B (event log immuable + projections) ;
- Conserver un format d’événement stable dès J1 ;
- Introduire bus ultérieurement sans casser le contrat client.

---

## 4) Spécification HTTP/JSON complète

## 4.1 Endpoint unique v1

`POST /interconnect`

Headers :

- `Content-Type: application/json`
- `Accept: application/json`
- `Authorization: Bearer <token>` (recommandé)
- `X-Request-Id: <uuid>` (recommandé)

### Réponses standardisées

- 200 OK : requête traitée
- 202 Accepted : traitement asynchrone accepté
- 400 Bad Request : JSON invalide / schéma invalide
- 401 Unauthorized : token absent/invalide
- 403 Forbidden : client non autorisé
- 409 Conflict : conflit logique (rare)
- 413 Payload Too Large
- 429 Too Many Requests
- 500/502/503/504 erreurs serveur/infra

## 4.2 Action `publish`

### Requête

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
      "eventType": "operation-opened",
      "operationForm": {},
      "opActiveDuplicate": {},
      "operation": {
        "ref": "2604110001",
        "name": "SATER Doubs",
        "type": "SATER"
      }
    }
  }
}
```

### Réponse recommandée

```json
{
  "ok": true,
  "requestId": "c5e53a66-1f18-4c62-bdb2-c3618e10d6d3",
  "ingested": true,
  "eventKey": "ADRASEC 25|25|2604110001|operation-opened|2026-04-11T10:15:00.000Z"
}
```

## 4.3 Action `list-active`

### Requête

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

### Réponse

```json
{
  "ok": true,
  "requestId": "ba6a2ca6-f8fd-4d11-a7db-4cb68c43da4b",
  "operations": [
    {
      "ref": "2604110007",
      "name": "SATER secteur Est",
      "entityName": "ADRASEC 39",
      "departement": "39",
      "updatedAt": "2026-04-11T10:15:59.000Z",
      "operation": {
        "ref": "2604110007",
        "name": "SATER secteur Est",
        "type": "SATER",
        "createdAt": "2026-04-11T08:45:00.000Z"
      }
    }
  ]
}
```

### Champs effectivement utilisés par le client

Le client lit prioritairement :

- `ref` ou `operation.ref`
- `name` ou `operation.name`
- `entityName` ou `entity.name`
- `departement` ou `entity.departement`

---

## 5) Validation des payloads et gouvernance de schéma

## 5.1 Principes

- Validation stricte sur les champs critiques, souple sur l’extensibilité.
- Refus des payloads invalides (`400`) avec message machine-readable.
- Conserver le payload brut validé dans l’event store.

## 5.2 Règles critiques de validation

- `action` ∈ {`publish`, `list-active`}
- `envelope.version` : entier >= 1
- `envelope.updatedAt` : RFC3339
- `entity.name`, `entity.departement` non vides
- `publish.payload.eventType` ∈ {`operation-opened`, `op-active-duplicate`, `operation-closed`}
- Taille body max recommandée : 1 MiB (ajuster selon charge)

## 5.3 Évolution de schéma

- accepter `additionalProperties: true`
- versionner les champs avec dépréciations documentées
- introduire des “feature flags” côté serveur

---

## 6) Modélisation de données

## 6.1 SQL (PostgreSQL) — DDL recommandé

```sql
create table if not exists interconnect_events (
  id uuid primary key,
  request_id uuid not null,
  received_at timestamptz not null default now(),
  entity_name text not null,
  departement text not null,
  operation_ref text,
  event_type text,
  envelope_updated_at timestamptz,
  dedupe_key text not null,
  payload_json jsonb not null
);

create unique index if not exists ux_interconnect_events_dedupe_key
  on interconnect_events(dedupe_key);

create index if not exists ix_interconnect_events_entity_ref
  on interconnect_events(entity_name, departement, operation_ref);

create table if not exists active_operations (
  entity_name text not null,
  departement text not null,
  operation_ref text not null,
  operation_name text,
  operation_type text,
  status text not null check (status in ('active', 'closed')),
  operation_json jsonb,
  last_snapshot_json jsonb,
  first_seen_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  primary key(entity_name, departement, operation_ref)
);

create index if not exists ix_active_operations_status_updated
  on active_operations(status, updated_at desc);
```

## 6.2 Stratégie NoSQL alternative

- Collection `events` (append-only)
- Collection `active_operations` (projection)
- Index composés : `(entity, departement, operation_ref)` + TTL optionnel

---

## 7) Pipeline d’ingestion et projection

## 7.1 Étapes d’ingestion (`publish`)

1. Parse/validate JSON
2. Normaliser `requestId` et `receivedAt`
3. Calculer `dedupe_key`
4. Écrire `interconnect_events` (transaction)
5. Mettre à jour projection `active_operations`
6. Répondre 200/202

## 7.2 Projection par `eventType`

- `operation-opened` : upsert status=active
- `op-active-duplicate` : refresh snapshot + updatedAt
- `operation-closed` : status=closed (ou purge logique)

## 7.3 Politique de conservation

- events : 1 à 5 ans selon conformité
- snapshots : fenêtre courte + archives froides

---

## 8) Idempotence, ordering, concurrence

## 8.1 Dédoublonnage

`dedupe_key = sha256(entity_name|departement|operation_ref|event_type|envelope.updatedAt)`

Comportement :

- duplicate détecté => répondre `ok=true`, `deduplicated=true`

## 8.2 Ordering

- ordre logique basé sur `envelope.updatedAt`
- fallback `received_at`
- règle LWW configurable

## 8.3 Concurrence

- verrouillage applicatif léger par clé `(entity, dept, ref)`
- transaction courte
- retries bornés côté worker

---

## 9) Sécurité

## 9.1 Authentification machine-to-machine

Minimum recommandé :

- JWT bearer signé (RS256)
- rotation clés via JWKS
- TTL token court (5-15 min)

## 9.2 Autorisation

- policy par client/entité
- quotas par client
- scope : `publish`, `list-active`

## 9.3 Hardening

- TLS 1.2+ only
- HSTS
- CORS fermé (si nécessaire)
- body size limit
- rate limit + circuit breaker
- protection WAF sur patterns abuse

## 9.4 Journalisation sécurité

- logs signés/immutables si possible
- corrélation `requestId`
- alerte sur 401/403/429 anormaux

---

## 10) Observabilité

## 10.1 Logs structurés

Champs minimum :

- `timestamp`, `level`, `requestId`, `action`, `statusCode`, `latencyMs`, `entity`, `departement`, `operationRef`, `deduplicated`

## 10.2 Métriques

- `http_requests_total{action,code}`
- `http_request_duration_ms_bucket{action}`
- `interconnect_publish_ingest_total`
- `interconnect_publish_dedup_total`
- `active_operations_count`

## 10.3 Traces distribuées

- OpenTelemetry
- propagation `traceparent`
- span DB pour ingestion/projection

---

## 11) Performance et capacité

## 11.1 SLO suggérés

- Publish p95 < 250 ms
- List-active p95 < 400 ms
- Erreur 5xx < 0.1%

## 11.2 Dimensionnement initial (ordre de grandeur)

- 100 entités
- 1 update/5 sec en moyenne par entité active
- ~20 req/s pic global (MVP)

## 11.3 Optimisations

- projection en mémoire + write-through DB
- Redis read cache sur list-active (TTL 2-5 s)
- indexation ciblée + vacuum/autovacuum tuning

---

## 12) Déploiement

## 12.1 Environnements

- `dev` : validation schéma, tests contrats
- `staging` : charge + chaos + sécurité
- `prod` : HA, backups, runbooks validés

## 12.2 Topologies

### Niveau 1 — Single AZ

- 2 pods API derrière LB
- PostgreSQL managé
- Redis managé (option)

### Niveau 2 — Multi AZ

- API autoscalée
- Postgres HA
- Observabilité centralisée

### Niveau 3 — Multi région

- active/passive ou active/active
- réplication logique + stratégie de convergence

---

## 13) Plan de tests

## 13.1 Tests contrats

- valider `publish` avec tous `eventType`
- valider `list-active` structure `operations`
- cas invalides (schema, taille, dates)

## 13.2 Tests de charge

- ramp-up 1 → 200 req/s
- spike x10 sur 1 min
- endurance 8h

## 13.3 Chaos

- latence DB
- perte partielle Redis
- redémarrage pods

## 13.4 Sécurité

- fuzz JSON
- injection payload texte
- brute force token

---

## 14) Versionnement et migration

## 14.1 Principes

- backward compatible par défaut
- champs inconnus ignorés
- dépréciation annoncée avec date

## 14.2 Feuille de route API

- v1 (actuel): endpoint unique action-based
- v1.1 : auth obligatoire + requestId standard
- v2 : endpoints explicites `/events`, `/operations/active`

---

## 15) Runbooks d’exploitation

## 15.1 Incident : montée 5xx

1. vérifier saturation CPU/mémoire et erreurs DB
2. activer mode dégradé (limiter publish payloads volumineux)
3. augmenter replicas
4. purger files bloquées
5. post-mortem avec timeline

## 15.2 Incident : latence list-active

1. vérifier index et plan SQL
2. activer/ajuster cache TTL
3. vérifier cardinalité et bloat table

## 15.3 Incident : conflit de cohérence

1. replay des événements depuis `interconnect_events`
2. recalcul projection `active_operations`
3. comparer hash projection avant/après

---

## 16) OpenAPI (base v1)

```yaml
openapi: 3.0.3
info:
  title: CartoFLU Interconnect API
  version: 1.0.0
paths:
  /interconnect:
    post:
      summary: Publish or list active operations
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required: [action, envelope]
              properties:
                action:
                  type: string
                  enum: [publish, list-active]
                envelope:
                  type: object
                  additionalProperties: true
      responses:
        '200':
          description: OK
          content:
            application/json:
              schema:
                type: object
                properties:
                  ok:
                    type: boolean
                  requestId:
                    type: string
                  operations:
                    type: array
                    items:
                      type: object
                      additionalProperties: true
```

---

## 17) Annexes

## 17.1 JSON Schema minimal de requête

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "required": ["action", "envelope"],
  "properties": {
    "action": { "type": "string", "enum": ["publish", "list-active"] },
    "envelope": {
      "type": "object",
      "required": ["version", "app", "updatedAt", "entity", "source", "payload"],
      "properties": {
        "version": { "type": "integer", "minimum": 1 },
        "app": { "type": "string" },
        "updatedAt": { "type": "string", "format": "date-time" },
        "entity": {
          "type": "object",
          "required": ["name", "departement"],
          "properties": {
            "name": { "type": "string", "minLength": 1 },
            "departement": { "type": "string", "minLength": 1 }
          }
        },
        "source": { "type": "object" },
        "payload": { "type": "object" }
      },
      "additionalProperties": true
    }
  },
  "additionalProperties": false
}
```

## 17.2 Checklist production (exécutable)

- [ ] Latence p95 conforme en charge nominale
- [ ] Taux 5xx < objectif sur 7 jours glissants
- [ ] Test restauration backup effectué < 30 jours
- [ ] Rotations clés/token validées
- [ ] Alertes pager configurées et testées
- [ ] Runbooks signés et connus de l’astreinte
- [ ] Documentation API diffusée aux équipes interop

## 17.3 Note de gouvernance documentaire

Vous avez raison : une documentation “industrielle” complète peut atteindre des centaines de pages.  
Ce document constitue la **base technique structurante** pour implémenter le serveur et l’exploiter, et peut être étendu en “volumes” :

- Volume A : spécification protocole + OpenAPI détaillée
- Volume B : architecture sécurité et conformité
- Volume C : exploitation SRE + PRA/PCA
- Volume D : qualification/performance/capacité
- Volume E : migration de versions et rétrocompatibilité
