# Changelog

## 2.5.0 (2026-11-23)
- Ouverture des agents beaucoup plus rapide : la page ne fabrique plus une ligne (ni ne lit la valeur) pour chaque commande de Jeedom. L'onglet « Infos & Actions exposées » charge à la demande les commandes cochées, puis celles d'un plugin au premier clic sur son bouton.
- La sélection est enregistrée dans deux champs (liste d'ID) au lieu de milliers de cases. Les anciennes sélections sont reprises automatiquement à l'ouverture de l'agent (sauvegarder pour les convertir).
- Recherche et filtre remis à zéro en passant d'un agent à l'autre.

## 2.4.1 (2026-11-23)
- Conformité avec la doc développeurs du Core 4.4 : `"require": "4.4"` dans info.json ; JavaScript basé uniquement sur les fonctions du Core (jeeValue, setJeeValues, triggerEvent, empty, domUtils.ajax, domUtils(…) pour le chargement, jeeDialog.toast pour les messages) ; addCmdToTable écrit selon le modèle de la doc (createElement + setJeeValues + appendChild). Solutions de repli supprimées.

## 2.4.0 (2026-11-23)
- Interface entièrement réécrite en JavaScript pur, sans jQuery (page des agents et configuration du plugin) : compatible avec les versions de Jeedom qui ne fournissent plus jQuery. Utilise jeeValue / setJeeValues / jeeDialog et domUtils.ajax de Jeedom quand ils existent, avec repli (fetch + jeton Jeedom).
- Les écouteurs ne sont posés qu'une fois, même quand Jeedom recharge la page du plugin (pas d'actions en double).

## 2.3.9 (2026-11-23)
- Onglet « Infos & Actions exposées » réorganisé : 1) on choisit un ou plusieurs plugins (boutons, avec le nombre de commandes cochées), seules leurs commandes s'affichent ; 2) on coche l'utile, avec recherche pour affiner et boutons « Cocher / Décocher les infos affichées ». Sans plugin choisi : seules les commandes déjà cochées sont affichées. Le choix des plugins est retenu pour chaque agent.

## 2.3.8 (2026-11-23)
- Correction importante : les valeurs JSON de la configuration (état précédent de la surveillance, compteur de contrôles réseau ratés, dernier agent par expéditeur) étaient relues de travers, car Jeedom les décode déjà. Conséquences corrigées : « Premier contrôle » à chaque passage, « Nouveaux hors ligne » jamais rempli, équipements IP jamais déclarés hors ligne, suite de conversation WhatsApp mal suivie.

## 2.3.7 (2026-11-23)
- Onglet « Infos & Actions exposées » : recherche à plusieurs mots (« victron batterie »), sans accents et avec les équivalents français / anglais, colonne Plugin, compteur des commandes cochées, liste plus haute.

## 2.3.6 (2026-11-23)
- Le champ « Commentaire » des équipements Jeedom est envoyé à l'agent avec leurs commandes (lire_plugin, infos exposées, recherche, lire_commande), une seule fois par équipement : un bon endroit pour expliquer une valeur (unité, signe, capacité…).

## 2.3.5 (2026-11-23)
- Filtre de `lire_plugin` : équivalents français / anglais (batterie = battery, soc, charge ; solaire = pv, solar ; consommation = consumption, load ; réseau = grid…), sans tenir compte des accents. Si rien ne correspond, l'agent reçoit la liste des noms de commandes pour réessayer, au lieu d'un résultat vide.

## 2.3.4 (2026-11-23)
- Réponse d'Ollama reçue au fil de l'eau : le délai (timeout) ne coupe plus que si Ollama ne produit plus rien pendant ce temps ; une réponse longue mais qui avance n'est plus perdue (plafond : 4 × le délai).
- `lire_plugin` compact : commandes visibles et non vides, groupées par équipement, 60 commandes / 6 000 caractères au plus, et paramètre `filtre` (ex. batterie) pour ne lire que l'utile. Évite de déborder le contexte du modèle (cause des 300 s sans réponse).
- Log : temps de réponse, taille du contexte en tokens, et avertissement si le contexte dépasse num_ctx.
- WhatsApp : en cas d'erreur, message lisible (« Désolé, je n'ai pas pu répondre… ») au lieu de l'erreur technique.

