# Change log

25/11/2026

- Stabilité des sessions push **Baichuan TCP (port 9000)** (`resources/demond/baichuan_push.py`) : abaissement de l'intervalle de keepalive applicatif de `50 s` à **`20 s`** (avec ajustement adaptatif jusqu'à `9 s` aligné sur `reolink_aio` et `SO_KEEPALIVE` TCP) afin d'envoyer le keepalive (`cmd 93` / `cmd 31`) avant l'expiration du délai d'inactivité de ~30 s des caméras autonomes Reolink ; reconnexion immédiate (`1 s` au lieu du backoff exponentiel `40–45 s`) et journalisation en `DEBUG` lors d'une fermeture propre de socket par la caméra sans perte d'événements ni bruit dans `reolinkd`

24/11/2026

- Page et modale **Santé** du plugin (`reolink::health()` & `desktop/modal/health.php`) : indicateurs synthétiques dans **Analyse → Santé** (équipements actifs, caméras en ligne, disponibilité locale `go2rtc` `127.0.0.1:1984`, mises à jour firmware) et bouton **Santé** sur la page du plugin affichant l'état consolidé de tous les NVR, Home Hub et caméras (rôle, modèle/révision, mode d'accès et d'événements, statut en ligne/veille, batterie, firmware et dernière communication) en lecture purement locale (zéro requête réseau vers les caméras, zéro réveil batterie)
- Scènes / Modes Reolink sur **Reolink Home Hub / Home Hub Pro** (`GetScenes`, `SetScene`, `SetSceneState`) : prise en charge native par le protocole Baichuan (`cmd 199` `<sceneModeCfg>`, `cmd 603` `sceneListID`, `cmd 604` `sceneCfg`, `cmd 601/602` `sceneModeCfg` — vérifié sur firmware Home Hub `v3.3.0.601`), avec détection automatique de la capacité sur l'équipement hôte (aucune commande superflue créée sur les NVR classiques), remplissage dynamique du sélecteur **Activer une scène** (`-1` = Désactiver + scènes personnalisées `Home`, `Away`, `Night`…) et synchronisation temps réel des changements de scène déclenchés depuis l'application Reolink (`cmd 601` push)
- Mode d'événements temps réel **Baichuan TCP (port 9000)** par équipement & état canal/veille `cmd 145` : nouveau sélecteur **Mode d'événements temps réel** sur les équipements HTTP/HTTPS (caméras autonomes, NVR et Home Hub, avec héritage automatique sur les canaux enfants) permettant d'opter au choix pour `ONVIF (webhook HTTP)` ou `Baichuan TCP (port 9000)` sans ouvrir de port webhook entrant vers Jeedom, et prise en charge temps réel des trames push `cmd 145` (`ChannelInfoList` : `ChannelOnline` et `BatterySleepState`, incluant les états `standby`, `pwdError`, `connectFailed` et `unsupportedFirmware` des Home Hub / NVR ainsi que les hôtes `HVR` et `DVR`)
- Découverte automatique des caméras NVR / Home Hub en un clic (**Synchroniser les caméras du NVR**) : nouveau bouton sur la fiche d'un NVR ou Reolink Home Hub parent interrogeant `GetChannelstatus` pour créer automatiquement un équipement Jeedom par canal caméra peuplé (y compris caméras sur batterie en veille, tout en ignorant les emplacements NVR vides et sans dupliquer les canaux déjà existants), avec récupération automatique des informations, création des commandes et lecture initiale des états
- Fiabilité de l'état en ligne (`ChannelOnline`), `Tester la connexion` par canal & alertes démon : passage immédiat de `ChannelOnline` à `0` lorsque l'équipement hôte (caméra autonome ou NVR) est injoignable lors du rafraîchissement et remise à `1` même lorsque `GetMdState` n'est pas actif ; vérification effective de `GetDevInfo` et de la présence/état du canal (`GetChannelstatus`) lors d'un clic sur **Tester la connexion** ; temporisation sur 2 cycles `cron10` consécutifs des alertes de souscription ONVIF afin d'absorber les redémarrages planifiés (`AutoMaint`) sans fausse alerte dans le Centre de messages ; harmonisation du format d'horodatage des journaux Uvicorn dans `reolink_daemon`
- Vérification des mises à jour firmware officielles Reolink : nouvelle option dans la configuration du plugin (`check_fw_github`, activée par défaut) s'appuyant sur l'index communautaire `AT0myks/reolink-fw-archive` tout en filtrant strictement les liens HTTPS hébergés sur les serveurs officiels Reolink (`home-cdn.reolink.us`, `*.reolink.com`, `reolink-storage.s3.amazonaws.com`) ; vérification quotidienne (`cronDaily` avec cache local 12 h), tuile **Vérifier les firmwares** sur la page d'accueil du plugin avec tableau récapitulatif et téléchargement direct (`.zip`), badge d'état + bouton de vérification sur la fiche équipement (`Version firmware`), et mise à jour des commandes `NewFirmwareState` / `LatestFirmware` / `CheckFirmware` sur tous les équipements (caméras autonomes HTTP/HTTPS, mode Baichuan port 9000, NVR et caméras rattachées y compris sur batterie sans réveil)
- Masque de confidentialité sur dômes PTZ 3D (`RLC-823S2`, #148) : conservation des coordonnées des zones de masquage existantes (`screen` + `block` lus via `GetMask`) lors de `SetMask` et préservation du champ `enableMaskTrack` sur les firmwares PTZ à masquage dynamique 3D

23/11/2026

- Événements push temps réel Baichuan, Sirène, IA & Diagonales PTZ (#130, #134, #136) : écoute persistante des notifications push Baichuan `cmd 33` (`AlarmEventList` via souscription `cmd 31`, sans dépendance Python supplémentaire grâce à OpenSSL `libcrypto`) dans le démon `reolinkd.py` pour remonter en temps réel `EvMotion`, `MdState`, `EvPeopleDetect`, `EvDogCatDetect`, `EvVehicleDetect`, `EvFaceDetect`, `EvPackageDetect`, `EvVisitor` et `SetPrivacyModeState` (`cmd 623`) sur les caméras en mode `Baichuan (port 9000 uniquement)` ; exposition de `supportAudioAlarm` (`AudioAlarmPlayTimes`, `SetSirenVolume`, `SetSirenVolumeState`) et `supportPtzCheck` dans `reolinkBaichuanAdapter` ; respect des capacités IA réelles par canal dans `GetAiState` (`aitype`/`cmd 299`) et nettoyage automatique des commandes `Ev*Detect` non supportées ainsi que `EvLastOnvif*` en mode Baichuan ; envoi des commandes `PtzControl` `cmd 18` en `lowerCamelCase` (`leftUp`/`rightUp`/`leftDown`/`rightDown`/`stop`) et maintien des 8 directions PTZ sur les caméras à vitesse variable (`supportPtzSpeed` / bit 6 de `ptzControl` : `TrackMix`, `RLC-823S2`) tout en conservant 4 directions sur les motorisations pas-à-pas mono-axe (`E1`, `E1 Pro`, `E1 Zoom`, `E1 Outdoor`, `E321`)
- Directions PTZ dynamiques (#136) : adaptation automatique de `SetPTZmoove` (`Mouvement PTZ`) et des flèches de la modale Live sur 2 (`Left`/`Right`), 4 (`Up`/`Down`/`Left`/`Right` — ex. gammes `E1`, `E1 Zoom`, `E1 Outdoor`, `E321` dont le contrôleur moteur ne gère qu'un axe à la fois) ou 8 directions avec diagonales (`LeftUp`/`RightUp`/`LeftDown`/`RightDown` — ex. `RLC-823S2`, `TrackMix`) selon `ptzDirection`, `supportPtzSpeed` et `ptzCtrl` (`GetAbility` / Baichuan `cmd 199`)
- Documentation & Compatibilité : mise à jour de `plugin_info/info.json` et `README.md` (support direct Baichuan port 9000 pour `E1`/`E1 Pro`/`E321`/`Lumus`, support `Argus`/batterie et `Bxxx`/`Dxxx` via NVR ou Home Hub, et explication technique des modèles non supportables en direct)
- Mode Baichuan E321 (#130, #134) : filtrage de `WhiteLed` selon `ledCtrl`/`lightType` (caméras sans projecteur), exclusion de `PtzPatrol` sur `<cruiseList />` vide (`ptzPatrol=0`), détection du suivi automatique `aiTrack` via `cmd 299` (`smartTrack`/`smartTrackPt`), détection `sdCard`/`recPreRecord` depuis `DeviceInfo` au login et lecture des résolutions/codecs `StreamInfoList`
- Ergonomie, Unités & Rondes PTZ (#136) : suppression de l'unité erronée sur `SetAutoReturnPtzGuardPointState`, ajout des unités `Kbps` et harmonisation `Débit Stream1/2` (avec synchronisation automatique des unités à la resynchronisation), affichage `Ronde 1` à `Ronde 6` aligné sur `Cruise 1..6` (clés `0..5` préservées pour les scénarios), ajout de l'état `SetSpeedState` lié au curseur `SetSpeed` et clarification de l'aide `SetAutoFocus`
- Commandes ISP, Encodage & ONVIF (#136) : correction des valeurs API `SetAntiFlicker` (`Other`), `SetExposure` (`Anti-Smearing`) et `SetBackLight` (`Off`/`BackLightControl`/`DynamicRangeControl` conditionné à `ispBackLight`) avec filtrage dynamique via `GetIsp` (`action=1`), remontée cron de `SetEncodingst1State`/`SetEncodingst2State` sur les flux mono-codec H.264 et retrait des sélecteurs de codec fixes/vides, suppression de la commande morte `EvMotionAlarm`, restriction de `EvVisitor` aux sonnettes (`Doorbell`) et garde sur `ptzPatrol` vide
- UID caméra autonome (#137) : correction de la requête `GetP2p` (casse officielle IPC), prise en charge des caméras autonomes avec canal `0` renseigné et fallback Baichuan `cmd 114` lorsque le compte HTTP est restreint

22/11/2026

- Actions V20 sur caméras autonomes vs NVR : envoie et lit automatiquement `enable` (`0`/`1`) sur les caméras autonomes (`SetEmailV20`, `SetFtpV20`, `SetPushV20`, `SetRecV20`, `SetBuzzerAlarmV20`) tout en conservant `scheduleEnable` par canal derrière un NVR, sans recréation de commandes requise (retours Community)
- Mode de connexion Baichuan seul (port TCP 9000 uniquement, issue #130) : nouveau type de connexion `Baichuan (port 9000 uniquement)` via `reolinkBaichuanAdapter` pour les caméras sans HTTP/HTTPS/RTSP/ONVIF (ex. `Reolink E321`), captures JPEG natives par `cmd 109` (`SendSnap` compatible widget Jeedom et plugin `camera`), pilotage complet (identité `80`/`318`, capacités `199`, PTZ/presets/garde/zoom `18`/`19`/`190`/`331`/`332`/`294`/`295`, IR/PowerLED/Floodlight `208`/`209`/`289`/`290`, image/ISP/OSD/encodage `26`/`25`/`296`/`297`/`44`/`45`/`56`/`57`, sirène/audio `263`/`264`/`265`, MD/IA/enregistrement `46`/`47`/`299`/`300`/`81`/`82`, événements `33`, confidentialité `574`/`575`), et bouton Expert **Diagnostic Baichuan (XML)** générant un export XML complet anonymisé
- Rondes PTZ, Zoom/Focus, Diagonales PTZ & IR (#119, #129) : dé-cumul automatique des étapes `GetPtzPatrol` inter-slots (`normalizePatrols`), activation de `ptzPatrol` sur `TrackMix` (`ver=0`) et vidage/désactivation de slot compatible `TrackMix`, calibrage dynamique des bornes `minValue`/`maxValue` de `SetZoom` et `SetFocus` via `GetZoomFocus` (`1000..6000` sur `TrackMix`, `0..1050` sur `RLC-823S2`), ajout des 4 diagonales PTZ (`LeftUp`, `RightUp`, `LeftDown`, `RightDown`), option `On|Activer` sur `SetIrLights` et synchronisation automatique des `listValue` statiques existantes
- Synchronisation plugin Caméra : renseigne `protocole` (`http`/`https`) et `port` web (`80`/`443` ou personnalisé) conformes au sélecteur du plugin officiel `camera`, inscrit explicitement le port RTSP (`:554`) dans `cameraStreamAccessUrl`, et resynchronise l'objet parent lorsqu'il est défini (retour #117)
- Privacy Mode : ignore le poll HTTP `GetAiState`/`GetMdState` du callback ONVIF `jeeReolink.php` pendant le Mode confidentialité ou la fenêtre de réveil HTTP `waitHttpPortReady`, évitant l'erreur transitoire « Réponse vide » lorsque ONVIF redémarre 1 s avant nginx/HTTPS (retour #122)

21/11/2026

- Caméras sur batterie via NVR / Home Hub : commandes `Batterie` (+ jauge native Jeedom), `Batterie en charge`, `État de charge`, `Température batterie` et `Mode veille (état)` (`GetBatteryInfo` / `GetChannelstatus` + fallback Baichuan `cmd 253` / `145`), support `HOMEHUB` / `WIFI_NVR`, polling économe `NONE_WAKING_COMMANDS` aligné sur Home Assistant (`reolink_aio`), cache snapshot en veille et réveil passif sur événement ONVIF
- Privacy Mode : alignement de la sortie de veille Baichuan sur `reolink_aio` (maintien session + drain push `623` sans toucher `NetPort` `cmd 37/36`), vérification CGI HTTP 200 réelle (`waitHttpPortReady`), purge du cache du plugin Caméra et `refreshNFO` post-réveil, gardes NVR/Standalone unifiés (retour #122)
- Rondes PTZ : activation automatique de l'emplacement (`enable=1`) à l'ajout d'une étape pour éviter la purge firmware (`preset: null`) et clarification de l'action `Désactiver` vs `Arrêter` (issue #119)
- Performances UI : ouverture équipement et onglet Commandes accélérés (préchauffage async `jeedom.getConfiguration`, rendu différé de `#table_cmd` en `DocumentFragment` hors-DOM, options `info` mises en cache, sauvegarde sans réécriture SQL des commandes non ouvertes, icônes lazy/async et `content-visibility`)
- UI rondes PTZ : Activer/Désactiver en vrais boutons Jeedom (vert/orange), plus le toggle radio Bootstrap illisible en Dark
- UI : assistant cron Auto-actualisation branché et pré-rempli ; rondes PTZ Activer/Désactiver lisible en Dark ; aide `?` cliquable et colorée (`--al-info-color`) sur chaque commande (description + valeurs détaillées, sans recréation requise) ; corrections orthographiques FR et i18n `en_US` / `es_ES` (issue #119)
- Privacy Mode : sortie propre sur caméra autonome (poll Baichuan 574, réouverture HTTP/HTTPS via cmd 37/36, fenêtre de réveil anti-spam Login HTTPS du widget Caméra) — retour Community / PR #92
- Live `go2rtc` : dépendances détectent le binaire manquant après MàJ ; téléchargement atomique avant fin d'install ; démon relance `go2rtc` si arrêté ; config inline (plus de `go2rtc.yaml` / RTSP `:8554`) ; erreur explicite si `127.0.0.1:1984` injoignable (issue #118)

20/11/2026

- NVR/canaux : modèle caméra via `GetChnTypeInfo` (ex. E1 Outdoor PoE) au lieu du modèle NVR ; commandes host (NTP/firmware/HDD/UPnP…) uniquement sur le parent NVR ; sync plugin Caméra avec `device` mappé
- Stabilité : validation des options `select`/`slider` (plus d'erreur « valeur à inserer »), listes `SetEnc` auto-peuplées, refus des `size` vides
- IA : filtre `supportAiFace`/`supportAiPackage` (+ `GetAiState`) ; RMW `SetBuzzerAlarmV20` / `SetAiAlarm` ; `CheckFirmware` throttlé 12 h/IP
- API : `-12`/`-17` sans retry/`ERROR` ; `deamon_start` détecte vraiment l'échec ; `sendToDaemon` avec timeout ; gardes `is_object`
- Pont plugin Caméra : `reolink::getSnapshot` (Snap CGI authentifié par token), `syncCameraPlugin`, URLs RTSP `GetRtspUrl`
- `GetHddInfo` : garde PHP 8 si pas de carte SD / `HddInfo` vide, clés partielles et `capacity <= 0` (évite DivisionByZero)
- Live : fMP4 par défaut, coupure propre à la fermeture/changement qualité (plus de workers Apache bloqués), PTZ pas/clic via `value.select`, projecteur OK
- Live vidéo : `go2rtc` (Low `sub` H.264 / High `main` H.265), proxy `stream.php`, modale Live
- Surveillance HTTP : Buzzer canal/NVR, enregistrement manuel, IA Visage/Colis, GetAlarm V1, CheckFirmware/UpgradeOnline, NTP/SyncTime
- Encodage dynamique `SetEnc` (résolution/FPS/bitrate/codec main+sub depuis `range`), ISP (HDR/Binning/seuil Jour-Nuit/Exposition/BackLight), RMW `SetWhiteLed`
- ONVIF : parse XML par `NotificationMessage` + canal `Source`, events IA sans remettre `motion` à 0
- Démon : suppression du `ping` ICMP (CAP_NET_RAW) ; health-check via socket TCP ONVIF uniquement
- Routage NVR : événement ONVIF filtré sur le canal `Source` ; lecture AI PHP 8 sûre (`alarm_state` ou entier)
- `cron10` : port `onvifPort` (fallback `port_onvif` / 8000) ; `cron` ne force plus `MdState=0` si démon down
- `refreshNFO` : V20 `scheduleEnable` (Push/Rec/Ftp/Email), FPS/`bitRate` GetEnc, HddInfo vide, `bSmartTrack`, Md/AiAlarm PHP 8
- Actions `Set*` : payloads V20/`channel` corrigés, Reboot, Read-Modify-Write (Enc/Isp/Osd/RecV20/AiCfg), `action:0` + garde PHP 8
- Privacy : `cron10` ignore ONVIF si `SetPrivacyModeState=1` (autonome) ; Baichuan `subItem` + message `sigVer=v3`
- Mode confidentialité (Privacy Mode) via Baichuan TCP 9000 : commandes `SetPrivacyMode` / `SetPrivacyModeState` (E1 Zoom…), garde anti faux-positif (remoteAbility + cmd 574), skip HTTP si privacy ON
- Fix création/sauvegarde d'équipement : `Unknown column 'cnxByIp'` (cache connexion renommé `$_cnxByIp`, convention Jeedom)
- Commandes non supportées : sondage réel des `Get*` à la création/mise à jour, choix automatique V1 vs V20, nettoyage sûr (conserve les commandes utilisées dans scénarios)
- Payloads corrigés : `GetAiCfg` / `GetPowerLed` avec canal ; Zoom/Focus via `supportZoom`/`supportFocus` ; subability AI / preRec
- Runtime : filet `unsupported_payloads` (codes francs immédiats, `-12`/`-17` après 3 échecs canal en ligne)
- Fiabilité : timeouts cURL 5/15 s ; `refreshNFO` ne fait plus `exit()` si une caméra est hors ligne ; resync différé (`needs_cmd_resync`)

19/11/2026

- Support NVR : canal équipement (`defined_channel`), souscriptions ONVIF sans doublon, lecture MD/AI par canal
- API : conservation des réponses valides d'un lot quand une commande n'est pas supportée ; auth token corrigée (PHP 8)
- Logs : appareil hors ligne (`device offline`) en warning sans retries agressifs ; équipement parent NVR ignoré par les boucles caméra
- Nouvelle commande `ChannelOnline` (canal joignable ou non)
- PTZ Patrol : commandes Start/Stop/Get, états `PtzPatrolState` / `PtzPatrolId`, modale de gestion des rondes (presets, vitesse, dwell)
- Démon : transmission des credentials Jeedom via variables d'environnement (corrige le Permission denied sur `jeedomcreds` / webhook)
- Dépendances Python : `urllib3` 2.8 et `requests` 2.34 (alertes Dependabot)
- Démon : fix fuite mémoire (Timer récursif), compatibilité Python 3.13, Session HTTP réutilisée, threads daemon
- Démon : `--socketport` honoré, cycle idle désactivé, arrêt propre du webhook, timeout ONVIF
- Webhook : état motion/AI isolé par IP caméra
- ONVIF : headers non mutés, UTC moderne, `renewtimer` corrigé
- API : session éphémère (login → commandes → logout), cache connexion par IP, verrou anti-stampede, plus de token orphelin

03/01/2025

- Fix une fuite mémoire sur le démon. Merci @Mips2648
- Plusieurs corrections de bugs mineurs

22/06/2024

- Ajout detection IA Chien/Cat Merci @jmvedrine
- Mise à jour des modèles supportés dans la description

21/06/2024

- Fix pour fonctionnement sur version inférieur à Debian 12. Merci à @Mips2648

20/06/2024

- Plusieurs corrections de bugs
- Mise à jours des dépendances et du démon pour compatibilité Debian12
- Version minimale Jeedom: 4.2
  
Merci @Mips2648
- Fix des problèmes de sécurité

10/03/2024
- Ajout de nouvelles détection depuis la souscrption ONVIF
- Amélioration communication entre daemon et plugin
Merci @t0urista 

21/12/2023
- Update vulnerable package in requirements.txt 

28/10/2022
- Bug fix RefreshInfo

18/10/2022
- Bug fix API (is_loggedin)

27/07/2022
- Bug fix for Atlas Jeedom (py wheel)

16/07/2022
- Bug fix sur le fichier reolinkapicmd.json (Merge Pull request depuis @mnpg)

15/07/2022
- Mise à jour du README.md (Merge Pull request depuis @mnpg)
- Code improvement

09/07/2022
- Ajout de la commande MD Sensibilité (Merge Pull request depuis @mnpg)

21/06/2022
- Ajout de la commande PTZGuard (Merge Pull request depuis @mnpg)

12/06/2022
- Merge Pull request depuis @Micka41 (Ajout de la commande Mode led blanche)

10/06/2022
- Sur la page équipement : ajout des informations des ports, et s'il sont activés ou pas (si la caméra remonte l'information)
- Daemon : ajout de vérification supplémentaire pour contrôler si la caméra répond au ping, et si le port ONVIF est actif.
- Divers bugs fix

01/06/2022
- Ajout des options de configuration suivantes :
    - Taille des block commandes
    - IP de callback du webhook
    - Port du webhook
- Modification/Ajout d'information dans le README
- Suppression des imports inutiles du daemon


31/05/2022
- Merge Pull request depuis @Micka41 (ajout de la commande TrackAI)
- Modification log level sur le daemon
- Correction détail dans info.json

30/05/2022
- Passage Jeedom stable

11/03/2022
- Ajout d'un daemon permettre la souscription aux évènements ONVIF (détection de mouvement en temps réel)

27/02/2022
- Merge Pull request depuis @mnpg (ajout de commandes AI)

20/02/2022
- Refonte de la gestion du refresh du plugin
- Détection ou non si la caméra est IA (adaptation des commandes en fonction)
- Bug fix

30/01/2022
- Bug fix masque vie privée état non remontée
- Bug fix Enregistrement : Pre/Post/Overwrite état non remontée
- Ajout support Led Blanche
