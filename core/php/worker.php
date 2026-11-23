<?php
if (php_sapi_name() !== 'cli') {
    die('Accès interdit');
}

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../class/ollamaAgent.class.php';

$eqLogicId = $argv[1] ?? null;
$prompt = isset($argv[2]) ? base64_decode($argv[2]) : '';
$imagePath = isset($argv[3]) ? base64_decode($argv[3]) : '';
$memoryPrompt = isset($argv[4]) ? base64_decode($argv[4]) : null;

log::add('ollamaAgent', 'debug', '[Worker] Démarrage eqLogicId=' . var_export($eqLogicId, true) . ' | prompt=' . mb_substr($prompt, 0, 120) . ' | img=' . $imagePath);

$context = isset($argv[5]) ? json_decode(base64_decode($argv[5]), true) : [];
if (!is_array($context)) {
    $context = [];
}

// Message reçu par la messagerie : tri vers le bon agent puis réponse à l'expéditeur
if ($eqLogicId === 'route') {
    try {
        ollamaAgentMessaging::handleIncoming($prompt, $context);
    } catch (Throwable $e) {
        log::add('ollamaAgent', 'error', '[Worker] Messagerie : ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
    }
    exit(0);
}

if (!$eqLogicId) {
    log::add('ollamaAgent', 'error', '[Worker] eqLogicId manquant, arrêt.');
    exit(1);
}

$eqLogic = ollamaAgent::byId($eqLogicId);
if (!is_object($eqLogic)) {
    log::add('ollamaAgent', 'error', '[Worker] Équipement introuvable pour ID=' . $eqLogicId);
    exit(1);
}

try {
    $eqLogic->processOllamaRequest($prompt, $imagePath, $memoryPrompt);
    log::add('ollamaAgent', 'debug', '[Worker] Terminé pour eqLogicId=' . $eqLogicId);
} catch (Throwable $e) {
    log::add('ollamaAgent', 'error', '[Worker] Exception : ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
    $eqLogic->checkAndUpdateCmd('reponse', 'Erreur Worker : ' . $e->getMessage());
    $eqLogic->checkAndUpdateCmd('statut', 'Erreur');
}