## 2.3.3 (2026-11-23)
- Ping plus patient : un essai rapide, puis pour ceux qui n'ont pas répondu, 3 essais avec 3 s de délai (réglables). Test de port : 2 essais, 5 s de délai (réglable).
- Un équipement IP ou un serveur n'est déclaré hors ligne qu'après 2 contrôles ratés de suite (réglable) ; avant, il apparaît « en vérification » dans le bilan.
- La surveillance et le préchargement des modèles tournent en arrière-plan : ils ne retardent plus les tâches des autres plugins, et deux contrôles ne se chevauchent jamais.

## 2.3.2 (2026-11-23)
- Correction : avec « Décharger juste après la réponse », le modèle était déchargé à chaque appel d'outil puis rechargé (demandes très lentes). Il reste chargé pendant toute la demande et n'est déchargé qu'à la fin.
- Surveillance : le tout premier contrôle sert de point de départ (plus d'alerte « Nouveaux hors ligne » pour ce qui était déjà en panne).
- Surveillance : équipements sur pile (contacts de fenêtre…) sans délai d'alerte Jeedom : délai de 25 h par défaut (réglable).
- Surveillance : la clé ou le contrôleur Zigbee / Z-Wave (Z-Stick, coordinateur) n'est plus compté comme équipement.

## 2.3.1 (2026-11-23)
- Correction : la page de configuration du plugin s'affichait vide (classe de la messagerie non chargée : « Class ollamaAgentMessaging not found »).
- Correction : même défaut dans le worker lors du tri d'un message reçu (les réponses WhatsApp ne partaient pas) et dans le script d'installation.

## 2.3.0 (2026-11-23)
- Intégration directe de jeeWhatsapp : il suffit de choisir l'équipement. Écoute sur « Reçu le » (mis à jour après le message et l'expéditeur : bon expéditeur, et deux messages identiques déclenchent bien). Réponse dans le groupe canal (titre vide) ou en privé.
- Laissés à jeeWhatsapp : messages commençant par son mot-clé d'interactions, réponses aux questions « Demander » des scénarios, messages des groupes additionnels, médias sans texte.
- Expéditeurs autorisés : numéro, identifiant LID ou profil jeeWhatsapp (user_mapping) ; l'expéditeur refusé est écrit dans le log.
- Avertissement si les interactions jeeWhatsapp sont actives sans mot-clé (double réponse).
- Correction : le titre par défaut de la réponse était « #agent# », pris pour un numéro par jeeWhatsapp. Il est maintenant vide.
- Correction : un numéro écrit avec des espaces dans « Expéditeurs autorisés » n'était pas reconnu.

## 2.2.0 (2026-11-23)
- Messagerie (jeeWhatsapp, Telegram…) : un message reçu est envoyé au bon agent puis la réponse est renvoyée à l'expéditeur. Tri par mot d'appel (@repas, « agenda: ») ou par l'IA (petite demande sans outils, avec la suite de conversation prise en compte). Expéditeurs autorisés obligatoires, commandes /agents et /oublie, accusé de réception en option. Vision jamais utilisée par la messagerie.

## 2.1.0 (2026-11-23)
- Sorties structurées : champs déclarés « nom;type;description » (binaire, nombre, texte, liste(a|b|c)), une commande info par champ + « Sorties (JSON) ». Après la réponse, une 2e demande courte sans outils (format JSON imposé, température 0) remplit les champs ; valeurs vides ou hors liste ignorées. Champs mis à jour avant Réponse.

## 2.0.0 (2026-11-23)
- **Correction importante :** la v1.9 avait perdu la fonction de lancement des demandes : la commande Demande plantait. Rétablie, et un contrôle automatique vérifie maintenant que chaque fonction appelée existe.
- Type d'agent **Vision** : commandes Verdict (INTRUSION / RAS / DOUTE / ERREUR), Intrusion, Fausse alarme (1 seulement si RAS), Description, action Analyser. Image depuis une URL (Frigate…), un fichier ou une commande info ; réduite à 640 px avant l'analyse. Mémoire coupée, prioritaire et modèle toujours chargé d'office.
- **Envoi de la réponse** : commande message au choix (Telegram, Pushover, Alexa…), avec l'image pour la vision ; RAS non envoyés par défaut.
- **Demandes programmées** : lignes « heure;jours;demande » (ex. 07:00;1-5;briefing du matin).
- Commande **Durée dernière réponse** (s) pour chaque agent.
- Réponse en « toujours répéter » : deux réponses identiques déclenchent quand même les scénarios.

