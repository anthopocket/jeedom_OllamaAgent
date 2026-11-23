<?php
/* Outils « repas » de l'agent Ollama : lecture seule du plugin repas (menu, calories, courses, frigo, recettes, goûts).
 * Les règles restent dans le plugin repas : ici on ne fait que lire et mettre en forme. */

class ollamaAgentRepas {

    const JOURS = [1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.'];

    public static function disponible() {
        return class_exists('repas') && method_exists('repas', 'slot');
    }

    private static function court($txt, $max) {
        $txt = trim(preg_replace('/\s*\R\s*/', ' / ', trim((string)$txt)));
        $txt = preg_replace('/[ \t]+/', ' ', $txt);
        return mb_strlen($txt) > $max ? mb_substr($txt, 0, $max) . '…' : $txt;
    }

    private static function byId() {
        $out = [];
        foreach (repas::recettes() as $r) {
            $out[$r['id']] = $r;
        }
        return $out;
    }

    private static function date($d) {
        $d = trim((string)$d);
        if ($d === '' || $d == 'aujourdhui' || $d == "aujourd'hui") {
            return date('Y-m-d');
        }
        $ts = strtotime($d);
        return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
    }

    public static function label($jour) {
        $ts = strtotime($jour . ' 12:00:00');
        return self::JOURS[(int)date('N', $ts)] . ' ' . date('d/m', $ts);
    }

    private static function nombre($v) {
        return str_replace('.', ',', (string)round((float)$v, 1));
    }

    /** Une ligne par repas : nom, couverts, mangé, calories */
    private static function ligneSlot($s, $avecMoment = true) {
        $txt = ($avecMoment ? ucfirst($s['moment']) . ' : ' : '') . repas::slotNom($s);
        $info = [];
        if ((float)$s['couverts'] <= 0) {
            $info[] = 'personne à la maison';
        } else {
            $info[] = self::nombre($s['couverts']) . ' couvert(s)';
        }
        if (!empty($s['enfant'])) {
            $info[] = 'avec l\'enfant';
        }
        if (method_exists('repas', 'invites') && repas::invites($s)) {
            $info[] = 'invités';
        }
        if (!empty($s['mange'])) {
            $info[] = 'mangé';
        }
        if (!empty($s['pour_demain'])) {
            $info[] = '+' . $s['pour_demain'] . ' portion(s) pour les gamelles';
        }
        if (!empty($s['nutri']['kcal'])) {
            $info[] = $s['nutri']['kcal'] . ' kcal';
        }
        return $txt . ' (' . implode(', ', $info) . ')';
    }

    /** Repas d'un jour, avec ingrédients et préparation */
    public static function jour($date, $details = true) {
        $jour = self::date($date);
        $byId = self::byId();
        $jn = repas::jourNutri($jour, $byId);
        $plan = repas::getPlan();
        $l = ['Repas du ' . self::label($jour) . ' (' . $jour . ') :'];
        $l[] = '- Collation matin : ' . self::court($plan['collation_matin'] ?? '', 200);
        $l[] = '- Collation après-midi : ' . self::court($plan['collation_am'] ?? '', 200);
        foreach (['midi', 'soir'] as $m) {
            $s = $jn[$m];
            $l[] = '- ' . self::ligneSlot($s);
            if ($details && $s['recette'] && empty($s['reste'])) {
                $ings = repas::lignesTexteCmd($s['lignes']);
                if ($ings !== '') {
                    $l[] = '    Ingrédients (pour ' . self::nombre($s['couverts']) . ' couvert(s)) : ' . self::court($ings, 800);
                }
                $etapes = trim((string)($s['recette']['etapes_adaptees'] ?? $s['recette']['etapes'] ?? ''));
                if ($etapes !== '') {
                    $l[] = '    Préparation : ' . self::court($etapes, 1200);
                }
            }
            if (!empty($s['reste']) && !empty($s['source_jour'])) {
                $l[] = '    Gamelle préparée le ' . self::label($s['source_jour']) . ' au soir';
            }
            if ($m == 'soir' && !empty($s['gamelles_pour'])) {
                $l[] = '    Ce soir, préparer aussi les gamelles de : ' . implode(', ', array_map([__CLASS__, 'label'], $s['gamelles_pour']));
            }
        }
        if (!empty($jn['nutri'])) {
            $l[] = 'Total de la journée (collations comprises) : ' . repasNutri::texte($jn['nutri']);
        }
        return implode("\n", $l);
    }

    /** Menu de la semaine (vendredi soir → vendredi midi) */
    public static function semaine($decalage = 0) {
        $sem = repas::semaine((int)$decalage, self::byId());
        $l = ['Menu de la semaine du ' . self::label($sem['debut']) . ' soir au ' . self::label($sem['fin']) . ' midi :'];
        foreach ($sem['slots'] as $s) {
            $l[] = '- ' . $s['label'] . ' ' . self::ligneSlot($s);
        }
        if (!empty($sem['nutri']['moyenne_kcal'])) {
            $l[] = 'Moyenne : ' . $sem['nutri']['moyenne_kcal'] . ' kcal par jour (' . $sem['nutri']['jours_complets'] . ' jours complets)';
        }
        $l[] = 'Œufs : ' . $sem['oeufs'] . ' repas · protéines riches le midi : ' . $sem['riches'] . ' · repas non planifiés : ' . $sem['vides'];
        if (!empty($sem['alertes'])) {
            $l[] = 'Alertes du plugin : ' . implode(' ; ', $sem['alertes']);
        }
        return implode("\n", $l);
    }

    public static function courses($decalage = 0) {
        $sem = repas::semaine((int)$decalage);
        $liste = repasCourses::liste($sem['debut']);
        $total = count($liste['items']);
        if ($total == 0) {
            return 'Aucune liste de courses pour la semaine du ' . self::label($sem['debut']) . ' (pas encore générée).';
        }
        return 'Liste de courses de la semaine du ' . self::label($sem['debut']) . ' : ' . $liste['restants'] . ' article(s) restant(s) sur ' . $total . "\n"
            . str_replace(' | ', "\n", repasCourses::texte($liste));
    }

    public static function frigo() {
        $stock = repas::stock();
        if (empty($stock)) {
            return 'Le frigo, le congélateur et le placard sont vides dans le plugin repas.';
        }
        $par = [];
        $limite = date('Y-m-d', strtotime('+2 days'));
        foreach ($stock as $p) {
            $q = ($p['quantite'] !== null && $p['quantite'] !== '') ? ' ' . (0 + $p['quantite']) . (($p['unite'] ?? '') !== '' && $p['unite'] != 'u' ? ' ' . $p['unite'] : '') : '';
            $dlc = !empty($p['dlc']) ? ' (DLC ' . date('d/m', strtotime($p['dlc'])) . ($p['dlc'] <= $limite ? ', à finir' : '') . ')' : '';
            $par[$p['lieu'] ?: 'frigo'][] = $p['nom'] . $q . $dlc . (!empty($p['reste']) ? ' [reste de repas]' : '');
        }
        $l = [];
        foreach ($par as $lieu => $items) {
            $l[] = ucfirst($lieu) . ' : ' . implode(', ', $items);
        }
        $af = repas::aFinir(2);
        if (!empty($af)) {
            $l[] = 'À finir dans les 2 jours : ' . implode(', ', array_column($af, 'nom'));
        }
        return implode("\n", $l);
    }

    public static function recettes($texte = '', $moment = '') {
        $texte = repas::norm((string)$texte);
        $moment = strtolower(trim((string)$moment));
        $out = [];
        $n = 0;
        foreach (repas::recettes() as $r) {
            if (($r['type'] ?? 'plat') != 'plat') {
                continue;
            }
            if ($moment == 'midi' || $moment == 'soir') {
                if ($r['moment'] != $moment && $r['moment'] != 'tous') {
                    continue;
                }
            }
            if ($texte !== '') {
                $hay = repas::norm($r['nom'] . ' ' . ($r['proteine'] ?? '') . ' ' . implode(' ', array_column($r['ingredients'], 'n')));
                $ok = true;
                foreach (preg_split('/\s+/', $texte) as $w) {
                    if ($w !== '' && strpos($hay, $w) === false) {
                        $ok = false;
                        break;
                    }
                }
                if (!$ok) {
                    continue;
                }
            }
            $n++;
            if (count($out) < 30) {
                $info = [$r['moment'] == 'tous' ? 'midi et soir' : $r['moment']];
                if (!empty($r['proteine'])) {
                    $info[] = $r['proteine'];
                }
                if (!empty($r['temps'])) {
                    $info[] = $r['temps'] . ' min';
                }
                if ((int)$r['avis'] == 1) {
                    $info[] = 'aimée';
                } elseif ((int)$r['avis'] == -1) {
                    $info[] = 'pas aimée';
                }
                if (!empty($r['note'])) {
                    $info[] = 'note ' . $r['note'];
                }
                if (!empty($r['plaisir'])) {
                    $info[] = 'soir plaisir';
                }
                $out[] = '- #' . $r['id'] . ' ' . ($r['nom_adapte'] ?? $r['nom']) . ' (' . implode(', ', $info) . ')';
            }
        }
        if ($n == 0) {
            return 'Aucune recette trouvée dans le carnet.';
        }
        return $n . ' recette(s) dans le carnet' . ($n > 30 ? ' (30 premières, les aimées d\'abord)' : '') . " :\n" . implode("\n", $out);
    }

    public static function recette($id, $moment = '') {
        $r = repas::recette((int)$id);
        if ($r === null) {
            return "Recette #{$id} introuvable.";
        }
        $moment = in_array($moment, ['midi', 'soir'], true) ? $moment : ($r['moment'] == 'soir' ? 'soir' : 'midi');
        $l = ['Recette #' . $r['id'] . ' : ' . ($r['nom_adapte'] ?? $r['nom']) . ' (' . ($r['moment'] == 'tous' ? 'midi et soir' : $r['moment']) . (!empty($r['temps']) ? ', ' . $r['temps'] . ' min' : '') . ')'];
        $l[] = 'Pour 1 portion au plan du ' . $moment . ' : ' . repas::lignesTexteCmd(repas::lignesRepas($r, $moment, 1));
        try {
            $l[] = 'Apports : ' . repasNutri::texte(repasNutri::recette($r, $moment));
        } catch (Throwable $e) {
        }
        $etapes = trim((string)($r['etapes_adaptees'] ?? $r['etapes'] ?? ''));
        if ($etapes !== '') {
            $l[] = 'Préparation : ' . self::court($etapes, 2500);
        }
        if (!empty($r['alertes'])) {
            $l[] = 'Remarques du plugin : ' . implode(' ; ', $r['alertes']);
        }
        if (!empty($r['source'])) {
            $l[] = 'Source : ' . $r['source'];
        }
        return implode("\n", $l);
    }

    public static function gouts() {
        $naime = [];
        $adore = [];
        foreach (repas::gouts() as $g) {
            if ($g['type'] == 'adore') {
                $adore[] = $g['nom'];
            } else {
                $naime[] = $g['nom'] . (!empty($g['remplace']) ? ' (remplacé par ' . $g['remplace'] . ')' : '');
            }
        }
        $l = ["N'aime pas : " . (empty($naime) ? 'rien' : implode(', ', $naime)), 'Adore : ' . (empty($adore) ? 'rien' : implode(', ', $adore))];
        if (class_exists('repasMemoire')) {
            $mem = trim(repasMemoire::texte());
            if ($mem !== '') {
                $l[] = $mem;
            }
        }
        return implode("\n", $l);
    }

    /** Résumé court pour le contexte (modèle sans outils) */
    public static function resume() {
        return self::jour('today', false) . "\n" . self::jour('tomorrow', false);
    }
}
