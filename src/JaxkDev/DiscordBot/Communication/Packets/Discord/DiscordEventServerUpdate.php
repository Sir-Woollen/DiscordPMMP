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

namespace JaxkDev\DiscordBot\Communication\Packets\Discord;

use JaxkDev\DiscordBot\Communication\Models\Server;
use JaxkDev\DiscordBot\Communication\Packets\Packet;

class DiscordEventServerUpdate extends Packet{

	/** @var Server */
	private $server;

	public function getServer(): Server{
		return $this->server;
	}

	public function setServer(Server $server): void{
		$this->server = $server;
	}

	public function serialize(): ?string{
		return serialize([$this->UID, $this->server]);
	}

	public function unserialize($serialized): void{
		[$this->UID, $this->server] = unserialize($serialized);
	}
}