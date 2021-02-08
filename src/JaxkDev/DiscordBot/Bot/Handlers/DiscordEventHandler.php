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

namespace JaxkDev\DiscordBot\Bot\Handlers;

use Discord\Discord;
use Discord\Parts\Channel\Channel as DiscordChannel;
use Discord\Parts\Channel\Message as DiscordMessage;
use Discord\Parts\Guild\Guild as DiscordGuild;
use Discord\Parts\Guild\Role as DiscordRole;
use Discord\Parts\User\Member as DiscordMember;
use Discord\Parts\User\User as DiscordUser;
use JaxkDev\DiscordBot\Bot\Client;
use JaxkDev\DiscordBot\Bot\ModelConverter;
use JaxkDev\DiscordBot\Communication\Models\Activity;
use JaxkDev\DiscordBot\Communication\Packets\DiscordEventAllData;
use JaxkDev\DiscordBot\Communication\Protocol;
use pocketmine\utils\MainLogger;

class DiscordEventHandler{

	/** @var Client */
	private $client;

	public function __construct(Client $client){
		$this->client = $client;
	}

	public function registerEvents(): void{
		$discord = $this->client->getDiscordClient();
		$discord->on('MESSAGE_CREATE', [$this, 'onMessage']);

		$discord->on('GUILD_MEMBER_ADD', [$this, 'onMemberJoin']);
		$discord->on('GUILD_MEMBER_REMOVE', [$this, 'onMemberLeave']);
		$discord->on('GUILD_MEMBER_UPDATE', [$this, 'onMemberUpdate']);   //Includes Roles,nickname etc

		$discord->on('GUILD_CREATE', [$this, 'onGuildJoin']);
		$discord->on('GUILD_UPDATE', [$this, 'onGuildUpdate']);
		$discord->on('GUILD_DELETE', [$this, 'onGuildLeave']);
		/*
		 * TODO, functions/models/packets:
		 *
		 * $discord->on('CHANNEL_CREATE', [$this, 'onChannelCreate']);   CHANNEL_CREATE/DELETE/EDIT
		 * $discord->on('CHANNEL_UPDATE', [$this, 'onChannelUpdate']);
		 * $discord->on('CHANNEL_DELETE', [$this, 'onChannelDelete']);
		 *
		 * $discord->on('GUILD_ROLE_CREATE', [$this, 'onRoleCreate']);   ROLE_CREATE/DELETE/EDIT
		 * $discord->on('GUILD_ROLE_UPDATE', [$this, 'onRoleUpdate']);
		 * $discord->on('GUILD_ROLE_DELETE', [$this, 'onRoleDelete']);
		 *
		 * $discord->on('MESSAGE_DELETE', [$this, 'onMessageDelete']);   MESSAGE_DELETE/EDIT
		 * $discord->on('MESSAGE_UPDATE', [$this, 'onMessageUpdate']);
		 *
		 * TODO (others not yet planned for 2.0.0):
		 * - Reactions
		 * - Pins
		 * - Server Integrations ?
		 * - Invites
		 * - Bans
		 */
	}

