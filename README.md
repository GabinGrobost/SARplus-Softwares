# SAR+ Software 📡

**Application web de radiogoniométrie pour opérations SAR, optimisée pour une SAR BOX (mini-serveur portable).**

---

## Vue d'ensemble

**SAR+ Software** permet de piloter une recherche de balise en temps réel :
- saisie des azimuts par station,
- visualisation cartographique des gisements,
- estimation du point d'intersection,
- suivi d'équipe (appel nominal + watchdog),
- persistance locale des opérations.

Le projet fonctionne en **mode autonome** (intranet SAR BOX) et reste exploitable en environnement réseau dégradé.

---

## Fonctionnalités confirmées dans le code

### Cartographie & relèvements
- Carte Leaflet avec changement de fond de carte.
- Ajout de stations et relèvements azimutaux.
- Calcul d'intersection affiché (coordonnées + indicateur d'erreur).
- Ajout d'une balise via menu contextuel.

### Gestion opérationnelle
- Appel nominal (rollcall) avec indicateurs de présence.
- Watchdog par station avec alertes visuelles.
- Sauvegarde d'opération active dans `op-active.json`.
- Archivage d'opérations clôturées dans `op-inactive.json`.

### APRS & configuration
- Intégration APRS.fi via clé API.
- Sauvegarde de configuration via `save-config.php` vers `config.json`.
- Interconnexion inter-entités via `interconnect-api.php` (publication + consultation des opérations en cours).

### Hors-ligne
- Bascule vers tuiles locales si indisponibilité réseau.
- Préparation / téléchargement de tuiles locales via `prepare-local-basemap.php`.
- Service local de tuiles via batch Windows `serveur_tuiles_configure.bat`.

---

## Arborescence utile

```text
.
├── CartoFLU-v1.0-beta.html      # Interface principale SAR+
├── config.json                  # Configuration runtime
├── save-config.php              # API de sauvegarde config
├── save-op-active.php           # API de sauvegarde opération active
├── interconnect-api.php         # Proxy API d'interconnexion multi-entités
├── prepare-local-basemap.php    # API de préchargement tuiles locales
├── op-active.json               # État opération en cours
├── op-inactive.json             # Historique opérations clôturées
├── member-list.json             # Données membres/stations
├── serveur_tuiles_configure.bat # Service tuiles local (Windows)
└── README.md
```

---

## Déploiement recommandé sur SAR BOX

1. Déposer le dépôt sur la SAR BOX.
2. Servir le dossier via un serveur web local (Apache/Nginx/PHP embarqué).
3. Vérifier les droits d'écriture pour :
   - `config.json`
   - `op-active.json`
   - `op-inactive.json`
4. Ouvrir `CartoFLU-v1.0-beta.html` depuis les postes opérateurs.

---

## Configuration minimale

### 1) APRS
Renseigner la clé APRS.fi dans l'interface, puis sauvegarder.

### 2) Fonds de carte hors-ligne (optionnel)
Configurer dans `config.json` les URL locales et paramètres de zone/zoom.

Exemple :

```json
"local-basemap-urls": {
  "ignplan_local": "http://localhost:8080/ignplan_local/{z}/{x}/{y}.png"
},
"local-basemap-download": {
  "minZoom": 6,
  "maxZoom": 14,
  "bounds": { "north": 51.2, "south": 41.2, "west": -5.8, "east": 9.8 }
}
```

---

## API locales (PHP)

- `POST /save-config.php` : enregistre la configuration applicative.
- `POST /save-op-active.php` : enregistre l'opération active et archive les opérations clôturées.
- `POST /interconnect-api.php` : publie les ouvertures/mises à jour/clôtures et récupère les opérations distantes en cours.
- `POST /prepare-local-basemap.php` : crée/remplit le cache de tuiles locales.

Configuration attendue dans `config.json` pour l'interconnexion :

```json
"interconnect-api-url": "https://votre-api-sar.example.net/interconnect",
"interconnect-fetch-timeout-ms": 6000
```

> Important : ces endpoints nécessitent un serveur web avec PHP activé.

---

## Licence (non libre)

Ce logiciel est distribué sous une **licence propriétaire restrictive** :
- ✅ usage interne autorisé pour vos opérations SAR,
- ❌ **commercialisation interdite**,
- ❌ **modification, adaptation, fork et redistribution interdits**,
- ❌ intégration dans une solution tierce sans autorisation écrite interdite.

Consulter le fichier `LICENSE` pour les termes complets.

---

## 11) Contact

**SWL2506** — ADRASEC 25 (Doubs, Bourgogne-Franche-Comté)
