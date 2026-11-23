# Agent Ollama (ollamaAgent)

Agent IA local (Ollama) pour Jeedom. Un scénario envoie une question avec la commande **Demande**. La réponse arrive dans **Réponse** et **Statut** passe à `Prêt`.

## Garder la vision rapide

Une carte graphique de 16 Go ne peut pas garder tous les modèles chargés en même temps.

| Agent | Garder le modèle en mémoire | Calcul sur le processeur |
|---|---|---|
| Vision | **Toujours chargé** | non |
| Repas, Énergie, Agenda, Surveillance | **Décharger juste après la réponse** | au choix |

- **Toujours chargé :** le modèle reste sur la carte graphique. Si un autre agent l'en fait sortir, il est rechargé dès la fin de la demande de cet agent, puis vérifié toutes les 5 minutes.
- **Décharger juste après la réponse :** l'agent libère la carte dès qu'il a répondu.
- **Calcul sur le processeur :** l'agent ne touche jamais la carte graphique. C'est plus lent, mais la vision n'est jamais déchargée, même pendant que l'agent travaille.

### Agent prioritaire

Coche « Agent prioritaire » sur l'agent Vision. Quand il démarre :
1. les autres agents qui utilisent la carte graphique coupent leur demande en cours (statut « En pause (agent prioritaire) ») ;
2. la vision passe tout de suite ;
3. les agents interrompus relancent leur demande dès que la vision a fini ; ils n'ont rien à refaire.

Une demande envoyée à un autre agent pendant que la vision travaille attend sa fin (statut « En attente (agent prioritaire) »).

## Agent Vision

À choisir comme type d'agent. Il n'a besoin d'aucun réglage de priorité, de mémoire ou de modèle : il est prioritaire, sans mémoire, et son modèle reste toujours chargé.

| Commande | Valeur |
|---|---|
| Verdict | `INTRUSION`, `RAS`, `DOUTE` ou `ERREUR` (mis à jour en dernier : **c'est le déclencheur à utiliser**) |
| Intrusion | 1 si INTRUSION |
| Fausse alarme | 1 **seulement** si RAS : c'est la seule valeur qui doit couper la sirène |
| Description | ce que l'IA a vu |
| Analyser (action) | analyse l'image de la « Source de l'image » |

**Source de l'image** : une URL de capture (Frigate : `http://IP:5000/api/<caméra>/latest.jpg`), un chemin de fichier, ou une commande info dont la valeur est une URL ou un chemin. Demande avec un chemin ou une URL en titre fonctionne aussi. L'image est réduite à 640 px de large (réglable).

Une réponse hors format, une image introuvable ou une panne d'Ollama ne donnent jamais « Fausse alarme = 1 » : l'alarme sonne comme avant.

## Sorties structurées (onglet « Sorties, envoi & programmation »)

Une ligne par champ, au format `nom;type;description`. À la sauvegarde, une commande info est créée pour chaque champ.

```
Lave-linge maintenant;binaire;1 s'il faut lancer le lave-linge maintenant
Créneau conseillé;texte;heure conseillée pour lancer les machines
Batterie ce soir;nombre;charge prévue de la batterie à 20 h, en %
Chauffage;liste(eco|confort|arret);mode de chauffage conseillé
```

**Comment c'est rempli :**
1. L'agent reçoit la liste des champs et répond normalement, avec ses outils.
2. Une deuxième demande courte, sans outils et au format JSON imposé, lit sa réponse et remplit les champs.
3. Un champ dont l'information manque, ou dont la valeur n'est pas dans la liste, garde sa valeur précédente.

Les champs sont mis à jour **avant** Réponse : déclenche ton scénario sur Réponse. La commande cachée « Sorties (JSON) » contient tous les champs remplis.

Avec une demande programmée (par exemple `09:00;*;Faut-il lancer les machines aujourd'hui ?`), tes scénarios reçoivent chaque matin des valeurs toutes prêtes.

## Envoi & programmation (onglet de l'agent)

- **Commande d'envoi** : une commande action « message », par exemple Telegram, Pushover ou une annonce Alexa. Le titre est le nom de l'agent (avec le verdict pour la vision) et le message est la réponse. L'image est jointe pour la vision (option `files`). Les RAS ne sont pas envoyés, sauf si tu coches l'option.
- **Demandes programmées** : une ligne par demande, au format `heure;jours;demande`. Jours : `*` = tous, `1-5` = lundi à vendredi, `6,7` = week-end. Exemple : `07:00;1-5;Fais le briefing du matin`.

## Messagerie (jeeWhatsapp, Telegram…)

**Avec jeeWhatsapp (recommandé) :**
1. Coche **Répondre aux messages reçus** et choisis l'**équipement jeeWhatsapp**. C'est tout pour les commandes : l'écoute, l'expéditeur et la réponse sont réglés automatiquement.
2. Réponse dans le **groupe canal** par défaut, ou en privé si tu coches l'option.
3. Dans l'équipement jeeWhatsapp, **désactive les interactions** ou donne-leur un **mot-clé** (ex. `/jeedom`). Sinon, Jeedom et les agents répondent tous les deux.
4. Ce qui reste à jeeWhatsapp : les messages qui commencent par son mot-clé, les réponses aux « Demander » de tes scénarios, les groupes additionnels et les médias sans texte.

**Avec un autre plugin, configuration du plugin :**
1. Coche **Répondre aux messages reçus**.
2. Choisis la commande info du **message reçu**, celle de l'**expéditeur**, et l'action **répondre**.
3. Remplis les **expéditeurs autorisés**. Si la liste est vide, personne n'a de réponse. Tu peux mettre un numéro, un identifiant LID ou un profil jeeWhatsapp. Un expéditeur refusé est écrit dans le log `ollamaAgent` : il suffit de le recopier.
4. Si ton plugin attend le destinataire dans le titre, mets `#expediteur#` dans « Titre de la réponse ».

**Sur chaque agent** (onglet « Sorties, envoi & programmation ») : coche **Répond sur la messagerie**. Tu peux aussi préciser ses sujets et ses mots d'appel.

**Comment l'agent est choisi :**
- `@repas …` ou `agenda: …` → l'agent nommé (nom, type ou mot d'appel) ;
- sinon, l'IA choisit d'après les sujets de chaque agent. Une suite de conversation (« et samedi aussi ») reste chez le même agent ;
- `/agents` donne la liste, et `/oublie` démarre une nouvelle conversation avec le dernier agent utilisé.