## 1.9.0 (2026-11-23)
- Option « Agent prioritaire » (ex. vision) : quand il démarre, les demandes en cours des autres agents qui utilisent la carte graphique sont coupées (Ollama arrête la génération), il passe tout de suite, puis les agents interrompus relancent leur demande automatiquement. Statuts « En pause (agent prioritaire) » / « En attente (agent prioritaire) ».
- Les agents « Calcul sur le processeur » ne sont jamais interrompus.

## 1.8.0 (2026-11-23)
- Réglage « Garder le modèle en mémoire » par agent : défaut Ollama, toujours chargé (keep_alive -1) ou déchargé juste après la réponse (keep_alive 0).
- Les modèles « toujours chargés » (ex. vision) sont rechargés automatiquement après chaque demande d'un autre agent et toutes les 5 minutes s'ils ne sont plus en mémoire.
- Option « Calcul sur le processeur » (num_gpu 0) : l'agent n'utilise pas la carte graphique.

## 1.7.2 (2026-11-22)
- Correction : erreur MySQL « Unknown column 'pingOk' » à la sauvegarde d'un agent (propriétés statiques de la classe vues par Jeedom comme des colonnes de eqLogic).

## 1.7.1 (2026-11-22)
- La conversation n'est plus dans le cache : elle est enregistrée dans la table `ollamaAgent_message` de la base Jeedom (survit aux redémarrages, incluse dans les sauvegardes). 200 derniers échanges gardés par agent ; supprimés avec l'agent. Reprise automatique de la conversation v1.7 à la mise à jour.
- Liste « hors ligne au contrôle précédent » de la surveillance enregistrée dans la configuration : plus de fausses alertes « Nouveaux hors ligne » après un redémarrage.

## 1.7.0 (2026-11-22)
- Mémoire de conversation par agent : les derniers échanges (5 par défaut) sont renvoyés à Ollama, avec ce que l'agent a fait (ajout agenda, actions). Oubliée après 30 min sans échange (réglable, 0 = jamais).
- Notes permanentes par agent : l'agent retient ce que tu lui dis (« retiens que… ») avec les outils `retenir` et `oublier_note` ; tu les lis et les corriges dans l'onglet Mémoire. Envoyées à chaque demande, 40 au plus.
- Commande « Nouvelle conversation », `/oublie` dans Demande, info « Mémoire - échanges ».
- Une seule demande à la fois par agent : une demande envoyée pendant qu'une autre tourne attend, puis voit l'échange précédent.
- Bilan santé : seule la consigne est gardée en mémoire (pas l'état complet).

## 1.6.0 (2026-11-22)
- Type d'agent : Général, Repas, Énergie, Agenda, Surveillance. Un agent spécialisé n'a que les outils de son type, quelles que soient les autres cases ; la page n'affiche que ses réglages. Icône et étiquette du type sur la carte de l'agent.
- Les agents existants passent en « Général » : rien ne change pour eux.

## 1.5.0 (2026-11-22)
- Outils dédiés au plugin repas (lecture seule, case « Outils repas » dans Plugins & outils) : `repas_jour`, `repas_semaine`, `repas_courses`, `repas_frigo`, `repas_recettes`, `repas_recette`, `repas_gouts`. Ils lisent directement le plugin repas (mêmes calculs que le panneau : couverts avec l'agenda, gamelles, calories, alertes du plan).
- Modèle sans outils : le résumé des repas d'aujourd'hui et de demain est ajouté au contexte.

## 1.4.0 (2026-11-22)
- Outils de lecture : `lire_plugin` (plugins cochés : repas, enerSol, solcast…), `lire_commande`, `lire_historique`, `chercher_commandes` (lecture totale).
- Agenda : `lire_agenda` et `ajouter_agenda` (un message → plusieurs lignes dans l'agenda via la commande « Ajouter »).
- Surveillance de Jeedom : Zigbee, Z-Wave, Bluetooth, IP (ping), serveurs (ping/TCP), démons, dépendances, piles, LQI, erreurs des logs ; commandes Santé + action « Bilan santé ».
- Date du jour et semaine paire/impaire envoyées au modèle.
- Relance automatique sans outils si le modèle ne les gère pas.
- « Inclure les logs » lit maintenant les erreurs de tous les logs sur 24 h (au lieu de http.error).
- Sous-type `select` géré pour les actions exposées ; limite d'appels d'outils réglable.
