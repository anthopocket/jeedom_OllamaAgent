/* ollamaAgent — JavaScript pur (sans jQuery) avec les fonctions du Core Jeedom 4.4+ (info.json : require 4.4) */

/* ---------- Outils ---------- */

function oaQ(sel, root) {
    return (root || document).querySelector(sel);
}

function oaQA(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
}

/** Lit ou écrit la valeur d'un champ (jeeValue du Core) */
function oaVal(el, v) {
    if (!el) {
        return v === undefined ? '' : undefined;
    }
    if (v === undefined) {
        return el.jeeValue();
    }
    el.jeeValue(v);
    el.triggerEvent('change');
}

function oaShown(el) {
    return !!el && el.style.display !== 'none';
}

function oaToggle(el, show) {
    if (el) {
        el.style.display = show ? '' : 'none';
    }
}

function oaAlert(message, level) {
    jeeDialog.toast({message: message, level: level || 'info'});
}

/** Appel Ajax au plugin, par domUtils.ajax du Core (il ajoute lui-même le jeton Jeedom) */
function oaAjax(data, onSuccess) {
    domUtils.ajax({
        type: 'POST',
        url: 'plugins/ollamaAgent/core/ajax/ollamaAgent.ajax.php',
        data: data,
        dataType: 'json',
        global: false,
        error: function(request, status, error) {
            oaAlert('Erreur Ajax : ' + error, 'danger');
        },
        success: function(res) {
            if (!res || res.state != 'ok') {
                oaAlert('Erreur : ' + (res && res.result ? res.result : 'réponse invalide'), 'danger');
                if (onSuccess) {
                    onSuccess(null, res);
                }
                return;
            }
            if (onSuccess) {
                onSuccess(res.result, res);
            }
        }
    });
}

/** Choix d'une commande Jeedom dans la fenêtre standard, résultat écrit dans le champ */
function oaSelectCmd(filter, target) {
    jeedom.cmd.getSelectModal({cmd: filter}, function(result) {
        oaVal(target, result.human);
    });
}

/* ---------- Tableau des commandes de l'agent ---------- */

function addCmdToTable(_cmd) {
    if (!isset(_cmd)) {
        var _cmd = {configuration: {}};
    }
    if (!isset(_cmd.configuration)) {
        _cmd.configuration = {};
    }
    var tr = '<td><input class="cmdAttr form-control input-sm" data-l1key="id" style="display:none;"><input class="cmdAttr form-control input-sm" data-l1key="name"></td>';
    tr += '<td><span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span><span class="subType" subType="' + init(_cmd.subType) + '"></span></td>';
    tr += '<td>';
    if (is_numeric(_cmd.id)) {
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> ';
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>';
    }
    tr += '</td>';
    // modèle du Core 4.4 : createElement + innerHTML + setJeeValues + appendChild
    let newRow = document.createElement('tr');
    newRow.classList.add('cmd');
    newRow.setAttribute('data-cmd_id', init(_cmd.id));
    newRow.innerHTML = tr;
    newRow.setJeeValues(_cmd, '.cmdAttr');
    document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow);
    jeedom.cmd.changeType(newRow, init(_cmd.subType));
}

/* ---------- Filtre des commandes exposées ---------- */

