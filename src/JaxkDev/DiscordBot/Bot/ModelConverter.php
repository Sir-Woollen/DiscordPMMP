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

use Discord\Parts\Channel\Channel as DiscordChannel;
use Discord\Parts\Channel\Message as DiscordMessage;
use Discord\Parts\Guild\Role as DiscordRole;
use Discord\Parts\Permissions\RolePermission as DiscordRolePermission;
use Discord\Parts\User\Activity as DiscordActivity;
use Discord\Parts\User\Member as DiscordMember;
use Discord\Parts\User\User as DiscordUser;
use Discord\Parts\Guild\Guild as DiscordServer;
use InvalidArgumentException;
use JaxkDev\DiscordBot\Communication\Models\Activity;
use JaxkDev\DiscordBot\Communication\Models\Channel;
use JaxkDev\DiscordBot\Communication\Models\Member;
use JaxkDev\DiscordBot\Communication\Models\Message;
use JaxkDev\DiscordBot\Communication\Models\Permissions\RolePermissions;
use JaxkDev\DiscordBot\Communication\Models\Role;
use JaxkDev\DiscordBot\Communication\Models\Server;
use JaxkDev\DiscordBot\Communication\Models\User;

abstract class ModelConverter{
	static public function genModelMember(DiscordMember $discordMember): Member{
		$m = new Member();
		$m->setUserId($discordMember->id)
			->setServerId($discordMember->guild_id)
			->setNickname($discordMember->nick)
			->setJoinTimestamp($discordMember->joined_at === null ? 0 : $discordMember->joined_at->getTimestamp())
			->setBoostTimestamp($discordMember->premium_since === null ? null : $discordMember->premium_since->getTimestamp())
			->setRolesId(array_keys($discordMember->roles->toArray()))
			->setPermissions(self::genModelRolePermission($discordMember->getPermissions()))
			->setId();
		return $m;
	}

	static public function genModelUser(DiscordUser $user): User{
		$u = new User();
		$u->setId($user->id)
			->setCreationTimestamp((int)$user->createdTimestamp())
			->setUsername($user->username)
			->setDiscriminator($user->discriminator)
			->setAvatarUrl($user->avatar);
		//Many more attributes to come.
		return $u;
	}

	static public function genModelServer(DiscordServer $discordServer): Server{
		$s = new Server();
		$s->setId($discordServer->id)
			->setName($discordServer->name)
			->setRegion($discordServer->region)
			->setOwnerId($discordServer->owner_id)
			->setLarge($discordServer->large)
			->setIconUrl($discordServer->icon) //?null
			->setMemberCount($discordServer->member_count)
			->setCreationTimestamp($discordServer->createdTimestamp());
		return $s;
	}

	static public function genModelChannel(DiscordChannel $discordChannel): Channel{
		if($discordChannel->type !== DiscordChannel::TYPE_TEXT || $discordChannel->guild_id === null){
			//Temporary.
			throw new InvalidArgumentException("Discord channel type must be `text` to generate model channel.");
		}
		$c = new Channel();
		$c->setId($discordChannel->id)
			->setName($discordChannel->name)
			->setDescription($discordChannel->topic)
			->setCategory(null) // $discordChannel->parent_id (Channel ID, Channel TYPE CATEGORY.
			->setServerId($discordChannel->guild_id);
		return $c;
	}

	static public function genModelMessage(DiscordMessage $discordMessage): Message{
		if($discordMessage->type !== DiscordMessage::TYPE_NORMAL){
			//Temporary.
			throw new InvalidArgumentException("Discord message type must be `normal` to generate model message.");
		}
		if($discordMessage->channel->guild_id === null){
			throw new InvalidArgumentException("Discord message does not have a guild_id, cannot generate model message.");
		}
		$m = new Message();
		$m->setId($discordMessage->id)
			->setTimestamp($discordMessage->timestamp->getTimestamp())
			->setAuthorId(($discordMessage->channel->guild_id.".".$discordMessage->author->id))
			->setChannelId($discordMessage->channel_id)
			->setServerId($discordMessage->channel->guild_id)
			->setEveryoneMentioned($discordMessage->mention_everyone)
			->setContent($discordMessage->content)
			->setChannelsMentioned(array_keys($discordMessage->mention_channels->toArray()))
			->setRolesMentioned(array_keys($discordMessage->mention_roles->toArray()))
			->setUsersMentioned(array_keys($discordMessage->mentions->toArray()));
		return $m;
	}

	static public function genModelRolePermission(DiscordRolePermission $rolePermission): RolePermissions{
		$p = new RolePermissions();
		$p->setBitwise($rolePermission->bitwise);
		return $p;
	}

	static public function genModelRole(DiscordRole $discordRole): Role{
		$r = new Role();
		$r->setId($discordRole->id)
			->setServerId($discordRole->guild_id)
			->setName($discordRole->name)
			->setPermissions(self::genModelRolePermission($discordRole->permissions))
			->setMentionable($discordRole->mentionable)
			->setHoistedPosition($discordRole->position)
			->setColour($discordRole->color);
		return $r;
	}

	/**
	 * @description NOTICE, setStatus() after generating.
	 * @param DiscordActivity $discordActivity
	 * @return Activity
	 */
	static public function genModelActivity(DiscordActivity $discordActivity): Activity{
		$a = new Activity();
		$a->setType($discordActivity->type)
			->setMessage($discordActivity->state)
			->setStatus(Activity::STATUS_OFFLINE); //Not included in discord activity must be set from user.
		return $a;
	}
}