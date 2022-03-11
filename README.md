# Plugin Reolink pour Jeedom

Ce plugin permet le controle des caméras de marque Réolink qui possède l'API.

### Support des caméras

Supportes :

* La série des RLC-xxxx,
* La série des DUO
* La serie des C1 & C2.
* La E1 Zoom
* La série des RLN-xxxx-x

Non-supportés :

* La série ARGUS
* La série GO
* Les modèles commencant part Bxxx et Dxxx
* Le modèle E1, Keen, Lumus

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

#### Réseau
- [ ] IP/DNS/MASQUE
- [ ] DDNS
- [ ] Wifi
- [ ] UPNP

#### Image/Vidéo
- [x] Luminosité, Contraste, Saturation, Teinte, Netteté
- [x] Retourner Verticalement/Horizontalement
- [ ] Avancée (Anti-scintillement, Exposition, Balance des blancs, Jour/nuit, Rétroeclairage, 3D-NR)
- [x] Contrôle des Leds Infra Rouge
- [x] Activation/désactivation des Leds blanches d'éclairage
- [ ] Configuration des Leds blanches d'eclairage (intensité/planning/)
- [x] Activation/désactivation masque de vie privée
- [ ] Configuration du masque de vie privée

#### Surveillance/Notification
- [x] Activation/désactivation email
- [ ] Planning email
- [x] Activation/désactivation push
- [ ] Planning push
- [x] Activation/désactivation FTP
- [ ] Planning FTP
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
