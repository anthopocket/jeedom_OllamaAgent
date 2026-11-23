<?php
try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');
    require_once dirname(__FILE__) . '/../class/ollamaAgent.class.php';

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    if (init('action') == 'testConnection') {
        $url = init('url');
        if (empty($url)) {
            $url = config::byKey('ollama_url', 'ollamaAgent', 'http://127.0.0.1:11434');
        }
        $url = rtrim($url, '/');

        log::add('ollamaAgent', 'debug', 'Test de connexion vers ' . $url . '/api/tags');

        $ch = curl_init($url . '/api/tags');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            log::add('ollamaAgent', 'error', 'Test connexion échoué : ' . $curlError);
            ajax::error('Erreur de connexion : ' . $curlError);
        }
        if ($httpCode !== 200) {
            log::add('ollamaAgent', 'error', 'Test connexion code HTTP ' . $httpCode);
            ajax::error('Ollama a répondu avec le code HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);
        $models = [];
        if (isset($data['models']) && is_array($data['models'])) {
            foreach ($data['models'] as $m) {
                $models[] = $m['name'];
            }
        }
        $modelsList = empty($models) ? 'Aucun modèle installé' : implode(', ', $models);
        log::add('ollamaAgent', 'info', 'Test connexion OK. Modèles : ' . $modelsList);
        ajax::success('Connexion réussie ! Modèles disponibles : <b>' . htmlspecialchars($modelsList) . '</b>');
    }

    if (in_array(init('action'), ['getMemory', 'saveNotes', 'clearConversation'])) {
        $eqLogic = ollamaAgent::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'ollamaAgent') {
            throw new Exception(__('Agent introuvable (sauvegarde-le d\'abord)', __FILE__));
        }
        if (init('action') == 'saveNotes') {
            $eqLogic->setNotes(init('notes'));
        } elseif (init('action') == 'clearConversation') {
            $eqLogic->clearHistory();
        }
        $lines = [];
        foreach ($eqLogic->getHistory() as $ex) {
            $lines[] = '[' . date('d/m H:i', $ex['ts']) . '] Toi : ' . $ex['user'] . "\n" . 'Agent : ' . $ex['assistant'];
        }
        ajax::success([
            'notes' => implode("\n", $eqLogic->getNotes()),
            'conversation' => empty($lines) ? 'Aucune conversation en cours.' : implode("\n\n", $lines),
        ]);
    }

    if (init('action') == 'listCmds') {
        $plugins = json_decode((string)init('plugins', '[]'), true);
        $ids = json_decode((string)init('ids', '[]'), true);
        ajax::success(ollamaAgent::listCmdsForUi(is_array($plugins) ? $plugins : [], is_array($ids) ? $ids : []));
    }

    if (init('action') == 'healthNow') {
        $health = ollamaAgent::refreshHealth();
        ajax::success(ollamaAgent::healthText($health, true, true));
    }

    throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));
} catch (Exception $e) {
    log::add('ollamaAgent', 'error', 'Erreur AJAX : ' . $e->getMessage());
    ajax::error(displayException($e), $e->getCode());
}
