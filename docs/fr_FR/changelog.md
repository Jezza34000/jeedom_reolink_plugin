# Changelog plugin Reolink

>**IMPORTANT**
>
>Pour rappel s'il n'y a pas d'information sur la mise à jour, c'est que celle-ci concerne uniquement de la mise à jour de documentation, de traduction ou de texte.

# 20/11/2026

- Pont plugin Caméra : Snap par token + sync auto des équipements
- Live `go2rtc` Low/High (proxy fMP4 authentifié)
- Buzzer NVR/canal, enregistrement manuel, IA Visage/Colis, firmware/NTP/SyncTime
- SetEnc dynamique + ISP avancés (HDR/Binning/seuil Jour-Nuit) + RMW SetWhiteLed
- ONVIF : canal `Source`, events IA sans reset motion ; plus de ping ICMP
- NVR : routage par canal ONVIF ; `onvifPort` dans cron10 ; AI PHP 8 sûr
- `refreshNFO` : `scheduleEnable` V20, FPS/bitrate, HddInfo vide, `bSmartTrack`, Md/AiAlarm PHP 8
- Actions `Set*` : V20/`channel`, Reboot, RMW Enc/Isp/Osd/RecV20/AiCfg
- Privacy : skip cron10 ONVIF si privacy ON ; Baichuan `subItem` / `sigVer=v3`
- Mode confidentialité (Privacy Mode) via Baichuan TCP (E1 Zoom…)
- Fix création/sauvegarde d'équipement : `Unknown column 'cnxByIp'`
- Sondage / nettoyage automatique des commandes non supportées (V1 vs V20, Zoom/Focus, AI, preRec)
- Payloads `GetAiCfg` / `GetPowerLed` avec canal ; filet runtime `unsupported_payloads`
- Timeouts cURL ; plus d'`exit()` dans `refreshNFO` ; resync différé si caméra hors ligne à la MAJ

# 19/11/2026

- Support NVR : canal équipement, souscriptions ONVIF sans doublon, lecture MD/AI par canal
- Correctifs API lot / auth token (PHP 8) ; logs offline apaisés ; parent NVR exclu des boucles caméra
- Commande `ChannelOnline`
- PTZ Patrol : commandes, états, modale de gestion des rondes
- Démon : credentials Jeedom via environnement (fix webhook / permissions)
- Dépendances Python sécurisées (`urllib3` 2.8, `requests` 2.34)
- Démon : fix fuite mémoire, Python 3.13, Session HTTP, threads daemon, arrêt webhook propre
- Webhook : état motion/AI isolé par IP ; ONVIF headers/UTC/`renewtimer` corrigés
- API : session éphémère login/logout, cache par IP, anti max-session

# 03/01/2025

- Fix une fuite mémoire sur le démon
- Plusieurs corrections de bugs mineurs

# 22/06/2024

- Ajout detection IA Chien/Cat
- Mise à jour des modèles supportés dans la description

# 20/06/2024

- Plusieurs corrections de bugs
- Mise à jours des dépendances et du démon pour compatibilité Debian12
- version minimale Jeedom: 4.2

# 20/11/2020

- Présentation officielle V4
- Ajouts d'éléments d'informations et de paramètres pour les commandes

# 16/11/2020

- version minimale Jeedom: 3.3.39 (dernière MAJ critique)

# 04/11/2020

- Nouvelle présentation de la liste des objets

# 07/08/2020

- Ajout de commentaires

# 17/05/2020

- Mise à jour de la documentation
