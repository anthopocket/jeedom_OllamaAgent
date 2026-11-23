<?php
/* Passerelle de messagerie (jeeWhatsapp, Telegram…) :
 * message reçu → choix de l'agent → réponse renvoyée à l'expéditeur.
 * Pas de propriété de classe ici non plus : tout passe par la configuration du plugin. */

class ollamaAgentMessaging {

    const TYPE_DESCRIPTIONS = [
        'repas' => 'repas, menus, recettes, cuisine, courses, frigo, calories, gamelles',
        'energie' => 'énergie, solaire, batterie, consommation électrique, quand lancer les machines, chauffage, chauffe-eau, piscine',
        'agenda' => 'agenda, ajouter ou lire un évènement, sorties, absences, invités, sport, qui mange à la maison',
        'surveillance' => 'état de Jeedom, équipements en panne ou hors ligne, piles, serveurs, démons',
        'general' => 'questions générales sur la maison',
    ];

    public static function enabled() {
        return config::byKey('msg_enable', 'ollamaAgent', 0) == 1;
    }

    private static function cmdFrom($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^#?(\d+)#?$/', $value, $m)) {
            $cmd = cmd::byId($m[1]);
            return is_object($cmd) ? $cmd : null;
        }
        $id = str_replace('#', '', cmd::humanReadableToCmd($value));
        if (ctype_digit($id)) {
            $cmd = cmd::byId($id);
            return is_object($cmd) ? $cmd : null;
        }
        return null;
    }

    /* ---------- Source : équipement jeeWhatsapp ou commandes au choix ---------- */

    /** Équipement jeeWhatsapp choisi (mode intégré), sinon null (mode générique) */
    public static function jwEq() {
        $id = (string)config::byKey('msg_jw_eq', 'ollamaAgent', '');
        if ($id === '') {
            return null;
        }
        $eq = eqLogic::byId($id);
        return (is_object($eq) && $eq->getEqType_name() == 'jeewhatsapp') ? $eq : null;
    }

    private static function jwValue($eq, $logicalId) {
        $cmd = $eq->getCmd('info', $logicalId);
        return is_object($cmd) ? trim((string)$cmd->execCmd()) : '';
    }

    /** Commande écoutée : « Reçu le » de jeeWhatsapp (mise à jour après le message et l'expéditeur), sinon la commande choisie */
    private static function listenedCmd() {
        $jw = self::jwEq();
        if ($jw !== null) {
            $cmd = $jw->getCmd('info', 'last_received_at');
            return is_object($cmd) ? $cmd : null;
        }
        $cmd = self::cmdFrom(config::byKey('msg_in_cmd', 'ollamaAgent', ''));
        return (is_object($cmd) && $cmd->getType() == 'info') ? $cmd : null;
    }

    /** Une question « Demander » d'un scénario attend-elle une réponse sur cet équipement jeeWhatsapp ? */
    private static function jwAskPending($eq) {
        foreach ($eq->getCmd('action') as $cmd) {
            if ($cmd->getCache('ask::variable', 'none') === 'none') {
                continue;
            }
            $end = $cmd->getCache('ask::endtime', null);
            if ($end !== null && $end >= time()) {
                return true;
            }
        }
        return false;
    }

    /* ---------- Écoute ---------- */

    /** Crée, met à jour ou supprime l'écouteur Jeedom sur la commande du message reçu */
    public static function syncListener() {
        $listener = listener::byClassAndFunction('ollamaAgent', 'onMessage');
        $in = self::listenedCmd();
        if (!self::enabled() || !is_object($in)) {
            if (is_object($listener)) {
                $listener->remove();
                log::add('ollamaAgent', 'info', '[Messagerie] Écoute arrêtée');
            }
            return;
        }
        $event = '#' . $in->getId() . '#';
        if (is_object($listener) && $listener->getEvent() == [$event]) {
            return;
        }
        if (!is_object($listener)) {
            $listener = new listener();
            $listener->setClass('ollamaAgent');
            $listener->setFunction('onMessage');
            $listener->setOption([]);
        }
        $listener->emptyEvent();
        $listener->addEvent($in->getId());
        $listener->save();
        log::add('ollamaAgent', 'info', '[Messagerie] Écoute de ' . $in->getHumanName());
    }

    /** Appelé par l'écouteur : lit le message, filtre, puis le tri et la réponse se font en arrière-plan */
    public static function onMessage($_option) {
        $in = self::readIncoming($_option);
        if ($in === null) {
            return;
        }
        list($text, $ctx) = $in;
        $worker = dirname(__FILE__) . '/../php/worker.php';
        $phpBin = defined('PHP_BINARY') && !empty(PHP_BINARY) ? PHP_BINARY : 'php';
        $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($worker) . ' route ' . escapeshellarg(base64_encode($text)) . ' ' . escapeshellarg(base64_encode('')) . ' ' . escapeshellarg(base64_encode($text)) . ' ' . escapeshellarg(base64_encode(json_encode($ctx))) . ' >> ' . escapeshellarg(log::getPathToLog('ollamaAgent')) . ' 2>&1 &';
        exec($cmd);
    }

    /** Message à traiter : [texte, contexte] ou null s'il doit être ignoré */
    public static function readIncoming($_option) {
        if (!self::enabled()) {
            return null;
        }
        $ctx = ['sender' => '', 'profile' => ''];
        $jw = self::jwEq();
        if ($jw !== null) {
            $text = self::jwValue($jw, 'last_message');
            $ctx['sender'] = self::jwValue($jw, 'last_sender');
            $ctx['profile'] = self::jwValue($jw, 'last_sender_profile');
            if (self::jwValue($jw, 'last_group') !== '') {
                log::add('ollamaAgent', 'debug', '[Messagerie] Message d\'un groupe additionnel ignoré');
                return null;
            }
            if (preg_match('/^\[(image|audio|video|document|sticker|voice|ptt)\]$/i', $text)) {
                return null; // média sans texte
            }
            $kw = trim((string)$jw->getConfiguration('interaction_keyword', ''));
            if ($kw !== '' && stripos(ltrim($text), $kw) === 0) {
                log::add('ollamaAgent', 'debug', '[Messagerie] Mot-clé des interactions jeeWhatsapp : laissé à jeeWhatsapp');
                return null;
            }
            if (self::jwAskPending($jw)) {
                log::add('ollamaAgent', 'debug', '[Messagerie] Réponse à une question « Demander » d\'un scénario : laissée à jeeWhatsapp');
                return null;
            }
        } else {
            $text = trim((string)($_option['value'] ?? ''));
            $ctx['from_cmd'] = 1; // l'expéditeur sera lu dans le worker (après sa mise à jour)
        }
        if ($text === '') {
            return null;
        }
        return [$text, $ctx];
    }

    /* ---------- Sécurité ---------- */

    private static function digits($s) {
        return preg_replace('/\D/', '', (string)$s);
    }

    /** Expéditeur autorisé ? Liste vide = personne (« * » = tout le monde) */
    public static function allowed($sender, $profile = '') {
        $raw = trim((string)config::byKey('msg_allowed', 'ollamaAgent', ''));
        if ($raw === '*') {
            return true;
        }
        if ($profile !== '' && $profile !== $sender && self::allowed($profile)) {
            return true; // profil de jeeWhatsapp (user_mapping), utile quand WhatsApp envoie un LID
        }
        $s = self::digits($sender);
        foreach (preg_split('/[,;\r\n]+/', $raw) as $a) {
            $a = trim($a);
            if ($a === '') {
                continue;
            }
            $d = self::digits($a);
            if ($d !== '' && $s !== '' && strlen($d) >= 9 && strlen($s) >= 9 && substr($s, -9) === substr($d, -9)) {
                return true;
            }
            if (($d === '' || !preg_match('/^[\d\s+().-]+$/', $a)) && mb_strtolower($a) === mb_strtolower(trim((string)$sender))) {
                return true; // identifiant non numérique (pseudo Telegram…)
            }
        }
        return false;
    }

    /* ---------- Agents et tri ---------- */

    /** Agents actifs qui répondent sur la messagerie (jamais la vision) */
    public static function agents() {
        $out = [];
        foreach (eqLogic::byType('ollamaAgent', true) as $eq) {
            if ($eq->getConfiguration('msg_enable', 0) == 1 && $eq->agentType() != 'vision') {
                $out[(string)$eq->getId()] = $eq;
            }
        }
        return $out;
    }

    public static function description($eq) {
        $d = trim((string)$eq->getConfiguration('msg_description', ''));
        return $d !== '' ? $d : (self::TYPE_DESCRIPTIONS[$eq->agentType()] ?? '');
    }

    private static function keywords($eq) {
        $k = [ollamaAgent::slug($eq->getName()), ollamaAgent::slug($eq->agentType())];
        foreach (explode(',', (string)$eq->getConfiguration('msg_keywords', '')) as $w) {
            $w = ollamaAgent::slug($w);
            if ($w !== '') {
                $k[] = $w;
            }
        }
        return array_unique(array_filter($k));
    }

    /** « repas: …», « @agenda …» : agent choisi par le premier mot, retiré du message */
    public static function routeByPrefix($text, $agents) {
        if (!preg_match('/^\s*(@)?([\p{L}\d_\-]+)\s*([:,])?\s*(.*)$/su', $text, $m)) {
            return null;
        }
        if ($m[1] === '' && $m[3] === '') {
            return null; // il faut @mot ou mot: pour ne pas couper une phrase normale
        }
        $word = ollamaAgent::slug($m[2]);
        foreach ($agents as $eq) {
            if (in_array($word, self::keywords($eq), true) && trim($m[4]) !== '') {
                return [$eq, trim($m[4])];
            }
        }
        return null;
    }

    private static function lastRoute($sender) {
        $all = json_decode((string)config::byKey('msg_last', 'ollamaAgent', '{}'), true);
        $key = self::digits($sender) ?: (string)$sender;
        return (is_array($all) && isset($all[$key])) ? $all[$key] : null;
    }

    private static function saveRoute($sender, $eq) {
        $all = json_decode((string)config::byKey('msg_last', 'ollamaAgent', '{}'), true);
        if (!is_array($all)) {
            $all = [];
        }
        $all[self::digits($sender) ?: (string)$sender] = ['agent' => $eq->getId(), 'ts' => time()];
        config::save('msg_last', json_encode($all), 'ollamaAgent');
    }

    private static function defaultAgent($agents) {
        $id = (string)config::byKey('msg_default_agent', 'ollamaAgent', '');
        if ($id !== '' && isset($agents[$id])) {
            return $agents[$id];
        }
        foreach ($agents as $eq) {
            if ($eq->agentType() == 'general') {
                return $eq;
            }
        }
        return reset($agents);
    }

    /** Petit appel à Ollama (sans outils, réponse imposée) pour choisir l'agent */
    public static function routeByModel($text, $agents, $last) {
        $default = self::defaultAgent($agents);
        $model = trim((string)config::byKey('msg_router_model', 'ollamaAgent', ''));
        if ($model === '') {
            $model = $default->modelName();
        }
        $lines = [];
        foreach ($agents as $id => $eq) {
            $lines[] = $id . ' : ' . $eq->getName() . ' — ' . self::description($eq);
        }
        $hint = '';
        if (is_array($last) && isset($agents[(string)$last['agent']]) && time() - (int)$last['ts'] < 1800) {
            $hint = "\nLe message précédent, il y a " . max(1, round((time() - (int)$last['ts']) / 60)) . ' min, a été traité par ' . $last['agent'] . ' (' . $agents[(string)$last['agent']]->getName() . ") : si le nouveau message est la suite de cette conversation (« et samedi ? », « oui », « ajoute aussi… »), choisis-le.";
        }
        $payload = [
            'model' => $model,
            'stream' => false,
            'think' => false,
            'format' => ['type' => 'object', 'properties' => ['agent' => ['type' => 'string', 'enum' => array_map('strval', array_keys($agents))]], 'required' => ['agent']],
            'options' => ['temperature' => 0],
            'messages' => [
                ['role' => 'system', 'content' => "Tu choisis quel assistant de la maison doit répondre au message. Réponds uniquement en JSON avec l'identifiant de l'assistant.\nAssistants :\n" . implode("\n", $lines) . $hint],
                ['role' => 'user', 'content' => $text],
            ],
        ];
        $keep = $default->keepAliveDuring(); // l'agent choisi va souvent utiliser le même modèle juste après
        if ($keep !== null) {
            $payload['keep_alive'] = $keep;
        }
        for ($try = 0; $try < 5; $try++) {
            $res = ollamaAgent::chatOllama($payload, 60, true);
            if (!empty($res['interrupted'])) {
                ollamaAgent::waitPriority();
                continue;
            }
            break;
        }
        if ($res['ok']) {
            $data = json_decode(trim((string)($res['data']['message']['content'] ?? '')), true);
            $id = is_array($data) ? (string)($data['agent'] ?? '') : '';
            if (isset($agents[$id])) {
                return $agents[$id];
            }
            log::add('ollamaAgent', 'warning', '[Messagerie] Tri illisible : ' . mb_substr((string)($res['data']['message']['content'] ?? ''), 0, 200));
        }
        if (is_array($last) && isset($agents[(string)$last['agent']])) {
            return $agents[(string)$last['agent']];
        }
        return $default;
    }

    /* ---------- Traitement d'un message (dans le worker, en arrière-plan) ---------- */

    public static function handleIncoming($text, $ctx) {
        $text = trim((string)$text);
        if (!is_array($ctx)) {
            $ctx = ['sender' => (string)$ctx];
        }
        $sender = (string)($ctx['sender'] ?? '');
        $profile = (string)($ctx['profile'] ?? '');
        if (!empty($ctx['from_cmd'])) {
            $from = self::cmdFrom(config::byKey('msg_from_cmd', 'ollamaAgent', ''));
            if (is_object($from)) {
                $sender = trim((string)$from->execCmd());
            }
        }
        log::add('ollamaAgent', 'info', '[Messagerie] Message de ' . ($sender !== '' ? $sender : '?') . ($profile !== '' ? ' (' . $profile . ')' : '') . ' : ' . mb_substr($text, 0, 150));
        if (!self::allowed($sender, $profile)) {
            log::add('ollamaAgent', 'warning', '[Messagerie] Expéditeur non autorisé, message ignoré. Pour l\'autoriser, ajoute dans « Expéditeurs autorisés » : ' . ($sender !== '' ? $sender : '(expéditeur inconnu)') . ($profile !== '' ? ' ou ' . $profile : ''));
            return;
        }
        $agents = self::agents();
        if (empty($agents)) {
            log::add('ollamaAgent', 'warning', '[Messagerie] Aucun agent n\'a « Répond sur la messagerie » coché.');
            return;
        }
        $lower = mb_strtolower($text);
        if (in_array($lower, ['/agents', 'agents', 'agents ?', '/aide', 'aide'], true)) {
            $l = ['Agents disponibles (commence ton message par @nom pour en choisir un) :'];
            foreach ($agents as $eq) {
                $l[] = '@' . ollamaAgent::slug($eq->getName()) . ' — ' . self::description($eq);
            }
            self::sendReply(implode("\n", $l), $sender, 'Agents');
            return;
        }
        if (preg_match('#^\s*/(oublie|nouveau|nouvelle|reset)\b#iu', $text)) {
            $last = self::lastRoute($sender);
            if (is_array($last) && isset($agents[(string)$last['agent']])) {
                $agents[(string)$last['agent']]->clearHistory();
                self::sendReply('Nouvelle conversation avec ' . $agents[(string)$last['agent']]->getName() . '.', $sender, $agents[(string)$last['agent']]->getName());
            }
            return;
        }

        $route = self::routeByPrefix($text, $agents);
        if ($route !== null) {
            list($agent, $text) = $route;
            $how = 'choisi par le message';
        } elseif (count($agents) == 1) {
            $agent = reset($agents);
            $how = 'seul agent';
        } else {
            $agent = self::routeByModel($text, $agents, self::lastRoute($sender));
            $how = 'choisi par l\'IA';
        }
        log::add('ollamaAgent', 'info', '[Messagerie] → ' . $agent->getName() . ' (' . $how . ')');
        self::saveRoute($sender, $agent);

        if (config::byKey('msg_ack', 'ollamaAgent', 0) == 1) {
            self::sendReply('⏳ ' . $agent->getName() . ' réfléchit…', $sender, $agent->getName());
        }
        $agent->processOllamaRequest($text, '', $text, ['reply_to' => $sender]);
    }

    /** Commande d'envoi : « Envoyer un message » de jeeWhatsapp, sinon la commande choisie */
    private static function replyCmd() {
        $jw = self::jwEq();
        if ($jw !== null) {
            $cmd = $jw->getCmd('action', 'send_message');
            return is_object($cmd) ? $cmd : null;
        }
        $cmd = self::cmdFrom(config::byKey('msg_reply_cmd', 'ollamaAgent', ''));
        return (is_object($cmd) && $cmd->getType() == 'action') ? $cmd : null;
    }

    /** Envoie la réponse : dans le groupe canal jeeWhatsapp (ou en privé à l'expéditeur), sinon avec la commande choisie */
    public static function sendReply($message, $sender, $agentName) {
        $cmd = self::replyCmd();
        if (!is_object($cmd)) {
            log::add('ollamaAgent', 'warning', '[Messagerie] Pas de commande de réponse valide : réponse non envoyée.');
            return;
        }
        if (self::jwEq() !== null) {
            // jeeWhatsapp : titre vide = groupe canal ; titre = numéro = message privé
            $title = (config::byKey('msg_private', 'ollamaAgent', 0) == 1 && $sender !== '' && strpos($sender, '@lid') === false) ? $sender : '';
        } else {
            $title = strtr((string)config::byKey('msg_reply_title', 'ollamaAgent', ''), ['#agent#' => $agentName, '#expediteur#' => $sender]);
        }
        if (config::byKey('msg_prefix_agent', 'ollamaAgent', 1) == 1 && $agentName !== '') {
            $message = '[' . $agentName . '] ' . $message;
        }
        try {
            $cmd->execCmd(['title' => $title, 'message' => $message]);
            log::add('ollamaAgent', 'debug', '[Messagerie] Réponse envoyée via #' . $cmd->getId() . '#' . ($title !== '' ? ' à ' . $title : ' (groupe canal)'));
        } catch (Throwable $e) {
            log::add('ollamaAgent', 'error', '[Messagerie] Envoi impossible : ' . $e->getMessage());
        }
    }

    public static function replyCmdId() {
        $cmd = self::replyCmd();
        return is_object($cmd) ? (string)$cmd->getId() : '';
    }

    /** Avertissements affichés dans la configuration (doublons de réponses) */
    public static function warnings() {
        $w = [];
        $jw = self::jwEq();
        if ($jw !== null && $jw->getConfiguration('interactions_enabled', 0) == 1 && trim((string)$jw->getConfiguration('interaction_keyword', '')) === '') {
            $w[] = "Les interactions de jeeWhatsapp sont actives sans mot-clé : Jeedom et les agents risquent de répondre tous les deux. Désactive-les ou mets un mot-clé (ex. /jeedom) dans l'équipement jeeWhatsapp.";
        }
        return $w;
    }
}
