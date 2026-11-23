<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/ollamaAgentRepas.php';
require_once dirname(__FILE__) . '/ollamaAgentMessaging.php';

class ollamaAgent extends eqLogic {

    /* Types d'équipements (eqType_name) par famille radio — modifiables dans la configuration du plugin */
    const DEFAULT_ZIGBEE_TYPES = 'zigbee,z2m,zigbee2mqtt,deconz,zigate,zhaJeedom';
    const DEFAULT_ZWAVE_TYPES = 'zwavejs,openzwave,zwave';
    const DEFAULT_BT_TYPES = 'blea,ble,theengs';

    /* Commandes info de la surveillance : logicalId => [nom, sous-type] */
    const HEALTH_CMDS = [
        'sante_resume'       => ['Santé - Résumé', 'string'],
        'sante_liste'        => ['Santé - Hors ligne', 'string'],
        'sante_nouveaux'     => ['Santé - Nouveaux hors ligne', 'string'],
        'sante_revenus'      => ['Santé - Revenus en ligne', 'string'],
        'sante_probleme'     => ['Santé - Problème', 'binary'],
        'sante_hs_zigbee'    => ['Santé - HS Zigbee', 'numeric'],
        'sante_hs_zwave'     => ['Santé - HS Z-Wave', 'numeric'],
        'sante_hs_bluetooth' => ['Santé - HS Bluetooth', 'numeric'],
        'sante_hs_ip'        => ['Santé - HS IP', 'numeric'],
        'sante_hs_autres'    => ['Santé - HS autres', 'numeric'],
        'sante_hs_serveurs'  => ['Santé - Serveurs HS', 'numeric'],
        'sante_demons_ko'    => ['Santé - Démons KO', 'numeric'],
        'sante_piles'        => ['Santé - Piles faibles', 'numeric'],
    ];

    /* Types d'agent : un agent par type de plugin ou pour la surveillance */
    const AGENT_TYPES = [
        'general' => ['Général', 'fas fa-brain'],
        'repas' => ['Repas', 'fas fa-utensils'],
        'energie' => ['Énergie', 'fas fa-solar-panel'],
        'agenda' => ['Agenda', 'fas fa-calendar-alt'],
        'surveillance' => ['Surveillance', 'fas fa-heartbeat'],
        'vision' => ['Vision', 'fas fa-eye'],
    ];

    /* Outils de chaque type (general = selon les cases cochées) */
    const TYPE_FEATURES = [
        'repas' => ['repas'],
        'energie' => ['plugins', 'history'],
        'agenda' => ['agenda'],
        'surveillance' => ['health'],
        'vision' => [],
    ];

    const CAT_LABELS = [
        'zigbee' => 'Zigbee',
        'zwave' => 'Z-Wave',
        'bluetooth' => 'Bluetooth',
        'ip' => 'IP',
        'autres' => 'Autres',
    ];

    /* ============================================================
     * Appel direct (scénario / autre plugin)
     * ============================================================ */

    /**
     * Appel direct (équivalent AI Task / appel programmatique depuis un autre plugin ou scénario).
     * Supporte prompt, instruction système, modèle, image locale, format JSON structuré et options Ollama (num_ctx, think, etc.).
     */
    public static function askDirect($prompt, $customSystemPrompt = '', $model = '', $imagePath = '', $options = []) {
        if (empty($model)) {
            $model = 'qwen2.5vl:7b';
        }

        $messages = [];
        if (!empty($customSystemPrompt)) {
            $messages[] = ['role' => 'system', 'content' => $customSystemPrompt];
        }

        $userMessage = ['role' => 'user', 'content' => $prompt];
        if (!empty($imagePath)) {
            if (file_exists($imagePath)) {
                $imageData = file_get_contents($imagePath);
                if ($imageData !== false) {
                    $userMessage['images'] = [base64_encode($imageData)];
                    log::add('ollamaAgent', 'debug', '[askDirect] Image encodée en Base64 : ' . $imagePath . ' (' . strlen($imageData) . ' octets)');
                } else {
                    log::add('ollamaAgent', 'error', '[askDirect] Impossible de lire le fichier image : ' . $imagePath);
                }
            } else {
                log::add('ollamaAgent', 'warning', '[askDirect] Fichier image introuvable : ' . $imagePath);
            }
        }
        $messages[] = $userMessage;

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false
        ];
        if (!empty($options['format'])) {
            $payload['format'] = $options['format'];
        }
        if (isset($options['think']) && $options['think'] !== '') {
            $payload['think'] = (bool)$options['think'];
        }
        if (!empty($options['num_ctx']) && (int)$options['num_ctx'] > 0) {
            $payload['options'] = ['num_ctx' => (int)$options['num_ctx']];
        }
        $timeout = (!empty($options['timeout']) && (int)$options['timeout'] > 0) ? (int)$options['timeout'] : 300;

