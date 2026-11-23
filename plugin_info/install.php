<?php
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../core/class/ollamaAgent.class.php';

function ollamaAgent_install() {
    ollamaAgent::ensureTable();
}

function ollamaAgent_update() {
    ollamaAgent::ensureTable();
    // v1.7 gardait la conversation dans le cache : on la reprend une fois en base
    foreach (eqLogic::byType('ollamaAgent') as $eq) {
        $key = 'ollamaAgent::conversation::' . $eq->getId();
        $old = json_decode((string)cache::byKey($key)->getValue('[]'), true);
        if (is_array($old) && !empty($old)) {
            foreach ($old as $ex) {
                DB::Prepare('INSERT INTO `ollamaAgent_message` (`eqLogic_id`, `datetime`, `user`, `assistant`) VALUES (:id, :dt, :u, :a)', [
                    'id' => $eq->getId(),
                    'dt' => date('Y-m-d H:i:s', (int)($ex['ts'] ?? time())),
                    'u' => (string)($ex['user'] ?? ''),
                    'a' => (string)($ex['assistant'] ?? ''),
                ], DB::FETCH_TYPE_ROW);
            }
        }
        cache::delete($key);
        $eq->save();
    }
}

function ollamaAgent_remove() {
    DB::Prepare('DROP TABLE IF EXISTS `ollamaAgent_message`', [], DB::FETCH_TYPE_ROW);
    foreach (eqLogic::byType('ollamaAgent') as $eq) {
        config::remove('notes_' . $eq->getId(), 'ollamaAgent');
    }
}
