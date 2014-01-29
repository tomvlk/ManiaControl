<?php
use FML\Controls\Frame;
use FML\Controls\Gauge;
use FML\Controls\Label;
use FML\Controls\Quad;
use FML\ManiaLink;
use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\ColorUtil;
use ManiaControl\ManiaControl;
use ManiaControl\Maps\Map;
use ManiaControl\Players\Player;
use ManiaControl\Plugins\Plugin;

/**
 * ManiaControl Karma Plugin
 *
 * @author steeffeen
 */
class KarmaPlugin implements CallbackListener, Plugin {
	/**
	 * Constants
	 */
	const ID                      = 5;
	const VERSION                 = 0.1;
	const MLID_KARMA              = 'KarmaPlugin.MLID';
	const TABLE_KARMA             = 'mc_karma';
	const SETTING_AVAILABLE_VOTES = 'Available Votes (X-Y: Comma separated)';
	const SETTING_WIDGET_TITLE    = 'Widget-Title';
	const SETTING_WIDGET_POSX     = 'Widget-Position: X';
	const SETTING_WIDGET_POSY     = 'Widget-Position: Y';
	const SETTING_WIDGET_WIDTH    = 'Widget-Size: Width';
	const SETTING_WIDGET_HEIGHT   = 'Widget-Size: Height';

	const STAT_PLAYER_MAPVOTES = 'Voted Maps';

	/**
	 * Private properties
	 */
	/**
	 *
	 * @var maniaControl $maniaControl
	 */
	private $maniaControl = null;
	private $updateManialink = false;
	private $manialink = null;

	/**
	 * Prepares the Plugin
	 *
	 * @param ManiaControl $maniaControl
	 * @return mixed
	 */
	public static function prepare(ManiaControl $maniaControl) {
		//do nothing
	}

