<?php

namespace ManiaControl\Update;

/**
 * Plugin Update Data Model Class
 *
 * @author    ManiaControl Team <mail@maniacontrol.com>
 * @copyright 2014-2015 ManiaControl Team
 * @license   http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class PluginUpdateData {
	/*
	 * Public properties
	 */
	public $pluginId = null;
	public $pluginName = null;
	public $pluginAuthor = null;
	public $pluginDescription = null;
	public $id = null;
	public $version = null;
	public $zipfile = null;
	public $url = null;

	/**
	 * Construct new plugin update data instance
	 *
	 * @param object $updateData
	 */
	public function __construct($updateData) {
		$this->pluginId          = $updateData->id;
		$this->pluginName        = $updateData->name;
		$this->pluginAuthor      = $updateData->author;
		$this->pluginDescription = $updateData->description;
		if ($updateData->currentVersion) {
			$this->id      = $updateData->currentVersion->id;
			$this->version = $updateData->currentVersion->version;
			$this->zipfile = $updateData->currentVersion->zipfile;
			$this->url     = $updateData->currentVersion->url;
		}
	}

	/**
	 * Check if the plugin update data is newer than the given plugin version
	 *
	 * @param float $version
	 * @return bool
	 */
	public function isNewerThan($version) {
		if (!$version) {
			return true;
		}
		return ($this->version > $version);
	}
} 
