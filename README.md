# SAR+ (Based on CartoFLU) 🦊📡

**Application web de radiogoniométrie pour les opérations de recherche et sauvetage (SAR), conçue pour équiper les SAR BOX.**

Développée pour les besoins terrain des équipes ADRASEC.

---

## 1) Objectif opérationnel

**SAR+** est une application web autonome dédiée à la **radiogoniométrie de balise de détresse**.
Elle est pensée pour fonctionner sur une **SAR BOX** (mini serveur portable durci pour les missions SAR), afin d'offrir une solution simple, robuste et immédiatement exploitable sur le terrain.

L'objectif est de permettre à une équipe de :
- suivre les stations de recherche,
- saisir les relèvements azimutaux,
- visualiser les intersections probables,
- conserver une trace des actions (sauvegarde / export),
- continuer à travailler même en environnement contraint (connectivité limitée).

---

## 2) Points forts

### 🗺️ Cartographie tactique
- Carte interactive basée sur **Leaflet.js**.
- Saisie de relèvements azimutaux par station.
- Calcul et affichage des **intersections de gisements**.
- Positionnement rapide d'une station via clic droit.
- Marqueurs déplaçables (drag & drop) pour ajustements terrain.

### 📋 Suivi d'équipe (Appel nominal)
- Tableau des stations actives.
- **Watchdog timer** configurable par station.
- Alertes visuelles en cas de non-réponse.
- Entrée **BALISE** pour matérialiser l'objectif recherché.

### 📡 Intégration APRS
- Affichage des stations APRS en direct sur la carte.
- Affichage de l'indicatif et de l'ancienneté de la dernière trame.
- Masquage individuel de stations (liste des indicatifs masqués).

### 💾 Résilience des données
- Autosauvegarde configurable (15 s / 30 s / 1 min / 5 min).
- Import / Export de session en **JSON** et **CSV**.

### 🌐 Continuité de mission
- Fonctionnement avec tuiles en ligne.
- Mode hors-ligne avec serveur de tuiles local.
- Bascule automatique vers des fonds locaux si internet est indisponible.

---

## 3) Architecture d'emploi sur SAR BOX

Déploiement type :
1. La **SAR BOX** héberge l'application et, si nécessaire, le service de tuiles locales.
2. Les opérateurs se connectent en réseau local à la SAR BOX.
3. L'application est ouverte dans un navigateur moderne sur chaque poste.
4. Les actions de radiogoniométrie sont consolidées en temps réel pour la conduite de mission.

Ce mode opératoire limite la dépendance à internet et favorise l'autonomie opérationnelle.

---

## 4) Prérequis

- Navigateur moderne (Chrome / Edge recommandés).
- Connexion internet pour les fonds en ligne **ou** tuiles locales pour un usage hors-ligne.
- (Optionnel) fichier `callsign_list.txt` pour l'autocomplétion des indicatifs.

---

## 5) Démarrage rapide

1. Télécharger le projet.
2. Ouvrir le fichier principal HTML dans un navigateur.
3. (Optionnel) Charger `callsign_list.txt` pour faciliter la saisie des indicatifs.

Aucune installation lourde n'est nécessaire pour l'usage standard.

---

## 6) Mode hors-ligne (recommandé pour SAR BOX)

Pour les opérations en zone blanche ou dégradée :

1. Préparer les tuiles OSM en local (ex. Mobile Atlas Creator).
2. Lancer `serveur_tuiles.bat` à la racine du dossier de tuiles (serveur Python sur `localhost:8080`).
3. Configurer `config.json` avec la clé HASH IGN (si fonds protégés) et les URL locales.

Exemple :

```json
"ignscan25-hash-key": "votre_cle_hash_cartes_gouv_fr",
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

## 7) Structure du dépôt

```text
.
├── [fichier_principal].html   # Fichier applicatif principal
├── callsign_list-example.txt  # Exemple de liste d'indicatifs
├── serveur_tuiles.bat         # Serveur local pour tuiles hors-ligne
└── README.md
```

---

## 8) Contributions

Les contributions sont bienvenues (radioamateurs, développeurs, membres ADRASEC, formateurs SAR).

Pour proposer une amélioration :
- créer une **Issue** (bug, besoin opérationnel, idée d'évolution),
- préciser le contexte d'usage (exercice, mission réelle, poste mobile, etc.),
- joindre captures/logs si possible.

---

## 9) Dépendances

| Bibliothèque | Version | Rôle |
|---|---|---|
| [Leaflet.js](https://leafletjs.com/) | 1.9.x | Cartographie interactive |
| CartoDB Voyager | — | Fond de carte par défaut |

Dépendances chargées via CDN. Aucun `npm install` requis pour l'usage standard.

---

## 10) Licence

Projet distribué sous licence **GNU GPL v3**.
Voir `LICENSE` ou [gnu.org/licenses/gpl-3.0](https://www.gnu.org/licenses/gpl-3.0.html).

---

## 11) Contact

**SWL2506** — ADRASEC 25 (Doubs, Bourgogne-Franche-Comté)

---

**SAR+ / SAR PLUS** est un projet bénévole orienté efficacité terrain au service des opérations de sécurité civile.
