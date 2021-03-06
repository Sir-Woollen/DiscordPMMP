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
use Discord\Parts\Guild\Ban as DiscordBan;
use Discord\Parts\Guild\Invite as DiscordInvite;
use Discord\Parts\Guild\Role as DiscordRole;
use Discord\Parts\Permissions\RolePermission as DiscordRolePermission;
use Discord\Parts\User\Activity as DiscordActivity;
use Discord\Parts\User\Member as DiscordMember;
use Discord\Parts\User\User as DiscordUser;
use Discord\Parts\Guild\Guild as DiscordServer;
use JaxkDev\DiscordBot\Communication\Models\Activity;
use JaxkDev\DiscordBot\Communication\Models\Ban;
use JaxkDev\DiscordBot\Communication\Models\Channel;
use JaxkDev\DiscordBot\Communication\Models\Invite;
use JaxkDev\DiscordBot\Communication\Models\Member;
use JaxkDev\DiscordBot\Communication\Models\Message;
use JaxkDev\DiscordBot\Communication\Models\Permissions\RolePermissions;
use JaxkDev\DiscordBot\Communication\Models\Role;
use JaxkDev\DiscordBot\Communication\Models\Server;
use JaxkDev\DiscordBot\Communication\Models\User;

abstract class ModelConverter{

	static public function genModelMember(DiscordMember $discordMember): Member{
		$m = new Member();
		$m->setUserId($discordMember->id);
		$m->setServerId($discordMember->guild_id);
		$m->setNickname($discordMember->nick);
		$m->setJoinTimestamp($discordMember->joined_at === null ? 0 : $discordMember->joined_at->getTimestamp());
		$m->setBoostTimestamp($discordMember->premium_since === null ? null : $discordMember->premium_since->getTimestamp());

		$bitwise = $discordMember->guild->roles->offsetGet($discordMember->guild_id)->permissions->bitwise; //Everyone perms.
		$roles = [];

		if($discordMember->guild->owner_id == $discordMember->id){
			$bitwise |= 0x8; // Add administrator permission
			foreach($discordMember->roles ?? [] as $role){
				$roles[] = $role->id;
			}
		}else{
			/* @var DiscordRole */
			foreach($discordMember->roles ?? [] as $role){
				$roles[] = $role->id;
				$bitwise |= $role->permissions->bitwise;
			}
		}

		$newPermission = new RolePermissions();
		$newPermission->setBitwise($bitwise);
		if($newPermission->getPermission("administrator")){
			$newPermission->setBitwise(2147483647); //All perms.
		}

		$m->setPermissions($newPermission);
		$m->setRolesId($roles);
		return $m;
	}

	static public function genModelUser(DiscordUser $user): User{
		$u = new User();
		$u->setId($user->id);
		$u->setCreationTimestamp((int)$user->createdTimestamp());
		$u->setUsername($user->username);
		$u->setDiscriminator($user->discriminator);
		$u->setAvatarUrl($user->avatar);
		//Many more attributes to come.
		return $u;
	}

	static public function genModelServer(DiscordServer $discordServer): Server{
		$s = new Server();
		$s->setId($discordServer->id);
		$s->setName($discordServer->name);
		$s->setRegion($discordServer->region);
		$s->setOwnerId($discordServer->owner_id);
		$s->setLarge($discordServer->large);
		$s->setIconUrl($discordServer->icon) ;//?null
		$s->setMemberCount($discordServer->member_count);
		$s->setCreationTimestamp($discordServer->createdTimestamp());
		return $s;
	}

	static public function genModelChannel(DiscordChannel $discordChannel): Channel{
		if($discordChannel->type !== DiscordChannel::TYPE_TEXT || $discordChannel->guild_id === null){
			//Temporary.
			throw new \AssertionError("Discord channel type must be `text` to generate model channel.");
		}
		$c = new Channel();
		$c->setId($discordChannel->id);
		$c->setName($discordChannel->name);
		$c->setDescription($discordChannel->topic);
		$c->setCategory(null); // $discordChannel->parent_id (Channel ID, Channel TYPE CATEGORY.
		$c->setServerId($discordChannel->guild_id);
		return $c;
	}

	static public function genModelMessage(DiscordMessage $discordMessage): Message{
		if($discordMessage->type !== DiscordMessage::TYPE_NORMAL){
			//Temporary.
			throw new \AssertionError("Discord message type must be `normal` to generate model message.");
		}
		if($discordMessage->channel->guild_id === null){
			throw new \AssertionError("Discord message does not have a guild_id, cannot generate model message.");
		}
		$m = new Message();
		$m->setId($discordMessage->id);
		$m->setTimestamp($discordMessage->timestamp->getTimestamp());
		$m->setAuthorId(($discordMessage->channel->guild_id.".".$discordMessage->author->id));
		$m->setChannelId($discordMessage->channel_id);
		$m->setServerId($discordMessage->channel->guild_id);
		$m->setEveryoneMentioned($discordMessage->mention_everyone);
		$m->setContent($discordMessage->content);
		$m->setChannelsMentioned(array_keys($discordMessage->mention_channels->toArray()));
		$m->setRolesMentioned(array_keys($discordMessage->mention_roles->toArray()));
		$m->setUsersMentioned(array_keys($discordMessage->mentions->toArray()));
		return $m;
	}

	static public function genModelRolePermission(DiscordRolePermission $rolePermission): RolePermissions{
		$p = new RolePermissions();
		$p->setBitwise($rolePermission->bitwise);
		return $p;
	}

	static public function genModelRole(DiscordRole $discordRole): Role{
		$r = new Role();
		$r->setId($discordRole->id);
		$r->setServerId($discordRole->guild_id);
		$r->setName($discordRole->name);
		$r->setPermissions(self::genModelRolePermission($discordRole->permissions));
		$r->setMentionable($discordRole->mentionable);
		$r->setHoistedPosition($discordRole->position);
		$r->setColour($discordRole->color);
		return $r;
	}

	static public function genModelInvite(DiscordInvite $invite): Invite{
		$i = new Invite();
		$i->setCode($invite->code);
		$i->setServerId($invite->guild_id);
		$i->setChannelId($invite->channel_id);
		$i->setCreatedAt($invite->created_at->getTimestamp());
		$i->setCreator($invite->guild_id.".".$invite->inviter->id);
		$i->setMaxAge($invite->max_age);
		$i->setMaxUses($invite->max_uses);
		$i->setTemporary($invite->temporary);
		$i->setUses($invite->uses);
		return $i;
	}

	static public function genModelBan(DiscordBan $ban): Ban{
		$b = new Ban();
		$b->setServerId($ban->guild_id);
		$b->setUserId($ban->user_id);
		$b->setReason($ban->reason);
		return $b;
	}

	/**
	 * @description NOTICE, setStatus() from User after generating.
	 * @param DiscordActivity $discordActivity
	 * @return Activity
	 */
	static public function genModelActivity(DiscordActivity $discordActivity): Activity{
		$a = new Activity();
		$a->setType($discordActivity->type);
		$a->setMessage($discordActivity->state);
		$a->setStatus(Activity::STATUS_OFFLINE); //Not included in discord activity must be set from user.
		return $a;
	}
}