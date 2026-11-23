function addCmdToTable(_cmd) {
    if (!isset(_cmd)) {
        var _cmd = {configuration: {}};
    }
    var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
    tr += '<td><input class="cmdAttr form-control input-sm" data-l1key="id" style="display:none;"><input class="cmdAttr form-control input-sm" data-l1key="name"></td>';
    tr += '<td><span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span><span class="subType" subType="' + init(_cmd.subType) + '"></span></td>';
    tr += '<td>';
    if (is_numeric(_cmd.id)) {
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> ';
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>';
    }
    tr += '</td></tr>';
    $('#table_cmd tbody').append(tr);
    $('#table_cmd tbody tr:last').setValues(_cmd, '.cmdAttr');
}

function filterExposedCmds() {
    var search = $('#in_searchExposedCmd').val().toLowerCase();
    var onlyChecked = $('#cb_onlyCheckedCmds').is(':checked');

    $('#table_exposedCmds tbody tr.exposedCmdRow, #table_exposedActionCmds tbody tr.exposedCmdRow').each(function() {
        var rowText = $(this).text().toLowerCase();
        var isChecked = $(this).find('.cb_exposedCmd').is(':checked');
        var matchSearch = (search === '' || rowText.indexOf(search) !== -1);
        var matchChecked = (!onlyChecked || isChecked);

        if (matchSearch && matchChecked) {
            $(this).show();
        } else {
            $(this).hide();
        }
    });
}

$('#in_searchExposedCmd').on('keyup', filterExposedCmds);
$('#cb_onlyCheckedCmds').on('change', filterExposedCmds);

$('#bt_selectAgendaCmd').off('click').on('click', function() {
    jeedom.cmd.getSelectModal({cmd: {type: 'action', subType: 'message'}}, function(result) {
        $('.eqLogicAttr[data-l1key=configuration][data-l2key=agenda_cmd]').value(result.human);
    });
});

$('#bt_healthNow').off('click').on('click', function() {
    var pre = $('#pre_healthResult');
    pre.show().text('Analyse en cours...');
    $.ajax({
        type: 'POST',
        url: 'plugins/ollamaAgent/core/ajax/ollamaAgent.ajax.php',
        data: {action: 'healthNow'},
        dataType: 'json',
        error: function(request, status, error) {
            handleAjaxError(request, status, error);
        },
        success: function(data) {
            if (data.state != 'ok') {
                pre.text('Erreur : ' + data.result);
                return;
            }
            pre.text(data.result);
        }
    });
});

var OLLAMA_AGENT_HELP = {
    general: 'Tous les outils, selon les cases cochées dans les onglets.',
    repas: 'Outils du plugin repas uniquement : jour, semaine, courses, frigo, recettes, goûts.',
    energie: 'Lecture et historique des plugins cochés (enerSol, Solcast, Victron…).',
    agenda: 'Lire l\'agenda et y ajouter des lignes à partir d\'un message.',
    surveillance: 'Santé de Jeedom : Zigbee, Z-Wave, IP, serveurs, démons, piles, logs. Commandes Santé mises à jour toutes les 5 min.',
    vision: 'Levée de doute : analyse une image, remplit Verdict (INTRUSION / RAS / DOUTE / ERREUR), Intrusion, Fausse alarme et Description. Prioritaire, sans mémoire, modèle toujours chargé.'
};

function ollamaAgentApplyType() {
    var type = $('#sel_agentType').value() || 'general';
    $('.eqLogic .typeOnly').each(function() {
        var types = String($(this).attr('data-types') || '').split(',');
        $(this).toggle(types.indexOf(type) !== -1);
    });
    $('#div_agentTypeHelp').text(OLLAMA_AGENT_HELP[type] || '');
    // si l'onglet affiché est masqué, on revient au premier onglet
    var active = $('.eqLogic .nav-tabs li.active');
    if (active.length && active.is(':hidden')) {
        $('.eqLogic .nav-tabs a[href="#eqlogictab"]').tab('show');
    }
}

$('#sel_agentType').on('change', ollamaAgentApplyType);

function printEqLogic(_eqLogic) {
    if (!_eqLogic.configuration || !_eqLogic.configuration.agent_type) {
        $('#sel_agentType').value('general');
    }
    ollamaAgentApplyType();
    $('#pre_conversation').hide().text('');
    $('#span_notesState').html('');
    $('#ta_agentNotes').val('');
    if (_eqLogic.id) {
        ollamaAgentMemory('getMemory', {});
    }
}

function ollamaAgentMemory(action, extra, done) {
    var id = $('.eqLogicAttr[data-l1key=id]').value();
    if (!id) {
        return;
    }
    $.ajax({
        type: 'POST',
        url: 'plugins/ollamaAgent/core/ajax/ollamaAgent.ajax.php',
        data: $.extend({action: action, id: id}, extra || {}),
        dataType: 'json',
        error: function(request, status, error) {
            handleAjaxError(request, status, error);
        },
        success: function(data) {
            if (data.state != 'ok') {
                $('#span_notesState').html('<span class="label label-danger">' + data.result + '</span>');
                return;
            }
            $('#ta_agentNotes').val(data.result.notes);
            if (done) {
                done(data.result);
            }
        }
    });
}

$('#bt_reloadNotes').off('click').on('click', function() {
    ollamaAgentMemory('getMemory', {}, function() {
        $('#span_notesState').html('');
    });
});

$('#bt_saveNotes').off('click').on('click', function() {
    ollamaAgentMemory('saveNotes', {notes: $('#ta_agentNotes').val()}, function() {
        $('#span_notesState').html('<span class="label label-success">Notes enregistrées</span>');
    });
});

$('#bt_showConversation').off('click').on('click', function() {
    ollamaAgentMemory('getMemory', {}, function(r) {
        $('#pre_conversation').show().text(r.conversation);
    });
});

$('#bt_clearConversation').off('click').on('click', function() {
    ollamaAgentMemory('clearConversation', {}, function(r) {
        $('#pre_conversation').show().text(r.conversation);
    });
});

$('#bt_selectNotifyCmd').off('click').on('click', function() {
    jeedom.cmd.getSelectModal({cmd: {type: 'action', subType: 'message'}}, function(result) {
        $('.eqLogicAttr[data-l1key=configuration][data-l2key=notify_cmd]').value(result.human);
    });
});

$('#bt_selectImageCmd').off('click').on('click', function() {
    jeedom.cmd.getSelectModal({cmd: {type: 'info'}}, function(result) {
        $('.eqLogicAttr[data-l1key=configuration][data-l2key=image_source]').value(result.human);
    });
});
