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

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class edisio extends eqLogic {
	/*     * *************************Attributs****************************** */

	/*     * ***********************Methode static*************************** */

	public static function slaveReload() {
		self::stopDeamon();
		self::runDeamon();
	}

	public static function start() {
		$port = config::byKey('port', 'edisio', 'none');
		if ($port != 'none') {
			if ($port == 'auto' || file_exists(jeedom::getUsbMapping($port))) {
				if (!self::deamonRunning()) {
					self::runDeamon();
				}
				message::removeAll('edisio', 'noEdisioComPort');
			} else {
				log::add('edisio', 'error', __('Le port du Edisio est vide ou n\'éxiste pas', __FILE__), 'noEdisioComPort');
			}
		}
	}

	public static function cronDaily() {
		sleep(180);
		$port = config::byKey('port', 'edisio', 'none');
		if ($port == 'auto' || file_exists(jeedom::getUsbMapping($port))) {
			self::runDeamon();
		}
	}

	public static function cron() {
		$port = config::byKey('port', 'edisio', 'none');
		if ($port != 'none') {
			if ($port == 'auto' || file_exists(jeedom::getUsbMapping($port))) {
				if (!self::deamonRunning()) {
					self::runDeamon();
				}
				message::removeAll('edisio', 'noRfxComPort');
			} else {
				log::add('edisio', 'error', __('Le port du EDISIO est vide ou n\'existe pas', __FILE__), 'noRfxComPort');
			}
		}
	}

	public static function createFromDef($_def) {
		if (config::byKey('autoDiscoverEqLogic', 'edisio') == 0) {
			return false;
		}
		$banId = explode(' ', config::byKey('banRfxId', 'edisio'));
		if (in_array($_def['id'], $banId)) {
			return false;
		}
		if (!isset($_def['mid']) || !isset($_def['id'])) {
			log::add('edisio', 'error', 'Information manquante pour ajouter l\'équipement : ' . print_r($_def, true));
			return false;
		}

		$edisio = edisio::byLogicalId($_def['id'], 'edisio');
		if (!is_object($edisio)) {
			$eqLogic = new edisio();
			$eqLogic->setName($_def['id']);
		}
		$eqLogic->setLogicalId($_def['id']);
		$eqLogic->setEqType_name('edisio');
		$eqLogic->setIsEnable(1);
		$eqLogic->setIsVisible(1);
		$eqLogic->setConfiguration('device', $_def['mid']);
		$eqLogic->save();
		$eqLogic->applyModuleConfiguration();
		return $eqLogic;
	}

	public static function devicesParameters($_device = '') {
		$path = dirname(__FILE__) . '/../config/devices';
		if (isset($_device) && $_device != '') {
			$files = ls($path, $_device . '.json', false, array('files', 'quiet'));
			if (count($files) == 1) {
				try {
					$content = file_get_contents($path . '/' . $files[0]);
					if (is_json($content)) {
						$deviceConfiguration = json_decode($content, true);
						return $deviceConfiguration[$_device];
					}
					return array();
				} catch (Exception $e) {
					return array();
				}
			}
		}
		$files = ls($path, '*.json', false, array('files', 'quiet'));
		$return = array();
		foreach ($files as $file) {
			try {
				$content = file_get_contents($path . '/' . $file);
				if (is_json($content)) {
					$return += json_decode($content, true);
				}
			} catch (Exception $e) {

			}
		}

		if (isset($_device) && $_device != '') {
			if (isset($return[$_device])) {
				return $return[$_device];
			}
			return array();
		}
		return $return;
	}

	public static function runDeamon($_debug = false) {
		self::stopDeamon();
		$port = config::byKey('port', 'edisio');
		if ($port != 'auto') {
			$port = jeedom::getUsbMapping($port);
			if (@!file_exists($port)) {
				throw new Exception(__('Le port : ', __FILE__) . print_r($port, true) . __(' n\'éxiste pas', __FILE__));
			}
		}
		$edisio_path = realpath(dirname(__FILE__) . '/../../ressources/edisiocmd');

		if (file_exists($edisio_path . '/config.xml')) {
			unlink($edisio_path . '/config.xml');
		}
		$enable_logging = (config::byKey('enableLogging', 'edisio', 0) == 1) ? 'yes' : 'no';
		if (file_exists(log::getPathToLog('edisiocmd') . '.message')) {
			unlink(log::getPathToLog('edisiocmd') . '.message');
		}
		if (!file_exists(log::getPathToLog('edisiocmd') . '.message')) {
			touch(log::getPathToLog('edisiocmd') . '.message');
		}
		$replace_config = array(
			'#device#' => $port,
			'#socketport#' => config::byKey('socketport', 'edisio', 55005),
			'#log_path#' => log::getPathToLog('edisiocmd'),
			'#enable_log#' => $enable_logging,
			'#pid_path#' => '/tmp/edisio.pid',
			'#repeat_message_time#' => config::byKey('repeatMessageTime', 'edisio', 9999999) * 60,
		);
		if (config::byKey('jeeNetwork::mode') == 'slave') {
			$replace_config['#trigger#'] = '/bin/bash ' . $edisio_path . '/remote.sh';
			$remote = str_replace(array('#ip_master#', '#apikey#'), array(config::byKey('jeeNetwork::master::ip'), config::byKey('jeeNetwork::master::apikey')), file_get_contents($edisio_path . '/remote_tmpl.sh'));
			file_put_contents($edisio_path . '/remote.sh', $remote);
			chmod($edisio_path . '/remote.sh', 0775);
			$replace_config['#sockethost#'] = config::byKey('internalAddr', 'core', '127.0.0.1');
		} else {
			$replace_config['#trigger#'] = "/usr/bin/php " . $edisio_path . "/../../core/php/jeeEdisio.php";
			$replace_config['#sockethost#'] = '127.0.0.1';
		}
		if (config::byKey('processRepeatMessage', 'edisio', 0) == 1) {
			$replace_config['#process_repeat_message#'] = 'yes';
		} else {
			$replace_config['#process_repeat_message#'] = 'no';
		}
		$config = template_replace($replace_config, file_get_contents($edisio_path . '/config_tmpl.xml'));
		file_put_contents($edisio_path . '/config.xml', $config);
		chmod($edisio_path . '/config.xml', 0777);
		if (!file_exists($edisio_path . '/config.xml')) {
			file_put_contents($edisio_path . '/config.xml', $config);
			chmod($edisio_path . '/config.xml', 0777);
		}
		if (!file_exists($edisio_path . '/config.xml')) {
			throw new Exception(__('Impossible de créer : ', __FILE__) . $edisio_path . '/config.xml');
		}
		$cmd = '/usr/bin/python ' . $edisio_path . '/edisiocmd.py -l -o ' . $edisio_path . '/config.xml';
		if ($_debug) {
			$cmd .= ' -D';
		}
		log::add('edisiocmd', 'info', 'Lancement démon edisiocmd : ' . $cmd);
		$result = exec($cmd . ' >> ' . log::getPathToLog('edisiocmd') . ' 2>&1 &');
		if (strpos(strtolower($result), 'error') !== false || strpos(strtolower($result), 'traceback') !== false) {
			log::add('edisio', 'error', $result);
			return false;
		}

		$i = 0;
		while ($i < 30) {
			if (self::deamonRunning()) {
				break;
			}
			sleep(1);
			$i++;
		}
		if ($i >= 30) {
			log::add('edisio', 'error', 'Impossible de lancer le démon EDISIO, vérifiez le log edisiocmd', 'unableStartDeamon');
			return false;
		}
		message::removeAll('edisio', 'unableStartDeamon');
		log::add('edisio', 'info', 'Démon EDISIO lancé');
		return true;
	}

	public static function deamonRunning() {
		$pid_file = '/tmp/edisio.pid';
		if (!file_exists($pid_file)) {
			return false;
		}
		$pid = trim(file_get_contents($pid_file));
		if (posix_getsid($pid)) {
			return true;
		}
		unlink($pid_file);
		return false;
	}

	public static function stopDeamon() {
		$pid_file = '/tmp/edisio.pid';
		if (file_exists($pid_file)) {
			$pid = intval(trim(file_get_contents($pid_file)));
			posix_kill($pid, 15);
			if (self::deamonRunning()) {
				sleep(1);
				posix_kill($pid, 9);
			}
			if (self::deamonRunning()) {
				sleep(1);
				exec('kill -9 ' . $pid . ' > /dev/null 2>&1');
			}
		}
		$edisio_path = realpath(dirname(__FILE__) . '/../../ressources/edisiocmd/edisiocmd.py');
		exec('fuser -k ' . config::byKey('socketport', 'edisio', 55000) . '/tcp > /dev/null 2>&1');
		exec('sudo fuser -k ' . config::byKey('socketport', 'edisio', 55000) . '/tcp > /dev/null 2>&1');
		return self::deamonRunning();
	}

/*     * *************************MARKET**************************************** */

	public static function shareOnMarket(&$market) {
		$moduleFile = dirname(__FILE__) . '/../config/devices/' . $market->getLogicalId() . '.json';
		if (!file_exists($moduleFile)) {
			throw new Exception('Impossible de trouver le fichier de conf ' . $moduleFile);
		}
		$tmp = dirname(__FILE__) . '/../../../../tmp/' . $market->getLogicalId() . '.zip';
		if (file_exists($tmp)) {
			if (!unlink($tmp)) {
				throw new Exception(__('Impossible de supprimer : ', __FILE__) . $tmp . __('. Vérifiez les droits', __FILE__));
			}
		}
		if (!create_zip($moduleFile, $tmp)) {
			throw new Exception(__('Echec de création du zip. Répertoire source : ', __FILE__) . $moduleFile . __(' / Répertoire cible : ', __FILE__) . $tmp);
		}
		return $tmp;
	}

	public static function getFromMarket(&$market, $_path) {
		$cibDir = dirname(__FILE__) . '/../config/devices/';
		if (!file_exists($cibDir)) {
			throw new Exception(__('Impossible d\'installer la configuration du module le repertoire n\éxiste pas : ', __FILE__) . $cibDir);
		}
		$zip = new ZipArchive;
		if ($zip->open($_path) === TRUE) {
			$zip->extractTo($cibDir . '/');
			$zip->close();
		} else {
			throw new Exception('Impossible de décompresser le zip : ' . $_path);
		}
		$moduleFile = dirname(__FILE__) . '/../config/devices/' . $market->getLogicalId() . '.json';
		if (!file_exists($moduleFile)) {
			throw new Exception(__('Echec de l\'installation. Impossible de trouver le module ', __FILE__) . $moduleFile);
		}

		foreach (eqLogic::byTypeAndSearhConfiguration('edisio', $market->getLogicalId()) as $eqLogic) {
			$eqLogic->applyModuleConfiguration();
		}
	}

	public static function removeFromMarket(&$market) {
		$moduleFile = dirname(__FILE__) . '/../config/devices/' . $market->getLogicalId() . '.json';
		if (!file_exists($moduleFile)) {
			throw new Exception(__('Echec lors de la suppression. Impossible de trouver le module ', __FILE__) . $moduleFile);
		}
		if (!unlink($moduleFile)) {
			throw new Exception(__('Impossible de supprimer le fichier :  ', __FILE__) . $moduleFile . '. Veuillez vérifier les droits');
		}
	}

	public static function listMarketObject() {
		$return = array();
		foreach (edisio::devicesParameters() as $logical_id => $name) {
			$return[] = $logical_id;
		}
		return $return;
	}

/*     * *********************Methode d'instance************************* */

	public function preInsert() {
		if ($this->getLogicalId() == '') {
			for ($i = 0; $i < 20; $i++) {
				$logicalId = strtoupper(str_pad(dechex(mt_rand()), 8, '0', STR_PAD_LEFT));
				$result = eqLogic::byLogicalId($logicalId, 'edisio');
				if (!is_object($result)) {
					$this->setLogicalId($logicalId);
					break;
				}
			}
		}
	}

	public function postSave() {
		if ($this->getConfiguration('applyDevice') != $this->getConfiguration('device')) {
			$this->applyModuleConfiguration();
		}
	}

	public function applyModuleConfiguration() {
		$this->setConfiguration('applyDevice', $this->getConfiguration('device'));
		$this->save();
		if ($this->getConfiguration('device') == '') {
			return true;
		}
		$device = self::devicesParameters($this->getConfiguration('device'));
		if (!is_array($device)) {
			return true;
		}
		if (isset($device['configuration'])) {
			foreach ($device['configuration'] as $key => $value) {
				$this->setConfiguration($key, $value);
			}
		}
		if (isset($device['category'])) {
			foreach ($device['category'] as $key => $value) {
				$this->setCategory($key, $value);
			}
		}
		$cmd_order = 0;
		$link_cmds = array();
		$link_actions = array();
		if (isset($device['commands'])) {
			foreach ($device['commands'] as $command) {
				$cmd = null;
				foreach ($this->getCmd() as $liste_cmd) {
					if ($liste_cmd->getLogicalId() == $command['logicalId']) {
						$cmd = $liste_cmd;
						break;
					}
				}
				try {
					if ($cmd == null || !is_object($cmd)) {
						$cmd = new edisioCmd();
						$cmd->setOrder($cmd_order);
						$cmd->setEqLogic_id($this->getId());
					} else {
						$command['name'] = $cmd->getName();
					}
					utils::a2o($cmd, $command);
					$cmd->save();
					if (isset($command['value'])) {
						$link_cmds[$cmd->getId()] = $command['value'];
					}
					if (isset($command['configuration']) && isset($command['configuration']['updateCmdId'])) {
						$link_actions[$cmd->getId()] = $command['configuration']['updateCmdId'];
					}
					$cmd_order++;
				} catch (Exception $exc) {

				}
			}
		}

		if (count($link_cmds) > 0) {
			foreach ($this->getCmd() as $eqLogic_cmd) {
				foreach ($link_cmds as $cmd_id => $link_cmd) {
					if ($link_cmd == $eqLogic_cmd->getName()) {
						$cmd = cmd::byId($cmd_id);
						if (is_object($cmd)) {
							$cmd->setValue($eqLogic_cmd->getId());
							$cmd->save();
						}
					}
				}
			}
		}
		if (count($link_actions) > 0) {
			foreach ($this->getCmd() as $eqLogic_cmd) {
				foreach ($link_actions as $cmd_id => $link_action) {
					if ($link_action == $eqLogic_cmd->getName()) {
						$cmd = cmd::byId($cmd_id);
						if (is_object($cmd)) {
							$cmd->setConfiguration('updateCmdId', $eqLogic_cmd->getId());
							$cmd->save();
						}
					}
				}
			}
		}

		$this->save();
	}

/*     * **********************Getteur Setteur*************************** */
}