	/**
	 *
	 * @see \ManiaControl\Plugins\Plugin::load()
	 */
	public function load(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;

		// Init database
		$this->initTables();

		// Init settings
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_AVAILABLE_VOTES, '-2,2');
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_WIDGET_TITLE, 'Map-Karma');
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_WIDGET_POSX, 160 - 27.5);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_WIDGET_POSY, 90 - 10 - 6);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_WIDGET_WIDTH, 25.);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_WIDGET_HEIGHT, 12.);

		// Register for callbacks
		$this->maniaControl->callbackManager->registerCallbackListener(CallbackManager::CB_MC_BEGINMAP, $this, 'handleBeginMap');
		$this->maniaControl->callbackManager->registerCallbackListener(CallbackManager::CB_MC_1_SECOND, $this, 'handle1Second');
		$this->maniaControl->callbackManager->registerCallbackListener(CallbackManager::CB_MP_PLAYERCONNECT, $this, 'handlePlayerConnect');
		$this->maniaControl->callbackManager->registerCallbackListener(CallbackManager::CB_MP_PLAYERCHAT, $this, 'handlePlayerChat');

		// Define player stats
		$this->maniaControl->statisticManager->defineStatMetaData(self::STAT_PLAYER_MAPVOTES);

		// Register Stat in Simple StatsList
		$this->maniaControl->statisticManager->simpleStatsList->registerStat(self::STAT_PLAYER_MAPVOTES, 100, "VM");

		$this->updateManialink = true;
		return true;
	}

	/**
	 *
	 * @see \ManiaControl\Plugins\Plugin::unload()
	 */
	public function unload() {
		$emptyManialink = new ManiaLink(self::MLID_KARMA);
		$manialinkText  = $emptyManialink->render()->saveXML();
		$this->maniaControl->manialinkManager->sendManialink($manialinkText);
		$this->maniaControl->callbackManager->unregisterCallbackListener($this);
		unset($this->maniaControl);
	}

	/**
	 *
	 * @see \ManiaControl\Plugins\Plugin::getId()
	 */
	public static function getId() {
		return self::ID;
	}

	/**
	 *
	 * @see \ManiaControl\Plugins\Plugin::getName()
	 */
	public static function getName() {
		return 'Karma Plugin';
	}

	/**
	 *
	 * @see \ManiaControl\Plugins\Plugin::getVersion()
	 */
	public static function getVersion() {
		return self::VERSION;
	}

	/**
	 *
	 * @see \ManiaControl\Plugins\Plugin::getAuthor()
	 */
	public static function getAuthor() {
		return 'steeffeen';
	}

	/**
	 *
	 * @see \ManiaControl\Plugins\Plugin::getDescription()
	 */
	public static function getDescription() {
		return 'Plugin offering Karma Voting for Maps.';
	}

	/**
	 * Handle ManiaControl 1 Second callback
	 *
	 * @param array $callback
	 */
	public function handle1Second(array $callback) {
		if(!$this->updateManialink) {
			return;
		}

		// Get players
		$players = $this->updateManialink;
		if($players === true) {
			$players = $this->maniaControl->playerManager->getPlayers();
		}
		$this->updateManialink = false;

		// Get map karma
		$map   = $this->maniaControl->mapManager->getCurrentMap();
		$karma = $this->getMapKarma($map);
		$votes = $this->getMapVotes($map);

		// Build karma manialink
		$this->buildManialink();

		// Update karma gauge & label
		$karmaGauge = $this->manialink->karmaGauge;
		$karmaLabel = $this->manialink->karmaLabel;
		if(is_numeric($karma)) {
			$karma = floatval($karma);
			$karmaGauge->setRatio($karma + 0.15 - $karma * 0.15);
			$karmaColor = ColorUtil::floatToStatusColor($karma);
			$karmaGauge->setColor($karmaColor . '7');
			$karmaLabel->setText('  ' . round($karma * 100.) . '% (' . $votes['count'] . ')');
		} else {
			$karma = 0.;
			$karmaGauge->setRatio(0.);
			$karmaGauge->setColor('00fb');
			$karmaLabel->setText('-');
		}

		// Loop players
		foreach($players as $login => $player) {
			// Get player vote
			$vote = $this->getPlayerVote($player, $map);

			// Adjust manialink for player's vote
			$votesFrame = $this->manialink->votesFrame;
			$votesFrame->removeChildren();

			// Send manialink
			$manialinkText = $this->manialink->render()->saveXML();
			$this->maniaControl->manialinkManager->sendManialink($manialinkText, $login);
		}
	}

	/**
	 * Handle BeginMap ManiaControl callback
	 *
	 * @param array $callback
	 */
	public function handleBeginMap(array $callback) {
		$this->updateManialink = true;
	}

	/**
	 * Handle PlayerConnect callback
	 *
	 * @param array $callback
	 */
	public function handlePlayerConnect(array $callback) {
		$login  = $callback[1][0];
		$player = $this->maniaControl->playerManager->getPlayer($login);
		if(!$player) {
			return;
		}
		$this->queryManialinkUpdateFor($player);
	}

	/**
	 * Handle PlayerChat callback
	 *
	 * @param array $chatCallback
	 */
	public function handlePlayerChat(array $chatCallback) {
		$login  = $chatCallback[1][1];
		$player = $this->maniaControl->playerManager->getPlayer($login);
		if(!$player) {
			return;
		}
		$message = $chatCallback[1][2];
		if($chatCallback[1][3]) {
			$message = substr($message, 1);
		}
		if(preg_match('/[^+-]/', $message)) {
			return;
		}
		$countPositive = substr_count($message, '+');
		$countNegative = substr_count($message, '-');
		if($countPositive <= 0 && $countNegative <= 0) {
			return;
		}
		$vote    = $countPositive - $countNegative;
		$success = $this->handleVote($player, $vote);
		if(!$success) {
			$this->maniaControl->chat->sendError('Error occurred.', $player->login);
			return;
		}
		$this->maniaControl->chat->sendSuccess('Vote updated!', $player->login);
	}

	/**
	 * Handle a vote done by a player
	 *
	 * @param Player $player
	 * @param int    $vote
	 * @return bool
	 */
	private function handleVote(Player $player, $vote) {
		// Check vote
		$votesSetting = $this->maniaControl->settingManager->getSetting($this, self::SETTING_AVAILABLE_VOTES);
		$votes        = explode(',', $votesSetting);
		$voteLow      = intval($votes[0]);
		$voteHigh     = $voteLow + 2;
		if(isset($votes[1])) {
			$voteHigh = intval($votes[1]);
		}
		if($vote < $voteLow || $vote > $voteHigh) {
			return false;
		}

		// Calculate actual voting
		$vote -= $voteLow;
		$voteHigh -= $voteLow;
		$vote /= $voteHigh;

		// Save vote
		$map = $this->maniaControl->mapManager->getCurrentMap();

		$voted = $this->getPlayerVote($player, $map);
		if(!$voted) {
			$this->maniaControl->statisticManager->incrementStat(self::STAT_PLAYER_MAPVOTES, $player, $this->maniaControl->server->index);
		}

		$success = $this->savePlayerVote($player, $map, $vote);
		if(!$success) {
			return false;
		}
		$this->updateManialink = true;
		return true;
	}

	/**
	 * Query the player to update the manialink
	 *
	 * @param Player $player
	 */
	private function queryManialinkUpdateFor(Player $player) {
		if($this->updateManialink === true) {
			return;
		}
		if(!is_array($this->updateManialink)) {
			$this->updateManialink = array();
		}
		$this->updateManialink[$player->login] = $player;
	}

	/**
	 * Create necessary database tables
	 */
	private function initTables() {
		$mysqli = $this->maniaControl->database->mysqli;
		$query  = "CREATE TABLE IF NOT EXISTS `" . self::TABLE_KARMA . "` (
				`index` int(11) NOT NULL AUTO_INCREMENT,
				`mapIndex` int(11) NOT NULL,
				`playerIndex` int(11) NOT NULL,
				`vote` float NOT NULL DEFAULT '-1',
				`changed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`index`),
				UNIQUE KEY `player_map_vote` (`mapIndex`, `playerIndex`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COMMENT='Save players map votes' AUTO_INCREMENT=1;";
		$mysqli->query($query);
		if($mysqli->error) {
			trigger_error($mysqli->error, E_USER_ERROR);
		}
	}

	/**
	 * Save the vote of the player for the map
	 *
	 * @param Player $player
	 * @param Map    $map
	 * @param float  $vote
	 * @return bool
	 */
	private function savePlayerVote(Player $player, Map $map, $vote) {
		$mysqli = $this->maniaControl->database->mysqli;
		$query  = "INSERT INTO `" . self::TABLE_KARMA . "` (
				`mapIndex`,
				`playerIndex`,
				`vote`
				) VALUES (
				{$map->index},
				{$player->index},
				{$vote}
				) ON DUPLICATE KEY UPDATE
				`vote` = VALUES(`vote`);";
		$result = $mysqli->query($query);
		if($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}
		return $result;
	}

	/**
	 * Get the current vote of the player for the map
	 *
	 * @param Player $player
	 * @param Map    $map
	 * @return int
	 */
	private function getPlayerVote(Player $player, Map $map) {
		$mysqli = $this->maniaControl->database->mysqli;
		$query  = "SELECT * FROM `" . self::TABLE_KARMA . "`
				WHERE `playerIndex` = {$player->index}
				AND `mapIndex` = {$map->index}
				AND `vote` >= 0;";
		$result = $mysqli->query($query);
		if($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}
		if($result->num_rows <= 0) {
			$result->free();
			return false;
		}
		$item = $result->fetch_object();
		$result->free();
		$vote = $item->vote;
		return floatval($vote);
	}

	/**
	 * Get the current karma of the map
	 *
	 * @param Map $map
	 * @return float | bool
	 */
	public function getMapKarma(Map $map) {
		$mysqli = $this->maniaControl->database->mysqli;
		$query  = "SELECT AVG(`vote`) AS `karma` FROM `" . self::TABLE_KARMA . "`
				WHERE `mapIndex` = {$map->index}
				AND `vote` >= 0;";
		$result = $mysqli->query($query);
		if($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}
		if($result->num_rows <= 0) {
			$result->free();
			return false;
		}
		$item = $result->fetch_object();
		$result->free();
		$karma = $item->karma;
		if($karma === null) {
			return false;
		}
		return floatval($karma);
	}

	/**
	 * Get the current Votes for the Map
	 *
	 * @param Map $map
	 * @return array
	 */
	public function getMapVotes(Map $map) {
		$mysqli = $this->maniaControl->database->mysqli;
		$query  = "SELECT `vote`, COUNT(`vote`) AS `count` FROM `" . self::TABLE_KARMA . "`
				WHERE `mapIndex` = {$map->index}
				AND `vote` >= 0
				GROUP BY `vote`;";
		$result = $mysqli->query($query);
		if($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}
		$votes = array();
		$count = 0;
		while($vote = $result->fetch_object()) {
			$votes[$vote->vote] = $vote;
			$count += $vote->count;
		}
		$votes['count'] = $count;
		$result->free();
		return $votes;
	}

	/**
	 * Build karma voting manialink if necessary
	 *
	 * @param bool $forceBuild
	 */
	private function buildManialink($forceBuild = false) {
		if(is_object($this->manialink) && !$forceBuild) {
			return;
		}

		$title        = $this->maniaControl->settingManager->getSetting($this, self::SETTING_WIDGET_TITLE);
		$pos_x        = $this->maniaControl->settingManager->getSetting($this, self::SETTING_WIDGET_POSX);
		$pos_y        = $this->maniaControl->settingManager->getSetting($this, self::SETTING_WIDGET_POSY);
		$width        = $this->maniaControl->settingManager->getSetting($this, self::SETTING_WIDGET_WIDTH);
		$height       = $this->maniaControl->settingManager->getSetting($this, self::SETTING_WIDGET_HEIGHT);
		$labelStyle   = $this->maniaControl->manialinkManager->styleManager->getDefaultLabelStyle();
		$quadStyle    = $this->maniaControl->manialinkManager->styleManager->getDefaultQuadStyle();
		$quadSubstyle = $this->maniaControl->manialinkManager->styleManager->getDefaultQuadSubstyle();

		$manialink = new ManiaLink(self::MLID_KARMA);

		$frame = new Frame();
		$manialink->add($frame);
		$frame->setPosition($pos_x, $pos_y);

		$backgroundQuad = new Quad();
		$frame->add($backgroundQuad);
		$backgroundQuad->setY($height * 0.15);
		$backgroundQuad->setSize($width, $height);
		$backgroundQuad->setStyles($quadStyle, $quadSubstyle);

		$titleLabel = new Label();
		$frame->add($titleLabel);
		$titleLabel->setY($height * 0.36);
		$titleLabel->setWidth($width * 0.85);
		$titleLabel->setStyle($labelStyle);
		$titleLabel->setTranslate(true);
		$titleLabel->setTextSize(1);
		$titleLabel->setScale(0.90);
		$titleLabel->setText($title);

		$karmaGauge = new Gauge();
		$frame->add($karmaGauge);
		$karmaGauge->setSize($width * 0.95, $height * 0.92);
		$karmaGauge->setDrawBg(false);
		$manialink->karmaGauge = $karmaGauge;

		$karmaLabel = new Label();
		$frame->add($karmaLabel);
		$karmaLabel->setPosition(0, -0.4, 1);
		$karmaLabel->setSize($width * 0.9, $height * 0.9);
		$karmaLabel->setStyle($labelStyle);
		$karmaLabel->setTextSize(1);
		$manialink->karmaLabel = $karmaLabel;

		$votesFrame = new Frame();
		$frame->add($votesFrame);
		$manialink->votesFrame = $votesFrame;

		$this->manialink = $manialink;
	}
}