function ollamaAgentFold(t) {
    return String(t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

// mêmes équivalents français / anglais que le filtre de l'agent (lire_plugin)
var OLLAMA_AGENT_SYNONYMS = {
    batterie: ['batterie', 'battery', 'batt', 'soc'],
    charge: ['charge', 'soc', 'charging'],
    solaire: ['solaire', 'solar', 'pv', 'mppt', 'yield'],
    production: ['production', 'pv', 'solar', 'yield', 'mppt'],
    consommation: ['consommation', 'consumption', 'conso', 'load'],
    puissance: ['puissance', 'power'],
    reseau: ['reseau', 'grid', 'linky'],
    tension: ['tension', 'voltage'],
    courant: ['courant', 'current'],
    temperature: ['temperature', 'temp'],
    prevision: ['prevision', 'forecast', 'estimate']
};

function ollamaAgentWordMatch(word, text) {
    var alts = OLLAMA_AGENT_SYNONYMS[word] || [word];
    return alts.some(function(a) { return text.indexOf(a) !== -1; });
}

function filterExposedCmds() {
    // plugins choisis (boutons du haut)
    var plugins = {};
    var nbPlugins = 0;
    oaQA('.cb_exposedPlugin').forEach(function(cb) {
        var btn = cb.closest('.exposedPluginBtn');
        if (btn) {
            btn.classList.toggle('btn-primary', cb.checked);
            btn.classList.toggle('btn-default', !cb.checked);
        }
        if (cb.checked) {
            plugins[cb.getAttribute('data-plugin')] = true;
            nbPlugins++;
        }
    });
    var search = oaQ('#in_searchExposedCmd');
    var words = ollamaAgentFold(search ? search.value : '').split(/\s+/).filter(function(w) { return w !== ''; });
    var onlyCb = oaQ('#cb_onlyCheckedCmds');
    var onlyChecked = !!(onlyCb && onlyCb.checked);
    var checkedByPlugin = {};
    oaQA('#table_exposedCmds tbody tr.exposedCmdRow, #table_exposedActionCmds tbody tr.exposedCmdRow').forEach(function(row) {
        var plugin = row.getAttribute('data-plugin');
        var cb = row.querySelector('.cb_exposedCmd');
        var isChecked = !!(cb && cb.checked);
        if (isChecked) {
            checkedByPlugin[plugin] = (checkedByPlugin[plugin] || 0) + 1;
        }
        var inScope;
        if (onlyChecked || nbPlugins === 0) {
            inScope = isChecked; // sans plugin choisi : ce qui est déjà coché
        } else {
            inScope = plugins[plugin] === true;
        }
        var rowText = inScope && words.length ? ollamaAgentFold(row.textContent) : '';
        var matchSearch = words.every(function(w) { return ollamaAgentWordMatch(w, rowText); });
        oaToggle(row, inScope && matchSearch);
    });
    oaQA('.exposedPluginBadge').forEach(function(b) {
        var n = checkedByPlugin[b.getAttribute('data-plugin')] || 0;
        b.textContent = n ? n : '';
    });
    var nInfo = oaQA('#table_exposedCmds .cb_exposedCmd').filter(function(c) { return c.checked; }).length;
    var nAction = oaQA('#table_exposedActionCmds .cb_exposedCmd').filter(function(c) { return c.checked; }).length;
    var count = oaQ('#span_exposedCount');
    if (count) {
        count.textContent = nInfo + ' info(s)' + (nAction ? ' · ' + nAction + ' action(s)' : '') + ' cochée(s)';
    }
    var hint = oaQ('#div_exposedHint');
    if (hint) {
        var waiting = nbPlugins === 0 && !onlyChecked;
        hint.classList.toggle('alert-warning', waiting);
        hint.classList.toggle('alert-info', !waiting);
        hint.textContent = waiting
            ? 'Choisis un ou plusieurs plugins ci-dessus pour afficher leurs commandes. En attendant, seules les commandes déjà cochées sont affichées.'
            : "Les commandes cochées sont envoyées à chaque demande (l'agent les voit sans appeler d'outil) et il peut lire leur historique. Coche seulement l'utile : moins il y en a, plus il répond vite. Le « Commentaire » de l'équipement est envoyé avec.";
    }
}

function ollamaAgentCheckVisible(state) {
    oaQA('#table_exposedCmds tbody tr.exposedCmdRow').forEach(function(row) {
        if (oaShown(row)) {
            var cb = row.querySelector('.cb_exposedCmd');
            if (cb) {
                cb.checked = state;
            }
        }
    });
    filterExposedCmds();
}

/* ---------- Type d'agent ---------- */

var OLLAMA_AGENT_HELP = {
    general: 'Tous les outils, selon les cases cochées dans les onglets.',
    repas: 'Outils du plugin repas uniquement : jour, semaine, courses, frigo, recettes, goûts.',
    energie: 'Lecture et historique des plugins cochés (enerSol, Solcast, Victron…).',
    agenda: 'Lire l\'agenda et y ajouter des lignes à partir d\'un message.',
    surveillance: 'Santé de Jeedom : Zigbee, Z-Wave, IP, serveurs, démons, piles, logs. Commandes Santé mises à jour toutes les 5 min.',
    vision: 'Levée de doute : analyse une image, remplit Verdict (INTRUSION / RAS / DOUTE / ERREUR), Intrusion, Fausse alarme et Description. Prioritaire, sans mémoire, modèle toujours chargé.'
};

function ollamaAgentApplyType() {
    var sel = oaQ('#sel_agentType');
    var type = (sel ? oaVal(sel) : '') || 'general';
    oaQA('.eqLogic .typeOnly').forEach(function(el) {
        var types = String(el.getAttribute('data-types') || '').split(',');
        oaToggle(el, types.indexOf(type) !== -1);
    });
    var help = oaQ('#div_agentTypeHelp');
    if (help) {
        help.textContent = OLLAMA_AGENT_HELP[type] || '';
    }
    // l'onglet affiché est masqué : retour au premier onglet
    var active = oaQ('.eqLogic .nav-tabs li.active');
    if (active && !oaShown(active)) {
        var first = oaQ('.eqLogic .nav-tabs a[href="#eqlogictab"]');
        if (first) {
            first.click();
        }
    }
}

/* ---------- Mémoire ---------- */

function ollamaAgentMemory(action, extra, done) {
    var idEl = oaQ('.eqLogicAttr[data-l1key="id"]');
    var id = idEl ? oaVal(idEl) : '';
    if (!id) {
        return;
    }
    var data = Object.assign({action: action, id: id}, extra || {});
    oaAjax(data, function(result) {
        if (!result) {
            return;
        }
        var ta = oaQ('#ta_agentNotes');
        if (ta) {
            ta.value = result.notes;
        }
        if (done) {
            done(result);
        }
    });
}

/* ---------- Chargement d'un agent (appelé par Jeedom) ---------- */

function printEqLogic(_eqLogic) {
    var sel = oaQ('#sel_agentType');
    if (sel && (!_eqLogic.configuration || !_eqLogic.configuration.agent_type)) {
        sel.value = 'general';
    }
    ollamaAgentApplyType();
    var pre = oaQ('#pre_conversation');
    if (pre) {
        pre.style.display = 'none';
        pre.textContent = '';
    }
    var state = oaQ('#span_notesState');
    if (state) {
        state.innerHTML = '';
    }
    var ta = oaQ('#ta_agentNotes');
    if (ta) {
        ta.value = '';
    }
    if (_eqLogic.id) {
        ollamaAgentMemory('getMemory', {});
    }
    setTimeout(filterExposedCmds, 100);
}

/* ---------- Événements (posés une seule fois : Jeedom recharge ce script à chaque ouverture) ---------- */

if (!window.__ollamaAgentEvents) {
    window.__ollamaAgentEvents = true;

    document.addEventListener('change', function(e) {
        var t = e.target;
        if (!t || !t.closest) {
            return;
        }
        if (t.matches('.cb_exposedCmd, .cb_exposedPlugin, #cb_onlyCheckedCmds')) {
            filterExposedCmds();
        } else if (t.matches('#sel_agentType')) {
            ollamaAgentApplyType();
        }
    });

    document.addEventListener('keyup', function(e) {
        if (e.target && e.target.matches && e.target.matches('#in_searchExposedCmd')) {
            filterExposedCmds();
        }
    });

    document.addEventListener('click', function(e) {
        var b = e.target && e.target.closest ? e.target.closest('a, button') : null;
        if (!b || !b.id) {
            return;
        }
        switch (b.id) {
            case 'bt_checkVisibleCmds':
                ollamaAgentCheckVisible(true);
                break;
            case 'bt_uncheckVisibleCmds':
                ollamaAgentCheckVisible(false);
                break;
            case 'bt_selectAgendaCmd':
                oaSelectCmd({type: 'action', subType: 'message'}, oaQ('.eqLogicAttr[data-l1key="configuration"][data-l2key="agenda_cmd"]'));
                break;
            case 'bt_selectNotifyCmd':
                oaSelectCmd({type: 'action', subType: 'message'}, oaQ('.eqLogicAttr[data-l1key="configuration"][data-l2key="notify_cmd"]'));
                break;
            case 'bt_selectImageCmd':
                oaSelectCmd({type: 'info'}, oaQ('.eqLogicAttr[data-l1key="configuration"][data-l2key="image_source"]'));
                break;
            case 'bt_healthNow':
                var pre = oaQ('#pre_healthResult');
                if (pre) {
                    pre.style.display = '';
                    pre.textContent = 'Analyse en cours...';
                }
                oaAjax({action: 'healthNow'}, function(result) {
                    if (pre) {
                        pre.textContent = result === null ? 'Erreur' : result;
                    }
                });
                break;
            case 'bt_reloadNotes':
                ollamaAgentMemory('getMemory', {}, function() {
                    var s = oaQ('#span_notesState');
                    if (s) {
                        s.innerHTML = '';
                    }
                });
                break;
            case 'bt_saveNotes':
                ollamaAgentMemory('saveNotes', {notes: (oaQ('#ta_agentNotes') || {}).value || ''}, function() {
                    var s = oaQ('#span_notesState');
                    if (s) {
                        s.innerHTML = '<span class="label label-success">Notes enregistrées</span>';
                    }
                });
                break;
            case 'bt_showConversation':
            case 'bt_clearConversation':
                ollamaAgentMemory(b.id == 'bt_showConversation' ? 'getMemory' : 'clearConversation', {}, function(r) {
                    var p = oaQ('#pre_conversation');
                    if (p) {
                        p.style.display = '';
                        p.textContent = r.conversation;
                    }
                });
                break;
            default:
                return;
        }
        e.preventDefault();
    });
}