	public function onReady(): void{
		if($this->client->getThread()->getStatus() !== Protocol::THREAD_STATUS_STARTED){
			MainLogger::getLogger()->warning("Closing thread, unexpected state change.");
			$this->client->close();
		}

		//Default activity.
		$ac = new Activity();
		$ac->setMessage("PocketMine-MP v".\pocketmine\VERSION)->setType(Activity::TYPE_PLAYING)->setStatus(Activity::STATUS_IDLE);
		$this->client->updatePresence($ac);

		// Register all other events.
		$this->registerEvents();

		// Dump all discord data.
		$pk = new DiscordEventAllData();
		$pk->setTimestamp(time());

		MainLogger::getLogger()->debug("Starting the data pack, please be patient.");
		$t = microtime(true);
		$mem = memory_get_usage(true);

		$client = $this->client->getDiscordClient();

		/** @var DiscordGuild $guild */
		foreach($client->guilds as $guild){
			$pk->addServer(ModelConverter::genModelServer($guild));

			/** @var DiscordChannel $channel */
			foreach($guild->channels as $channel){
				if($channel->type !== DiscordChannel::TYPE_TEXT) continue;
				$pk->addChannel(ModelConverter::genModelChannel($channel));
			}

			/** @var DiscordRole $role */
			foreach($guild->roles as $role){
				$pk->addRole(ModelConverter::genModelRole($role));
			}

			/** @var DiscordMember $member */
			foreach($guild->members as $member){
				$pk->addMember(ModelConverter::genModelMember($member));
			}
		}

		/** @var DiscordUser $user */
		foreach($client->users as $user){
			$pk->addUser(ModelConverter::genModelUser($user));
		}

		$pk->setBotUser(ModelConverter::genModelUser($client->user));

		$this->client->getThread()->writeOutboundData($pk);

		MainLogger::getLogger()->debug("Data pack Took: ".round(microtime(true)-$t, 5)."s & ".
			round(((memory_get_usage(true)-$mem)/1024)/1024, 4)."mb of memory, Final size: ".$pk->getSize());

		// Force fresh heartbeat asap, as that took quite some time.
		$this->client->getCommunicationHandler()->sendHeartbeat();

		$this->client->getThread()->setStatus(Protocol::THREAD_STATUS_READY);
		MainLogger::getLogger()->info("Client ready.");

		$this->client->logDebugInfo();
	}

	public function onMessage(DiscordMessage $message, Discord $discord): void{
		// Can be user if bot doesnt have correct intents enabled on discord developer dashboard.
		if($message->author instanceof DiscordMember ? $message->author->user->bot : $message->author->bot) return;

		//if($message->author->id === "305060807887159296") $message->react("❤️");
		//Dont ask questions...

		// Other types of messages not used right now.
		if($message->type !== DiscordMessage::TYPE_NORMAL) return;
		if($message->channel->type !== DiscordChannel::TYPE_TEXT) return;
		if(($message->content ?? "") === "") return; //Images/Files, can be empty strings or just null in other cases.

		if($message->channel->guild_id === null) throw new \AssertionError("GuildID Cannot be null.");

		$this->client->getCommunicationHandler()->sendMessageSentEvent(ModelConverter::genModelMessage($message));
	}

	public function onMemberJoin(DiscordMember $member, Discord $discord): void{
		$this->client->getCommunicationHandler()->sendMemberJoinEvent(ModelConverter::genModelMember($member),
			ModelConverter::genModelUser($member->user));
	}

	public function onMemberLeave(DiscordMember $member, Discord $discord): void{
		$this->client->getCommunicationHandler()->sendMemberLeaveEvent($member->guild_id.".".$member->id);
	}

	public function onMemberUpdate(DiscordMember $member, Discord $discord): void{
		$this->client->getCommunicationHandler()->sendMemberUpdateEvent(ModelConverter::genModelMember($member));
	}

	public function onGuildJoin(DiscordGuild $guild, Discord $discord): void{
		$channels = [];
		/** @var DiscordChannel $channel */
		foreach($guild->channels->toArray() as $channel){
			if($channel->type === DiscordChannel::TYPE_TEXT){
				$channels[] = ModelConverter::genModelChannel($channel);
			}
		}
		$roles = [];
		/** @var DiscordRole $role */
		foreach($guild->roles->toArray() as $role){
			$roles[] = ModelConverter::genModelRole($role);
		}
		$members = [];
		/** @var DiscordMember $member */
		foreach($guild->members->toArray() as $member){
			$members[] = ModelConverter::genModelMember($member);
		}
		$server = ModelConverter::genModelServer($guild);
		$this->client->getCommunicationHandler()->sendServerJoinEvent($server, $channels, $roles, $members);
	}

	public function onGuildLeave(DiscordGuild $guild, Discord $discord): void{
		$this->client->getCommunicationHandler()->sendServerLeaveEvent(ModelConverter::genModelServer($guild));
	}

	public function onGuildUpdate(DiscordGuild $guild, Discord $discord): void{
		$this->client->getCommunicationHandler()->sendServerUpdateEvent(ModelConverter::genModelServer($guild));
	}
}