## Un agent par type

Le type se choisit dans le premier onglet. Crée un équipement par type : chacun a ses propres commandes Demande, Réponse et Statut, son modèle et son prompt système.

| Type | Outils | Réglages |
|---|---|---|
| **Repas** | `repas_jour`, `repas_semaine`, `repas_courses`, `repas_frigo`, `repas_recettes`, `repas_recette`, `repas_gouts` | aucun : il lit le plugin repas |
| **Énergie** | `lire_plugin`, `lire_commande`, `lire_historique` | cocher les plugins d'énergie (enerSol, Solcast, Victron…) |
| **Agenda** | `lire_agenda`, `ajouter_agenda` | commande « Ajouter » (vide = recherche automatique) |
| **Surveillance** | `etat_jeedom` + commandes Santé (toutes les 5 min) + action Bilan santé | serveurs, familles radio et délais dans la configuration du plugin |
| **Général** | selon les cases cochées (comme avant) | tout |

Les commandes cochées dans « Infos & Actions exposées » restent possibles pour tous les types : par exemple les températures pour l'agent Énergie, ou « Changer midi » pour l'agent Repas.

## Aider l'agent à comprendre tes commandes

Écris une explication dans le champ **Commentaire** de l'équipement Jeedom (page de l'équipement, onglet Informations). L'agent la reçoit avec les commandes de cet équipement :
```
#14990 [Maison][2-Batterie - Charge][Conso Jour] = 3.2 kWh
  (À savoir sur [Maison][2-Batterie - Charge] : Charge de la batterie Pytes 15 kWh. Valeur négative = décharge.)
```
Le commentaire est utile pour indiquer ce que mesure l'équipement, le sens d'une valeur négative, une capacité, un compteur monté à l'envers… Pour une information générale, qui ne concerne pas un équipement précis, utilise plutôt les notes permanentes de l'agent.

## Mémoire (onglet de l'agent)

