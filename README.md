# Plugin Reolink pour Jeedom

Ce plugin permet le controle des caméras de marque Réolink qui possède l'API.
Il ne permet pas le visionnage des flux vidéo, pour ceci vous devrait utiliser le plugin Jeedom officiel "Caméra"

### Support des caméras

Supportes :

* La série des RLC-xxxx
* La série des RLN-xxxx-x
* La série des DUO
* La serie des C1 & C2.
* La E1 Zoom

Non-supportés :

* La série ARGUS
* La série GO
* Les modèles commencant part Bxxx & Dxxx
* Le modèle Keen, Lumus, E1 & E1 Pro

### Page de configuration du plufin :

> **Ces paramètres ne doivent être modifier que si vous rencontrer des problèmes.**

- **Methode d'authentification GET** : Modification de la manière dont le plugin s'authentifie pour utiliser l'API.
- **Taille des blocs commandes** : Modification du nombres de commandes envoyé dans un seul bloc d'appel API, plus les blocs sont gros plus l'exécution sera rapide, mais le risque de faire planter la caméra est plus grand, plus les blocs sont petit, plus l'execution sera lente mais economise la caméra en terme d'usage.
- **IP de callback du webhook** : Pour obtenir les détections de mouvements la caméra envoi les évènements ONVIF à un webhook. Ce webhook est le daemon du plugin, il s'agit ici de l'IP qui est transmise à la caméra sur laquelle elle devra envoyer ses notifications. \
Les options correspondent aux paramètres que vous avez dans : Réglages > Système > Configuration > Réseaux (sous votre Jeedom)
- **IP personnalisée** : Si vous souhaiter personnaliser l'IP vers laquelle la caméra doit renvoyer ses notifications ONVIF.
- **Port du webhook** : Port d'appel du webhook depuis la caméra.


### Listes des fonctions de l'API intégré dans le plugin :

#### Système
- [x] Login
- [x] Logout
- [x] Reboot
- [x] Obtention des informations de la caméra
- [x] Obtention des capacités hardware/software de la caméra
- [x] Auto Reboot
- [ ] Gestion des utilisateurs (ajout/supression/modification)
- [ ] Gestion de l'heure
- [ ] Restaurer la config par défaut
- [ ] Formattage de l'espace de stockage
- [x] Contrôle de la Led d'état
- [ ] Controle des mise à jour logiciel

#### PTZ
- [x] Zoom
- [x] Focus
- [x] Mouvement (Haut/Bas/Gauche/Droite)
- [x] Récupération des presets PTZ
- [x] Utilisation des presets PTZ
- [x] Activation/Désactivation du PTZ Patrol
- [ ] PTZ Guard
- [ ] Schéma/Chemin PTZ
- [ ] PTZ Serial
- [X] Calibration de la camera (Etat/Exécution) (1)

#### Réseau
- [ ] IP/DNS/MASQUE
- [ ] DDNS
- [ ] Wifi
- [X] Activation/Désactivation UPNP
- [X] Activation/Désactivation P2P

#### Image/Vidéo
- [x] Luminosité, Contraste, Saturation, Teinte, Netteté
- [x] Retourner Verticalement/Horizontalement
- [ ] Avancée (Anti-scintillement, Exposition, Balance des blancs, Jour/nuit, Rétroeclairage, 3D-NR)
- [x] Contrôle des Leds Infra Rouge
- [x] Activation/Désactivation des Leds blanches d'éclairage
- [X] Configuration des Leds blanches d'eclairage (Intensité)
- [x] Activation/Désactivation masque de vie privée
- [ ] Configuration du masque de vie privée

#### Audio
- [x] Déclenchement manuel de la sirène(2)
- [X] Volume de la sirène(2)

#### Surveillance/Notification
- [x] Activation/désactivation email
- [ ] Planning email
- [x] Activation/désactivation push
- [ ] Planning push
- [x] Activation/désactivation FTP
- [ ] Planning FTP
- [x] Activation/désactivation AI track
- [x] Activation/désactivation enregistrement SDCARD/HDD
- [ ] Planning enregistrement SDCARD/HDD
- [x] Activation/désactivation alarme audio
- [ ] Planning alarme audio
- [ ] Configuration de la détection de mouvement
- [ ] Configuration des fonctions AI
- [x] Remontée des détections de mouvements en temps-réel

#### OSD
- [x] Afficher/Masquer Watermark
- [x] Afficher/Masquer Nom de la caméras
- [x] Afficher/Masquer Date/heure
- [x] Régler la position du nom de la caméras
- [x] Régler la position de la date/heure


(1): Ne fonctionne qu'avec les Cameras ayant la fonctionnalité (Ability) "supportPtzCheck" 

(2): Ne fonctionne qu'avec les Cameras ayant la fonctionnalité (Ability) "supportAudioAlarm" 
