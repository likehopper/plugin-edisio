<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* Fonctions sans dépendance au core Jeedom (testées par tests/php). */

/**
 * Construit les trames à envoyer pour une commande action.
 * $_template : logicalId de la commande (#ID#, #GROUP#, #slider#, #color#, trames séparées par &&)
 * Retourne la liste des trames hexadécimales.
 */
function edisio_build_frames($_template, $_id, $_group, $_subType, $_options = array()) {
	$value = trim(str_replace('#ID#', $_id, $_template));
	$group = ($_group == '') ? '01' : (string) $_group;
	if (strlen($group) == 1) {
		$group = '0' . $group;
	}
	$value = trim(str_replace('#GROUP#', $group, $value));
	switch ($_subType) {
		case 'slider':
			/* Niveau 0..100 : hors bornes, dechex() produisait une trame invalide (3 chiffres, ou négatif). */
			$level = max(0, min(100, intval($_options['slider'])));
			$hexvalue = strtoupper(dechex($level));
			if (strlen($hexvalue) < 2) {
				$hexvalue = '0' . $hexvalue;
			}
			if ($hexvalue != '00') {
				$value = str_replace('#slider#', $hexvalue, $value);
			} else {
				$value = str_replace('04#slider#', '02', $value);
			}
			break;
		case 'color':
			$value = str_replace('#color#', $_options['color'], $value);
			break;
	}
	return explode('&&', trim($value, '$'));
}

/**
 * Définition d'un modèle, lue dans <$_devicesDir>/<code>/<code>.json.
 * Retourne array() si le code est invalide ou inconnu.
 */
function edisio_device_definition($_devicesDir, $_device) {
	if (!preg_match('/^[0-9A-Fa-f]+$/', (string) $_device)) {
		return array();
	}
	$file = $_devicesDir . '/' . $_device . '/' . $_device . '.json';
	$content = file_exists($file) ? json_decode(file_get_contents($file), true) : null;
	return (is_array($content) && isset($content[$_device]) && is_array($content[$_device])) ? $content[$_device] : array();
}
