<?php

namespace ManiaControl\Server;

use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\Files\FileUtil;
use ManiaControl\ManiaControl;

/**
 * Class offering Operations for the Server Directory
 *
 * @author    ManiaControl Team <mail@maniacontrol.com>
 * @copyright 2014 ManiaControl Team
 * @license   http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class Directory implements CallbackListener {
	/**
	 * Private Properties
	 */
	private $maniaControl = null;

	/**
	 * Create new Server Directory Object
	 *
	 * @param ManiaControl $maniaControl
	 */
	public function __construct(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;

		$this->maniaControl->callbackManager->registerCallbackListener(CallbackManager::CB_MP_SERVERSTOP, $this, 'handleServerStopCallback');
	}

	/**
	 * Retrieve the Maps Folder Path
	 *
	 * @return string
	 */
	public function getMapsFolder() {
		return $this->maniaControl->client->getMapsDirectory();
	}

	/**
	 * Retrieve the Skins Folder Path
	 *
	 * @return string
	 */
	public function getSkinsFolder() {
		return $this->maniaControl->client->getSkinsDirectory();
	}

	/**
	 * Handle Server Stop Callback
	 */
	public function handleServerStopCallback() {
		$this->cleanLogsFolder();
		$this->cleanCacheFolder();
	}

	/**
	 * Clean the server logs folder
	 *
	 * @return bool
	 */
	private function cleanLogsFolder() {
		return FileUtil::cleanDirectory($this->getLogsFolder());
	}

	/**
	 * Retrieve the Logs Folder Path
	 *
	 * @return string
	 */
	public function getLogsFolder() {
		return $this->getGameDataFolder() . '..' . DIRECTORY_SEPARATOR . 'Logs' . DIRECTORY_SEPARATOR;
	}

	/**
	 * @return bool
	 */
	private function cleanCacheFolder() {
		return FileUtil::cleanDirectory($this->getCacheFolder(), 50);
	}

	/**
	 * Retrieve the Cache Folder Path
	 *
	 * @return string
	 */
	public function getCacheFolder() {
		return $this->getGameDataFolder() . '..' . DIRECTORY_SEPARATOR . 'CommonData' . DIRECTORY_SEPARATOR . 'Cache' . DIRECTORY_SEPARATOR;
	}

	/**
	 * Retrieve the Game Data Folder Path
	 *
	 * @return string
	 */
	public function getGameDataFolder() {
		return $this->maniaControl->client->gameDataDirectory();
	}
}