        $res = self::chatOllama($payload, $timeout);
        if (!$res['ok']) {
            return $res['error'];
        }
        if (!isset($res['data']['message']['content'])) {
            return "Aucune réponse reçue d'Ollama.";
        }
        $content = trim($res['data']['message']['content']);
        log::add('ollamaAgent', 'info', '[askDirect] Réponse générée (' . mb_strlen($content) . ' caractères)');
        return $content;
    }

    /**
     * Envoi d'une requête /api/chat à Ollama.
     * Retourne ['ok' => bool, 'data' => array|null, 'error' => string, 'http' => int, 'raw' => string]
     */
    public static function chatOllama($payload, $timeout = 300, $interruptible = false) {
        $ollamaUrl = rtrim(config::byKey('ollama_url', 'ollamaAgent', 'http://127.0.0.1:11434'), '/');
        $endpoint = $ollamaUrl . '/api/chat';
        log::add('ollamaAgent', 'debug', '[chatOllama] POST ' . $endpoint . ' modèle=' . ($payload['model'] ?? '?') . ' outils=' . (isset($payload['tools']) ? count($payload['tools']) : 0) . ' timeout=' . $timeout . 's');

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        if ($interruptible) {
            // un agent prioritaire (vision) démarre : on coupe la connexion, Ollama arrête alors la génération
            $lastCheck = 0.0;
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function () use (&$lastCheck) {
                $now = microtime(true);
                if ($now - $lastCheck < 0.25) {
                    return 0;
                }
                $lastCheck = $now;
                return self::priorityActive() ? 1 : 0;
            });
        }
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        $res = ['ok' => false, 'data' => null, 'error' => '', 'http' => $httpCode, 'raw' => (string)$response, 'interrupted' => false];
        if ($interruptible && $curlErrno == 42) {
            log::add('ollamaAgent', 'info', '[chatOllama] Demande interrompue : un agent prioritaire passe devant.');
            $res['interrupted'] = true;
            $res['error'] = 'Interrompu par un agent prioritaire';
            return $res;
        }
        if ($curlError) {
            log::add('ollamaAgent', 'error', '[chatOllama] Erreur cURL : ' . $curlError);
            $res['error'] = 'Erreur Ollama (cURL) : ' . $curlError;
            return $res;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            log::add('ollamaAgent', 'error', '[chatOllama] HTTP ' . $httpCode . ' : ' . mb_substr((string)$response, 0, 500));
            $res['error'] = 'Erreur Ollama (HTTP ' . $httpCode . ') : ' . mb_substr((string)$response, 0, 300);
            return $res;
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            $res['error'] = 'Erreur décodage réponse Ollama : ' . json_last_error_msg();
            return $res;
        }
        $res['ok'] = true;
        $res['data'] = $data;
        return $res;
    }

    /* ============================================================
     * Contexte
     * ============================================================ */

    public static function dateContext() {
        $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
        $week = (int)date('W');
        return 'Date et heure actuelles : ' . $jours[(int)date('w')] . ' ' . date('d/m/Y H:i') . ' (' . date('Y-m-d') . '), semaine ISO ' . $week . ' (' . ($week % 2 == 0 ? 'paire' : 'impaire') . ').';
    }

    public function agentType() {
        $t = (string)$this->getConfiguration('agent_type', 'general');
        return isset(self::AGENT_TYPES[$t]) ? $t : 'general';
    }

    /**
     * L'agent a-t-il cette fonction ? (repas, agenda, health, plugins, history, full_read)
     * Type spécialisé : uniquement ses fonctions. Type général : selon les cases cochées.
     */
    public function hasFeature($f) {
        $type = $this->agentType();
        if ($type != 'general') {
            return in_array($f, self::TYPE_FEATURES[$type], true);
        }
        switch ($f) {
            case 'repas':
                return $this->getConfiguration('repas_enable', 0) == 1;
            case 'agenda':
                return $this->getConfiguration('agenda_enable', 0) == 1;
            case 'health':
                return $this->getConfiguration('health_enable', 0) == 1;
            case 'history':
                return $this->getConfiguration('history_tool', 1) == 1;
            case 'full_read':
                return $this->getConfiguration('full_read', 0) == 1;
            case 'plugins':
                return true;
        }
        return false;
    }

    /** Liste des plugins que cet agent a le droit de lire */
    public function allowedPlugins() {
        $out = [];
        if (!$this->hasFeature('plugins')) {
            return $out;
        }
        $conf = $this->getConfiguration('readable_plugins', []);
        if (is_array($conf)) {
            foreach ($conf as $id => $checked) {
                if ($checked == 1) {
                    $out[] = (string)$id;
                }
            }
        }
        return $out;
    }

    /** Outils repas : case cochée et plugin repas installé */
    public function repasEnabled() {
        return $this->hasFeature('repas') && ollamaAgentRepas::disponible();
    }

    private function isFullRead() {
        return $this->hasFeature('full_read');
    }

    private function exposedInfoIds() {
        $ids = [];
        $exposed = $this->getConfiguration('exposed_cmds', []);
        if (is_array($exposed)) {
            foreach ($exposed as $cmdId => $checked) {
                if ($checked == 1) {
                    $ids[] = (string)$cmdId;
                }
            }
        } elseif (!empty($exposed)) {
            $ids = array_map('trim', explode(',', $exposed));
        }
        return $ids;
    }

    private static function shortValue($val, $max = 300) {
        if (is_array($val) || is_object($val)) {
            $val = json_encode($val, JSON_UNESCAPED_UNICODE);
        }
        $val = trim(preg_replace('/\s+/', ' ', strip_tags((string)$val)));
        if (mb_strlen($val) > $max) {
            $val = mb_substr($val, 0, $max) . '…';
        }
        return $val;
    }

    private static function cmdLine($cmd, $max = 300) {
        $eq = $cmd->getEqLogic();
        $eqName = is_object($eq) ? $eq->getHumanName() : '';
        $val = self::shortValue($cmd->execCmd(), $max);
        $unit = $cmd->getUnite();
        $date = $cmd->getValueDate();
        return '- #' . $cmd->getId() . ' ' . $eqName . '[' . $cmd->getName() . '] = ' . $val . ($unit !== '' ? ' ' . $unit : '') . ($date ? ' (' . date('d/m H:i', strtotime($date)) . ')' : '');
    }

    /** Toutes les commandes info des équipements actifs d'un plugin */
    public static function dumpPlugin($pluginId, $maxCmds = 150) {
        $eqs = eqLogic::byType($pluginId, true);
        if (!is_array($eqs) || count($eqs) == 0) {
            return "Plugin {$pluginId} : aucun équipement actif.";
        }
        $lines = ["Plugin {$pluginId} :"];
        $n = 0;
        foreach ($eqs as $eq) {
            foreach ($eq->getCmd('info') as $cmd) {
                if ($n++ >= $maxCmds) {
                    $lines[] = '… (liste tronquée à ' . $maxCmds . ' commandes)';
                    break 2;
                }
                $lines[] = self::cmdLine($cmd);
            }
        }
        return implode("\n", $lines);
    }

    /** Le contexte injecté directement (modèles sans outils, ou option cochée) */
    private function injectedContext() {
        $parts = [];
        foreach ($this->allowedPlugins() as $pluginId) {
            $parts[] = self::dumpPlugin($pluginId, 80);
        }
        if ($this->repasEnabled()) {
            try {
                $parts[] = '--- REPAS ---' . "\n" . ollamaAgentRepas::resume();
            } catch (Throwable $e) {
                log::add('ollamaAgent', 'warning', '[Repas] ' . $e->getMessage());
            }
        }
        if ($this->hasFeature('health')) {
            $health = self::getHealth();
            $parts[] = '--- SANTÉ JEEDOM ---' . "\n" . self::healthText($health, false);
        }
        return implode("\n\n", $parts);
    }

    /* ============================================================
     * Surveillance de Jeedom (connectivité, démons, piles, serveurs)
     * ============================================================ */

    /* ============================================================
     * Modèles gardés en mémoire (keep_alive)
     * ============================================================ */

    /** keep_alive envoyé à Ollama : -1 = toujours chargé, 0 = déchargé après la réponse, null = défaut Ollama (5 min) */
    public function keepAlive() {
        $keep = $this->getConfiguration('keep_model', '');
        if ($keep === '' && $this->agentType() == 'vision') {
            $keep = 'permanent';
        }
        switch ($keep) {
            case 'permanent':
                return -1;
            case 'decharger':
                return 0;
        }
        return null;
    }

    public function modelName() {
        $m = trim((string)$this->getConfiguration('model', ''));
        return $m === '' ? 'qwen2.5vl:7b' : $m;
    }

    /** Modèles des agents actifs réglés sur « Toujours chargé » */
    public static function permanentModels() {
        $out = [];
        foreach (eqLogic::byType('ollamaAgent', true) as $eq) {
            if ($eq->keepAlive() === -1) {
                $out[$eq->modelName()] = ($eq->getConfiguration('cpu_only', 0) == 1);
            }
        }
        return $out;
    }

    private static function ollamaRequest($method, $path, $payload = null, $timeout = 10) {
        $url = rtrim(config::byKey('ollama_url', 'ollamaAgent', 'http://127.0.0.1:11434'), '/') . $path;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
        $response = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $code < 200 || $code >= 300) {
            return null;
        }
        $data = json_decode($response, true);
        return is_array($data) ? $data : [];
    }

    /** Modèles actuellement chargés dans Ollama : [nom => octets en VRAM] */
    public static function loadedModels() {
        $ps = self::ollamaRequest('GET', '/api/ps');
        if (!is_array($ps)) {
            return null;
        }
        $out = [];
        foreach ($ps['models'] ?? [] as $m) {
            $out[$m['name'] ?? ($m['model'] ?? '')] = (int)($m['size_vram'] ?? 0);
        }
        return $out;
    }

    /** Recharge les modèles « Toujours chargé » qui ne sont plus en mémoire */
    public static function warmPermanentModels() {
        $wanted = self::permanentModels();
        if (empty($wanted)) {
            return;
        }
        $loaded = self::loadedModels();
        if ($loaded === null) {
            log::add('ollamaAgent', 'debug', '[Modèles] Ollama injoignable : préchargement reporté.');
            return;
        }
        foreach ($wanted as $model => $cpuOnly) {
            $name = strpos($model, ':') === false ? $model . ':latest' : $model;
            if (isset($loaded[$model]) || isset($loaded[$name])) {
                continue;
            }
            log::add('ollamaAgent', 'info', '[Modèles] Rechargement du modèle permanent ' . $model);
            $payload = ['model' => $model, 'keep_alive' => -1];
            if ($cpuOnly) {
                $payload['options'] = ['num_gpu' => 0];
            }
            if (self::ollamaRequest('POST', '/api/generate', $payload, 180) === null) {
                log::add('ollamaAgent', 'warning', '[Modèles] Impossible de charger ' . $model);
            }
        }
    }

    /* ---------- Messagerie (jeeWhatsapp, Telegram…) ---------- */

    /** Appelé par l'écouteur Jeedom sur la commande « message reçu » */
    public static function onMessage($_option) {
        ollamaAgentMessaging::onMessage($_option);
    }

    public static function postConfig_msg_enable($_value) {
        ollamaAgentMessaging::syncListener();
    }

    public static function postConfig_msg_in_cmd($_value) {
        ollamaAgentMessaging::syncListener();
    }

    public static function postConfig_msg_jw_eq($_value) {
        ollamaAgentMessaging::syncListener();
    }

    public static function cron5() {
        try {
            ollamaAgentMessaging::syncListener();
        } catch (Throwable $e) {
            log::add('ollamaAgent', 'warning', '[Messagerie] ' . $e->getMessage());
        }
        self::warmPermanentModels();
        $hasHealth = false;
        foreach (eqLogic::byType('ollamaAgent', true) as $eq) {
            if ($eq->hasFeature('health')) {
                $hasHealth = true;
                break;
            }
        }
        if ($hasHealth) {
            self::refreshHealth();
        }
    }

    private static function typesFor($key, $default) {
        $raw = config::byKey($key, 'ollamaAgent', $default);
        if (trim((string)$raw) === '') {
            $raw = $default;
        }
        return array_filter(array_map(function ($t) {
            return strtolower(trim($t));
        }, explode(',', $raw)));
    }

    /** Famille d'un équipement + adresse IP éventuelle */
    private static function classify($eq, $zigbee, $zwave, $bt) {
        $type = strtolower($eq->getEqType_name());
        if (in_array($type, $zigbee)) {
            return ['zigbee', ''];
        }
        if (in_array($type, $zwave)) {
            return ['zwave', ''];
        }
        if (in_array($type, $bt)) {
            return ['bluetooth', ''];
        }
        $conf = $eq->getConfiguration();
        if (is_array($conf)) {
            foreach ($conf as $k => $v) {
                if (!is_string($v) || $v === '') {
                    continue;
                }
                if (!in_array(strtolower($k), ['ip', 'addr', 'address', 'adresse', 'host', 'hostname', 'ipaddress', 'ip_address', 'ipaddr', 'ip_addr', 'localip'])) {
                    continue;
                }
                $host = preg_replace('#^[a-z]+://#i', '', trim($v));
                $host = preg_replace('#[:/].*$#', '', $host);
                if ($host === '' || in_array($host, ['127.0.0.1', 'localhost'])) {
                    continue;
                }
                if (filter_var($host, FILTER_VALIDATE_IP) || preg_match('/^[a-z0-9][a-z0-9.\-]*\.[a-z0-9\-]+$/i', $host)) {
                    return ['ip', $host];
                }
            }
        }
        return ['autres', ''];
    }

    /** Ping parallèle : [clé => hôte] → [clé => bool] */
    public static function pingMany(array $hosts) {
        $out = [];
        if (empty($hosts) || !self::pingAvailable()) {
            return $out;
        }
        foreach (array_chunk($hosts, 40, true) as $chunk) {
            $parts = [];
            foreach ($chunk as $k => $h) {
                $parts[] = '(ping -c 1 -W 1 ' . escapeshellarg($h) . ' >/dev/null 2>&1 && echo ' . escapeshellarg($k . ' OK') . ' || echo ' . escapeshellarg($k . ' KO') . ') &';
            }
            $lines = [];
            exec('/bin/bash -c ' . escapeshellarg(implode(' ', $parts) . ' wait'), $lines);
            foreach ($lines as $l) {
                $p = explode(' ', trim($l));
                if (count($p) == 2) {
                    $out[$p[0]] = ($p[1] == 'OK');
                }
            }
        }
        return $out;
    }

    /** ping utilisable par l'utilisateur de Jeedom ? (testé une fois sur 127.0.0.1) */
    public static function pingAvailable() {
        static $pingOk = null; // pas de propriété de classe : Jeedom les enregistre comme colonnes de eqLogic
        if ($pingOk === null) {
            $rc = 1;
            $o = [];
            exec('ping -c 1 -W 1 127.0.0.1 >/dev/null 2>&1', $o, $rc);
            $pingOk = ($rc === 0);
            if (!$pingOk) {
                log::add('ollamaAgent', 'warning', '[Santé] La commande ping ne fonctionne pas pour l\'utilisateur Jeedom : les tests par ping sont ignorés.');
            }
        }
        return $pingOk;
    }

    /** Liste des serveurs de la configuration : « Nom;hôte[;port] » par ligne */
    public static function parseServers() {
        $list = [];
        $raw = (string)config::byKey('health_servers', 'ollamaAgent', '');
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] == '#') {
                continue;
            }
            $p = array_map('trim', preg_split('/[;|]/', $line));
            if (count($p) < 2 || $p[1] === '') {
                continue;
            }
            $list[] = ['name' => $p[0], 'host' => $p[1], 'port' => (isset($p[2]) && ctype_digit($p[2])) ? (int)$p[2] : 0];
        }
        return $list;
    }

    private static function fmtDate($ts) {
        return $ts > 0 ? date('d/m H:i', $ts) : 'jamais';
    }

    /** Calcule l'état complet de Jeedom */
    public static function computeHealth() {
        $now = time();
        $zigbee = self::typesFor('types_zigbee', self::DEFAULT_ZIGBEE_TYPES);
        $zwave = self::typesFor('types_zwave', self::DEFAULT_ZWAVE_TYPES);
        $bt = self::typesFor('types_bt', self::DEFAULT_BT_TYPES);
        $defTimeout = [
            'zigbee' => (int)config::byKey('timeout_zigbee', 'ollamaAgent', 240),
            'zwave' => (int)config::byKey('timeout_zwave', 'ollamaAgent', 240),
            'bluetooth' => (int)config::byKey('timeout_bt', 'ollamaAgent', 60),
            'ip' => 0,
            'autres' => 0,
        ];
        $batThreshold = (int)config::byKey('battery_threshold', 'ollamaAgent', 20);
        $lqiThreshold = (int)config::byKey('lqi_threshold', 'ollamaAgent', 30);
        $pingIp = config::byKey('ping_ip', 'ollamaAgent', 1) == 1;
        $ignore = array_filter(array_map('trim', explode(',', (string)config::byKey('ignore_ids', 'ollamaAgent', ''))));

        $h = [
            'date' => date('Y-m-d H:i:s'),
            'categories' => [],
            'piles' => [],
            'signal' => [],
            'serveurs' => ['total' => 0, 'hs' => []],
            'demons' => [],
            'dependances' => [],
            'systeme' => self::systemInfo(),
            'ping' => $pingIp,
        ];
        foreach (array_keys(self::CAT_LABELS) as $c) {
            $h['categories'][$c] = ['total' => 0, 'hs' => []];
        }

        $toPing = [];
        $ipEntries = [];
        foreach (eqLogic::all() as $eq) {
            if (!$eq->getIsEnable() || $eq->getEqType_name() == 'ollamaAgent' || in_array((string)$eq->getId(), $ignore, true)) {
                continue;
            }
            list($cat, $ip) = self::classify($eq, $zigbee, $zwave, $bt);
            $name = $eq->getHumanName();
            $last = $eq->getStatus('lastCommunication', '');
            $lastTs = $last ? (int)strtotime($last) : 0;
            $timeoutMin = (int)$eq->getTimeout();
            if ($timeoutMin <= 0) {
                $timeoutMin = $defTimeout[$cat];
            }
            $h['categories'][$cat]['total']++;

            $reason = '';
            if ($eq->getStatus('timeout', 0) == 1) {
                $reason = 'délai de communication Jeedom dépassé';
            } elseif ($timeoutMin > 0 && $lastTs > 0 && ($now - $lastTs) > $timeoutMin * 60) {
                $reason = 'aucune communication depuis plus de ' . $timeoutMin . ' min';
            } elseif ($timeoutMin > 0 && $lastTs == 0 && in_array($cat, ['zigbee', 'zwave', 'bluetooth'])) {
                $reason = 'aucune communication enregistrée';
            }
            $entry = ['id' => $eq->getId(), 'name' => $name, 'type' => $eq->getEqType_name(), 'last' => self::fmtDate($lastTs), 'reason' => $reason, 'ip' => $ip];
            if ($cat == 'ip' && $pingIp) {
                $toPing['e' . $eq->getId()] = $ip;
                $ipEntries['e' . $eq->getId()] = $entry;
            } elseif ($reason !== '') {
                $h['categories'][$cat]['hs'][] = $entry;
            }

            $bat = $eq->getStatus('battery', '');
            if ($bat !== '' && is_numeric($bat) && (int)$bat <= $batThreshold) {
                $h['piles'][] = ['id' => $eq->getId(), 'name' => $name, 'level' => (int)$bat];
            }

            if ($cat == 'zigbee' || $cat == 'zwave') {
                foreach ($eq->getCmd('info') as $cmd) {
                    $lid = strtolower($cmd->getLogicalId() . ' ' . $cmd->getName());
                    if (preg_match('/\b(linkquality|lqi)\b/', $lid)) {
                        $v = $cmd->execCmd();
                        if (is_numeric($v) && (int)$v < $lqiThreshold) {
                            $h['signal'][] = ['id' => $eq->getId(), 'name' => $name, 'value' => (int)$v];
                        }
                        break;
                    }
                }
            }
        }

        // Ping des équipements IP
        if (!empty($toPing)) {
            $results = self::pingMany($toPing);
            if (!self::pingAvailable()) {
                $h['ping'] = false;
            }
            foreach ($ipEntries as $k => $entry) {
                if (isset($results[$k]) && $results[$k] === false) {
                    $entry['reason'] = trim('ne répond pas au ping (' . $entry['ip'] . ')' . ($entry['reason'] !== '' ? ', ' . $entry['reason'] : ''));
                }
                if ($entry['reason'] !== '') {
                    $h['categories']['ip']['hs'][] = $entry;
                }
            }
        }

        // Serveurs déclarés dans la configuration
        $servers = self::parseServers();
        $h['serveurs']['total'] = count($servers);
        $pingServers = [];
        foreach ($servers as $i => $s) {
            if ($s['port'] > 0) {
                $errno = 0;
                $errstr = '';
                $fp = @fsockopen($s['host'], $s['port'], $errno, $errstr, 2);
                if ($fp) {
                    fclose($fp);
                } else {
                    $h['serveurs']['hs'][] = ['name' => $s['name'], 'reason' => 'port ' . $s['port'] . ' fermé sur ' . $s['host'] . ($errstr ? ' (' . $errstr . ')' : '')];
                }
            } else {
                $pingServers['s' . $i] = $s['host'];
            }
        }
        if (!empty($pingServers)) {
            $r = self::pingMany($pingServers);
            foreach ($pingServers as $k => $host) {
                if (isset($r[$k]) && $r[$k] === false) {
                    $s = $servers[(int)substr($k, 1)];
                    $h['serveurs']['hs'][] = ['name' => $s['name'], 'reason' => 'ne répond pas au ping (' . $host . ')'];
                }
            }
        }

        // Démons et dépendances des plugins
        foreach (plugin::listPlugin(true) as $plugin) {
            try {
                if ($plugin->getHasOwnDeamon() == 1) {
                    $info = $plugin->deamon_info();
                    if (isset($info['state']) && $info['state'] != 'ok' && (!isset($info['auto']) || $info['auto'] == 1)) {
                        $h['demons'][] = $plugin->getName() . ' (' . $plugin->getId() . ')';
                    }
                }
                if ($plugin->getHasDependency() == 1) {
                    $dep = $plugin->dependancy_info();
                    if (isset($dep['state']) && $dep['state'] == 'nok') {
                        $h['dependances'][] = $plugin->getName() . ' (' . $plugin->getId() . ')';
                    }
                }
            } catch (Throwable $e) {
                log::add('ollamaAgent', 'debug', '[Santé] Plugin ' . $plugin->getId() . ' : ' . $e->getMessage());
            }
        }

        // Comparaison avec le passage précédent
        $current = [];
        foreach ($h['categories'] as $cat => $c) {
            foreach ($c['hs'] as $e) {
                $current['eq' . $e['id']] = $e['name'];
            }
        }
        foreach ($h['serveurs']['hs'] as $s) {
            $current['srv_' . $s['name']] = 'Serveur ' . $s['name'];
        }
        foreach ($h['demons'] as $d) {
            $current['dem_' . $d] = 'Démon ' . $d;
        }
        $prev = json_decode((string)config::byKey('health_prev', 'ollamaAgent', '[]'), true);
        if (!is_array($prev)) {
            $prev = [];
        }
        $h['nouveaux'] = array_values(array_diff_key($current, $prev));
        $h['revenus'] = array_values(array_diff_key($prev, $current));
        config::save('health_prev', json_encode($current, JSON_UNESCAPED_UNICODE), 'ollamaAgent');
        return $h;
    }

    public static function systemInfo() {
        $s = [];
        try {
            $s['jeedom'] = jeedom::version();
        } catch (Throwable $e) {
        }
        $total = @disk_total_space('/');
        $free = @disk_free_space('/');
        if ($total > 0) {
            $s['disque'] = round(100 - $free * 100 / $total) . ' % utilisé (' . round($free / 1073741824, 1) . ' Go libres)';
        }
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        if (is_array($load)) {
            $s['charge'] = implode(' / ', array_map(function ($v) {
                return round($v, 2);
            }, $load));
        }
        $mem = @file_get_contents('/proc/meminfo');
        if ($mem && preg_match('/MemTotal:\s+(\d+)/', $mem, $t) && preg_match('/MemAvailable:\s+(\d+)/', $mem, $a)) {
            $s['memoire'] = round(100 - $a[1] * 100 / $t[1]) . ' % utilisée';
        }
        $up = @file_get_contents('/proc/uptime');
        if ($up) {
            $s['uptime'] = round(((float)$up) / 86400, 1) . ' jours';
        }
        return $s;
    }

    /** Erreurs récentes dans les logs Jeedom : [log => ['count' => n, 'last' => texte]] */
    public static function logErrors($hours = 24) {
        $out = [];
        $limit = time() - $hours * 3600;
        $dir = dirname(log::getPathToLog('ollamaAgent'));
        foreach (glob($dir . '/*') as $file) {
            if (!is_file($file) || filemtime($file) < $limit) {
                continue;
            }
            $fh = @fopen($file, 'r');
            if (!$fh) {
                continue;
            }
            $size = filesize($file);
            if ($size > 131072) {
                fseek($fh, -131072, SEEK_END);
            }
            $content = stream_get_contents($fh);
            fclose($fh);
            $count = 0;
            $lastMsg = '';
            foreach (explode("\n", $content) as $line) {
                if (strpos($line, '[ERROR]') === false) {
                    continue;
                }
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m) && strtotime($m[1]) < $limit) {
                    continue;
                }
                $count++;
                $lastMsg = $line;
            }
            if ($count > 0) {
                $out[basename($file)] = ['count' => $count, 'last' => self::shortValue($lastMsg, 200)];
            }
        }
        arsort($out);
        return $out;
    }

    /** État en cache (moins de 5 min) ou recalculé */
    public static function getHealth($maxAge = 300) {
        $cached = json_decode((string)cache::byKey('ollamaAgent::health')->getValue(''), true);
        if (is_array($cached) && isset($cached['date']) && (time() - strtotime($cached['date'])) < $maxAge) {
            return $cached;
        }
        return self::refreshHealth();
    }

    /** Recalcule et met à jour les commandes des agents qui ont la surveillance */
    public static function refreshHealth() {
        $h = self::computeHealth();
        cache::set('ollamaAgent::health', json_encode($h, JSON_UNESCAPED_UNICODE));

        $hsNames = [];
        $totalHs = 0;
        foreach ($h['categories'] as $cat => $c) {
            $totalHs += count($c['hs']);
            foreach ($c['hs'] as $e) {
                $hsNames[] = $e['name'];
            }
        }
        foreach ($h['serveurs']['hs'] as $s) {
            $hsNames[] = 'Serveur ' . $s['name'];
        }
        foreach ($h['demons'] as $d) {
            $hsNames[] = 'Démon ' . $d;
        }
        $probleme = ($totalHs + count($h['serveurs']['hs']) + count($h['demons'])) > 0 ? 1 : 0;

        foreach (eqLogic::byType('ollamaAgent', true) as $eq) {
            if (!$eq->hasFeature('health')) {
                continue;
            }
            $eq->checkAndUpdateCmd('sante_resume', self::healthSummary($h));
            $eq->checkAndUpdateCmd('sante_liste', empty($hsNames) ? 'Aucun' : implode(', ', $hsNames));
            $eq->checkAndUpdateCmd('sante_nouveaux', implode(', ', $h['nouveaux']));
            $eq->checkAndUpdateCmd('sante_revenus', implode(', ', $h['revenus']));
            $eq->checkAndUpdateCmd('sante_probleme', $probleme);
            foreach (array_keys(self::CAT_LABELS) as $cat) {
                $eq->checkAndUpdateCmd('sante_hs_' . $cat, count($h['categories'][$cat]['hs']));
            }
            $eq->checkAndUpdateCmd('sante_hs_serveurs', count($h['serveurs']['hs']));
            $eq->checkAndUpdateCmd('sante_demons_ko', count($h['demons']));
            $eq->checkAndUpdateCmd('sante_piles', count($h['piles']));
        }
        if (!empty($h['nouveaux'])) {
            log::add('ollamaAgent', 'warning', '[Santé] Nouveaux hors ligne : ' . implode(', ', $h['nouveaux']));
        }
        return $h;
    }

    public static function healthSummary($h) {
        $parts = [];
        foreach (self::CAT_LABELS as $cat => $label) {
            $c = $h['categories'][$cat];
            if ($c['total'] > 0 && ($cat != 'autres' || count($c['hs']) > 0)) {
                $parts[] = $label . ' ' . count($c['hs']) . '/' . $c['total'] . ' HS';
            }
        }
        if ($h['serveurs']['total'] > 0) {
            $parts[] = 'Serveurs ' . count($h['serveurs']['hs']) . '/' . $h['serveurs']['total'] . ' HS';
        }
        $parts[] = 'Démons KO ' . count($h['demons']);
        $parts[] = 'Piles faibles ' . count($h['piles']);
        return implode(' · ', $parts);
    }

    /** Texte lisible de l'état (pour l'IA ou l'affichage) */
    public static function healthText($h, $detailed = true, $withLogs = false) {
        $l = ['État de Jeedom au ' . date('d/m/Y H:i', strtotime($h['date'])) . ' : ' . self::healthSummary($h)];
        $max = $detailed ? 40 : 10;
        foreach (self::CAT_LABELS as $cat => $label) {
            $c = $h['categories'][$cat];
            if (count($c['hs']) == 0) {
                continue;
            }
            $l[] = $label . ' hors ligne (' . count($c['hs']) . ') :';
            foreach (array_slice($c['hs'], 0, $max) as $e) {
                $l[] = '  - ' . $e['name'] . ' [' . $e['type'] . '] : ' . $e['reason'] . ', dernière communication ' . $e['last'];
            }
        }
        if (!empty($h['serveurs']['hs'])) {
            $l[] = 'Serveurs hors service :';
            foreach ($h['serveurs']['hs'] as $s) {
                $l[] = '  - ' . $s['name'] . ' : ' . $s['reason'];
            }
        }
        if (!empty($h['demons'])) {
            $l[] = 'Démons arrêtés : ' . implode(', ', $h['demons']);
        }
        if (!empty($h['dependances'])) {
            $l[] = 'Dépendances à réinstaller : ' . implode(', ', $h['dependances']);
        }
        if (!empty($h['piles'])) {
            $l[] = 'Piles faibles :';
            foreach (array_slice($h['piles'], 0, $max) as $p) {
                $l[] = '  - ' . $p['name'] . ' : ' . $p['level'] . ' %';
            }
        }
        if (!empty($h['signal'])) {
            $l[] = 'Signal radio faible (LQI) :';
            foreach (array_slice($h['signal'], 0, $max) as $p) {
                $l[] = '  - ' . $p['name'] . ' : ' . $p['value'];
            }
        }
        if (!empty($h['nouveaux'])) {
            $l[] = 'Nouveaux problèmes depuis le dernier contrôle : ' . implode(', ', $h['nouveaux']);
        }
        if (!empty($h['revenus'])) {
            $l[] = 'Revenus en ligne depuis le dernier contrôle : ' . implode(', ', $h['revenus']);
        }
        if ($detailed && !empty($h['systeme'])) {
            $s = [];
            foreach ($h['systeme'] as $k => $v) {
                $s[] = $k . ' : ' . $v;
            }
            $l[] = 'Système : ' . implode(' · ', $s);
        }
        if (!$h['ping']) {
            $l[] = '(Ping des équipements IP désactivé ou indisponible)';
        }
        if ($withLogs) {
            $errors = self::logErrors(24);
            if (empty($errors)) {
                $l[] = 'Aucune erreur dans les logs sur 24 h.';
            } else {
                $l[] = 'Erreurs dans les logs sur 24 h :';
                foreach (array_slice($errors, 0, 15, true) as $log => $e) {
                    $l[] = '  - ' . $log . ' : ' . $e['count'] . ' erreur(s), dernière : ' . $e['last'];
                }
            }
        }
        return implode("\n", $l);
    }

    /* ============================================================
     * Agenda
     * ============================================================ */

    private static function cmdFromConfig($value) {
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

    /** Commande action « Ajouter » de l'agenda : celle choisie, sinon recherche dans le plugin agenda */
    public function agendaAddCmd() {
        $cmd = self::cmdFromConfig($this->getConfiguration('agenda_cmd', ''));
        if (is_object($cmd)) {
            return $cmd;
        }
        $eqs = [];
        if (class_exists('agenda') && method_exists('agenda', 'mainAgenda')) {
            $main = agenda::mainAgenda();
            if (is_object($main)) {
                $eqs[] = $main;
            }
        }
        if (empty($eqs)) {
            $eqs = eqLogic::byType('agenda', true);
        }
        foreach ($eqs as $eq) {
            foreach ($eq->getCmd('action') as $c) {
                if ($c->getSubType() == 'message' && (in_array(strtolower($c->getLogicalId()), ['ajouter', 'add', 'quickadd']) || stripos($c->getName(), 'ajout') !== false)) {
                    return $c;
                }
            }
        }
        return null;
    }

    public static function normalizeMoment($m) {
        $m = strtolower(trim((string)$m));
        $m = str_replace(['é', 'è', 'ê', 'à', ' ', '-', '_'], ['e', 'e', 'e', 'a', '', '', ''], $m);
        $map = [
            'matin' => 'matin', 'midi' => 'midi', 'dejeuner' => 'midi',
            'apresmidi' => 'après-midi', 'aprem' => 'après-midi',
            'soir' => 'soir', 'soiree' => 'soir', 'diner' => 'soir',
            'journee' => 'journée', 'jour' => 'journée', 'toutelajournee' => 'journée',
        ];
        return $map[$m] ?? 'journée';
    }

    /** Construit le message compris par la commande « Ajouter » de l'agenda */
    public static function agendaMessage($args) {
        $date = trim((string)($args['date'] ?? ''));
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            $d2 = DateTime::createFromFormat('d/m/Y', $date);
            $date = ($d2) ? $d2->format('Y-m-d') : $date;
        }
        $parts = [];
        if ($date !== '') {
            $parts[] = $date;
        }
        $parts[] = self::normalizeMoment($args['moment'] ?? 'journée');
        $effet = strtolower(trim((string)($args['effet'] ?? 'info')));
        if ($effet == 'absent' || $effet == 'maison_vide' || $effet == 'maisonvide') {
            $parts[] = 'absent';
        } elseif ($effet == 'dehors' || $effet == 'repas_dehors') {
            $parts[] = 'dehors';
        } elseif ($effet == 'couverts') {
            $delta = (int)($args['couverts'] ?? 0);
            if ($delta != 0) {
                $parts[] = sprintf('%+d', $delta);
            }
        }
        return implode(' ', $parts);
    }

    private function agendaAdd($args) {
        $cmd = $this->agendaAddCmd();
        if (!is_object($cmd)) {
            return "Erreur : commande « Ajouter » de l'agenda introuvable (à choisir dans l'onglet Plugins & outils de l'agent).";
        }
        $nom = trim((string)($args['nom'] ?? ''));
        if ($nom === '') {
            return 'Erreur : le nom de l\'évènement est obligatoire.';
        }
        $message = self::agendaMessage($args);
        log::add('ollamaAgent', 'info', "[Agenda] Ajout via #{$cmd->getId()}# : titre=« {$nom} » message=« {$message} »");
        $cmd->execCmd(['title' => $nom, 'message' => $message]);
        return "Ajouté dans l'agenda : « {$nom} » — {$message}";
    }

    public static function agendaRead($from, $to) {
        $start = strtotime((string)$from) ?: strtotime('today');
        $end = strtotime((string)$to) ?: $start + 6 * 86400;
        if ($end < $start) {
            $end = $start;
        }
        if ($end - $start > 31 * 86400) {
            $end = $start + 31 * 86400;
        }
        if (class_exists('agenda') && method_exists('agenda', 'dayInfoFor')) {
            $jours = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
            $lines = [];
            for ($t = $start; $t <= $end; $t += 86400) {
                $day = date('Y-m-d', $t);
                $info = agenda::dayInfoFor($day);
                if (!is_array($info)) {
                    return "Agenda : aucun agenda actif.";
                }
                $line = $jours[(int)date('w', $t)] . ' ' . date('d/m', $t) . ' : ' . (trim((string)($info['summary'] ?? '')) ?: 'rien');
                if (isset($info['moments']['midi']['couverts'], $info['moments']['soir']['couverts'])) {
                    $line .= ' | couverts midi ' . $info['moments']['midi']['couverts'] . ', soir ' . $info['moments']['soir']['couverts'];
                }
                if (!empty($info['emptyMoments'])) {
                    $line .= ' | maison vide : ' . implode(', ', $info['emptyMoments']);
                }
                $lines[] = $line;
            }
            return implode("\n", $lines);
        }
        return self::dumpPlugin('agenda');
    }

    /* ============================================================
     * Outils (tools) proposés au modèle
     * ============================================================ */

    private static function tool($name, $description, $properties = [], $required = []) {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => empty($properties) ? new stdClass() : (object)$properties,
                    'required' => $required,
                ],
            ],
        ];
    }

    private function buildReadTools() {
        $tools = [];
        if ($this->memoryEnabled() && $this->notesWritable()) {
            $tools[] = self::tool('retenir', 'Retient une information durable que l\'utilisateur donne ou demande de retenir (habitude, préférence, info sur la maison). Une phrase courte. Pas pour les infos du moment.', [
                'texte' => ['type' => 'string', 'description' => 'L\'information à retenir, en une phrase courte'],
            ], ['texte']);
            $tools[] = self::tool('oublier_note', 'Oublie une note permanente devenue fausse ou que l\'utilisateur demande d\'oublier.', [
                'numero' => ['type' => 'integer', 'description' => 'Numéro de la note dans la liste « ce que tu as retenu »'],
            ], ['numero']);
        }
        $plugins = $this->allowedPlugins();
        $full = $this->isFullRead();
        if (!empty($plugins) || $full) {
            $prop = ['type' => 'string', 'description' => 'Identifiant du plugin Jeedom' . (!empty($plugins) ? ' (ex. ' . implode(', ', $plugins) . ')' : '')];
            if (!$full) {
                $prop['enum'] = $plugins;
            }
            $tools[] = self::tool('lire_plugin', "Lit l'état actuel (commandes info et valeurs) de tous les équipements d'un plugin Jeedom : repas, énergie, agenda, etc.", ['plugin' => $prop], ['plugin']);
        }
        if ($full) {
            $tools[] = self::tool('chercher_commandes', 'Cherche dans tout Jeedom les commandes info dont l\'objet, l\'équipement ou le nom contient le texte, et donne leur valeur.', [
                'texte' => ['type' => 'string', 'description' => 'Texte à chercher (ex. température salon, batterie, piscine)'],
            ], ['texte']);
        }
        if (!empty($plugins) || $full || !empty($this->exposedInfoIds())) {
            $tools[] = self::tool('lire_commande', 'Lit la valeur actuelle d\'une commande info Jeedom par son ID.', [
                'id' => ['type' => 'integer', 'description' => 'ID de la commande (le nombre après #)'],
            ], ['id']);
            if ($this->hasFeature('history')) {
                $tools[] = self::tool('lire_historique', 'Donne le minimum, le maximum, la moyenne et l\'évolution d\'une commande info historisée sur les dernières heures (énergie, température…).', [
                    'id' => ['type' => 'integer', 'description' => 'ID de la commande'],
                    'heures' => ['type' => 'integer', 'description' => 'Nombre d\'heures en arrière (1 à 720, défaut 24)'],
                ], ['id']);
            }
        }
        if ($this->hasFeature('health')) {
            $tools[] = self::tool('etat_jeedom', 'Donne l\'état complet de Jeedom : équipements Zigbee, Z-Wave, Bluetooth et IP hors ligne, serveurs, démons, piles faibles, signal radio, erreurs des logs.');
        }
        if ($this->repasEnabled()) {
            $tools[] = self::tool('repas_jour', 'Repas d\'un jour (plugin repas) : collations, midi et soir, couverts, gamelles, ingrédients, préparation et calories de la journée.', [
                'date' => ['type' => 'string', 'description' => 'Date AAAA-MM-JJ (défaut : aujourd\'hui)'],
            ]);
            $tools[] = self::tool('repas_semaine', 'Menu de la semaine (vendredi soir au vendredi midi) avec couverts, calories, moyenne par jour et alertes du plan.', [
                'decalage' => ['type' => 'integer', 'description' => '0 = semaine en cours, 1 = suivante, -1 = précédente'],
            ]);
            $tools[] = self::tool('repas_courses', 'Liste de courses de la semaine, rangée par rayon, avec ce qui reste à acheter.', [
                'decalage' => ['type' => 'integer', 'description' => '0 = semaine en cours, 1 = suivante'],
            ]);
            $tools[] = self::tool('repas_frigo', 'Contenu du frigo, du congélateur et du placard, avec les dates limites et ce qui est à finir.');
            $tools[] = self::tool('repas_recettes', 'Cherche dans le carnet de recettes du plugin repas (nom, protéine, ingrédient). Sans texte : les recettes aimées d\'abord.', [
                'texte' => ['type' => 'string', 'description' => 'Mots à chercher (ex. poulet, courgette, gratin). Vide = tout'],
                'moment' => ['type' => 'string', 'enum' => ['', 'midi', 'soir'], 'description' => 'Filtrer sur midi ou soir'],
            ]);
            $tools[] = self::tool('repas_recette', 'Détail d\'une recette du carnet : ingrédients pour 1 portion au plan, apports, préparation.', [
                'id' => ['type' => 'integer', 'description' => 'ID de la recette (#…)'],
                'moment' => ['type' => 'string', 'enum' => ['midi', 'soir'], 'description' => 'Quantités du plan du midi ou du soir'],
            ], ['id']);
            $tools[] = self::tool('repas_gouts', 'Goûts enregistrés dans le plugin repas : je n\'aime pas (avec remplaçant), j\'adore, et ce que l\'IA du plugin a noté.');
        }
        if ($this->hasFeature('agenda')) {
            $tools[] = self::tool('lire_agenda', 'Lit l\'agenda (évènements par moment de la journée, couverts, maison vide) entre deux dates.', [
                'du' => ['type' => 'string', 'description' => 'Date de début AAAA-MM-JJ'],
                'au' => ['type' => 'string', 'description' => 'Date de fin AAAA-MM-JJ'],
            ], ['du', 'au']);
            $tools[] = self::tool('ajouter_agenda', 'Ajoute UN évènement dans l\'agenda. Appeler une fois par évènement quand on demande d\'en ajouter plusieurs.', [
                'nom' => ['type' => 'string', 'description' => 'Nom court de l\'évènement (ex. Foot, Rugby Armand, Restau, Mamie)'],
                'date' => ['type' => 'string', 'description' => 'Date au format AAAA-MM-JJ, calculée à partir de la date du jour'],
                'moment' => ['type' => 'string', 'enum' => ['matin', 'midi', 'après-midi', 'soir', 'journée'], 'description' => 'Moment de la journée'],
                'effet' => ['type' => 'string', 'enum' => ['info', 'absent', 'dehors', 'couverts'], 'description' => 'info = simple info ; absent = maison vide ; dehors = repas pris dehors ; couverts = couverts en plus ou en moins'],
                'couverts' => ['type' => 'integer', 'description' => 'Seulement si effet = couverts : nombre de couverts en plus (positif) ou en moins (négatif)'],
            ], ['nom', 'date', 'moment']);
        }
        return $tools;
    }

    /**
     * Construit les déclarations d'outils (tools) pour les commandes actions exposées à l'agent.
     * Inspiré du mode Device control (Conversation + AI tools) d'Home Assistant.
     */
    private function buildActionTools($allowedActionCmdIds) {
        $tools = [];
        foreach ($allowedActionCmdIds as $cmdId) {
            $cmd = cmd::byId(trim($cmdId));
            if (!is_object($cmd) || $cmd->getType() != 'action') {
                continue;
            }
            $eqName = is_object($cmd->getEqLogic()) ? $cmd->getEqLogic()->getHumanName() : '';
            $properties = [];
            $required = [];
            if ($cmd->getSubType() == 'message') {
                $properties = [
                    'title' => ['type' => 'string', 'description' => 'Titre ou sujet'],
                    'message' => ['type' => 'string', 'description' => 'Contenu ou message à envoyer'],
                ];
                $required = ['message'];
            } elseif ($cmd->getSubType() == 'slider') {
                $properties = ['slider' => ['type' => 'number', 'description' => 'Valeur numérique cible']];
                $required = ['slider'];
            } elseif ($cmd->getSubType() == 'color') {
                $properties = ['color' => ['type' => 'string', 'description' => 'Couleur hexadécimale ex: #ff0000']];
                $required = ['color'];
            } elseif ($cmd->getSubType() == 'select') {
                $properties = ['select' => ['type' => 'string', 'description' => 'Valeur à choisir : ' . self::shortValue($cmd->getConfiguration('listValue', ''), 200)]];
                $required = ['select'];
            }
            $tools[] = self::tool('exec_cmd_' . $cmd->getId(), "Exécute la commande Jeedom '{$cmd->getName()}' de l'équipement '{$eqName}' (ID #{$cmd->getId()}#)", $properties, $required);
        }
        return $tools;
    }

    /** La commande info est-elle lisible par cet agent ? */
    private function canRead($cmd) {
        if ($this->isFullRead()) {
            return true;
        }
        if (in_array((string)$cmd->getId(), $this->exposedInfoIds(), true)) {
            return true;
        }
        $eq = $cmd->getEqLogic();
        return is_object($eq) && in_array($eq->getEqType_name(), $this->allowedPlugins(), true);
    }

    public static function readHistory($cmd, $hours) {
        $hours = max(1, min(720, (int)$hours ?: 24));
        $end = date('Y-m-d H:i:s');
        $start = date('Y-m-d H:i:s', time() - $hours * 3600);
        if ($cmd->getIsHistorized() != 1) {
            return "La commande #{$cmd->getId()}# n'est pas historisée. Valeur actuelle : " . self::shortValue($cmd->execCmd());
        }
        $values = [];
        foreach (history::all($cmd->getId(), $start, $end) as $hist) {
            $values[] = [strtotime($hist->getDatetime()), $hist->getValue()];
        }
        if (empty($values)) {
            return "Aucun historique sur {$hours} h pour #{$cmd->getId()}#.";
        }
        $nums = array_filter(array_column($values, 1), 'is_numeric');
        $unit = $cmd->getUnite();
        $out = self::cmdLine($cmd) . "\nSur {$hours} h (" . count($values) . ' valeurs)';
        if (!empty($nums)) {
            $out .= ' : min ' . round(min($nums), 2) . ', max ' . round(max($nums), 2) . ', moyenne ' . round(array_sum($nums) / count($nums), 2) . ($unit ? ' ' . $unit : '');
        }
        $step = max(1, (int)ceil(count($values) / 24));
        $pts = [];
        for ($i = 0; $i < count($values); $i += $step) {
            $pts[] = date('d/m H:i', $values[$i][0]) . ' ' . (is_numeric($values[$i][1]) ? round($values[$i][1], 2) : self::shortValue($values[$i][1], 40));
        }
        return $out . "\nÉvolution : " . implode(' | ', $pts);
    }

    public static function searchCmds($text, $limit = 40) {
        $needle = mb_strtolower(trim((string)$text));
        if ($needle === '') {
            return 'Texte de recherche vide.';
        }
        $words = array_filter(preg_split('/\s+/', $needle));
        $lines = [];
        foreach (eqLogic::all() as $eq) {
            if (!$eq->getIsEnable()) {
                continue;
            }
            $eqText = mb_strtolower($eq->getHumanName() . ' ' . $eq->getEqType_name());
            foreach ($eq->getCmd('info') as $cmd) {
                $hay = $eqText . ' ' . mb_strtolower($cmd->getName());
                $ok = true;
                foreach ($words as $w) {
                    if (mb_strpos($hay, $w) === false) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    $lines[] = self::cmdLine($cmd, 150);
                    if (count($lines) >= $limit) {
                        $lines[] = '… (résultats tronqués, préciser la recherche)';
                        return implode("\n", $lines);
                    }
                }
            }
        }
        return empty($lines) ? "Aucune commande trouvée pour « {$text} »." : implode("\n", $lines);
    }

    private function executeToolCall($toolName, $args, $allowedActionCmdIds) {
        if (!is_array($args)) {
            $args = [];
        }
        log::add('ollamaAgent', 'info', "[ToolCall] {$toolName} " . json_encode($args, JSON_UNESCAPED_UNICODE));
        try {
            switch ($toolName) {
                case 'retenir':
                    return ($this->memoryEnabled() && $this->notesWritable()) ? $this->addNote($args['texte'] ?? '') : 'Mémoire non autorisée.';
                case 'oublier_note':
                    return ($this->memoryEnabled() && $this->notesWritable()) ? $this->delNote($args['numero'] ?? 0) : 'Mémoire non autorisée.';
                case 'lire_plugin':
                    $p = (string)($args['plugin'] ?? '');
                    if (!$this->isFullRead() && !in_array($p, $this->allowedPlugins(), true)) {
                        return "Plugin « {$p} » non autorisé pour cet agent.";
                    }
                    return self::dumpPlugin($p);
                case 'chercher_commandes':
                    return $this->isFullRead() ? self::searchCmds($args['texte'] ?? '') : 'Recherche non autorisée.';
                case 'lire_commande':
                case 'lire_historique':
                    $cmd = cmd::byId((int)($args['id'] ?? 0));
                    if (!is_object($cmd) || $cmd->getType() != 'info') {
                        return 'Commande info introuvable.';
                    }
                    if (!$this->canRead($cmd)) {
                        return "Commande #{$cmd->getId()}# non autorisée pour cet agent.";
                    }
                    return ($toolName == 'lire_commande') ? self::cmdLine($cmd, 1000) : self::readHistory($cmd, $args['heures'] ?? 24);
                case 'etat_jeedom':
                    return self::healthText(self::getHealth(), true, true);
                case 'lire_agenda':
                    return $this->hasFeature('agenda') ? self::agendaRead($args['du'] ?? 'today', $args['au'] ?? '+6 days') : 'Agenda non autorisé.';
                case 'ajouter_agenda':
                    return $this->hasFeature('agenda') ? $this->agendaAdd($args) : 'Agenda non autorisé.';
            }
            if (strpos($toolName, 'repas_') === 0) {
                if (!$this->repasEnabled()) {
                    return 'Outils repas non disponibles (plugin repas absent ou non autorisé).';
                }
                switch ($toolName) {
                    case 'repas_jour':
                        return ollamaAgentRepas::jour($args['date'] ?? '');
                    case 'repas_semaine':
                        return ollamaAgentRepas::semaine((int)($args['decalage'] ?? 0));
                    case 'repas_courses':
                        return ollamaAgentRepas::courses((int)($args['decalage'] ?? 0));
                    case 'repas_frigo':
                        return ollamaAgentRepas::frigo();
                    case 'repas_recettes':
                        return ollamaAgentRepas::recettes($args['texte'] ?? '', $args['moment'] ?? '');
                    case 'repas_recette':
                        return ollamaAgentRepas::recette((int)($args['id'] ?? 0), $args['moment'] ?? '');
                    case 'repas_gouts':
                        return ollamaAgentRepas::gouts();
                }
            }
        } catch (Throwable $e) {
            log::add('ollamaAgent', 'error', "[ToolCall] {$toolName} : " . $e->getMessage());
            return 'Erreur : ' . $e->getMessage();
        }

        if (!preg_match('/^exec_cmd_(\d+)$/', $toolName, $matches)) {
            return "Outil inconnu : {$toolName}";
        }
        $cmdId = $matches[1];
        if (!in_array((string)$cmdId, array_map('strval', $allowedActionCmdIds), true)) {
            log::add('ollamaAgent', 'warning', "[ToolCall] Commande action #{$cmdId}# non autorisée pour cet agent");
            return "Commande #{$cmdId}# non autorisée.";
        }
        $cmd = cmd::byId($cmdId);
        if (!is_object($cmd) || $cmd->getType() != 'action') {
            return "Commande action #{$cmdId}# introuvable.";
        }
        $options = [];
        foreach (['title', 'message', 'slider', 'color', 'select'] as $key) {
            if (isset($args[$key])) {
                $options[$key] = $args[$key];
            }
        }
        log::add('ollamaAgent', 'info', "[ToolCall] Exécution commande action #{$cmdId}# ({$cmd->getName()}) avec options=" . json_encode($options));
        $cmd->execCmd($options);
        return "Commande #{$cmdId}# exécutée avec succès.";
    }

    /* ============================================================
     * Commandes de l'agent
     * ============================================================ */

    private function ensureCmd($logicalId, $name, $type, $subType, $visible = 1) {
        $cmd = $this->getCmd(null, $logicalId);
        if (!is_object($cmd)) {
            $cmd = new ollamaAgentCmd();
            $cmd->setName(__($name, __FILE__));
            $cmd->setLogicalId($logicalId);
            $cmd->setEqLogic_id($this->getId());
            $cmd->setType($type);
            $cmd->setSubType($subType);
            $cmd->setIsVisible($visible);
            $cmd->save();
        }
        return $cmd;
    }

    public function postSave() {
        $rep = $this->ensureCmd('reponse', 'Réponse', 'info', 'string');
        // « toujours répéter » : deux réponses identiques déclenchent quand même les scénarios
        if ($rep->getConfiguration('repeatEventManagement', '') != 'always') {
            $rep->setConfiguration('repeatEventManagement', 'always');
            $rep->save();
        }
        $this->ensureCmd('statut', 'Statut', 'info', 'string');
        $duree = $this->ensureCmd('duree', 'Durée dernière réponse', 'info', 'numeric', 0);
        if ($duree->getUnite() != 's') {
            $duree->setUnite('s');
            $duree->save();
        }

        // Sorties structurées : une commande info par champ déclaré
        $fields = $this->parseOutputs();
        foreach ($fields as $slug => $f) {
            $sub = self::OUTPUT_TYPES[$f['type']];
            $c = $this->getCmd(null, 'out_' . $slug);
            if (!is_object($c)) {
                $c = $this->ensureCmd('out_' . $slug, $f['name'], 'info', $sub, 1);
            }
            $changed = false;
            if ($c->getSubType() != $sub) {
                $c->setSubType($sub);
                $changed = true;
            }
            if ($c->getConfiguration('repeatEventManagement', '') != 'always') {
                $c->setConfiguration('repeatEventManagement', 'always');
                $changed = true;
            }
            if ($changed) {
                $c->save();
            }
        }
        foreach ($this->getCmd('info') as $c) {
            if (strpos($c->getLogicalId(), 'out_') === 0 && !isset($fields[substr($c->getLogicalId(), 4)])) {
                $c->remove();
            }
        }
        if (!empty($fields)) {
            $this->ensureCmd('sorties_json', 'Sorties (JSON)', 'info', 'string', 0);
        } else {
            $c = $this->getCmd(null, 'sorties_json');
            if (is_object($c)) {
                $c->remove();
            }
        }

        $visionCmds = [
            'description' => ['Description', 'string', 1],
            'intrusion' => ['Intrusion', 'binary', 1],
            'fausse_alarme' => ['Fausse alarme', 'binary', 0],
            'verdict' => ['Verdict', 'string', 1],
        ];
        if ($this->agentType() == 'vision') {
            foreach ($visionCmds as $lid => $d) {
                $c = $this->ensureCmd($lid, $d[0], 'info', $d[1], $d[2]);
                if ($c->getConfiguration('repeatEventManagement', '') != 'always') {
                    $c->setConfiguration('repeatEventManagement', 'always');
                    $c->save();
                }
            }
            $this->ensureCmd('analyser', 'Analyser', 'action', 'other');
        } else {
            foreach (array_merge(array_keys($visionCmds), ['analyser']) as $lid) {
                $c = $this->getCmd(null, $lid);
                if (is_object($c)) {
                    $c->remove();
                }
            }
        }
        $this->ensureCmd('nouvelle_conversation', 'Nouvelle conversation', 'action', 'other');
        $this->ensureCmd('memoire_echanges', 'Mémoire - échanges', 'info', 'numeric', 0);
        $demande = $this->getCmd(null, 'demande');
        if (!is_object($demande)) {
            $demande = $this->ensureCmd('demande', 'Demande', 'action', 'message');
            $demande->setDisplay('title_placeholder', __('Chemin image (optionnel)', __FILE__));
            $demande->setDisplay('message_placeholder', __('Votre question ou consigne...', __FILE__));
            $demande->save();
        }

        if ($this->hasFeature('health')) {
            foreach (self::HEALTH_CMDS as $lid => $def) {
                $visible = in_array($lid, ['sante_resume', 'sante_liste', 'sante_probleme']) ? 1 : 0;
                $this->ensureCmd($lid, $def[0], 'info', $def[1], $visible);
            }
            $analyse = $this->getCmd(null, 'analyser_sante');
            if (!is_object($analyse)) {
                $analyse = $this->ensureCmd('analyser_sante', 'Bilan santé', 'action', 'message');
                $analyse->setDisplay('title_disable', 1);
                $analyse->setDisplay('message_placeholder', __('Consigne (vide = consigne de l\'agent)', __FILE__));
                $analyse->save();
            }
            $refresh = $this->ensureCmd('rafraichir_sante', 'Rafraîchir santé', 'action', 'other');
        } else {
            foreach (array_merge(array_keys(self::HEALTH_CMDS), ['analyser_sante', 'rafraichir_sante']) as $lid) {
                $cmd = $this->getCmd(null, $lid);
                if (is_object($cmd)) {
                    $cmd->remove();
                }
            }
        }
    }

    /* ============================================================
     * Mémoire : conversation courte + notes permanentes
     * ============================================================ */

    const MAX_NOTES = 40;
    const NOTE_MAX_LEN = 250;

    public function memoryEnabled() {
        if ($this->agentType() == 'vision') {
            return false; // chaque image est analysée seule
        }
        return $this->getConfiguration('memory_disable', 0) != 1;
    }

    public function memoryTurns() {
        $n = (int)$this->getConfiguration('memory_turns', 5);
        return $n > 0 ? min($n, 20) : 5;
    }

    /** Minutes sans échange avant d'oublier la conversation (0 = jamais) */
    public function memoryMinutes() {
        $v = $this->getConfiguration('memory_minutes', '');
        return ($v === '' || !is_numeric($v)) ? 30 : max(0, (int)$v);
    }

    public function notesWritable() {
        return $this->getConfiguration('notes_agent_disable', 0) != 1;
    }

    /** Nombre d'échanges conservés en base par agent (pour l'historique) */
    const KEEP_EXCHANGES = 200;

    /** Table de la conversation (créée à l'installation, ou au premier usage si les fichiers ont été copiés à la main) */
    public static function ensureTable() {
        static $tableOk = false; // pas de propriété de classe (voir pingAvailable)
        if ($tableOk) {
            return;
        }
        DB::Prepare("CREATE TABLE IF NOT EXISTS `ollamaAgent_message` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `eqLogic_id` INT NOT NULL,
            `datetime` DATETIME NOT NULL,
            `user` MEDIUMTEXT NULL,
            `assistant` MEDIUMTEXT NULL,
            PRIMARY KEY (`id`),
            KEY `eqLogic_id` (`eqLogic_id`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", [], DB::FETCH_TYPE_ROW);
        $tableOk = true;
    }

    /** Échanges encore valides de la conversation en cours : [['user' => …, 'assistant' => …, 'ts' => …], …] */
    public function getHistory() {
        self::ensureTable();
        $rows = DB::Prepare('SELECT `user`, `assistant`, `datetime` FROM `ollamaAgent_message` WHERE `eqLogic_id` = :id ORDER BY `id` DESC LIMIT ' . (int)$this->memoryTurns(),
            ['id' => $this->getId()], DB::FETCH_TYPE_ALL);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }
        // du plus récent au plus ancien : on s'arrête au premier trou de plus de N minutes (= conversation précédente)
        $minutes = $this->memoryMinutes();
        $out = [];
        $ref = time();
        foreach ($rows as $r) {
            $ts = (int)strtotime($r['datetime']);
            if ($minutes > 0 && $ref - $ts > $minutes * 60) {
                break;
            }
            $out[] = ['user' => (string)$r['user'], 'assistant' => (string)$r['assistant'], 'ts' => $ts];
            $ref = $ts;
        }
        return array_reverse($out);
    }

    public function saveExchange($user, $assistant) {
        self::ensureTable();
        DB::Prepare('INSERT INTO `ollamaAgent_message` (`eqLogic_id`, `datetime`, `user`, `assistant`) VALUES (:id, :dt, :u, :a)', [
            'id' => $this->getId(),
            'dt' => date('Y-m-d H:i:s'),
            'u' => self::shortValue($user, 2000),
            'a' => mb_substr(trim((string)$assistant), 0, 3000),
        ], DB::FETCH_TYPE_ROW);
        // on garde les 200 derniers échanges de l'agent
        $row = DB::Prepare('SELECT `id` FROM `ollamaAgent_message` WHERE `eqLogic_id` = :id ORDER BY `id` DESC LIMIT 1 OFFSET ' . (self::KEEP_EXCHANGES - 1),
            ['id' => $this->getId()], DB::FETCH_TYPE_ROW);
        if (is_array($row) && !empty($row['id'])) {
            DB::Prepare('DELETE FROM `ollamaAgent_message` WHERE `eqLogic_id` = :id AND `id` < :min', ['id' => $this->getId(), 'min' => (int)$row['id']], DB::FETCH_TYPE_ROW);
        }
        $this->checkAndUpdateCmd('memoire_echanges', count($this->getHistory()));
    }

    /** Nouvelle conversation : les échanges en base sont effacés */
    public function clearHistory() {
        self::ensureTable();
        DB::Prepare('DELETE FROM `ollamaAgent_message` WHERE `eqLogic_id` = :id', ['id' => $this->getId()], DB::FETCH_TYPE_ROW);
        $this->checkAndUpdateCmd('memoire_echanges', 0);
        log::add('ollamaAgent', 'info', '[Mémoire] Conversation effacée pour l\'agent ' . $this->getName());
    }

    /** Suppression de l'agent : sa conversation et ses notes partent avec lui */
    public function preRemove() {
        try {
            self::ensureTable();
            DB::Prepare('DELETE FROM `ollamaAgent_message` WHERE `eqLogic_id` = :id', ['id' => $this->getId()], DB::FETCH_TYPE_ROW);
            config::remove($this->notesKey(), 'ollamaAgent');
        } catch (Throwable $e) {
            log::add('ollamaAgent', 'warning', '[Mémoire] Nettoyage à la suppression : ' . $e->getMessage());
        }
    }

    private function notesKey() {
        return 'notes_' . $this->getId();
    }

    /** Notes permanentes (une par ligne), modifiables dans la page de l'agent */
    public function getNotes() {
        $raw = (string)config::byKey($this->notesKey(), 'ollamaAgent', '');
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $raw)), 'strlen'));
    }

    public function setNotes($notes) {
        if (!is_array($notes)) {
            $notes = preg_split('/\R/', (string)$notes);
        }
        $clean = [];
        foreach ($notes as $n) {
            $n = trim(preg_replace('/\s+/', ' ', (string)$n));
            if ($n !== '') {
                $clean[] = mb_substr($n, 0, self::NOTE_MAX_LEN);
            }
        }
        config::save($this->notesKey(), implode("\n", array_slice($clean, 0, self::MAX_NOTES)), 'ollamaAgent');
    }

    private function addNote($texte) {
        $texte = trim(preg_replace('/\s+/', ' ', (string)$texte));
        if ($texte === '') {
            return 'Note vide : rien retenu.';
        }
        $notes = $this->getNotes();
        foreach ($notes as $n) {
            if (mb_strtolower($n) == mb_strtolower($texte)) {
                return 'Déjà retenu.';
            }
        }
        if (count($notes) >= self::MAX_NOTES) {
            return 'Mémoire pleine (' . self::MAX_NOTES . ' notes) : demande d\'abord d\'oublier une note.';
        }
        $notes[] = $texte;
        $this->setNotes($notes);
        log::add('ollamaAgent', 'info', '[Mémoire] ' . $this->getName() . ' retient : ' . $texte);
        return 'Retenu (note ' . count($notes) . ') : ' . $texte;
    }

    private function delNote($numero) {
        $notes = $this->getNotes();
        $i = (int)$numero - 1;
        if (!isset($notes[$i])) {
            return "Note {$numero} introuvable.";
        }
        $old = $notes[$i];
        unset($notes[$i]);
        $this->setNotes(array_values($notes));
        log::add('ollamaAgent', 'info', '[Mémoire] ' . $this->getName() . ' oublie : ' . $old);
        return 'Oublié : ' . $old;
    }

    private function notesContext() {
        $notes = $this->getNotes();
        if (empty($notes)) {
            return '';
        }
        $l = ["\n--- CE QUE TU AS RETENU (notes permanentes) ---"];
        foreach ($notes as $i => $n) {
            $l[] = ($i + 1) . '. ' . $n;
        }
        return implode("\n", $l);
    }

    public static function tmpDir() {
        $dir = sys_get_temp_dir();
        try {
            if (method_exists('jeedom', 'getTmpFolder')) {
                $dir = jeedom::getTmpFolder('ollamaAgent');
            }
        } catch (Throwable $e) {
        }
        return rtrim($dir, '/');
    }

    /** Une seule demande à la fois par agent : la suivante attend et voit l'échange précédent */
    private function lockFile() {
        return self::tmpDir() . '/agent_' . $this->getId() . '.lock';
    }

    /* ---------- Priorité (vision) ---------- */

    public function isPriority() {
        return $this->agentType() == 'vision' || $this->getConfiguration('priority', 0) == 1;
    }

    /** Un agent prioritaire est-il en train de travailler ? (marqueurs de plus de 5 min ignorés) */
    public static function priorityActive() {
        foreach (glob(self::tmpDir() . '/priority_*.flag') ?: [] as $f) {
            $mt = @filemtime($f);
            if ($mt !== false && time() - $mt < 300) {
                return true;
            }
            @unlink($f);
        }
        return false;
    }

    /** Attend la fin des agents prioritaires (5 min max) */
    public static function waitPriority($max = 300) {
        $start = time();
        while (self::priorityActive() && time() - $start < $max) {
            usleep(300000);
        }
    }

    /** Les demandes de cet agent peuvent-elles être coupées par un agent prioritaire ? */
    private function interruptible() {
        return !$this->isPriority() && $this->getConfiguration('cpu_only', 0) != 1;
    }

    /* ============================================================
     * Image (vision)
     * ============================================================ */

    /** Image depuis un fichier, une URL (http) ou une commande Jeedom (#id# ou #[Objet][Eq][Cmd]#) dont la valeur est un chemin ou une URL */
    public function loadImage($source, $depth = 0) {
        $source = trim((string)$source);
        if ($source === '' || $depth > 2) {
            return null;
        }
        if (preg_match('/^#.+#$/', $source)) {
            $cmd = self::cmdFromConfig($source);
            if (is_object($cmd) && $cmd->getType() == 'info') {
                return $this->loadImage((string)$cmd->execCmd(), $depth + 1);
            }
            log::add('ollamaAgent', 'warning', '[Image] Commande introuvable : ' . $source);
            return null;
        }
        if (preg_match('#^https?://#i', $source)) {
            $ch = curl_init($source);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 4);
            $data = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($data === false || $data === '' || $code < 200 || $code >= 300) {
                log::add('ollamaAgent', 'warning', '[Image] Téléchargement impossible (HTTP ' . $code . ') : ' . $source);
                return null;
            }
        } else {
            if (!is_file($source)) {
                log::add('ollamaAgent', 'warning', '[Image] Fichier introuvable : ' . $source);
                return null;
            }
            $data = @file_get_contents($source);
            if ($data === false) {
                return null;
            }
        }
        return self::resizeImage($data, (int)$this->getConfiguration('image_max_width', $this->agentType() == 'vision' ? 640 : 0));
    }

    /** Réduit l'image à une largeur maximale (plus rapide à analyser). 0 = taille d'origine */
    public static function resizeImage($data, $maxWidth) {
        if ($maxWidth <= 0 || !function_exists('imagecreatefromstring')) {
            return $data;
        }
        $img = @imagecreatefromstring($data);
        if ($img === false) {
            return $data;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= $maxWidth) {
            imagedestroy($img);
            return $data;
        }
        $nh = (int)round($h * $maxWidth / $w);
        $small = imagecreatetruecolor($maxWidth, $nh);
        imagecopyresampled($small, $img, 0, 0, 0, 0, $maxWidth, $nh, $w, $h);
        ob_start();
        imagejpeg($small, null, 85);
        $out = ob_get_clean();
        imagedestroy($img);
        imagedestroy($small);
        return $out !== '' ? $out : $data;
    }

    /** Verdict de la vision : INTRUSION / RAS / DOUTE (réponse hors format = DOUTE, erreur = ERREUR) */
    public static function parseVerdict($reply, $isError = false) {
        if ($isError) {
            return ['verdict' => 'ERREUR', 'description' => (string)$reply];
        }
        $txt = trim((string)$reply);
        if (preg_match('/\b(INTRUSION|RAS|DOUTE)\b\s*[:\-–—]?\s*(.*)$/su', $txt, $m)) {
            return ['verdict' => $m[1], 'description' => trim(preg_replace('/\s+/', ' ', $m[2]))];
        }
        return ['verdict' => 'DOUTE', 'description' => self::shortValue($txt, 200)];
    }

    /* ============================================================
     * Fin de demande : commandes, vision, durée, notification
     * ============================================================ */

    /* ============================================================
     * Sorties structurées : champs déclarés → commandes info
     * ============================================================ */

    const OUTPUT_TYPES = ['binaire' => 'binary', 'nombre' => 'numeric', 'texte' => 'string', 'liste' => 'string'];

    public static function slug($txt) {
        $t = mb_strtolower(trim((string)$txt));
        $t = strtr($t, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe']);
        return trim(preg_replace('/[^a-z0-9]+/', '_', $t), '_');
    }

    /**
     * Champs déclarés, une ligne par champ : « nom;type;description »
     * type : binaire, nombre, texte, ou liste(valeur1|valeur2|…)
     */
    public function parseOutputs() {
        if ($this->agentType() == 'vision') {
            return [];
        }
        $out = [];
        foreach (preg_split('/\R/', (string)$this->getConfiguration('outputs', '')) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] == '#') {
                continue;
            }
            $p = array_map('trim', explode(';', $line, 3));
            $slug = self::slug($p[0] ?? '');
            if ($slug === '' || isset($out[$slug])) {
                continue;
            }
            $typeRaw = mb_strtolower($p[1] ?? 'texte');
            $values = [];
            if (preg_match('/^liste\s*\((.*)\)$/u', $typeRaw, $m)) {
                $type = 'liste';
                $values = array_values(array_filter(array_map('trim', explode('|', $m[1])), 'strlen'));
            } else {
                $type = isset(self::OUTPUT_TYPES[$typeRaw]) ? $typeRaw : 'texte';
            }
            $out[$slug] = ['name' => $p[0], 'type' => $type, 'values' => $values, 'description' => $p[2] ?? ''];
        }
        return array_slice($out, 0, 20, true);
    }

    /** Schéma JSON envoyé à Ollama (format) */
    public static function outputsSchema($fields) {
        $props = [];
        foreach ($fields as $slug => $f) {
            switch ($f['type']) {
                case 'binaire':
                    $props[$slug] = ['type' => ['integer', 'null'], 'enum' => [0, 1, null]];
                    break;
                case 'nombre':
                    $props[$slug] = ['type' => ['number', 'null']];
                    break;
                case 'liste':
                    $props[$slug] = ['type' => ['string', 'null'], 'enum' => array_merge($f['values'], [null])];
                    break;
                default:
                    $props[$slug] = ['type' => ['string', 'null']];
            }
            if ($f['description'] !== '') {
                $props[$slug]['description'] = $f['description'];
            }
        }
        return ['type' => 'object', 'properties' => $props, 'required' => array_keys($fields)];
    }

    /** Ligne ajoutée au contexte de l'agent pour que sa réponse contienne ces informations */
    private function outputsContext($fields) {
        $l = ["\n--- INFORMATIONS À DONNER CLAIREMENT DANS TA RÉPONSE ---"];
        foreach ($fields as $f) {
            $l[] = '- ' . $f['name'] . ($f['description'] !== '' ? ' : ' . $f['description'] : '') . ($f['type'] == 'liste' ? ' (' . implode(', ', $f['values']) . ')' : '');
        }
        return implode("\n", $l);
    }

    /** 2e demande, sans outils et au format imposé : remplit les champs à partir de la réponse */
    private function extractOutputs($fields, $prompt, $reply, $model, $timeout) {
        $desc = [];
        foreach ($fields as $slug => $f) {
            $t = $f['type'] == 'liste' ? 'une valeur parmi : ' . implode(', ', $f['values']) : ($f['type'] == 'binaire' ? '1 = oui, 0 = non' : $f['type']);
            $desc[] = '- ' . $slug . ' (' . $t . ')' . ($f['description'] !== '' ? ' : ' . $f['description'] : '');
        }
        $payload = [
            'model' => $model,
            'stream' => false,
            'think' => false,
            'format' => self::outputsSchema($fields),
            'messages' => [
                ['role' => 'system', 'content' => "Tu remplis des champs à partir de la réponse d'un assistant. Réponds uniquement avec l'objet JSON demandé. Si la réponse ne contient pas l'information, mets null. N'invente rien."],
                ['role' => 'user', 'content' => "Question posée :\n" . $prompt . "\n\nRéponse de l'assistant :\n" . $reply . "\n\nChamps à remplir :\n" . implode("\n", $desc)],
            ],
            'options' => ['temperature' => 0],
        ];
        if ($this->getConfiguration('cpu_only', 0) == 1) {
            $payload['options']['num_gpu'] = 0;
        }
        $keepAlive = $this->keepAlive();
        if ($keepAlive !== null) {
            $payload['keep_alive'] = $keepAlive;
        }
        for ($try = 0; $try < 5; $try++) {
            $res = self::chatOllama($payload, $timeout, $this->interruptible());
            if (!empty($res['interrupted'])) {
                self::waitPriority();
                continue;
            }
            break;
        }
        if (!$res['ok']) {
            log::add('ollamaAgent', 'warning', '[Sorties] ' . $res['error']);
            return null;
        }
        $content = trim((string)($res['data']['message']['content'] ?? ''));
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $content);
        $data = json_decode($content, true);
        if (!is_array($data)) {
            log::add('ollamaAgent', 'warning', '[Sorties] JSON illisible : ' . mb_substr($content, 0, 300));
            return null;
        }
        return $data;
    }

    /** Écrit les valeurs dans les commandes (null = commande inchangée) */
    private function applyOutputs($fields, $data) {
        $clean = [];
        foreach ($fields as $slug => $f) {
            $v = $data[$slug] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            switch ($f['type']) {
                case 'binaire':
                    $v = (in_array($v, [1, '1', true, 'true', 'oui'], true) || $v === 1.0) ? 1 : 0;
                    break;
                case 'nombre':
                    if (!is_numeric(str_replace(',', '.', (string)$v))) {
                        continue 2;
                    }
                    $v = 0 + str_replace(',', '.', (string)$v);
                    break;
                case 'liste':
                    if (!in_array((string)$v, $f['values'], true)) {
                        continue 2;
                    }
                    break;
                default:
                    $v = self::shortValue($v, 1000);
            }
            $clean[$slug] = $v;
            $this->checkAndUpdateCmd('out_' . $slug, $v);
        }
        $this->checkAndUpdateCmd('sorties_json', json_encode($clean, JSON_UNESCAPED_UNICODE));
        log::add('ollamaAgent', 'info', '[Sorties] ' . $this->getName() . ' : ' . json_encode($clean, JSON_UNESCAPED_UNICODE));
        return $clean;
    }

    private function finishRequest($reply, $isError, $imageFile, $tStart, $context = []) {
        $verdict = null;
        if ($this->agentType() == 'vision') {
            $v = self::parseVerdict($reply, $isError);
            $verdict = $v['verdict'];
            // description et binaires d'abord, Verdict en dernier (déclencheur conseillé) : le scénario lit des valeurs à jour
            $this->checkAndUpdateCmd('description', $v['description']);
            $this->checkAndUpdateCmd('intrusion', $verdict == 'INTRUSION' ? 1 : 0);
            $this->checkAndUpdateCmd('fausse_alarme', $verdict == 'RAS' ? 1 : 0);
            $this->checkAndUpdateCmd('verdict', $verdict);
            log::add('ollamaAgent', 'info', '[Vision] Verdict ' . $verdict . ' : ' . $v['description']);
        }
        $duree = round(microtime(true) - $tStart, 1);
        $this->checkAndUpdateCmd('duree', $duree);
        $this->checkAndUpdateCmd('reponse', $reply);
        $this->checkAndUpdateCmd('statut', $isError ? 'Erreur' : 'Prêt');
        log::add('ollamaAgent', 'info', '[Agent ' . $this->getName() . '] Réponse en ' . $duree . ' s (statut=' . ($isError ? 'Erreur' : 'Prêt') . ')');
        $viaMessaging = isset($context['reply_to']);
        if ($viaMessaging) {
            ollamaAgentMessaging::sendReply($reply, (string)$context['reply_to'], $this->getName());
        }
        // pas de double envoi si la commande d'envoi de l'agent est celle de la messagerie
        $notifyCmd = self::cmdFromConfig($this->getConfiguration('notify_cmd', ''));
        if (!$viaMessaging || !is_object($notifyCmd) || (string)$notifyCmd->getId() !== ollamaAgentMessaging::replyCmdId()) {
            $this->notify($reply, $isError, $verdict, $imageFile);
        }
    }

    /** Envoie la réponse à la commande de notification choisie (Telegram, Pushover, Alexa…) */
    private function notify($reply, $isError, $verdict, $imageFile) {
        $cmd = self::cmdFromConfig($this->getConfiguration('notify_cmd', ''));
        if (!is_object($cmd) || $cmd->getType() != 'action') {
            return;
        }
        if ($isError && $this->getConfiguration('notify_no_errors', 0) == 1) {
            return;
        }
        if ($verdict == 'RAS' && $this->getConfiguration('notify_ras', 0) != 1) {
            return;
        }
        $options = ['title' => $this->getName() . ($verdict !== null ? ' : ' . $verdict : ''), 'message' => $reply];
        if ($imageFile !== '' && is_file($imageFile) && $this->getConfiguration('notify_no_image', 0) != 1) {
            $options['files'] = [$imageFile];
        }
        try {
            $cmd->execCmd($options);
            log::add('ollamaAgent', 'debug', '[Notification] Envoyée via #' . $cmd->getId() . '#');
        } catch (Throwable $e) {
            log::add('ollamaAgent', 'warning', '[Notification] ' . $e->getMessage());
        }
    }

    /* ============================================================
     * Demandes programmées
     * ============================================================ */

    /** Lignes « HH:MM;jours;demande » — jours : * (tous), 1-5 (lun→ven), 6,7 (week-end) */
    public function parseSchedules() {
        $out = [];
        foreach (preg_split('/\R/', (string)$this->getConfiguration('schedules', '')) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] == '#') {
                continue;
            }
            $p = explode(';', $line, 3);
            if (count($p) < 3 || !preg_match('/^(\d{1,2}):(\d{2})$/', trim($p[0]), $t) || trim($p[2]) === '') {
                continue;
            }
            $days = [];
            $spec = str_replace(' ', '', trim($p[1]));
            foreach (explode(',', $spec === '' ? '*' : $spec) as $part) {
                if ($part == '*') {
                    $days = range(1, 7);
                } elseif (preg_match('/^([1-7])-([1-7])$/', $part, $r)) {
                    $days = array_merge($days, range((int)$r[1], (int)$r[2]));
                } elseif (preg_match('/^[1-7]$/', $part)) {
                    $days[] = (int)$part;
                }
            }
            $out[] = ['time' => sprintf('%02d:%02d', $t[1], $t[2]), 'days' => array_values(array_unique($days)), 'prompt' => trim($p[2])];
        }
        return $out;
    }

    public static function cron() {
        $now = date('H:i');
        $day = (int)date('N');
        foreach (eqLogic::byType('ollamaAgent', true) as $eq) {
            foreach ($eq->parseSchedules() as $sch) {
                if ($sch['time'] == $now && in_array($day, $sch['days'], true)) {
                    log::add('ollamaAgent', 'info', '[Programmation] ' . $eq->getName() . ' à ' . $now . ' : ' . $sch['prompt']);
                    $eq->triggerAsyncRequest($sch['prompt']);
                }
            }
        }
    }

    public function triggerAsyncRequest($prompt, $imagePath = '', $memoryPrompt = null) {
        log::add('ollamaAgent', 'info', '[Agent ' . $this->getName() . ' #' . $this->getId() . '] Nouvelle demande reçue : ' . mb_substr($prompt, 0, 120));
        $this->checkAndUpdateCmd('statut', 'Analyse en cours...');

        $worker = dirname(__FILE__) . '/../php/worker.php';
        $phpBin = defined('PHP_BINARY') && !empty(PHP_BINARY) ? PHP_BINARY : 'php';
        $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($this->getId()) . ' ' . escapeshellarg(base64_encode($prompt)) . ' ' . escapeshellarg(base64_encode((string)$imagePath)) . ' ' . escapeshellarg(base64_encode($memoryPrompt === null ? $prompt : (string)$memoryPrompt)) . ' >> ' . escapeshellarg(log::getPathToLog('ollamaAgent')) . ' 2>&1 &';

        log::add('ollamaAgent', 'debug', '[triggerAsyncRequest] Lancement commande : ' . $cmd);
        $output = [];
        $retVar = 0;
        exec($cmd, $output, $retVar);
        if ($retVar !== 0) {
            log::add('ollamaAgent', 'error', '[triggerAsyncRequest] Code retour exec non nul : ' . $retVar);
        }
    }

    public function processOllamaRequest($prompt, $imagePath = '', $memoryPrompt = null, $context = []) {
        $flag = null;
        if ($this->isPriority()) {
            // posé AVANT d'attendre le verrou : les autres agents s'arrêtent tout de suite
            $flag = self::tmpDir() . '/priority_' . $this->getId() . '_' . getmypid() . '.flag';
            @touch($flag);
        }
        $lock = @fopen($this->lockFile(), 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            $this->runOllamaRequest($prompt, $imagePath, $memoryPrompt === null ? $prompt : $memoryPrompt, is_array($context) ? $context : []);
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            if ($flag) {
                @unlink($flag);
            }
        }
    }

    private function runOllamaRequest($prompt, $imagePath, $memoryPrompt, $context = []) {
        $tStart = microtime(true);
        $model = $this->getConfiguration('model', 'qwen2.5vl:7b');
        if (trim($model) === '') {
            $model = 'qwen2.5vl:7b';
        }
        $systemPrompt = $this->getConfiguration('system_prompt', '');
        $includeLogs = $this->getConfiguration('include_logs', 0);
        $deviceControl = $this->getConfiguration('device_control', 0);
        $exposedActionCmds = $this->getConfiguration('exposed_action_cmds', []);
        $jsonFormat = $this->getConfiguration('json_format', 0);
        $disableThink = $this->getConfiguration('disable_think', 0);
        $numCtx = (int)$this->getConfiguration('num_ctx', 0);
        $timeout = (int)$this->getConfiguration('timeout', 300);
        if ($timeout <= 0) {
            $timeout = 300;
        }

        log::add('ollamaAgent', 'debug', '[processOllamaRequest] Agent=' . $this->getName() . ' | modèle=' . $model . ' | deviceControl=' . $deviceControl);

        $contextParts = [];
        if (!empty($systemPrompt)) {
            $contextParts[] = $systemPrompt;
        }
        $contextParts[] = self::dateContext();
        $outputFields = $this->parseOutputs();
        if (!empty($outputFields)) {
            $contextParts[] = $this->outputsContext($outputFields);
        }
        if ($this->memoryEnabled()) {
            $nc = $this->notesContext();
            if ($nc !== '') {
                $contextParts[] = $nc;
            }
        }

        $selectedInfoIds = $this->exposedInfoIds();
        if (!empty($selectedInfoIds)) {
            $contextParts[] = "\n--- ÉTAT ACTUEL DES ÉQUIPEMENTS JEEDOM ---";
            foreach ($selectedInfoIds as $id) {
                $cmd = cmd::byId(trim($id));
                if (is_object($cmd) && $cmd->getType() == 'info') {
                    $contextParts[] = self::cmdLine($cmd);
                }
            }
        }

        if ($includeLogs == 1) {
            $errors = self::logErrors(24);
            $contextParts[] = "\n--- ERREURS DES LOGS JEEDOM (24 h) ---";
            if (empty($errors)) {
                $contextParts[] = 'Aucune erreur.';
            }
            foreach (array_slice($errors, 0, 10, true) as $log => $e) {
                $contextParts[] = '- ' . $log . ' : ' . $e['count'] . ' erreur(s), dernière : ' . $e['last'];
            }
        }

        $injected = false;
        if ($this->getConfiguration('inject_plugins', 0) == 1) {
            $ctx = $this->injectedContext();
            if ($ctx !== '') {
                $contextParts[] = "\n--- ÉTAT DES PLUGINS ---\n" . $ctx;
            }
            $injected = true;
        }

        $selectedActionIds = [];
        if ($deviceControl == 1 && is_array($exposedActionCmds)) {
            foreach ($exposedActionCmds as $cmdId => $checked) {
                if ($checked == 1) {
                    $selectedActionIds[] = (string)$cmdId;
                }
            }
        }

        $tools = $this->buildReadTools();
        if ($deviceControl == 1) {
            $tools = array_merge($tools, $this->buildActionTools($selectedActionIds));
        }

        $messages = [['role' => 'system', 'content' => implode("\n", $contextParts)]];
        if ($this->memoryEnabled()) {
            foreach ($this->getHistory() as $ex) {
                $messages[] = ['role' => 'user', 'content' => (string)$ex['user']];
                $messages[] = ['role' => 'assistant', 'content' => (string)$ex['assistant']];
            }
        }
        $userMessage = ['role' => 'user', 'content' => $prompt];
        $imageFile = '';
        if (trim((string)$imagePath) === '' && $this->agentType() == 'vision') {
            $imagePath = $this->getConfiguration('image_source', '');
        }
        if (trim((string)$imagePath) !== '') {
            $imageData = $this->loadImage($imagePath);
            if ($imageData !== null) {
                $userMessage['images'] = [base64_encode($imageData)];
                $imageFile = self::tmpDir() . '/image_' . $this->getId() . '.jpg';
                @file_put_contents($imageFile, $imageData);
            } elseif ($this->agentType() == 'vision') {
                $this->finishRequest('Erreur : image introuvable ou illisible (' . $imagePath . ')', true, $imageFile, $tStart, $context);
                return;
            }
        }
        $messages[] = $userMessage;

        // Boucle d'outils
        $maxTurns = (int)$this->getConfiguration('max_turns', 8);
        if ($maxTurns <= 0) {
            $maxTurns = 8;
        }
        $reply = '';
        $isError = false;
        $retriedWithoutTools = false;
        $actions = [];
        $interruptible = $this->interruptible();
        $interruptions = 0;

        for ($turn = 0; $turn < $maxTurns; $turn++) {
            if ($interruptible && self::priorityActive()) {
                $this->checkAndUpdateCmd('statut', 'En attente (agent prioritaire)');
                self::waitPriority();
                $this->checkAndUpdateCmd('statut', 'Analyse en cours...');
            }
            $payload = ['model' => $model, 'messages' => $messages, 'stream' => false];
            if (!empty($tools)) {
                $payload['tools'] = $tools;
            }
            if ($jsonFormat == 1) {
                $payload['format'] = 'json';
            }
            if ($disableThink == 1) {
                $payload['think'] = false;
            }
            if ($numCtx > 0) {
                $payload['options'] = ['num_ctx' => $numCtx];
            }
            if ($this->getConfiguration('cpu_only', 0) == 1) {
                $payload['options']['num_gpu'] = 0;
            }
            $keepAlive = $this->keepAlive();
            if ($keepAlive !== null) {
                $payload['keep_alive'] = $keepAlive;
            }

            $res = self::chatOllama($payload, $timeout, $interruptible);

            if (!empty($res['interrupted']) && $interruptions < 10) {
                $interruptions++;
                $this->checkAndUpdateCmd('statut', 'En pause (agent prioritaire)');
                self::waitPriority();
                log::add('ollamaAgent', 'info', '[Agent ' . $this->getName() . '] Reprise après l\'agent prioritaire (' . $interruptions . ')');
                $this->checkAndUpdateCmd('statut', 'Analyse en cours...');
                $turn--;
                continue;
            }

            // Modèle sans support des outils (ex. qwen2.5vl) : on relance sans outils, avec l'état des plugins injecté
            if (!$res['ok'] && $res['http'] == 400 && !empty($tools) && !$retriedWithoutTools && stripos($res['raw'], 'support') !== false && stripos($res['raw'], 'tool') !== false) {
                log::add('ollamaAgent', 'warning', '[processOllamaRequest] Le modèle ' . $model . ' ne gère pas les outils : relance sans outils (lecture seule par contexte, pas d\'agenda ni d\'actions).');
                $tools = [];
                $retriedWithoutTools = true;
                if (!$injected) {
                    $ctx = $this->injectedContext();
                    if ($ctx !== '') {
                        $messages[0]['content'] .= "\n\n--- ÉTAT DES PLUGINS ---\n" . $ctx;
                    }
                    $injected = true;
                }
                $turn--;
                continue;
            }
            if (!$res['ok']) {
                $reply = $res['error'];
                $isError = true;
                break;
            }
            $data = $res['data'];
            if (!isset($data['message'])) {
                $reply = "Aucune réponse reçue d'Ollama.";
                $isError = true;
                break;
            }

            $assistantMsg = $data['message'];
            unset($assistantMsg['thinking']);
            $messages[] = $assistantMsg;

            if (!empty($assistantMsg['tool_calls']) && is_array($assistantMsg['tool_calls'])) {
                foreach ($assistantMsg['tool_calls'] as $call) {
                    $fnName = $call['function']['name'] ?? '';
                    $fnArgs = $call['function']['arguments'] ?? [];
                    if (is_string($fnArgs)) {
                        $decodedArgs = json_decode($fnArgs, true);
                        $fnArgs = is_array($decodedArgs) ? $decodedArgs : [];
                    }
                    $toolResult = $this->executeToolCall($fnName, $fnArgs, $selectedActionIds);
                    if (in_array($fnName, ['ajouter_agenda', 'retenir', 'oublier_note'], true) || strpos($fnName, 'exec_cmd_') === 0) {
                        $actions[] = self::shortValue($toolResult, 200);
                    }
                    $messages[] = ['role' => 'tool', 'tool_name' => $fnName, 'content' => $toolResult];
                }
                if ($turn == $maxTurns - 1) {
                    $reply = 'Trop d\'appels d\'outils sans réponse finale (limite ' . $maxTurns . ').';
                    $isError = true;
                }
                continue;
            }

            $reply = trim($assistantMsg['content'] ?? '');
            if ($reply === '') {
                $reply = "Aucune réponse reçue d'Ollama.";
                $isError = true;
            }
            break;
        }

        if (!$isError && $this->memoryEnabled()) {
            $this->saveExchange($memoryPrompt, $reply . (empty($actions) ? '' : "\n(Fait : " . implode(' ; ', $actions) . ')'));
        }
        if (!$isError && !empty($outputFields)) {
            $this->checkAndUpdateCmd('statut', 'Lecture des sorties...');
            $data = $this->extractOutputs($outputFields, $prompt, $reply, $model, $timeout);
            if (is_array($data)) {
                $this->applyOutputs($outputFields, $data);
            }
        }
        $this->finishRequest($reply, $isError, $imageFile, $tStart, $context);
        // cet agent a pu faire sortir un modèle permanent (ex. vision) de la carte graphique : on le recharge
        if ($this->keepAlive() !== -1) {
            self::warmPermanentModels();
        }
        log::add('ollamaAgent', 'info', '[processOllamaRequest] Commandes Réponse & Statut mises à jour (statut=' . ($isError ? 'Erreur' : 'Prêt') . ')');
    }
}

