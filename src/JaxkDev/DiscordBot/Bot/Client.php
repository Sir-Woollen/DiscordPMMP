<?php
/*
 * DiscordBot, PocketMine-MP Plugin.
 *
 * Licensed under the Open Software License version 3.0 (OSL-3.0)
 * Copyright (C) 2020-2021 JaxkDev
 *
 * Twitter :: @JaxkDev
 * Discord :: JaxkDev#2698
 * Email   :: JaxkDev@gmail.com
 */

namespace JaxkDev\DiscordBot\Bot;

use Discord\Discord;
use Discord\Parts\Channel\Channel as DiscordChannel;
use Discord\Parts\Guild\Guild as DiscordGuild;
use Discord\Parts\User\Activity as DiscordActivity;
use Error;
use ErrorException;
use Exception;
use JaxkDev\DiscordBot\Bot\Handlers\DiscordEventHandler;
use JaxkDev\DiscordBot\Bot\Handlers\CommunicationHandler;
use JaxkDev\DiscordBot\Communication\BotThread;
use JaxkDev\DiscordBot\Communication\Models\Activity;
use JaxkDev\DiscordBot\Communication\Models\Message;
use JaxkDev\DiscordBot\Communication\Packets\Packet;
use JaxkDev\DiscordBot\Communication\Protocol;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use pocketmine\utils\MainLogger;
use React\EventLoop\TimerInterface;
use Throwable;

class Client{

	/** @var BotThread */
	private $thread;

	/** @var Discord */
	private $client;

	/** @var CommunicationHandler */
	private $communicationHandler;

	/** @var DiscordEventHandler */
	private $discordEventHandler;

	/** @var TimerInterface|null */
	private $readyTimer;
	/** @var TimerInterface|null */
	private $tickTimer;

	/** @var int */
	private $tickCount;
	/** @var int */
	private $lastGCCollection = 0;

	/** @var array */
	private $config;

	public function __construct(BotThread $thread, array $config){
		$this->thread = $thread;
		$this->config = $config;

		gc_enable();

		error_reporting(E_ALL & ~E_NOTICE);
		set_error_handler([$this, 'sysErrorHandler']);
		register_shutdown_function([$this, 'close']);

		// Mono logger can have issues with other timezones, for now use UTC.
		// Note, this does not effect outside thread config.
		// TODO CDT Investigate.
		ini_set("date.timezone", "UTC");
		MainLogger::getLogger()->debug("Log files will be in UTC timezone.");

		Packet::$UID_COUNT = 1;

		$logger = new Logger('DiscordPHP');
		$httpLogger = new Logger('DiscordPHP.HTTP');
		$handler = new RotatingFileHandler(\JaxkDev\DiscordBot\DATA_PATH.$config['logging']['directory'].DIRECTORY_SEPARATOR."DiscordBot.log", $config['logging']['maxFiles'], Logger::DEBUG);
		$handler->setFilenameFormat('{filename}-{date}', 'Y-m-d');
		$logger->setHandlers(array($handler));
		$httpLogger->setHandlers(array($handler));

		if($config['logging']['debug']){
			$handler = new StreamHandler(($r = fopen('php://stdout', 'w')) === false ? "" : $r);
			$logger->pushHandler($handler);
			$httpLogger->pushHandler($handler);
		}

		// TODO Intents.

		$socket_opts = [];
		if($config["discord"]["usePluginCacert"]){
			MainLogger::getLogger()->debug("TLS cafile set to '".\JaxkDev\DiscordBot\DATA_PATH."cacert.pem"."'");
			$socket_opts["tls"] = [
				"cafile" => \JaxkDev\DiscordBot\DATA_PATH."cacert.pem"
			];
		}

		/** @noinspection PhpUnhandledExceptionInspection */ //Impossible.
		$this->client = new Discord([
			'token' => $config['discord']['token'],
			'logger' => $logger,
			'httpLogger' => $httpLogger,
			'socket_options' => $socket_opts,
			'loadAllMembers' => true
		]);

		$this->config['discord']['token'] = "REDACTED";

		$this->communicationHandler = new CommunicationHandler($this);
		$this->discordEventHandler = new DiscordEventHandler($this);

		$this->registerHandlers();
		$this->registerTimers();

		if($this->thread->getStatus() === Protocol::THREAD_STATUS_STARTING){
			$this->thread->setStatus(Protocol::THREAD_STATUS_STARTED);
			$this->client->run();
		}else{
			MainLogger::getLogger()->warning("Closing thread, unexpected state change.");
			$this->close();
		}
	}

