<?php
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
require_once dirname(__FILE__) . '/../core/class/ollamaAgent.class.php';
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
    <fieldset>
        <legend><i class="fas fa-server"></i> {{Ollama}}</legend>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{URL du serveur Ollama}}</label>
            <div class="col-lg-4">
                <input class="configKey form-control" id="in_ollamaUrl" data-l1key="ollama_url" placeholder="http://192.168.1.50:11434" />
            </div>
            <div class="col-lg-4">
                <a class="btn btn-info" id="bt_testOllama"><i class="fas fa-vial"></i> {{Tester la connexion Ollama}}</a>
            </div>
        </div>
        <div class="form-group">
            <label class="col-lg-4 control-label"></label>
            <div class="col-lg-6" id="div_ollamaTestResult"></div>
        </div>

        <legend><i class="fab fa-whatsapp"></i> {{Messagerie (jeeWhatsapp, Telegram…)}}</legend>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Répondre aux messages reçus}}</label>
            <div class="col-lg-6">
                <input type="checkbox" class="configKey" data-l1key="msg_enable" />
                <span class="help-block">{{Chaque message reçu est envoyé au bon agent (ceux qui ont « Répond sur la messagerie » coché), puis la réponse est renvoyée à l'expéditeur.}}</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Équipement jeeWhatsapp}}</label>
            <div class="col-lg-4">
                <select class="configKey form-control" data-l1key="msg_jw_eq" id="sel_msgJwEq">
                    <option value="">{{Aucun (autre plugin : commandes ci-dessous)}}</option>
                    <?php
                    if (class_exists('jeewhatsapp')) {
                        foreach (eqLogic::byType('jeewhatsapp') as $jw) {
                            echo '<option value="' . $jw->getId() . '">' . htmlspecialchars($jw->getHumanName()) . '</option>';
                        }
                    }
                    ?>
                </select>
            </div>
            <div class="col-lg-4">
                <label class="checkbox-inline msgJwOnly"><input type="checkbox" class="configKey" data-l1key="msg_private" /> {{Répondre en privé (sinon dans le groupe canal)}}</label>
            </div>
        </div>
        <?php
        foreach (ollamaAgentMessaging::warnings() as $w) {
            echo '<div class="alert alert-warning col-lg-offset-4 col-lg-6">' . htmlspecialchars($w) . '</div><div class="clearfix"></div>';
        }
        ?>
        <div class="msgGenericOnly">
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Commande info « message reçu »}}</label>
            <div class="col-lg-5">
                <div class="input-group">
                    <input class="configKey form-control" data-l1key="msg_in_cmd" placeholder="#[Maison][WhatsApp][Dernier message]#" />
                    <span class="input-group-btn"><a class="btn btn-default bt_selectMsgCmd" data-key="msg_in_cmd" data-type="info"><i class="fas fa-list-alt"></i></a></span>
                </div>
            </div>
        </div>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Commande info « expéditeur »}}</label>
            <div class="col-lg-5">
                <div class="input-group">
                    <input class="configKey form-control" data-l1key="msg_from_cmd" placeholder="#[Maison][WhatsApp][Expéditeur]#" />
                    <span class="input-group-btn"><a class="btn btn-default bt_selectMsgCmd" data-key="msg_from_cmd" data-type="info"><i class="fas fa-list-alt"></i></a></span>
                </div>
            </div>
        </div>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Commande action « répondre »}}</label>
            <div class="col-lg-5">
                <div class="input-group">
                    <input class="configKey form-control" data-l1key="msg_reply_cmd" placeholder="#[Maison][WhatsApp][Envoyer]#" />
                    <span class="input-group-btn"><a class="btn btn-default bt_selectMsgCmd" data-key="msg_reply_cmd" data-type="action"><i class="fas fa-list-alt"></i></a></span>
                </div>
            </div>
        </div>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Titre de la réponse}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Champ titre de la commande répondre. #agent# = nom de l'agent, #expediteur# = expéditeur du message (si ton plugin attend le destinataire dans le titre).}}"></i></sup>
            </label>
            <div class="col-lg-3">
                <input class="configKey form-control" data-l1key="msg_reply_title" placeholder="{{Vide (ou #expediteur#)}}" />
            </div>
            <div class="col-lg-4">
                <label class="checkbox-inline"><input type="checkbox" class="configKey" data-l1key="msg_prefix_agent" checked /> {{Nom de l'agent devant la réponse}}</label>
            </div>
        </div>
        </div>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Expéditeurs autorisés}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Numéros, identifiants WhatsApp (LID) ou profils jeeWhatsapp (user_mapping), séparés par des virgules. Vide = personne. * = tout le monde (déconseillé). Un expéditeur refusé est écrit dans le log ollamaAgent, à recopier ici.}}"></i></sup>
            </label>
            <div class="col-lg-5">
                <input class="configKey form-control" data-l1key="msg_allowed" placeholder="+33612345678" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Agent par défaut}}</label>
            <div class="col-lg-3">
                <select class="configKey form-control" data-l1key="msg_default_agent">
                    <option value="">{{Automatique}}</option>
                    <?php
                    foreach (eqLogic::byType('ollamaAgent') as $eq) {
                        echo '<option value="' . $eq->getId() . '">' . htmlspecialchars($eq->getName()) . '</option>';
                    }
                    ?>
                </select>
            </div>
            <div class="col-lg-5 help-block">{{Si l'IA n'arrive pas à choisir.}}</div>
        </div>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Modèle pour le tri}}</label>
            <div class="col-lg-3">
                <input class="configKey form-control" data-l1key="msg_router_model" placeholder="{{Vide = modèle de l'agent par défaut}}" />
            </div>
            <div class="col-lg-5">
                <label class="checkbox-inline"><input type="checkbox" class="configKey" data-l1key="msg_ack" /> {{Accusé de réception (« ⏳ Repas réfléchit… »)}}</label>
            </div>
        </div>

        <legend><i class="fas fa-heartbeat"></i> {{Surveillance de Jeedom}}</legend>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Serveurs à surveiller}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Un par ligne : Nom;adresse;port. Sans port = ping, avec port = test TCP.}}"></i></sup>
            </label>
            <div class="col-lg-6">
                <textarea class="configKey form-control" data-l1key="health_servers" rows="7" placeholder="Proxmox;192.168.1.10;8006&#10;Ollama;192.168.1.50;11434&#10;Mosquitto;192.168.1.60;1883&#10;Frigate;192.168.1.70;5000&#10;SearXNG;192.168.1.80;8080&#10;Box internet;192.168.1.1"></textarea>
            </div>
        </div>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Pinger les équipements IP}}</label>
            <div class="col-lg-6">
                <input type="checkbox" class="configKey" data-l1key="ping_ip" checked />
                <span class="help-block">{{Équipements dont la configuration contient une adresse IP (ip, host, addr…). Ping en parallèle, 1 s max.}}</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Patience réseau}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Ping : un essai rapide, puis pour ceux qui ne répondent pas, N essais avec ce délai. Port : 2 essais avec ce délai. Un équipement IP ou un serveur n'est déclaré hors ligne qu'après le nombre de contrôles ratés de suite indiqué (un contrôle toutes les 5 min).}}"></i></sup>
            </label>
            <div class="col-lg-2"><div class="input-group"><span class="input-group-addon">{{Ping}}</span><input class="configKey form-control" data-l1key="ping_timeout" placeholder="3" /><span class="input-group-addon">s</span></div></div>
            <div class="col-lg-2"><div class="input-group"><span class="input-group-addon">{{Essais}}</span><input class="configKey form-control" data-l1key="ping_count" placeholder="3" /></div></div>
            <div class="col-lg-2"><div class="input-group"><span class="input-group-addon">{{Port}}</span><input class="configKey form-control" data-l1key="tcp_timeout" placeholder="5" /><span class="input-group-addon">s</span></div></div>
            <div class="col-lg-2"><div class="input-group"><span class="input-group-addon">{{Ratés de suite}}</span><input class="configKey form-control" data-l1key="net_fail_count" placeholder="2" /></div></div>
        </div>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Types Zigbee}}</label>
            <div class="col-lg-6">
                <input class="configKey form-control" data-l1key="types_zigbee" placeholder="zigbee,z2m,zigbee2mqtt,deconz,zigate,zhaJeedom" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Types Z-Wave}}</label>
            <div class="col-lg-6">
                <input class="configKey form-control" data-l1key="types_zwave" placeholder="zwavejs,openzwave,zwave" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Types Bluetooth}}</label>
            <div class="col-lg-6">
                <input class="configKey form-control" data-l1key="types_bt" placeholder="blea,ble,theengs" />
                <span class="help-block">{{Identifiants de plugins (eqType), séparés par des virgules. Vide = valeurs par défaut.}}</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Délai sans communication (min)}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Utilisé quand l'équipement n'a pas de délai d'alerte réglé dans Jeedom.}}"></i></sup>
            </label>
            <div class="col-lg-2"><div class="input-group"><span class="input-group-addon">Zigbee</span><input class="configKey form-control" data-l1key="timeout_zigbee" placeholder="240" /></div></div>
            <div class="col-lg-2"><div class="input-group"><span class="input-group-addon">Z-Wave</span><input class="configKey form-control" data-l1key="timeout_zwave" placeholder="240" /></div></div>
            <div class="col-lg-2"><div class="input-group"><span class="input-group-addon">BT</span><input class="configKey form-control" data-l1key="timeout_bt" placeholder="60" /></div></div>
        </div>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Équipements sur pile (min)}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Contacts de fenêtre, détecteurs… : ils ne communiquent qu'au changement d'état ou au réveil. Délai utilisé s'il n'y a pas de délai d'alerte réglé dans Jeedom. 1500 min = 25 h.}}"></i></sup>
            </label>
            <div class="col-lg-2"><input class="configKey form-control" data-l1key="timeout_battery" placeholder="1500" /></div>
            <div class="col-lg-6 help-block">{{Pour un équipement précis, règle plutôt son « Délai avant alerte » dans Jeedom (Configuration avancée de l'équipement) : il est prioritaire.}}</div>
        </div>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{Seuils}}</label>
            <div class="col-lg-2"><div class="input-group"><span class="input-group-addon">{{Pile ≤}}</span><input class="configKey form-control" data-l1key="battery_threshold" placeholder="20" /><span class="input-group-addon">%</span></div></div>
            <div class="col-lg-2"><div class="input-group"><span class="input-group-addon">{{LQI <}}</span><input class="configKey form-control" data-l1key="lqi_threshold" placeholder="30" /></div></div>
        </div>
        <div class="form-group">
            <label class="col-lg-4 control-label">{{ID d'équipements à ignorer}}</label>
            <div class="col-lg-6">
                <input class="configKey form-control" data-l1key="ignore_ids" placeholder="12,45,78" />
            </div>
        </div>
    </fieldset>
</form>

<script>
function ollamaAgentMsgMode() {
    var jw = $('#sel_msgJwEq').value() !== '';
    $('.msgGenericOnly').toggle(!jw);
    $('.msgJwOnly').toggle(jw);
}
$('#sel_msgJwEq').on('change', ollamaAgentMsgMode);
setTimeout(ollamaAgentMsgMode, 500);

$('.bt_selectMsgCmd').off('click').on('click', function() {
    var key = $(this).attr('data-key');
    var type = $(this).attr('data-type');
    var filter = (type == 'action') ? {type: 'action', subType: 'message'} : {type: 'info'};
    jeedom.cmd.getSelectModal({cmd: filter}, function(result) {
        $('.configKey[data-l1key=' + key + ']').value(result.human);
    });
});

$('#bt_testOllama').off('click').on('click', function() {
    var url = $('#in_ollamaUrl').val();
    $('#div_ollamaTestResult').html('<span class="label label-warning"><i class="fas fa-spinner fa-spin"></i> {{Test en cours...}}</span>');
    $.ajax({
        type: 'POST',
        url: 'plugins/ollamaAgent/core/ajax/ollamaAgent.ajax.php',
        data: {
            action: 'testConnection',
            url: url
        },
        dataType: 'json',
        error: function(request, status, error) {
            handleAjaxError(request, status, error);
        },
        success: function(data) {
            if (data.state != 'ok') {
                $('#div_ollamaTestResult').html('<div class="alert alert-danger" style="margin-top:5px;">' + data.result + '</div>');
                return;
            }
            $('#div_ollamaTestResult').html('<div class="alert alert-success" style="margin-top:5px;">' + data.result + '</div>');
        }
    });
});
</script>