class ollamaAgentCmd extends cmd {
    public function execute($_options = array()) {
        log::add('ollamaAgent', 'debug', '[ollamaAgentCmd::execute] logicalId=' . $this->getLogicalId() . ' | options=' . json_encode($_options));
        $eqLogic = $this->getEqLogic();
        switch ($this->getLogicalId()) {
            case 'demande':
                $prompt = $_options['message'] ?? '';
                $imagePath = $_options['title'] ?? '';
                if (empty($prompt) && empty($imagePath)) {
                    log::add('ollamaAgent', 'warning', '[ollamaAgentCmd::execute] Demande vide ignorée.');
                    return;
                }
                if (preg_match('#^\s*/(oublie|nouveau|nouvelle|reset)\b#iu', $prompt)) {
                    $eqLogic->clearHistory();
                    $eqLogic->checkAndUpdateCmd('reponse', 'Nouvelle conversation : j\'ai oublié l\'échange en cours (les notes permanentes restent).');
                    $eqLogic->checkAndUpdateCmd('statut', 'Prêt');
                    return;
                }
                $eqLogic->triggerAsyncRequest($prompt, $imagePath);
                break;
            case 'analyser':
                $eqLogic->triggerAsyncRequest('Analyse cette image.', (string)$eqLogic->getConfiguration('image_source', ''));
                break;
            case 'nouvelle_conversation':
                $eqLogic->clearHistory();
                break;
            case 'rafraichir_sante':
                ollamaAgent::refreshHealth();
                break;
            case 'analyser_sante':
                $consigne = trim((string)($_options['message'] ?? ''));
                if ($consigne === '') {
                    $consigne = trim((string)$eqLogic->getConfiguration('health_prompt', ''));
                }
                if ($consigne === '') {
                    $consigne = 'Fais un bilan court de la santé de Jeedom à partir de cet état.';
                }
                $health = ollamaAgent::refreshHealth();
                $eqLogic->triggerAsyncRequest($consigne . "\n\n" . ollamaAgent::healthText($health, true, true), '', 'Bilan santé : ' . $consigne);
                break;
        }
    }
}
