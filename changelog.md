# Change log

20/11/2026

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
