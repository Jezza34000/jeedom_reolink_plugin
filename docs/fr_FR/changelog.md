# Changelog plugin Reolink

>**IMPORTANT**
>
>Pour rappel s'il n'y a pas d'information sur la mise à jour, c'est que celle-ci concerne uniquement de la mise à jour de documentation, de traduction ou de texte.

# 24/11/2026

- Page et modale **Santé** (`reolink::health()`) : résumé dans **Analyse → Santé** et bouton **Santé** sur la page d'accueil du plugin présentant en lecture instantanée (sans requête réseau ni réveil batterie) le rôle, le mode d'accès/événements, l'état en ligne, la batterie, le firmware et la dernière communication de chaque équipement
- Scènes / Modes Reolink sur **Home Hub** (`GetScenes`, `SetScene`, `SetSceneState`) : pilotage natif en Baichuan (`cmd 601..604`), détection automatique de la capacité `sceneModeCfg` (`cmd 199`) sans commande superflue sur les NVR classiques, liste dynamique des scènes configurées et mise à jour temps réel sur push `cmd 601`
- Mode d'événements temps réel **Baichuan TCP (port 9000)** par équipement & push `cmd 145` : sélecteur optionnel par équipement HTTP/HTTPS (avec héritage NVR → canaux) permettant de recevoir les événements IA/mouvement/confidentialité ainsi que l'état temps réel des canaux et de la veille batterie (`cmd 145` `ChannelOnline` / `BatterySleepState`) en TCP 9000 sans webhook ONVIF entrant
- Découverte automatique des caméras NVR / Home Hub (**Synchroniser les caméras du NVR**) : bouton dédié sur l'équipement NVR / Home Hub parent créant en un clic tous les canaux caméras peuplés (`GetChannelstatus`, caméras en veille incluses, emplacements vides ignorés, aucun doublon sur les canaux existants) avec création automatique des commandes
- Fiabilité de l'état en ligne (`ChannelOnline`), `Tester la connexion` par canal & alertes démon : mise à `0` de `ChannelOnline` lorsque l'équipement est injoignable, contrôle effectif de `GetDevInfo` et de l'état du canal NVR lors du test de connexion, temporisation sur 2 cycles `cron10` des alertes de souscription ONVIF (évite les fausses alertes lors des redémarrages planifiés `AutoMaint`) et formatage Jeedom des journaux Uvicorn
- Vérification des mises à jour firmware officielles Reolink : nouvelle option dans la configuration du plugin permettant de vérifier quotidiennement (`cronDaily`) et à la demande (**Vérifier les firmwares**) la disponibilité de nouveaux firmwares par modèle et révision matérielle (`hardVer`) via l'index `AT0myks/reolink-fw-archive` filtré exclusivement sur les liens HTTPS officiels Reolink, avec badge sur la fiche équipement, téléchargement direct (`.zip`) et commandes `NewFirmwareState` / `LatestFirmware` sur l'ensemble des caméras et NVR (sans accès Internet requis côté caméra et sans réveiller les caméras sur batterie)
- Masque de confidentialité PTZ 3D (`RLC-823S2`, #148) : conservation des zones `screen`/`block` et du paramètre `enableMaskTrack` lors de l'activation ou désactivation de `SetMask`

# 23/11/2026

- Événements push temps réel Baichuan, Sirène, IA & Diagonales PTZ (#130, #134, #136) : écoute temps réel des événements push Baichuan `cmd 33` (`EvMotion`, `MdState`, `EvPeopleDetect`, `EvDogCatDetect`, etc.) dans le démon Python pour le mode `Baichuan (port 9000 uniquement)` sans dépendance externe supplémentaire, ajout de `supportAudioAlarm` (sirène & volume sur `E321`), filtrage/nettoyage strict des événements IA non supportés (`EvVehicleDetect` sur `E321`) et des commandes `EvLastOnvif*` en mode Baichuan, casse `lowerCamelCase` (`leftUp`…) sur `PtzControl` `cmd 18` et maintien des 8 directions PTZ sur `TrackMix` / `RLC-823S2` (`supportPtzSpeed`) contre 4 directions sur la gamme `E1` / `E321`
- Directions PTZ dynamiques (#136) : adaptation automatique de `SetPTZmoove` (`Mouvement PTZ`) et des flèches de la modale Live sur 2, 4 (ex. `E1 Zoom`, `E1 Outdoor`, `E321`) ou 8 directions avec diagonales (ex. `RLC-823S2`, `TrackMix`) selon `ptzDirection`, `supportPtzSpeed` et `ptzCtrl` (`GetAbility` / Baichuan `cmd 199`)
- Documentation & Compatibilité : mise à jour des modèles supportés (`HTTP/HTTPS`, `Baichuan port 9000`, `via NVR / Home Hub`) et explication technique des limites matérielles (4G `Go`, batterie sans Hub/NVR, `Bxxx`/`Dxxx` sans NVR)
- Mode Baichuan E321 (#130, #134) : filtrage de `WhiteLed` selon `ledCtrl`/`lightType`, exclusion de `PtzPatrol` sur `<cruiseList />` vide, détection `aiTrack` via `cmd 299` (`smartTrack`) et détection `sdCard` depuis `DeviceInfo` au login
- Ergonomie, Unités & Rondes PTZ (#136) : suppression de l'unité erronée sur `SetAutoReturnPtzGuardPointState`, ajout des unités `Kbps` et harmonisation `Débit Stream1/2`, numérotation d'affichage `Ronde 1` à `Ronde 6` (clés `0..5` préservées), ajout de l'état `SetSpeedState` lié au curseur `SetSpeed` et clarification de l'aide `SetAutoFocus`
- Commandes ISP, Encodage & ONVIF (#136) : correction des valeurs API `SetAntiFlicker` (`Other`), `SetExposure` (`Anti-Smearing`) et `SetBackLight` (`ispBackLight`) avec filtrage dynamique via `GetIsp` (`action=1`), remontée cron des codecs H.264 fixes (`SetEncodingst1State`/`SetEncodingst2State`) et retrait des sélecteurs de codec fixes/vides, suppression de la commande morte `EvMotionAlarm` et restriction de `EvVisitor` aux sonnettes (`Doorbell`)
- UID caméra autonome (#137) : correction de la requête `GetP2p`, prise en charge des caméras autonomes avec canal `0` renseigné et fallback Baichuan `cmd 114`

# 22/11/2026

- Actions V20 sur caméras autonomes vs NVR : envoie et lit automatiquement `enable` (`0`/`1`) sur les caméras autonomes (`SetEmailV20`, `SetFtpV20`, `SetPushV20`, `SetRecV20`, `SetBuzzerAlarmV20`) tout en conservant `scheduleEnable` par canal derrière un NVR, sans recréation de commandes requise (retours Community)
- Mode Baichuan (port 9000 uniquement) : prise en charge native des caméras Reolink n'ouvrant que le port TCP 9000 (ex. `E321`), captures JPEG via `cmd 109` et outil Expert **Diagnostic Baichuan (XML)** avec export anonymisé (#130)
- Rondes PTZ, Zoom/Focus, Diagonales PTZ & IR : dé-cumul automatique des rondes PTZ (`E1 Zoom`, `E1 Outdoor`, `TrackMix`, `RLC-823S2`), support `ptzPatrol` et bornes dynamiques `Zoom`/`Focus` sur `TrackMix` & `RLC-823S2`, ajout des diagonales PTZ, mode `On` sur `SetIrLights` et mise à jour automatique des listes existantes (#119, #129)
- Synchronisation plugin Caméra : protocole `http`/`https` + port web cohérents dans l'interface du plugin `camera`, port RTSP explicite dans l'URL de flux et propagation de l'objet parent lors des resynchronisations (#117)
- Privacy Mode : suppression de l'erreur transitoire « Réponse vide » dans `jeeReolink.php` lorsqu'un événement ONVIF survient pendant le réveil HTTP de la caméra (#122)

# 21/11/2026

- Caméras sur batterie via NVR / Home Hub : niveau de batterie (intégré à la jauge native Jeedom), état de charge, température batterie et mode veille, avec polling économe sans réveil inutile (`NONE_WAKING_COMMANDS`), cache snapshot en veille, réveil passif ONVIF et fallback Baichuan
- Privacy Mode : sortie de veille Baichuan alignée sur `reolink_aio` (sans modification `NetPort` ni coupure immédiate), sonde CGI HTTP 200 au réveil, purge du cache plugin Caméra et rafraîchissement automatique post-réveil (#122)
- Rondes PTZ : activation automatique du slot à l'ajout d'étape pour conserver les presets sur la caméra et clarification `Désactiver` vs `Arrêter` (#119)
- Performances UI : ouverture des équipements et de l'onglet Commandes fluide (rendu différé hors-DOM, préchauffage config async, icônes lazy/async)
- UI rondes PTZ : Activer/Désactiver en boutons Jeedom verts/oranges (Dark)
- UI : assistant cron pré-rempli, rondes PTZ lisibles en Dark, aide `?` cliquable et détaillée sur chaque commande, corrections orthographiques FR et i18n EN/ES (issue #119)
- Privacy Mode : désactivation fiable (attente réveil HTTP, ports Baichuan, plus d’erreurs Login pendant le reboot caméra)
- Live `go2rtc` : binaire détecté dans les dépendances ; install atomique ; relance automatique ; plus de `go2rtc.yaml` / port RTSP ouvert ; message clair si le service est down (issue #118)

# 20/11/2026

- NVR/canaux : vrai modèle caméra (`GetChnTypeInfo`), séparation commandes host NVR vs canal, sync Caméra avec device mappé
- Stabilité : options select/slider, listes SetEnc auto, filtre IA Face/Colis, RMW Buzzer/AiAlarm, CheckFirmware 12h/IP
- API : codes -12/-17 sans retry ERROR ; démon et sendToDaemon plus robustes
- Pont plugin Caméra : Snap par token + sync auto des équipements
- Live `go2rtc` Low/High (proxy fMP4 authentifié)
- Live : arrêt propre des flux, PTZ pas/clic, select Jeedom corrigé
- `GetHddInfo` : PHP 8 sûr sans carte SD / capacité nulle
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
