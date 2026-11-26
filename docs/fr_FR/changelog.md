# Changelog plugin Reolink

>**IMPORTANT**
>
>Pour rappel s'il n'y a pas d'information sur la mise à jour, c'est que celle-ci concerne uniquement de la mise à jour de documentation, de traduction ou de texte.

# 26/11/2026

- Compatibilité Apache `AllowOverride All` (`core/php/.htaccess`) & flux local **RTSP Opus** pour `JeedomConnect` : autorisation des points d'entrée authentifiés (`jeeReolink.php`, `snap.php`, `stream.php`, `live.php`) sur Jeedom 4.5 / 4.6 par défaut, pré-enregistrement automatique non-éveillant des flux dans `go2rtc` au démarrage du démon, et ajout du champ **`RTSP Flux local (Opus)`** (`rtsp://127.0.0.1:44014/reolink_<id>_sub?video=h264&audio=opus`) sur chaque équipement caméra pour bénéficier du son ambiant entrant en WebRTC dans `JeedomConnect`
- Cache de session HTTP CGI & fluidité des rafales Zoom/Focus : conservation en mémoire vive du token de session Reolink (`3600 s`) avec rotation propre sous verrou exclusif (`Logout` préalable de l'ancien token avant renouvellement pour n'occuper qu'une seule session sur le NVR, renouvellement automatique sur expiration ou redémarrage caméra/NVR), fusion côté serveur des consignes `StartZoomFocus` intermédiaires et debounce client `180 ms` sur les boutons Zoom/Focus du lecteur Live afin de supprimer les erreurs `Caméra injoignable ou connexion impossible (StartZoomFocus)` et les allers-retours du moteur optique lors de clics rapides sur Zoom `+`/`-` ou Focus
- Option **Live WebRTC & Microphone** dans la configuration du plugin (`enable_webrtc`, désactivée par défaut) : fonctionnement en pur **fMP4** par défaut (compatible nativement avec tous les reverse proxies HTTPS, aucun port ICE `:44012` ouvert), activation à la demande du WebRTC basse latence et du bouton **Parler** avec modale d'aide complète (fMP4 vs WebRTC, exposition publique NAT et sécurité DTLS-SRTP du port ICE), champ optionnel **Candidats ICE externes** pour l'accès hors LAN, relance automatique du démon à l'enregistrement, maintien permanent du pont audio local (`127.0.0.1:44013`) pour `JeedomConnect` et correction du repli ICE vers fMP4 sans rechargement parasite
- Tuile vidéo Dashboard / Design, modale Live enrichie, WebView `JeedomConnect` & captures en scénario (#152094) : nouveau mode d'affichage par caméra (`Commandes seules` par défaut, `Vignette périodique + Live / Interphone au clic` sans réveil des caméras sur batterie, ou `Live direct automatique` coupé hors écran), enrichissement de la modale Live (**Zoom -/+**, **Focus -/+**, **Presets PTZ** avec sauvegarde directe, **Rondes PTZ**, **Réponse rapide** sonnette, **Plein écran**), page WebView autonome `live.php` à jeton révocable pour l'interphone vidéo dans `JeedomConnect`, et nouvelle commande **`Envoyer une capture`** (`SendSnapshot`) pour expédier des clichés JPEG vers Telegram, Mail, Discord ou JeedomConnect directement depuis les scénarios sans le plugin `Caméra`
- Sonnettes **Reolink Video Doorbell** & carillons sans fil **Reolink Chime** (#152094) : prise en charge des messages vocaux pré-enregistrés (`Réponse rapide` / `QuickReplyPlay`), de la réponse automatique après sonnerie (`Réponse auto`, message et délai `1–60 s`), de l'anneau lumineux du bouton (`LED bouton sonnette`), des volumes dédiés interphone et sonnerie extérieure (`cmd 264/265`), et des carillons sans fil **Reolink Chime** appairés sur sonnette, canal NVR ou **Home Hub** (`Faire sonner le carillon`, volume `0–4`, LED, mélodie visiteur et mise en sourdine temporaire `15 min – 4 h`), sans réveiller les sonnettes sur batterie en veille
- Live multi-flux, coexistence `go2rtc` avec `JeedomConnect` & audio bidirectionnel universel Baichuan (#138, #152094) : séparation des ports `go2rtc` (`127.0.0.1:44011` API et `:44012` WebRTC, configurables) pour cohabiter sans conflit avec `JeedomConnect` (`:1984`/`:8555`), prise en charge dynamique des flux `Low (sub)`, `Fluent (ext)` H.264, `High (main)`, 2e objectif `Téléobjectif Low/High` (`TrackMix` / `supportAutoTrackStream`) et `JPEG (cmd 109)`, transcodage audio WebRTC Opus à la demande et serveur local RTSP backchannel (`127.0.0.1:44013` → Baichuan TCP `9000` `16 kHz IMA-ADPCM`) activant le bouton **Parler** sur caméras autonomes, sonnettes et canaux NVR / Home Hub
- Organisation des commandes par onglets & allègement du Dashboard par défaut : découpage des commandes en 6 onglets thématiques (**Détections & IA**, **PTZ & Rondes**, **Éclairage & Audio**, **Alertes & Enreg.**, **Image & Encodage**, **Système**, onglets vides masqués automatiquement), filtre rapide **Toutes / Infos (lecture) / Actions** + recherche texte, affichage par défaut allégé à la création sur le Dashboard (commandes courantes uniquement, sans modifier les équipements existants) et bouton **Affichage par défaut** (sur confirmation) pour réappliquer cette sélection sur une caméra déjà installée
- Vérification des mises à jour firmware : affichage d'un état neutre `Inactif — données non actualisées` sur les équipements désactivés (fiche équipement, vérification unitaire, vue liste et modale **Santé**) et remise à zéro immédiate de `NewFirmwareState` lors de la désactivation afin d'éviter tout faux positif sur d'anciens équipements inactifs

# 25/11/2026

- Correction du plantage MySQL `1054` à l'ajout et à la sauvegarde d'une caméra (`Unknown column 'lastBaichuanSyncHash' in 'INSERT INTO'` / `UPDATE`) : préfixage de la propriété statique interne `$_lastBaichuanSyncHash` conformément à l'ORM Jeedom (`utils::o2a()` / `DB::buildField()`)
- Protection anti-saturation de Jeedom lorsqu'une caméra est plantée ou hors ligne (`Too many connections` SQL / blocage des scénarios) : disjoncteur automatique par hôte (`30 s → 60 s → 120 s` sur timeout ou `502 Bad Gateway`, réinitialisé dès qu'un test ou rafraîchissement manuel est lancé), verrou de session non bloquant (`2 s` max au lieu d'une file d'attente illimitée), timeouts HTTP ramenés à `3 s / 6 s` sans triple retry sur caméra muette ou en erreur `502`, suppression du double `login()` sur les captures JPEG, arrêt immédiat des appels `getSnapshot` / `snap.php` / `stream.php` / `jeeReolink` lorsque l'équipement ou le plugin est désactivé, et protection anti-chevauchement de `reolink::cron()`
- Stabilité des sessions push **Baichuan TCP (port 9000)** : réduction du keepalive de `50 s` à `20 s` (avec ajustement adaptatif jusqu'à `9 s` aligné sur `reolink_aio` et `SO_KEEPALIVE` TCP) pour prévenir les fermetures de session au bout de ~30 s d'inactivité sur les caméras autonomes, et suppression du délai de backoff de `40–45 s` en cas de fermeture par la caméra

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
