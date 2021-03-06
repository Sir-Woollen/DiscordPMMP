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

use JaxkDev\DiscordBot\Communication\Models\Ban;
use JaxkDev\DiscordBot\Communication\Packets\Packet;

class DiscordEventBanAdd extends Packet{

	/** @var Ban */
	private $ban;

	public function getBan(): Ban{
		return $this->ban;
	}

	public function setBan(Ban $ban): void{
		$this->ban = $ban;
	}

	public function serialize(): ?string{
		return serialize([$this->UID, $this->ban]);
	}

	public function unserialize($serialized): void{
		[$this->UID, $this->ban] = unserialize($serialized);
	}
}