# Change log

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
