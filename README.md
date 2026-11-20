# Plugin Reolink pour Jeedom

Ce plugin permet le controle des caméras de marque Réolink qui possède l'API.
Il ne permet pas le visionnage des flux vidéo, pour ceci vous devrait utiliser le plugin Jeedom officiel "Caméra"

### Support des caméras

Supportes :

* La série des RLC-xxxx
* La série des RLN-xxxx-x
* Les séries des DUO, DUO2 et DUO3
* La série des C1 & C2.
* La E1 Zoom et E1 Outdoor
* La série TrackMix
* La série CX

Non-supportés :

* La série ARGUS
* La série GO
* Les modèles commencant part Bxxx & Dxxx
* Le modèle Keen, Lumus, E1 & E1 Pro

### Page de configuration d'une caméra :

* Ajouter une camera :<br>
![image](https://user-images.githubusercontent.com/54839700/174433235-2f7462fa-f868-4391-8916-b88e20cd2643.png)

  * Donner un nom à l'équipement
  * Renseigner les champs ci-dessous :
    * IP / Nom d'hôte
    * Port HTTP/HTTPS (ce champ est facultatif, à renseigner uniquement si vous avez personnaliser le port d'accès à l'interface web)
    * Login
    * Port ONVIF (par défaut 8000, à modifier uniquement si vous avez personnaliser le port d'accès au protocole ONVIF )
    * Mot de passe
    * Type de connexion (http/https)
    * Auto-actualisation (cron) (par défaut réglé à 1h, ce CRON sert à actualiser les paramètres globaux de la caméra, il n'influence en rien la remontée de détection de mouvement)

  * Sauvegardez l'équipement en cliquant sur **"Sauvegarder"** ![image](https://user-images.githubusercontent.com/54839700/174434052-44ac9904-3bfa-4afa-a20f-4cd669d4c636.png)

Vous pouvez tester si les paramètres sont correctes et si le plugin arrive à accéder à votre caméra via le bouton :  **"Tester la connexion"** : ![image](https://user-images.githubusercontent.com/54839700/174433973-af0f7a4a-4947-4dec-9a98-2836cae5e534.png)

* Récupérez les informations de la caméra : bouton ![image](https://user-images.githubusercontent.com/54839700/174434125-261308a3-c8a7-4689-9095-0a5d56177449.png)
* Générez les commandes de la caméra : bouton ![image](https://user-images.githubusercontent.com/54839700/174434177-5f433dc2-91b5-4cdb-9bcc-8a42d6a48f0d.png)


> IMPORTANT : Lors de mise(s) à jour du plugin Reolink, de nouvelles commandes peuvent être ajouter (cf. Changelog du plugin) pour faciliter une meilleure gestion de la caméra. **Pour bénéficier des nouvelles commandes, relancer le processus de création de commandes pour chacun de la (des) caméra(s)**
  
 > ATTENTION : La remontée de détection de mouvement depuis la caméra vers le plugin n'est pas fiable, et ne dois aucunement être utilisé en tant que système de sécurité.

<BR><P>

### Page de configuration du plugin :

> **Ces paramètres ne doivent être modifier que si vous rencontrer des problèmes.**

- **Methode d'authentification GET** : Modification de la manière dont le plugin s'authentifie pour utiliser l'API.
- **Taille des blocs commandes** : Modification du nombres de commandes envoyé dans un seul bloc d'appel API, plus les blocs sont gros plus l'exécution sera rapide, mais le risque de faire planter la caméra est plus grand, plus les blocs sont petit, plus l'execution sera lente mais economise la caméra en terme d'usage.
- **IP de callback du webhook** : Pour obtenir les détections de mouvements la caméra envoi les évènements ONVIF à un webhook. Ce webhook est le daemon du plugin, il s'agit ici de l'IP qui est transmise à la caméra sur laquelle elle devra envoyer ses notifications. \
Les options correspondent aux paramètres que vous avez dans : Réglages > Système > Configuration > Réseaux (sous votre Jeedom)
- **IP personnalisée** : Si vous souhaiter personnaliser l'IP vers laquelle la caméra doit renvoyer ses notifications ONVIF.
- **Port du webhook** : Port d'appel du webhook depuis la caméra.


### Listes des fonctions de l'API intégré dans le plugin :

#### Système
- [x] Authentification
  - [x] Login
  - [x] Logout
- [x] Support NVR multi-canaux (équipement par canal + état `ChannelOnline`)
- [x] Reboot
- [x] Obtention des informations de la caméra
- [x] Obtention des capacités hardware/software de la caméra
- [ ] Auto Reboot
  - [x] Activation/Désactivation Auto Reboot
  - [ ] Planning Auto Reboot
- [ ] Gestion des utilisateurs (ajout/supression/modification)
- [ ] Gestion de l'heure / NTP
- [ ] Restaurer la config par défaut
- [ ] Stockage
  - [x] Etat du stockage
  - [x] Espace utilisé
  - [ ] Formattage de l'espace de stockage
- [x] Contrôle de la Led d'état
- [ ] Controle des mise à jour logiciel (vérification + installation OTA)
- [x] Monitoring de la caméra : Utilisation CPU, Débit codec et Débit réseau
- [ ] Buzzer NVR / Home Hub (activation/désactivation)
- [ ] Scènes / Modes Reolink (Home, Away, Night…)
- [ ] Caméras sur batterie via NVR / Home Hub (niveau batterie, charge, mode veille)

#### PTZ
- [x] Zoom/Focus
  - [x] Zoom
  - [x] Focus
  - [x] Activation/Désactivation Auto-Focus
- [x] Mouvement (Haut/Bas/Gauche/Droite)
- [ ] Position PTZ exacte (lecture/écriture Pan & Tilt)
- [ ] Zoom 3D
- [x] Presets PTZ
  - [x] Récupération des presets PTZ
  - [x] Utilisation des presets PTZ
- [x] PTZ Patrol
  - [x] Activation/Désactivation du PTZ Patrol
  - [x] Gestion PTZ Patrol
- [x] PTZ Guard (3)
  - [x] Etat du point de garde
  - [x] Création/Actualisation du point de garde
  - [x] Activation/Désactivation du retour auto. au point de garde
  - [x] Réglage du délai de retour auto. au point de garde
- [ ] PTZ Serial
- [x] Calibration de la camera (1)
  - [x] Etat de la calibration
  - [x] Exécution de la calibration

#### Réseau
- [ ] IP/DNS/MASQUE
- [ ] DDNS
- [ ] Wifi (configuration + niveau de signal RSSI)
- [ ] Activation/Désactivation des ports réseau (HTTP, HTTPS, RTSP, RTMP, ONVIF)
- [x] Activation/Désactivation UPNP
- [x] Activation/Désactivation P2P

#### Image/Vidéo
- [x] Luminosité, Contraste, Saturation, Teinte, Netteté
- [x] Retourner l'image : Verticalement/Horizontalement
- [ ] Encodage flux Principal / Secondaire
  - [x] Lecture Résolution, FPS et Bitrate
  - [ ] Modification Résolution, FPS, Bitrate et Codec (H.264/H.265)
- [ ] Avancée (Anti-scintillement, Exposition, Balance des blancs, Jour/nuit, Rétroeclairage, 3D-NR, HDR, Binning)
  - [x] Anti-scintillement
  - [x] Jour/nuit
  - [ ] Seuil de bascule Jour/Nuit
  - [x] 3D-NR
  - [ ] HDR
  - [ ] Mode Binning (basse lumière)
  - [ ] Exposition
  - [ ] Rétroeclairage
  - [ ] Balance des blancs
- [x] Leds Infra rouge
  - [x] Contrôle des Leds Infra rouge
  - [ ] Intensité des Leds Infra rouge
- [x] Leds blanches d'éclairage (Projecteur Led)
  - [x] Gestion du mode des leds blanches : Off/Auto
  - [x] Activation/Désactivation manuelle des leds blanches
  - [x] Gestion de l'intensité des leds blanches
  - [ ] Température de couleur et planning / mode événement du projecteur
- [ ] Led du bouton de sonnette (Doorbell LED)
- [ ] Masque de vie privée
  - [x] Activation/Désactivation masque de vie privée
  - [ ] Configuration du masque de vie privée
- [x] Mode confidentialité / Privacy Mode (4)
  - [x] Activation/Désactivation du mode confidentialité (Baichuan TCP)

#### Audio & Sonnette
- [x] Sirène
  - [x] Déclenchement manuel de la sirène(2)
  - [x] Volume de la sirène(2)
- [ ] Volume haut-parleur (conversation et bouton sonnette)
- [ ] Réponses rapides vocales (Quick Reply : activation, choix et lecture manuelle d'un message)
- [ ] Réduction de bruit IA et Pré-alarme sonore
- [ ] Carillon Reolink Chime (association, mélodie, volume, LED, mode silencieux)

#### Surveillance/Notification
- [ ] Enregistrement SDCARD/HDD (cameras AI **ET** non-AI)
  - [x] Activation/désactivation Enregistrement SDCARD/HDD
  - [x] Ecraser les enregistrements
  - [x] Enregistrement avant détection (pré-enregistrement)
  - [x] Durée enregistrement après détection
  - [ ] Enregistrement manuel (Start/Stop) et durée de découpage (`pakTime`)
  - [ ] Planning enregistrement SDCARD/HDD
- [ ] Email (cameras AI **ET** non-AI)
  - [x] Activation/désactivation Email
  - [ ] Planning email
- [ ] FTP (cameras AI **ET** non-AI)
  - [x] Activation/désactivation FTP
  - [ ] Planning FTP
- [ ] Alarme audio (cameras AI **ET** non-AI)
  - [x] Activation/désactivation Alarme audio
  - [ ] Planning Alarme audio
- [ ] Push (cameras AI **ET** non-AI)
  - [x] Activation/désactivation Push
  - [x] Intervalle Push (`PushCfg`)
  - [ ] Planning Push
- [ ] Règles / Linkages Reolink (Home Hub / IFTTT)
- [x] Activation/désactivation Enregistrement audio

#### Detection de mouvement & IA
- [x] Remontée des détections en temps-réel (ONVIF : Mouvement, Visage, Personne, Véhicule, Chien/Chat, Sonnette)
- [ ] Push d'événements temps-réel via Baichuan TCP (modèles sans ONVIF : E1 Pro, Lumus, sonnettes…)
- [ ] Suivi automatique (AI Track)
  - [x] Activation/désactivation AI Track
  - [ ] Méthode de suivi, limites gauche/droite et délais d'arrêt
- [ ] Capteur PIR (activation, sensibilité, intervalle)
- [ ] Configuration de la Détection de mouvement & IA
  - [ ] Activation/désactivation globale de la détection de mouvement
  - [ ] Configuration de la zone de detection de mouvement (cameras AI **ET** non-AI)
  - [x] Sensibilité par défaut (cameras AI **ET** non-AI)
  - [ ] Planning Sensibilité (cameras AI **ET** non-AI)
  - [x] Sensibilité Detection intelligente Personne **(cameras AI)**
  - [x] Sensibilité Detection intelligente Véhicule **(cameras AI)**
  - [x] Sensibilité Detection intelligente Chien/Chat **(cameras AI)**
  - [x] Delai d'alarme Personne **(cameras AI)**
  - [x] Delai d'alarme Véhicule **(cameras AI)**
  - [x] Delai d'alarme Chien/Chat **(cameras AI)**
  - [ ] Détection Colis (`Package` : événement, sensibilité, délai)
  - [ ] Détection Pleurs de bébé (`Cry`)
  - [ ] Détections périmétriques IA (Franchissement de ligne, Intrusion, Flânerie, Objet oublié/retiré)
  - [ ] Détection de sabotage (`Tamper`) et détection étendue YOLO World
  - [ ] Dimension d'objet Personne / Véhicule / Chien-Chat **(cameras AI)**


#### OSD
- [x] Afficher/Masquer Watermark
- [x] Afficher/Masquer Nom de la caméras
- [x] Afficher/Masquer Date/heure
- [x] Régler la position du nom de la caméras
- [x] Régler la position de la date/heure


(1): Ne fonctionne qu'avec les Cameras ayant la fonctionnalité (Ability) "supportPtzCheck" : E1Outdoor, RLC-523WA et RLC-823A UNIQUEMENT

(2): Ne fonctionne qu'avec les Cameras ayant la fonctionnalité (Ability) "supportAudioAlarm"

(3): Ne fonctionne qu'avec les **Cameras AI** ayant la fonctionnalité (Ability) "ptzPreset" ET les commandes Get(Set)PtzGuard intégrées aux firmwares : E1Outdoor, RLC-523WA et RLC-823A UNIQUEMENT

(4): Via protocole Baichuan (port 9000), distinct du masque vidéo. Détection automatique (remoteAbility + sondage) ; typiquement E1 Zoom et firmwares récents avec Privacy Mode dans l'appli Reolink. Quand le mode est actif, HTTP/RTSP peuvent être coupés sur les caméras autonomes.
