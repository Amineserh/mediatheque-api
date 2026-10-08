# Smart Display — Équipe D · Edge : Raspberry Pi & capteurs

Nœud capteurs du projet **Smart Display** (BTS CIEL IR — affichage dynamique intelligent de l'établissement, déploiement décembre 2026).

L'équipe D fournit le **nœud Pi de la salle du self** : acquisition des mesures d'ambiance, publication MQTT vers l'API, pilotage de l'écran en mode kiosque et réveil de l'écran sur détection de présence.

---

## 1. Périmètre de l'équipe D

| Livrable | Description |
|---|---|
| `sensor-agent.py` | Lecture des 4 capteurs, mise en forme JSON, publication MQTT, tampon SQLite hors-ligne |
| `player-agent.py` | Abonnement aux commandes (`reload`, `reboot`, `screenshot`, `update`), heartbeat, HDMI-CEC, mise à jour OTA |
| Chromium kiosque | Affichage de la page *player* servie par l'API |
| Câblage & banc de test | Montage sur T-Cobbler Plus V2.2 + platine d'essai, procédure de qualification des capteurs |
| Ansible | Provisionnement reproductible d'un Pi neuf (flash → écran en ligne) |

Mission transverse : **provisionnement et supervision** du parc.

---

## Sommaire

1. [Périmètre de l'équipe D](#1-périmètre-de-léquipe-d)
2. [Matériel](#2-matériel)
3. [Câblage](#3-câblage)
4. [Installation](#4-installation)
5. [Arborescence](#5-arborescence)
6. [Configuration](#6-configuration-configyaml)
7. [Contrat MQTT](#7-contrat-mqtt)
8. [Lancement](#8-lancement)
9. [Banc de test et qualification](#9-banc-de-test-et-qualification)
10. [Dépannage](#10-dépannage)
11. [RGPD et éthique](#11-rgpd-et-éthique)
12. [Équipe](#12-équipe)

---

## 2. Matériel

### 2.1 Carte

- Raspberry Pi 4 (4 Go) ou Pi 5, Raspberry Pi OS 64 bits (Bookworm)
- Adaptateur **T-Cobbler Plus V2.2** + platine d'essai (prototypage)
- Alimentation officielle (le SDS011 consomme ~80 mA en fonctionnement, ventilateur inclus)

### 2.2 Capteurs

| Capteur | Mesure | Interface | Plage | Usage Smart Display |
|---|---|---|---|---|
| **Keyestudio PYE_IR** (PIR pyroélectrique) | Présence / mouvement | GPIO numérique (3,3 V) | ~5 m, ~100° | Réveil de l'écran, extinction après inactivité |
| **DHT11** | Température, humidité | 1-wire propriétaire sur GPIO | 0–50 °C (±2 °C), 20–90 % HR (±5 %) | Confort de la salle, affichage temps réel |
| **Nova PM SDS011** | Particules PM2.5 / PM10 | UART 9600 bauds | 0–999,9 µg/m³ | Qualité de l'air, alerte « Aérez la salle » |
| **Gravity Sound Level Meter V1.0** (DFRobot SEN0232) | Niveau sonore | **Analogique** 0,6–2,6 V | 30–130 dB(A) | Bruit ambiant (aucun audio n'est enregistré) |

> ⚠️ Le Raspberry Pi **n'a pas d'entrée analogique**. Le sonomètre impose donc un convertisseur **ADS1115** (ADC 16 bits, I²C, adresse `0x48`).

### 2.3 Écart avec l'avant-projet

Le dossier initial prévoyait SCD41 / BME280 / INMP441 / BH1750 / LD2410. Le lot réellement attribué à l'équipe D est celui du tableau ci-dessus. Conséquences assumées et documentées :

- pas de mesure **CO₂** → l'alerte « Aérez » est déclenchée par les **particules (PM2.5)** au lieu du CO₂ ;
- pas de mesure de **luminosité** → l'auto-brightness est remplacée par une extinction sur planning + absence de présence ;
- le niveau sonore est mesuré par un module analogique déjà pondéré A (pas de FFT à implémenter côté Pi).

Le contrat MQTT du projet reste respecté : seules les clés `{mesure}` changent.

---

## 3. Câblage

Toutes les broches sont données en **numérotation physique (BOARD)** et en **BCM**.

### 3.1 PIR Keyestudio PYE_IR

| Broche capteur | Pi (physique) | BCM |
|---|---|---|
| `VCC` | 2 (5 V) | — |
| `GND` | 6 | — |
| `OUT` | 11 | **GPIO17** |

La sortie est à 3,3 V compatible — aucun pont diviseur nécessaire. Les deux potentiomètres règlent la sensibilité et la temporisation ; laisser le cavalier en mode **H (retriggerable)**.

### 3.2 DHT11

| Broche capteur | Pi (physique) | BCM |
|---|---|---|
| `VCC` | 1 (3,3 V) | — |
| `DATA` | 7 | **GPIO4** |
| `GND` | 9 | — |

Résistance de tirage **10 kΩ entre VCC et DATA** si le module n'en embarque pas (module 3 broches = déjà présente).

### 3.3 SDS011 (particules)

Deux montages possibles :

**A. USB (recommandé pour le développement)** — adaptateur CH340 fourni avec le capteur → `/dev/ttyUSB0`. Aucun câblage GPIO.

**B. UART direct (montage final)**

| Broche capteur | Pi (physique) | BCM |
|---|---|---|
| `5V` | 4 | — |
| `GND` | 14 | — |
| `TXD` | 10 (RXD Pi) | **GPIO15** |
| `RXD` | 8 (TXD Pi) | **GPIO14** |

Le port série doit être libéré de la console Linux (voir §4.2) → `/dev/serial0`.

### 3.4 Sonomètre SEN0232 + ADS1115

| SEN0232 | Destination |
|---|---|
| `VCC` | Pi broche 4 (5 V) |
| `GND` | Pi broche 20 |
| `OUT` | ADS1115 `A0` |

| ADS1115 | Pi (physique) | BCM |
|---|---|---|
| `VDD` | 17 (3,3 V) | — |
| `GND` | 25 | — |
| `SDA` | 3 | **GPIO2** |
| `SCL` | 5 | **GPIO3** |
| `ADDR` | GND | → adresse `0x48` |

> La sortie du SEN0232 ne dépasse pas 2,6 V, donc reste sous la VDD de l'ADS1115 (3,3 V) : pas de risque pour l'entrée analogique.

**Conversion :** `dB(A) = Vout × 50` (0,6 V → 30 dB, 2,6 V → 130 dB).

### 3.5 Synoptique

```
                      ┌───────────────────────┐
   PYE_IR  ──GPIO17──▶│                       │
   (PIR)              │                       │
                      │                       │
   DHT11   ──GPIO4───▶│   Raspberry Pi 4/5    │──HDMI/CEC──▶  Écran du self
                      │   (T-Cobbler Plus)    │
   SDS011  ──UART────▶│                       │──Ethernet──▶  VLAN « affichage »
   (PM2.5/PM10)       │                       │                     │
                      │                       │                     │
   SEN0232 ──▶ ADS1115 ──I²C 0x48──▶          │              MQTT/TLS 8883
   (analogique)       └───────────────────────┘                     │
                                                                    ▼
                                                          Mosquitto → API → MySQL
```

### 3.6 Récapitulatif des GPIO occupés

```
GPIO2  / pin 3   → SDA  (ADS1115)
GPIO3  / pin 5   → SCL  (ADS1115)
GPIO4  / pin 7   → DATA (DHT11)
GPIO14 / pin 8   → TXD  (SDS011 RXD)
GPIO15 / pin 10  → RXD  (SDS011 TXD)
GPIO17 / pin 11  → OUT  (PIR PYE_IR)
```

---

## 4. Installation

### 4.1 Dépendances système

```bash
sudo apt update
sudo apt install -y python3-venv python3-dev i2c-tools \
                    libgpiod2 python3-libgpiod git
```

### 4.2 Activation des bus

```bash
sudo raspi-config
# Interface Options → I2C        → Enable
# Interface Options → Serial Port → login shell : NON / hardware : OUI
sudo reboot
```

Vérifications :

```bash
i2cdetect -y 1        # doit afficher 48  (ADS1115)
ls -l /dev/serial0    # lien vers ttyAMA0/ttyS0
```

Ajouter l'utilisateur aux groupes nécessaires :

```bash
sudo usermod -aG i2c,dialout,gpio $USER
```

### 4.3 Dépôt et environnement Python

```bash
git clone <url-du-depot-edge> ~/smart-display-edge
cd ~/smart-display-edge
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
```

`requirements.txt` :

```
paho-mqtt>=2.0
pyserial>=3.5
adafruit-circuitpython-ads1x15
adafruit-circuitpython-dht
gpiozero
lgpio
PyYAML
```

> **Pi 5 :** `adafruit_dht` s'appuie sur la libgpiod et peut échouer. Repli testé : lecture du DHT11 via `lgpio` ou déport du capteur sur un Pico W. Documenter le choix retenu dans l'ADR `docs/adr/0003-dht11.md`.

---

## 5. Arborescence

```
smart-display-edge/
├── README.md
├── requirements.txt
├── config.yaml                # configuration du nœud (salle, broker, périodes)
├── agents/
│   ├── sensor_agent.py        # boucle d'acquisition + publication MQTT
│   ├── player_agent.py        # commandes, heartbeat, HDMI-CEC, OTA
│   └── drivers/
│       ├── pir_pye_ir.py      # présence (interruption GPIO)
│       ├── dht11.py           # température / humidité
│       ├── sds011.py          # PM2.5 / PM10 (trame UART 10 octets)
│       └── sound_sen0232.py   # ADS1115 → tension → dB(A)
├── storage/
│   └── buffer.py              # tampon SQLite si le broker est injoignable
├── tests/
│   ├── test_sds011_frame.py
│   ├── test_sound_conversion.py
│   └── test_buffer.py
├── systemd/
│   ├── sensor-agent.service
│   └── player-agent.service
├── ansible/
│   └── playbook-edge.yml
└── docs/
    ├── cablage.md
    ├── banc-de-test.md
    └── adr/
```

---

## 6. Configuration (`config.yaml`)

```yaml
node:
  room: "self"                 # identifiant de salle utilisé dans les topics
  display_id: "disp-self-01"

mqtt:
  host: "mqtt.smartdisplay.lan"
  port: 8883
  tls: true
  ca_cert: "/etc/ssl/certs/smartdisplay-ca.pem"
  username: "disp-self-01"
  password_file: "/etc/smart-display/mqtt.pass"
  qos: 1

sensors:
  dht11:
    gpio: 4
    period_s: 10
  pir:
    gpio: 17
    hold_s: 120                # écran maintenu allumé après le dernier mouvement
  sds011:
    port: "/dev/serial0"
    period_s: 60               # ventilateur en veille entre deux mesures
    warmup_s: 30
  sound:
    i2c_addr: 0x48
    channel: 0
    period_s: 10
    samples: 50                # moyenne glissante sur 50 lectures

thresholds:
  pm25_warn: 25                # µg/m³ — seuil OMS 24 h
  pm25_alert: 50
  noise_warn: 75               # dB(A)
```

---

## 7. Contrat MQTT

Conforme au contrat inter-équipes (dépôt `contracts`, `openapi.yaml` + schémas JSON).

### 7.1 Publication (le Pi écrit)

```
sensors/{salle}/temperature   {"v": 21.4,  "unit": "°C",    "ts": "2026-09-14T08:30:00Z"}
sensors/{salle}/humidity      {"v": 48,    "unit": "%",     "ts": "..."}
sensors/{salle}/pm25          {"v": 12.3,  "unit": "µg/m3", "ts": "..."}
sensors/{salle}/pm10          {"v": 19.8,  "unit": "µg/m3", "ts": "..."}
sensors/{salle}/noise         {"v": 62.5,  "unit": "dBA",   "ts": "..."}
sensors/{salle}/presence      {"v": true,  "unit": "bool",  "ts": "..."}

displays/{id}/status          {"online": true, "version": "1.2.0", "cpu_temp": 47.2,
                               "url": "https://.../player/disp-self-01",
                               "ts": "..."}                        (retain)
```

- QoS 1, `retain` sur `status` et `presence`.
- `presence` est **événementielle** (publiée au front montant/descendant), pas périodique.
- Les mesures sont horodatées en UTC ISO 8601.

### 7.2 Abonnement (le Pi écoute)

```
displays/{id}/cmd    →  reload | reboot | screenshot | update
alerts/global        →  PPMS : écrase tout le parc
alerts/{zone}        →  alerte ciblée
```

### 7.3 Sécurité

- TLS sur le port 8883, certificat signé par l'AC interne du projet.
- Identifiants uniques par appareil ; ACL Mosquitto : l'écran ne publie que sur `displays/{id}/#` et `sensors/{sa_salle}/#`.
- Aucun mot de passe dans Git (`password_file` hors dépôt, `.env` ignoré, scan `gitleaks` en CI).

---

## 8. Lancement

### 8.1 En développement

```bash
source .venv/bin/activate
python -m agents.sensor_agent --config config.yaml --verbose
```

Test unitaire d'un capteur isolé :

```bash
python -m agents.drivers.sds011 --port /dev/serial0 --once
python -m agents.drivers.sound_sen0232 --duration 30
```

### 8.2 En production (systemd)

```bash
sudo cp systemd/*.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now sensor-agent player-agent
journalctl -u sensor-agent -f
```

Les deux services sont en `Restart=always`, avec `After=network-online.target`.

---

## 9. Banc de test et qualification

Procédure détaillée dans `docs/banc-de-test.md`. Critères de recette du lot capteurs :

| Capteur | Test | Critère |
|---|---|---|
| PYE_IR | 20 passages devant le capteur à 2 m | 20/20 détections, 0 faux positif sur 30 min salle vide |
| DHT11 | Comparaison avec thermomètre étalon | Écart ≤ 2 °C et ≤ 5 % HR |
| SDS011 | Mesure à l'air libre vs. fumée contrôlée | Réaction < 30 s, retour à la ligne de base < 5 min |
| SEN0232 | Sonomètre de référence (application calibrée) | Écart ≤ 3 dB(A) entre 40 et 90 dB |
| Nœud complet | 72 h en continu | 0 redémarrage non planifié, aucune mesure manquante > 2 périodes |
| Réseau | Coupure du broker 10 min | Tampon SQLite rempli puis rejoué sans perte |

---

## 10. Dépannage

| Symptôme | Cause probable | Action |
|---|---|---|
| `i2cdetect` n'affiche pas `48` | I²C désactivé ou câblage SDA/SCL inversé | `raspi-config` → I2C, revérifier les broches 3 et 5 |
| `RuntimeError: Timed out waiting for PulseIn` (DHT11) | Lecture trop fréquente ou charge CPU | Période ≥ 2 s, réessai automatique, vérifier la résistance de tirage |
| SDS011 : aucune trame | Console série encore active sur l'UART | Désactiver le login shell série, utiliser `/dev/serial0` |
| dB(A) figé à 30 | SEN0232 alimenté en 3,3 V au lieu de 5 V | Repasser sur la broche 4 (5 V) |
| PIR toujours à `1` | Cavalier en mode H + temporisation au maximum | Réduire le potentiomètre de délai, vérifier sources de chaleur/courants d'air |
| Pas de publication MQTT | Certificat ou ACL | `mosquitto_sub -h ... --cafile ... -t 'sensors/#' -v`, consulter les logs du broker |
| Écran noir | Chromium ou CEC | Runbook `docs/runbook-ecran-noir.md`, commande `reload` via MQTT |

---

## 11. RGPD et éthique

- Le PIR ne produit qu'un **booléen de présence** — aucune image, aucune vidéo, aucun comptage de personnes.
- Le sonomètre ne restitue qu'un **niveau en dB(A)** — aucun signal audio n'est enregistré ni transmis.
- Aucune donnée nominative ne transite par le nœud Pi.
- Rétention des mesures : 1 an, table `measurement` partitionnée par mois côté serveur.

---

## 12. Équipe

Projet Smart Display — BTS CIEL IR, promotion 2026.
**Équipe D — Edge : Raspberry Pi & capteurs.**

Contributions via *merge request* uniquement, relecture croisée obligatoire, *conventional commits*, SemVer.