class edisioCmd extends cmd {
	/*     * *************************Attributs****************************** */

	/*     * ***********************Methode static*************************** */

	/*     * *********************Methode d'instance************************* */

	public function execute($_options = null) {
		log::add('edisio', 'debug', 'Début fonction d\'envoi commandes edisio');
		if ($this->getType() == 'action') {
			$logicalId = $this->getEqlogic()->getLogicalId();
			$value = trim(str_replace("#ID#", $logicalId, $this->getLogicalId()));
			switch ($this->getSubType()) {
				case 'slider':
					$value = str_replace('#slider#', strtoupper(dechex(intval($_options['slider']))), $value);
					break;
				case 'color':
					$value = str_replace('#color#', $_options['color'], $value);
					break;
			}
			$values = explode('&&', $value);
			if (config::byKey('jeeNetwork::mode') == 'master') {
				foreach (jeeNetwork::byPlugin('edisio') as $jeeNetwork) {
					foreach ($values as $value) {
						$socket = socket_create(AF_INET, SOCK_STREAM, 0);
						socket_connect($socket, $jeeNetwork->getRealIp(), config::byKey('socketport', 'edisio', 55000));
						socket_write($socket, trim($value), strlen(trim($value)));
						socket_close($socket);
					}
					sleep(1);
				}
			}
			if (config::byKey('port', 'edisio', 'none') != 'none') {
				foreach ($values as $value) {
					$socket = socket_create(AF_INET, SOCK_STREAM, 0);
					socket_connect($socket, '127.0.0.1', config::byKey('socketport', 'edisio', 55000));
					socket_write($socket, trim($value), strlen(trim($value)));
					socket_close($socket);
				}
			}
			log::add('edisio', 'debug', 'Début fonction d\'envoi commandes edisio');
		}
	}

	/*     * **********************Getteur Setteur*************************** */
}

?>
