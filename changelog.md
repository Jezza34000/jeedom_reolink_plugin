# Change log

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
