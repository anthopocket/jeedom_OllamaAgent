<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
require_once dirname(__FILE__) . '/../../core/class/ollamaAgent.class.php';
$plugin = plugin::byId('ollamaAgent');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>
<div class="row row-overflow">
    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoPrimary" data-action="add">
                <i class="fas fa-plus-circle"></i><br><span>{{Ajouter un Agent}}</span>
            </div>
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i><br><span>{{Configuration}}</span>
            </div>
        </div>
        <legend><i class="fas fa-robot"></i> {{Mes Agents IA}}</legend>
        <div class="eqLogicThumbnailContainer">
            <?php
            foreach ($eqLogics as $eqLogic) {
                $t = ollamaAgent::AGENT_TYPES[$eqLogic->agentType()];
                $opacity = $eqLogic->getIsEnable() ? '' : 'disableCard';
                echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
                echo '<i class="' . $t[1] . '" style="font-size:4em;margin-top:15px;"></i><br>';
                echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span><br>';
                echo '<span class="label label-info">' . $t[0] . '</span>';
                echo '</div>';
            }
            ?>
        </div>
    </div>

    <div class="col-xs-12 eqLogic" style="display: none;">
        <div class="input-group pull-right" style="display:inline-flex">
            <span class="input-group-btn">
                <a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i> {{Configuration avancée}}</a>
                <a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
                <a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
            </span>
        </div>
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation"><a href="#" class="eqLogicAction" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
            <li role="presentation" class="active"><a href="#eqlogictab" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Agent IA}}</a></li>
            <li role="presentation" class="typeOnly" data-types="general,repas,energie,agenda,surveillance"><a href="#memorytab" role="tab" data-toggle="tab"><i class="fas fa-memory"></i> {{Mémoire}}</a></li>
            <li role="presentation"><a href="#sendtab" role="tab" data-toggle="tab"><i class="fas fa-paper-plane"></i> {{Sorties, envoi & programmation}}</a></li>
            <li role="presentation" class="typeOnly" data-types="general,repas,energie,agenda"><a href="#pluginstab" role="tab" data-toggle="tab"><i class="fas fa-puzzle-piece"></i> {{Plugins & outils}}</a></li>
            <li role="presentation" class="typeOnly" data-types="general,surveillance"><a href="#healthtab" role="tab" data-toggle="tab"><i class="fas fa-heartbeat"></i> {{Surveillance}}</a></li>
            <li role="presentation"><a href="#exposedtab" role="tab" data-toggle="tab"><i class="fas fa-check-square"></i> {{Infos & Actions exposées}}</a></li>
            <li role="presentation"><a href="#commandtab" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes de l'agent}}</a></li>
        </ul>
        <div class="tab-content">
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <form class="form-horizontal">
                    <fieldset>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Nom de l'agent}}</label>
                            <div class="col-sm-4">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;" />
                                <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Ex: Assistant Maison, Agent Vision, Agent Énergie...}}" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Objet parent}}</label>
                            <div class="col-sm-4">
                                <select class="eqLogicAttr form-control" data-l1key="object_id">
                                    <option value="">{{Aucun}}</option>
                                    <?php
                                    foreach (jeeObject::all() as $object) {
                                        echo '<option value="' . $object->getId() . '">' . $object->getName() . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label"></label>
                            <div class="col-sm-9">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked />{{Activer}}</label>
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked />{{Visible}}</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Type d'agent}}</label>
                            <div class="col-sm-4">
                                <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="agent_type" id="sel_agentType">
                                    <option value="general">{{Général (outils au choix)}}</option>
                                    <option value="repas">{{Repas}}</option>
                                    <option value="energie">{{Énergie}}</option>
                                    <option value="agenda">{{Agenda}}</option>
                                    <option value="surveillance">{{Surveillance de Jeedom}}</option>
                                    <option value="vision">{{Vision (levée de doute)}}</option>
                                </select>
                            </div>
                            <div class="col-sm-5 help-block" id="div_agentTypeHelp"></div>
                        </div>
                        <div class="typeOnly" data-types="vision">
                            <legend><i class="fas fa-eye"></i> {{Image à analyser}}</legend>
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{Source de l'image}}
                                    <sup><i class="fas fa-question-circle tooltips" title="{{URL d'une capture (ex. Frigate http://IP:5000/api/entree/latest.jpg), chemin de fichier, ou commande info dont la valeur est une URL ou un chemin. Utilisée par la commande Analyser et par Demande sans titre.}}"></i></sup>
                                </label>
                                <div class="col-sm-6">
                                    <div class="input-group">
                                        <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="image_source" placeholder="http://192.168.1.70:5000/api/entree/latest.jpg" />
                                        <span class="input-group-btn"><a class="btn btn-default" id="bt_selectImageCmd" title="{{Choisir une commande info}}"><i class="fas fa-list-alt"></i></a></span>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{Largeur maximale (px)}}</label>
                                <div class="col-sm-2">
                                    <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="image_max_width" placeholder="640" />
                                </div>
                                <div class="col-sm-6 help-block">{{L'image est réduite avant l'analyse : plus petite = plus rapide. 0 = taille d'origine.}}</div>
                            </div>
                            <div class="alert alert-info col-sm-offset-3 col-sm-8">{{La réponse doit commencer par INTRUSION, RAS ou DOUTE (voir le prompt). Le plugin remplit les commandes Verdict, Intrusion, Fausse alarme et Description. Mémoire coupée, agent prioritaire et modèle toujours chargé d'office.}}</div>
                        </div>
                        <legend><i class="fas fa-sliders-h"></i> {{Paramètres Ollama (style Home Assistant)}}</legend>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Modèle Ollama}}</label>
                            <div class="col-sm-4">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="model" placeholder="qwen2.5vl:7b, qwen3:14b..." />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Instructions Système (Rôle)}}</label>
                            <div class="col-sm-6">
                                <textarea class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="system_prompt" rows="4" placeholder="Rôle de l'agent ou laissez vide si piloté par un autre plugin."></textarea>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Contrôle des équipements (Tools)}}</label>
                            <div class="col-sm-6">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="device_control" /> {{Autoriser l'agent à exécuter les commandes actions cochées (équivalent Device control)}}</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Sortie JSON structurée}}</label>
                            <div class="col-sm-6">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="json_format" /> {{Forcer une réponse JSON structurée (format: json)}}</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Désactiver le raisonnement (think: false)}}</label>
                            <div class="col-sm-6">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="disable_think" /> {{Réponse plus rapide sur les modèles type Qwen3 sur CPU}}</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Taille contexte (num_ctx)}}</label>
                            <div class="col-sm-2">
                                <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="num_ctx" placeholder="0 = défaut" />
                            </div>
                            <label class="col-sm-2 control-label">{{Timeout (sec)}}</label>
                            <div class="col-sm-2">
                                <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="timeout" placeholder="300" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Appels d'outils max}}</label>
                            <div class="col-sm-2">
                                <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="max_turns" placeholder="8" />
                            </div>
                            <div class="col-sm-5 help-block">{{Nombre maximum d'allers-retours avec Ollama par demande (lecture de plugins, ajout agenda…).}}</div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Garder le modèle en mémoire}}</label>
                            <div class="col-sm-4">
                                <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="keep_model">
                                    <option value="">{{Défaut Ollama (5 min après la dernière demande)}}</option>
                                    <option value="permanent">{{Toujours chargé (réponse rapide, ex. vision)}}</option>
                                    <option value="decharger">{{Décharger juste après la réponse}}</option>
                                </select>
                            </div>
                            <div class="col-sm-5 help-block">{{« Toujours chargé » : rechargé automatiquement si un autre agent l'a fait sortir de la carte graphique.}}</div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Agent prioritaire}}</label>
                            <div class="col-sm-8">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="priority" /> {{Passe devant tout : interrompt les autres agents qui utilisent la carte graphique ; ils reprennent leur demande après (ex. vision)}}</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Calcul sur le processeur}}</label>
                            <div class="col-sm-8">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="cpu_only" /> {{N'utilise pas la carte graphique (plus lent, mais laisse toute la carte à la vision)}}</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Inclure les erreurs des logs}}</label>
                            <div class="col-sm-6">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="include_logs" /> {{Ajoute au contexte les erreurs de tous les logs Jeedom des dernières 24 h}}</label>
                            </div>
                        </div>
                    </fieldset>
                </form>
            </div>

            <div role="tabpanel" class="tab-pane" id="sendtab">
                <form class="form-horizontal">
                    <fieldset>
                        <div class="typeOnly" data-types="general,repas,energie,agenda,surveillance">
                        <legend><i class="fab fa-whatsapp"></i> {{Messagerie}}</legend>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Répond sur la messagerie}}</label>
                            <div class="col-sm-8">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="msg_enable" /> {{Les messages reçus (jeeWhatsapp…) peuvent être envoyés à cet agent (réglages dans la configuration du plugin)}}</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Sujets de l'agent}}
                                <sup><i class="fas fa-question-circle tooltips" title="{{Aide l'IA à choisir le bon agent. Vide = description par défaut du type.}}"></i></sup>
                            </label>
                            <div class="col-sm-6">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="msg_description" placeholder="{{Ex. repas, menus, recettes, courses, frigo}}" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Mots-clés d'appel}}
                                <sup><i class="fas fa-question-circle tooltips" title="{{Un message qui commence par @mot ou « mot: » va directement à cet agent. Le nom et le type de l'agent marchent toujours (@repas, repas: …).}}"></i></sup>
                            </label>
                            <div class="col-sm-4">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="msg_keywords" placeholder="{{Ex. cuisine, menu}}" />
                            </div>
                        </div>
                        </div>
                        <div class="typeOnly" data-types="general,repas,energie,agenda,surveillance">
                        <legend><i class="fas fa-table"></i> {{Sorties structurées}}</legend>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Champs}}
                                <sup><i class="fas fa-question-circle tooltips" title="{{Une ligne par champ : nom;type;description. Types : binaire, nombre, texte, liste(valeur1|valeur2). Une commande info est créée par champ à la sauvegarde. Après chaque réponse, l'agent les remplit (vide si l'information manque).}}"></i></sup>
                            </label>
                            <div class="col-sm-7">
                                <textarea class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="outputs" rows="5" placeholder="Lave-linge maintenant;binaire;1 s'il faut lancer le lave-linge maintenant&#10;Créneau conseillé;texte;heure conseillée pour lancer les machines, ex. 13h-15h&#10;Batterie ce soir;nombre;charge prévue de la batterie à 20 h, en %&#10;Chauffage;liste(eco|confort|arret);mode de chauffage conseillé"></textarea>
                                <span class="help-block">{{Ordre de mise à jour : les champs, puis Réponse. Déclenche ton scénario sur Réponse : les champs sont déjà à jour.}}</span>
                            </div>
                        </div>
                        </div>
                        <legend><i class="fas fa-paper-plane"></i> {{Envoyer la réponse}}</legend>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Commande d'envoi}}
                                <sup><i class="fas fa-question-circle tooltips" title="{{Commande action de type message : Telegram, Pushover, annonce Alexa… Titre = nom de l'agent, message = réponse. Vide = pas d'envoi.}}"></i></sup>
                            </label>
                            <div class="col-sm-6">
                                <div class="input-group">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="notify_cmd" placeholder="{{Vide = pas d'envoi}}" />
                                    <span class="input-group-btn"><a class="btn btn-default" id="bt_selectNotifyCmd"><i class="fas fa-list-alt"></i></a></span>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Options}}</label>
                            <div class="col-sm-8">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="notify_no_errors" /> {{Ne pas envoyer les erreurs}}</label>
                                <label class="checkbox-inline typeOnly" data-types="vision"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="notify_ras" /> {{Envoyer aussi les RAS (fausses alarmes)}}</label>
                                <label class="checkbox-inline typeOnly" data-types="general,vision"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="notify_no_image" /> {{Sans l'image}}</label>
                            </div>
                        </div>
                        <div class="typeOnly" data-types="general,repas,energie,agenda,surveillance">
                        <legend><i class="fas fa-clock"></i> {{Demandes programmées}}</legend>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Programmation}}
                                <sup><i class="fas fa-question-circle tooltips" title="{{Une ligne par demande : heure;jours;demande. Jours : * = tous, 1-5 = lundi à vendredi, 6,7 = week-end. Lignes commençant par # ignorées.}}"></i></sup>
                            </label>
                            <div class="col-sm-7">
                                <textarea class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="schedules" rows="5" placeholder="07:00;1-5;Fais le briefing du matin&#10;18:30;5;Qu'est-ce qu'il reste à acheter pour les courses ?&#10;20:00;*;Bilan de la journée"></textarea>
                                <span class="help-block">{{La réponse arrive dans Réponse, et part sur la commande d'envoi si elle est réglée.}}</span>
                            </div>
                        </div>
                        </div>
                    </fieldset>
                </form>
            </div>

            <div role="tabpanel" class="tab-pane" id="memorytab">
                <form class="form-horizontal">
                    <fieldset>
                        <legend><i class="fas fa-comments"></i> {{Conversation}}</legend>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Désactiver la mémoire}}</label>
                            <div class="col-sm-8">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="memory_disable" /> {{Chaque demande repart de zéro (pas de conversation, pas de notes)}}</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Échanges gardés}}</label>
                            <div class="col-sm-2">
                                <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="memory_turns" placeholder="5" />
                            </div>
                            <label class="col-sm-2 control-label">{{Oublier après (min)}}</label>
                            <div class="col-sm-2">
                                <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="memory_minutes" placeholder="30" />
                            </div>
                            <div class="col-sm-3 help-block">{{Minutes sans échange avant une nouvelle conversation (0 = jamais).}}</div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label"></label>
                            <div class="col-sm-8">
                                <a class="btn btn-default" id="bt_showConversation"><i class="fas fa-eye"></i> {{Voir la conversation}}</a>
                                <a class="btn btn-warning" id="bt_clearConversation"><i class="fas fa-eraser"></i> {{Nouvelle conversation}}</a>
                                <span class="help-block">{{Aussi possible avec la commande « Nouvelle conversation » ou en envoyant /oublie dans Demande.}}</span>
                            </div>
                        </div>
                        <pre id="pre_conversation" style="display:none; white-space:pre-wrap; max-height:400px; overflow-y:auto;"></pre>

                        <legend><i class="fas fa-sticky-note"></i> {{Notes permanentes (ce que l'agent retient)}}</legend>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Notes écrites par l'agent}}</label>
                            <div class="col-sm-8">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="notes_agent_disable" /> {{Interdire à l'agent d'ajouter ou d'oublier des notes (outils retenir / oublier_note) : seules tes notes comptent}}</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Notes}}
                                <sup><i class="fas fa-question-circle tooltips" title="{{Une note par ligne, 40 au plus. Envoyées à chaque demande. Ex. : Foot tous les jeudis soir.}}"></i></sup>
                            </label>
                            <div class="col-sm-7">
                                <textarea class="form-control" id="ta_agentNotes" rows="10" placeholder="{{Une note par ligne}}"></textarea>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label"></label>
                            <div class="col-sm-7">
                                <a class="btn btn-success" id="bt_saveNotes"><i class="fas fa-save"></i> {{Enregistrer les notes}}</a>
                                <a class="btn btn-default" id="bt_reloadNotes"><i class="fas fa-sync"></i> {{Recharger}}</a>
                                <span id="span_notesState" style="margin-left:10px;"></span>
                            </div>
                        </div>
                    </fieldset>
                </form>
            </div>

            <div role="tabpanel" class="tab-pane" id="pluginstab">
                <form class="form-horizontal">
                    <fieldset>
                        <div class="typeOnly" data-types="general">
                        <legend><i class="fas fa-book-reader"></i> {{Lecture de Jeedom}}</legend>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Lecture totale}}</label>
                            <div class="col-sm-8">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="full_read" /> {{L'agent peut lire n'importe quelle commande info de Jeedom (outils chercher_commandes, lire_plugin sur tous les plugins). Lecture seule : aucune action.}}</label>
                            </div>
                        </div>
                        <div class="form-group typeOnly" data-types="general">
                            <label class="col-sm-3 control-label">{{Historique}}</label>
                            <div class="col-sm-8">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="history_tool" checked /> {{Outil lire_historique (min / max / moyenne / évolution d'une commande historisée)}}</label>
                            </div>
                        </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Injecter dans le contexte}}</label>
                            <div class="col-sm-8">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="inject_plugins" /> {{Envoie directement l'état des plugins cochés à chaque demande (utile pour un modèle sans outils ; plus lent). Automatique si le modèle refuse les outils.}}</label>
                            </div>
                        </div>
                        <div class="typeOnly" data-types="general,energie">
                        <legend><i class="fas fa-puzzle-piece"></i> {{Plugins lisibles par l'agent (outil lire_plugin)}}</legend>
                        <div class="typeOnly alert alert-info" data-types="energie">{{Coche tes plugins d'énergie (enerSol, Solcast, Victron…). L'agent Énergie lit leurs commandes et leur historique.}}</div>
                        <div style="max-height: 320px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; margin-bottom: 15px;">
                            <table class="table table-condensed table-hover">
                                <thead><tr><th style="width:50px;">{{Lire}}</th><th>{{Plugin}}</th><th>{{Id}}</th><th>{{Équipements actifs}}</th></tr></thead>
                                <tbody>
                                    <?php
                                    $highlight = ['repas', 'enersol', 'solcast', 'agenda', 'victron', 'victrongx', 'reveils', 'bonspoints'];
                                    $plugins = plugin::listPlugin(true);
                                    usort($plugins, function ($a, $b) use ($highlight) {
                                        $ha = in_array(strtolower($a->getId()), $highlight) ? 0 : 1;
                                        $hb = in_array(strtolower($b->getId()), $highlight) ? 0 : 1;
                                        return $ha == $hb ? strcasecmp($a->getName(), $b->getName()) : $ha - $hb;
                                    });
                                    $eqActive = [];
                                    foreach (eqLogic::all() as $eqY) {
                                        if ($eqY->getIsEnable()) {
                                            $eqActive[$eqY->getEqType_name()] = ($eqActive[$eqY->getEqType_name()] ?? 0) + 1;
                                        }
                                    }
                                    foreach ($plugins as $pl) {
                                        if ($pl->getId() == 'ollamaAgent') {
                                            continue;
                                        }
                                        $nb = $eqActive[$pl->getId()] ?? 0;
                                        $bold = in_array(strtolower($pl->getId()), $highlight);
                                        echo '<tr>';
                                        echo '<td><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="readable_plugins" data-l3key="' . htmlspecialchars($pl->getId()) . '" /></td>';
                                        echo '<td>' . ($bold ? '<b>' : '') . htmlspecialchars($pl->getName()) . ($bold ? '</b>' : '') . '</td>';
                                        echo '<td><code>' . htmlspecialchars($pl->getId()) . '</code></td>';
                                        echo '<td>' . $nb . '</td>';
                                        echo '</tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                        </div>
                        <div class="typeOnly" data-types="general,repas">
                        <legend><i class="fas fa-utensils"></i> {{Repas}}</legend>
                        <div class="typeOnly alert alert-info" data-types="repas">{{Agent Repas : repas du jour, menu de la semaine, courses, frigo, carnet de recettes et goûts du plugin repas (lecture seule).}}</div>
                        <div class="form-group typeOnly" data-types="general">
                            <label class="col-sm-3 control-label">{{Outils repas}}</label>
                            <div class="col-sm-8">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="repas_enable" /> {{Lecture du plugin repas : repas du jour, menu de la semaine, courses, frigo, carnet de recettes, goûts (lecture seule)}}</label>
                                <?php if (!class_exists('repas')) echo '<span class="label label-warning">{{Plugin repas non installé}}</span>'; ?>
                            </div>
                        </div>
                        </div>
                        <div class="typeOnly" data-types="general,agenda">
                        <legend><i class="fas fa-calendar-alt"></i> {{Agenda}}</legend>
                        <div class="typeOnly alert alert-info" data-types="agenda">{{Agent Agenda : envoie par exemple « ajoute foot jeudi soir et Mamie dimanche midi » dans Demande. L'agent ajoute une ligne par évènement.}}</div>
                        <div class="form-group typeOnly" data-types="general">
                            <label class="col-sm-3 control-label">{{Lire et ajouter dans l'agenda}}</label>
                            <div class="col-sm-8">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="agenda_enable" /> {{Outils lire_agenda et ajouter_agenda (ex. « ajoute foot jeudi soir et restau samedi midi »)}}</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Commande « Ajouter » de l'agenda}}</label>
                            <div class="col-sm-5">
                                <div class="input-group">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="agenda_cmd" placeholder="{{Vide = recherche automatique dans le plugin agenda}}" />
                                    <span class="input-group-btn">
                                        <a class="btn btn-default" id="bt_selectAgendaCmd"><i class="fas fa-list-alt"></i></a>
                                    </span>
                                </div>
                            </div>
                        </div>
                        </div>
                    </fieldset>
                </form>
            </div>

            <div role="tabpanel" class="tab-pane" id="healthtab">
                <form class="form-horizontal">
                    <fieldset>
                        <legend><i class="fas fa-heartbeat"></i> {{Surveillance de Jeedom}}</legend>
                        <div class="form-group typeOnly" data-types="general">
                            <label class="col-sm-3 control-label">{{Activer sur cet agent}}</label>
                            <div class="col-sm-8">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="health_enable" /> {{Crée les commandes Santé (mises à jour toutes les 5 min) et l'outil etat_jeedom. Sauvegarder pour créer les commandes.}}</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Consigne du « Bilan santé »}}</label>
                            <div class="col-sm-6">
                                <textarea class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="health_prompt" rows="3" placeholder="{{Ex. Fais un bilan court : d'abord ce qui est urgent, puis les piles à changer.}}"></textarea>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label"></label>
                            <div class="col-sm-6">
                                <a class="btn btn-info" id="bt_healthNow"><i class="fas fa-stethoscope"></i> {{Voir l'état maintenant}}</a>
                                <span class="help-block">{{Familles radio, délais, serveurs à surveiller : dans la configuration du plugin.}}</span>
                            </div>
                        </div>
                        <pre id="pre_healthResult" style="display:none; white-space:pre-wrap; max-height:500px; overflow-y:auto;"></pre>
                    </fieldset>
                </form>
            </div>

            <div role="tabpanel" class="tab-pane" id="exposedtab">
                <br>
                <?php
                // Rien de lourd ici : seulement le nombre d'équipements par plugin (les commandes sont chargées à la demande)
                $eqCountByType = [];
                foreach (eqLogic::all() as $eqX) {
                    if ($eqX->getEqType_name() != 'ollamaAgent') {
                        $eqCountByType[$eqX->getEqType_name()] = ($eqCountByType[$eqX->getEqType_name()] ?? 0) + 1;
                    }
                }
                $pluginNames = [];
                foreach (plugin::listPlugin(true) as $pl) {
                    $pluginNames[$pl->getId()] = $pl->getName();
                }
                uksort($eqCountByType, function ($x, $y) use ($pluginNames) {
                    return strcasecmp($pluginNames[$x] ?? $x, $pluginNames[$y] ?? $y);
                });
                ?>
                <!-- la sélection est enregistrée dans ces deux champs (liste d'ID séparés par des virgules) -->
                <input type="hidden" class="eqLogicAttr" data-l1key="configuration" data-l2key="exposed_list" id="in_exposedList" />
                <input type="hidden" class="eqLogicAttr" data-l1key="configuration" data-l2key="exposed_action_list" id="in_exposedActionList" />
                <legend><i class="fas fa-puzzle-piece"></i> {{1. Choisis un ou plusieurs plugins}}</legend>
                <div id="div_exposedPlugins" style="margin-bottom:10px;">
                    <?php
                    foreach ($eqCountByType as $type => $nb) {
                        $label = $pluginNames[$type] ?? $type;
                        echo '<label class="btn btn-default btn-xs exposedPluginBtn" style="margin:2px;" data-plugin="' . htmlspecialchars($type) . '" title="' . $nb . ' {{équipement(s)}}">';
                        echo '<input type="checkbox" class="eqLogicAttr cb_exposedPlugin" style="display:none;" data-l1key="configuration" data-l2key="exposed_view_plugins" data-l3key="' . htmlspecialchars($type) . '" data-plugin="' . htmlspecialchars($type) . '" /> ';
                        echo htmlspecialchars($label) . ' <span class="badge exposedPluginBadge" data-plugin="' . htmlspecialchars($type) . '"></span>';
                        echo '</label>';
                    }
                    ?>
                </div>
                <legend><i class="fas fa-check-square"></i> {{2. Coche les commandes utiles}}</legend>
                <div class="row">
                    <div class="col-sm-5">
                        <div class="input-group">
                            <span class="input-group-addon"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" id="in_searchExposedCmd" placeholder="{{Affiner : batterie, salon temperature…}}" />
                        </div>
                    </div>
                    <div class="col-sm-7">
                        <label class="checkbox-inline" style="margin-top:6px;">
                            <input type="checkbox" id="cb_onlyCheckedCmds" /> {{Seulement les cochées (tous plugins)}}
                        </label>
                        <a class="btn btn-default btn-xs" id="bt_checkVisibleCmds" style="margin-left:8px;"><i class="far fa-check-square"></i> {{Cocher les infos affichées}}</a>
                        <a class="btn btn-default btn-xs" id="bt_uncheckVisibleCmds"><i class="far fa-square"></i> {{Décocher les infos affichées}}</a>
                        <span class="label label-info" id="span_exposedCount" style="margin-left:8px;"></span>
                    </div>
                </div>
                <div class="alert alert-info" id="div_exposedHint" style="margin-top:10px;"></div>

                <legend><i class="fas fa-eye"></i> {{Commandes Infos exposées (lecture contexte)}}</legend>
                <div style="max-height: 450px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; margin-bottom: 15px;">
                    <table class="table table-condensed table-hover" id="table_exposedCmds">
                        <thead>
                            <tr>
                                <th style="width: 50px;">{{Lire}}</th>
                                <th>{{Objet}}</th>
                                <th>{{Équipement}}</th>
                                <th>{{Commande Info}}</th>
                                <th>{{Valeur actuelle}}</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

                <legend><i class="fas fa-bolt"></i> {{Commandes Actions exposées (pilotage via outils / Device control)}}</legend>
                <div style="max-height: 260px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">
                    <table class="table table-condensed table-hover" id="table_exposedActionCmds">
                        <thead>
                            <tr>
                                <th style="width: 50px;">{{Action}}</th>
                                <th>{{Objet}}</th>
                                <th>{{Équipement}}</th>
                                <th>{{Commande Action}}</th>
                                <th>{{Sous-type}}</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            <div role="tabpanel" class="tab-pane" id="commandtab">
                <table id="table_cmd" class="table table-bordered table-condensed">
                    <thead>
                        <tr>
                            <th>{{Nom}}</th>
                            <th>{{Type}}</th>
                            <th>{{Action}}</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include_file('desktop', 'ollamaAgent', 'js', 'ollamaAgent'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