	private function registerTimers(): void{
		// Handles shutdown, rather than a SHUTDOWN const to send through internal communication, set flag to closed.
		// Saves time & will guarantee closure ASAP rather then waiting in line through ^
		$this->client->getLoop()->addPeriodicTimer(1, function(){
			if($this->thread->getStatus() === Protocol::THREAD_STATUS_CLOSING){
				$this->close();
			}
		});

		// Handles any problems pre-ready.
		$this->readyTimer = $this->client->getLoop()->addTimer(30, function(){
			if($this->client->id !== null){
				MainLogger::getLogger()->warning("Client has taken >30s to get ready, How large is your discord server !?  [Create an issue on github is this persists]");
				$this->client->getLoop()->addTimer(30, function(){
					if($this->thread->getStatus() !== Protocol::THREAD_STATUS_READY){
						MainLogger::getLogger()->critical("Client has taken too long to become ready, shutting down.");
						$this->close();
					}
				});
			}else{
				//Should never happen unless your internet speed is like 10kb/s
				MainLogger::getLogger()->critical("Client failed to login/connect within 30 seconds, See log file for details.");
				$this->close();
			}
		});

		$this->tickTimer = $this->client->getLoop()->addPeriodicTimer(1/20, function(){
			// Note this is not accurate/fixed dynamically to 1/20th of a second.
			$this->tick();
		});
	}

	/** @noinspection PhpUnusedParameterInspection */
	private function registerHandlers(): void{
		// https://github.com/teamreflex/DiscordPHP/issues/433
		// Note ready is emitted after successful connection + all servers/users loaded, so only register events
		// After this event.
		$this->client->on('ready', function(Discord $discord){
			if($this->readyTimer !== null){
				$this->client->getLoop()->cancelTimer($this->readyTimer);
				$this->readyTimer = null;
			}
			$this->discordEventHandler->onReady();
		});

		$this->client->on('error', [$this, 'discordErrorHandler']);
		$this->client->on('closed', [$this, 'close']);
	}

	public function tick(): void{
		$data = $this->thread->readInboundData(Protocol::PPT);

		foreach($data as $d){
			if(!$this->communicationHandler->handle($d)){
				MainLogger::getLogger()->debug("Packet ".get_class($d)." [".$d->getUID()."] not handled.");
			}
		}

		if(($this->tickCount % 20) === 0){
			//Run every second TODO Check own status before sending/checking heartbeat...
			$this->communicationHandler->checkHeartbeat();
			$this->communicationHandler->sendHeartbeat();

			//GC Tests.
			if(microtime(true)-$this->lastGCCollection >= 600){
				$cycles = gc_collect_cycles();
				$mem = round(gc_mem_caches()/1024, 3);
				MainLogger::getLogger()->debug("[GC] Claimed {$mem}kb and {$cycles} cycles.");
				$this->lastGCCollection = time();
			}
		}

		$this->tickCount++;
	}

	public function getThread(): BotThread{
		return $this->thread;
	}

	public function getDiscordClient(): Discord{
		return $this->client;
	}

	public function getCommunicationHandler(): CommunicationHandler{
		return $this->communicationHandler;
	}

	/*
	 * Note, It will only show warning ONCE per channel/guild that fails.
	 * Fix on the way hopefully.
	 */
	public function sendMessage(Message $message): void{
		if($this->thread->getStatus() !== Protocol::THREAD_STATUS_READY) return;

		/** @noinspection PhpUnhandledExceptionInspection */ //Impossible.
		$this->client->guilds->fetch($message->getServerId())->done(function(DiscordGuild $guild) use($message){
			$guild->channels->fetch($message->getChannelId())->done(function(DiscordChannel $channel) use($message){
				$channel->sendMessage($message->getContent());
				MainLogger::getLogger()->debug("Sent message(".strlen($message->getContent()).") to ({$message->getServerId()}|{$message->getChannelId()})");
			}, function() use($message){
				MainLogger::getLogger()->warning("Failed to fetch channel {$message->getChannelId()} in server {$message->getServerId()} while attempting to send message.");
			});
		}, function() use($message){
			MainLogger::getLogger()->warning("Failed to fetch server {$message->getServerId()} while attempting to send message.");
		});
	}

	public function updatePresence(Activity $activity): bool{
		$presence = new DiscordActivity($this->client, [
			'name' => $activity->getMessage(),
			'type' => $activity->getType()
		]);

		try{
			$this->client->updatePresence($presence, $activity->getStatus() === Activity::STATUS_IDLE, $activity->getStatus());
			return true;
		}catch (Exception $e){
			return false;
		}
	}

	public function logDebugInfo(): void{
		MainLogger::getLogger()->debug("Debug Information:\n".
			"> Username: {$this->client->username}#{$this->client->discriminator}\n".
			"> ID: {$this->client->id}\n".
			"> Servers: {$this->client->guilds->count()}\n".
			"> Users: {$this->client->users->count()}"
		);
	}

	public function sysErrorHandler(int $severity, string $message, string $file, int $line): bool{
		$this->close(new ErrorException($message, 0, $severity, $file, $line));
		return true;
	}

	/** @var Throwable[] $data */
	public function discordErrorHandler(array $data): void{
		$this->close($data[0]??null);
	}

	public function close(?Throwable $error = null): void{
		if($this->thread->getStatus() === Protocol::THREAD_STATUS_CLOSED) return;
		$this->thread->setStatus(Protocol::THREAD_STATUS_CLOSED);
		if($this->client instanceof Discord){
			try{
				$this->client->close(true);
			}catch (Error $e){
				MainLogger::getLogger()->debug("Failed to close client, probably due it not being started.");
			}
		}
		if($error instanceof Throwable){
			MainLogger::getLogger()->logException($error);
		}
		MainLogger::getLogger()->debug("Client closed.");
		exit(0);
	}
}