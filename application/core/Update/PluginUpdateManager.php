<?php

namespace ManiaControl\Update;

use ManiaControl\Admin\AuthenticationManager;
use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\Callbacks\TimerListener;
use ManiaControl\Commands\CommandListener;
use ManiaControl\Files\FileUtil;
use ManiaControl\ManiaControl;
use ManiaControl\Players\Player;
use ManiaControl\Players\PlayerManager;
use ManiaControl\Plugins\Plugin;
use ManiaControl\Plugins\PluginInstallMenu;
use ManiaControl\Plugins\PluginMenu;
use ManiaControl\Plugins\PluginManager;
use ManiaControl\Files\BackupUtil;

/**
 * Manager checking for ManiaControl Plugin Updates
 * 
 * @author ManiaControl Team
 * @copyright ManiaControl Copyright © 2014 ManiaControl Team
 * @license http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class PluginUpdateManager implements CallbackListener, CommandListener, TimerListener {
	/*
	 * Private Properties
	 */
	private $maniaControl = null;

	/**
	 * Create a new Plugin Update Manager
	 * 
	 * @param ManiaControl $maniaControl
	 */
	public function __construct(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;
		
		// Register for callbacks
		$this->maniaControl->callbackManager->registerCallbackListener(CallbackManager::CB_MP_PLAYERMANIALINKPAGEANSWER, $this, 'handleManialinkPageAnswer');
		
		// Register for chat commands
		$this->maniaControl->commandManager->registerCommandListener('checkpluginsupdate', $this, 'handle_CheckPluginsUpdate', true, 'Check for Plugin Updates.');
		$this->maniaControl->commandManager->registerCommandListener('pluginsupdate', $this, 'handle_PluginsUpdate', true, 'Perform the Plugin Updates.');
	}

	/**
	 * Handle //checkpluginsupdate command
	 * 
	 * @param array $chatCallback
	 * @param Player $player
	 */
	public function handle_CheckPluginsUpdate(array $chatCallback, Player $player) {
		if (!$this->maniaControl->authenticationManager->checkPermission($player, UpdateManager::SETTING_PERMISSION_UPDATECHECK)) {
			$this->maniaControl->authenticationManager->sendNotAllowed($player);
			return;
		}
		
		$this->checkPluginsUpdate($player);
	}

	/**
	 * Handle //pluginsupdate command
	 * 
	 * @param array $chatCallback
	 * @param Player $player
	 */
	public function handle_PluginsUpdate(array $chatCallback, Player $player) {
		if (!$this->maniaControl->authenticationManager->checkPermission($player, UpdateManager::SETTING_PERMISSION_UPDATE)) {
			$this->maniaControl->authenticationManager->sendNotAllowed($player);
			return;
		}
		
		$this->performPluginsUpdate($player);
	}

	/**
	 * Handle PlayerManialinkPageAnswer callback
	 * 
	 * @param array $callback
	 */
	public function handleManialinkPageAnswer(array $callback) {
		$actionId = $callback[1][2];
		$update = (strpos($actionId, PluginMenu::ACTION_PREFIX_UPDATEPLUGIN) === 0);
		$install = (strpos($actionId, PluginInstallMenu::ACTION_PREFIX_INSTALLPLUGIN) === 0);
		
		$login = $callback[1][1];
		$player = $this->maniaControl->playerManager->getPlayer($login);
		
		if ($update) {
			$pluginClass = substr($actionId, strlen(PluginMenu::ACTION_PREFIX_UPDATEPLUGIN));
			if ($pluginClass == 'All') {
				$this->checkPluginsUpdate($player);
			}
			else {
				$newUpdate = $this->getPluginUpdate($pluginClass);
				if ($newUpdate) {
					$newUpdate->pluginClass = $pluginClass;
					$this->updatePlugin($newUpdate, $player, true);
				}
			}
		}
		
		if ($install) {
			$pluginId = substr($actionId, strlen(PluginInstallMenu::ACTION_PREFIX_INSTALLPLUGIN));
			
			$url = ManiaControl::URL_WEBSERVICE . 'plugins?id=' . $pluginId;
			$dataJson = FileUtil::loadFile($url);
			$pluginVersions = json_decode($dataJson);
			if ($pluginVersions && isset($pluginVersions[0])) {
				$pluginData = $pluginVersions[0];
				$this->installPlugin($pluginData, $player, true);
			}
		}
	}

	/**
	 * Check if there are Outdated Plugins installed
	 * 
	 * @param Player $player
	 */
	public function checkPluginsUpdate(Player $player = null) {
		$message = 'Checking Plugins for newer Versions...';
		if ($player) {
			$this->maniaControl->chat->sendInformation($message, $player);
		}
		$this->maniaControl->log($message);
		
		$self = $this;
		$this->maniaControl->pluginManager->fetchPluginList(function ($data, $error) use(&$self, &$player) {
			
			if (!$data || $error) {
				$message = 'Error while checking Plugins for newer Versions!';
				if ($player) {
					$self->maniaControl->chat->sendError($message, $player);
				}
				$self->maniaControl->log($message);
				return;
			}
			
			$pluginsData = $self->parsePluginsData($data);
			$pluginClasses = $self->maniaControl->pluginManager->getPluginClasses();
			$pluginUpdates = array();
			
			foreach ($pluginClasses as $pluginClass) {
				/**
				 *
				 * @var Plugin $pluginClass
				 */
				$pluginId = $pluginClass::getId();
				if (!isset($pluginsData[$pluginId])) {
					continue;
				}
				$pluginData = $pluginsData[$pluginId];
				$pluginVersion = $pluginClass::getVersion();
				if ($pluginData->isNewerThan($pluginVersion)) {
					$pluginUpdates[$pluginId] = $pluginData;
					$message = "There is an Update of '{$pluginData->pluginName}' available! ('{$pluginClass}' - Version {$pluginData->version})";
					if ($player) {
						$self->maniaControl->chat->sendSuccess($message, $player);
					}
					$self->maniaControl->log($message);
				}
			}
			
			if (empty($pluginUpdates)) {
				$message = 'Plugins Update Check completed: All Plugins are up-to-date!';
				if ($player) {
					$self->maniaControl->chat->sendSuccess($message, $player);
				}
				$self->maniaControl->log($message);
			}
			else {
				$updatesCount = count($pluginUpdates);
				$message = "Plugins Update Check completed: There are {$updatesCount} Updates available!";
				if ($player) {
					$self->maniaControl->chat->sendSuccess($message, $player);
				}
				$self->maniaControl->log($message);
			}
		});
	}

	/**
	 * Perform an Update of all outdated Plugins
	 * 
	 * @param Player $player
	 */
	public function performPluginsUpdate(Player $player = null) {
		$pluginsUpdates = $this->getPluginsUpdates();
		if (empty($pluginsUpdates)) {
			$message = 'There are no Plugin Updates available!';
			if ($player) {
				$this->maniaControl->chat->sendInformation($message, $player);
			}
			$this->maniaControl->log($message);
			return;
		}
		
		$message = "Starting Plugins Updating... You'll need to restart ManiaControl when it's finished!";
		if ($player) {
			$this->maniaControl->chat->sendInformation($message, $player);
		}
		$this->maniaControl->log($message);
		
		$performBackup = $this->maniaControl->settingManager->getSetting($this->maniaControl->updateManager, UpdateManager::SETTING_PERFORM_BACKUPS);
		if ($performBackup && !BackupUtil::performPluginsBackup()) {
			$message = 'Creating Backup before Plugins Update failed!';
			if ($player) {
				$this->maniaControl->chat->sendError($message, $player);
			}
			$this->maniaControl->log($message);
		}
		
		foreach ($pluginsUpdates as $pluginUpdateData) {
			$self->installPlugin($pluginUpdateData, $player, true);
		}
	}

	/**
	 * Check given Plugin Class for Update
	 * 
	 * @param string $pluginClass
	 * @return mixed
	 */
	public function getPluginUpdate($pluginClass) {
		$pluginClass = PluginManager::getPluginClass($pluginClass);
		/**
		 *
		 * @var Plugin $pluginClass
		 */
		$pluginId = $pluginClass::getId();
		$url = ManiaControl::URL_WEBSERVICE . 'plugins?id=' . $pluginId;
		$dataJson = FileUtil::loadFile($url);
		$pluginVersions = json_decode($dataJson);
		if (!$pluginVersions || !isset($pluginVersions[0])) {
			return false;
		}
		$pluginUpdateData = new PluginUpdateData($pluginVersions[0]);
		$pluginVersion = $pluginClass::getVersion();
		if ($pluginUpdateData->isNewerThan($pluginVersion)) {
			return $pluginUpdateData;
		}
		return false;
	}

	/**
	 * Get an Array of Plugin Update Data from the given Web Service Result
	 * 
	 * @param mixed $webServiceResult
	 * @return mixed
	 */
	public function parsePluginsData($webServiceResult) {
		if (!$webServiceResult || is_array($webServiceResult)) {
			return false;
		}
		$pluginsData = array();
		foreach ($webServiceResult as $pluginResult) {
			$pluginData = new PluginUpdateData($pluginResult);
			$pluginsData[$pluginData->pluginId] = $pluginData;
		}
		return $pluginsData;
	}

	/**
	 * Check for Plugin Updates
	 * 
	 * @return mixed
	 */
	public function getPluginsUpdates() {
		$url = ManiaControl::URL_WEBSERVICE . 'plugins';
		$dataJson = FileUtil::loadFile($url);
		$pluginData = json_decode($dataJson);
		if (!$pluginData || empty($pluginData)) {
			return false;
		}
		
		$pluginsUpdates = $this->parsePluginsData($pluginData);
		
		$updates = array();
		$pluginClasses = $this->maniaControl->pluginManager->getPluginClasses();
		foreach ($pluginClasses as $pluginClass) {
			/**
			 *
			 * @var Plugin $pluginClass
			 */
			$pluginId = $pluginClass::getId();
			if (isset($pluginsUpdates[$pluginId])) {
				$pluginUpdateData = $pluginsUpdates[$pluginId];
				$pluginVersion = $pluginClass::getVersion();
				if ($pluginUpdateData->isNewerThan($pluginVersion)) {
					$updates[$pluginId] = $pluginUpdateData;
				}
			}
		}
		
		if (empty($updates)) {
			return false;
		}
		return $updates;
	}

	/**
	 * Load the given Plugin Update Data
	 * 
	 * @param PluginUpdateData $pluginUpdateData
	 * @param Player $player
	 */
	private function installPlugin(PluginUpdateData $pluginUpdateData, Player $player = null, $update = false) {
		$self = $this;
		$this->maniaControl->fileReader->loadFile($pluginData->currentVersion->url, function ($updateFileContent, $error) use(&$self, &$pluginUpdateData, &$player, &$update) {
			$actionNoun = ($update ? 'Update' : 'Install');
			$actionVerb = ($update ? 'Updating' : 'Installing');
			$actionVerbDone = ($update ? 'updated' : 'installed');
			
			$message = "Now {$actionVerb} '{$pluginUpdateData->pluginName}'...";
			if ($player) {
				$self->maniaControl->chat->sendInformation($message, $player);
			}
			$self->maniaControl->log($message);
			
			$tempDir = FileUtil::getTempFolder();
			$updateFileName = $tempDir . $pluginUpdateData->zipfile;
			
			$bytes = file_put_contents($updateFileName, $updateFileContent);
			if (!$bytes || $bytes <= 0) {
				$message = "Plugin {$actionNoun} failed: Couldn't save {$actionNoun} Zip!";
				if ($player) {
					$self->maniaControl->chat->sendError($message, $player);
				}
				trigger_error($message);
				return;
			}
			
			$zip = new \ZipArchive();
			$result = $zip->open($updateFileName);
			if ($result !== true) {
				$message = "Plugin {$actionNoun} failed: Couldn't open {$actionNoun} Zip! ({$result})";
				if ($player) {
					$self->maniaControl->chat->sendError($message, $player);
				}
				trigger_error($message);
				return;
			}
			
			$zip->extractTo(ManiaControlDir . '/plugins/');
			$zip->close();
			unlink($updateFileName);
			FileUtil::removeTempFolder();
			
			$message = "Successfully {$actionVerbDone} '{$pluginUpdateData->pluginName}'!";
			if ($player) {
				$self->maniaControl->chat->sendSuccess($message, $player);
			}
			$self->maniaControl->log($message);
			
			if (!$update) {
				$newPluginClasses = $self->maniaControl->pluginManager->loadPlugins();
				if (empty($newPluginClasses)) {
					$message = "Loading fresh installed Plugin '{$pluginUpdateData->pluginName}' failed!";
					if ($player) {
						$self->maniaControl->chat->sendError($message, $player);
					}
					$self->maniaControl->log($message);
				}
				else {
					$message = "Successfully loaded fresh installed Plugin '{$pluginUpdateData->pluginName}'!";
					if ($player) {
						$self->maniaControl->chat->sendSuccess($message, $player);
					}
					$self->maniaControl->log($message);
					
					$menuId = $self->maniaControl->configurator->getMenuId('Install Plugins');
					$self->maniaControl->configurator->reopenMenu($player, $menuId);
				}
			}
		});
	}
}