**Conversation :** l'agent garde les derniers échanges, 5 par défaut. Tu peux donc enchaîner :
« ajoute foot jeudi soir », puis « et samedi aussi ». Ce qu'il a fait est gardé avec sa réponse, par exemple `(Fait : Ajouté dans l'agenda : « Foot » — 2026-11-26 soir)`.

La conversation est oubliée dans ces cas :
- après 30 min sans échange (réglable ; 0 = jamais) ;
- avec la commande **Nouvelle conversation** ;
- en envoyant `/oublie` dans Demande.

**Notes permanentes :** ce que l'agent doit savoir tout le temps, une note par ligne. Exemples : « Foot tous les jeudis soir », « Armand au rugby le samedi matin ».
- Tu peux les écrire toi-même dans l'onglet Mémoire.
- L'agent peut aussi les ajouter quand tu lui dis « retiens que… », et les retirer quand tu lui dis « oublie que… ». Tu peux le lui interdire avec une case.
- Elles sont envoyées à chaque demande. Il y en a 40 au plus, et chaque agent a les siennes.

La conversation est enregistrée dans la base Jeedom, dans la table `ollamaAgent_message`. Elle survit aux redémarrages et elle est dans les sauvegardes. Les 200 derniers échanges de chaque agent sont gardés. Les notes sont dans la configuration du plugin.

Une seule demande tourne à la fois par agent. Si tu en envoies une deuxième pendant que la première tourne, elle attend la fin de la première puis la prend en compte.

## Plugins & outils (onglet de l'agent)

L'agent ne lit que ce que tu coches. Tout est en **lecture seule**, sauf l'agenda et les actions cochées dans « Infos & Actions exposées ».

| Outil | Quand il est proposé au modèle |
|---|---|
| `lire_plugin` | au moins un plugin coché (repas, enerSol, solcast, agenda…) ou « Lecture totale » |
| `chercher_commandes` | « Lecture totale » cochée |
| `lire_commande`, `lire_historique` | commandes des plugins cochés ou des infos exposées |
| `etat_jeedom` | Surveillance activée sur l'agent |
| `lire_agenda`, `ajouter_agenda` | « Lire et ajouter dans l'agenda » coché |
| `exec_cmd_<id>` | « Contrôle des équipements » + actions cochées |

La date du jour, avec la semaine ISO paire ou impaire, est toujours envoyée. Le modèle peut donc calculer « jeudi » ou « demain ».

**Modèle :** il faut un modèle qui gère les outils (qwen3, qwen2.5, llama3.1, mistral-small…). Si le modèle refuse les outils (ex. `qwen2.5vl`), l'agent relance la demande sans outils. Il envoie alors l'état des plugins cochés dans le contexte : la lecture marche encore, mais l'agenda et les actions ne marchent plus. Mets `num_ctx` à 8192 ou plus.

### Repas

Pour activer ces outils, coche « Outils repas ». Ils lisent directement le plugin repas, en lecture seule :

| Outil | Contenu |
|---|---|
| `repas_jour` | collations, midi et soir, couverts (agenda, enfant, invités), gamelles, ingrédients, préparation, calories et apports de la journée |
| `repas_semaine` | menu du vendredi soir au vendredi midi, calories, moyenne par jour, œufs, protéines riches, alertes du plan (semaine suivante avec `decalage = 1`) |
| `repas_courses` | liste de courses par rayon, articles restants |
| `repas_frigo` | frigo, congélateur, placard, DLC, ce qui est à finir |
| `repas_recettes`, `repas_recette` | recherche dans le carnet et détail d'une recette pour 1 portion au plan |
| `repas_gouts` | je n'aime pas / j'adore et la mémoire de l'IA du plugin repas |

Les règles du plan restent dans le plugin repas. Pour que l'agent change un plat, coche les actions du plugin repas (« Changer midi », « Choix du plat soir »…) dans « Infos & Actions exposées » et active « Contrôle des équipements ».

### Agenda par message

Exemple : `Demande` → « ajoute foot jeudi soir, Mamie vient dimanche midi et on mange au restau samedi soir ».

L'agent appelle `ajouter_agenda` une fois par évènement. Ensuite, la commande **Ajouter** du plugin agenda reçoit :
- titre = `Foot`, message = `2026-11-26 soir`
- titre = `Mamie`, message = `2026-11-29 midi +2`
- titre = `Restau`, message = `2026-11-28 soir dehors`

Effets possibles : `info`, `absent` (maison vide), `dehors` (repas dehors), `couverts` (+/−).
Tu peux choisir toi-même la commande « Ajouter ». Si le champ est vide, l'agent la cherche dans le plugin agenda.

## Surveillance de Jeedom

À activer dans l'onglet **Surveillance** de l'agent. Ensuite, clique sur Sauvegarder pour créer les commandes. Les commandes sont mises à jour toutes les 5 minutes.

| Commande | Contenu |
|---|---|
| Santé - Résumé | `Zigbee 1/35 HS · Z-Wave 0/12 HS · IP 0/20 HS · Serveurs 0/5 HS · Démons KO 0 · Piles faibles 2` |
| Santé - Hors ligne | liste des noms |
| Santé - Nouveaux hors ligne | ce qui vient de tomber depuis le contrôle précédent (vide sinon), à utiliser comme déclencheur de scénario |
| Santé - Revenus en ligne | ce qui est revenu |
| Santé - Problème | 0 / 1 |
| Santé - HS Zigbee / Z-Wave / Bluetooth / IP / autres / Serveurs, Démons KO, Piles faibles | nombres |
| Bilan santé (action) | envoie l'état complet et les erreurs des logs sur 24 h à Ollama, la réponse arrive dans « Réponse » |
| Rafraîchir santé (action) | recalcule tout de suite |

**Comment c'est calculé :**
- **Zigbee / Z-Wave / Bluetooth** : famille reconnue par le type de plugin (réglable dans la configuration du plugin). Un équipement est HS si le délai d'alerte Jeedom est dépassé. Sans délai réglé, le délai par défaut de la famille s'applique (240 min pour Zigbee et Z-Wave, 60 min pour Bluetooth).
- **IP** : équipements dont la configuration contient une adresse (`ip`, `host`, `addr`…). Ils sont pingés en parallèle, 1 s maximum.
- **Serveurs** : liste `Nom;adresse;port` dans la configuration du plugin. Avec un port, c'est un test TCP. Sans port, c'est un ping.
- **Démons** : démons des plugins en gestion automatique qui ne sont pas « OK ». Les dépendances en erreur sont aussi signalées.
- **Piles** sous le seuil (20 % par défaut) et **LQI** Zigbee/Z-Wave sous le seuil (30 par défaut).
