<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

/**
 * Event related classes
 */
namespace pocketmine\event;

use pocketmine\plugin\RegisteredListener;
use pocketmine\Server;
use pocketmine\utils\MainLogger;

/**
 * @noHandle
 */
abstract class Event{

	/** @var string|null */
	protected $eventName = null;
	/** @var bool */
	private $isCancelled = false;

	/** @var bool */
	private $async = false;
	/** @var RegisteredListener[]|null */
	private $asyncQueue = null;
	/** @var bool */
	private $asyncComplete = false;
	/** @var callable|null */
	private $asyncCompleteFunc = null;

	/** @var int|null */
	private $pauseTimeout = null;
	/** @var callable|null */
	private $pauseTimeoutFunc = null;

	/**
	 * @return string
	 */
	final public function getEventName() : string{
		return $this->eventName ?? get_class($this);
	}

	/**
	 * @return bool
	 *
	 * @throws \BadMethodCallException
	 */
	public function isCancelled() : bool{
		if(!($this instanceof Cancellable)){
			throw new \BadMethodCallException("Event is not Cancellable");
		}

		/** @var Event $this */
		return $this->isCancelled === true;
	}

	/**
	 * @param bool $value
	 *
	 * @throws \BadMethodCallException
	 */
	public function setCancelled(bool $value = true){
		if(!($this instanceof Cancellable)){
			throw new \BadMethodCallException("Event is not Cancellable");
		}

		/** @var Event $this */
		$this->isCancelled = $value;
	}

	/**
	 * @deprecated
	 * @return HandlerList|null
	 */
	public function getHandlers() : ?HandlerList{
		return HandlerList::getHandlerListFor(get_class($this));
	}

	/**
	 * @internal Only to be called from PluginManager->callAsyncEvent
	 *
	 * @param RegisteredListener[] $asyncQueue
	 * @param callable             $onCompletion
	 */
	public final function setAsyncQueue(array $asyncQueue, callable $onCompletion) : void{
		$this->async = true;
		$this->asyncQueue = $asyncQueue;
		$this->asyncCompleteFunc = $onCompletion;
	}

	public final function isAsync() : bool{
		return $this->async;
	}

	/**
	 * @internal Only to be called from PluginManager->callAsyncEvent
	 *
	 * @param int $currentTick
	 */
	public final function startAsyncQueue(int $currentTick) : void{
		if(!$this->async){
			throw new \InvalidStateException("Could not start async queue on a non-async event");
		}
		if(reset($this->asyncQueue) === false){
			$this->asyncComplete = true;
			return;
		}

		$this->doAsyncLoop();
		if($this->asyncComplete){
			$this->onComplete();
		}
	}

	public final function asyncCheck(int $currentTick) : bool{
		if(!$this->asyncComplete){
			if($this->pauseTimeout !== null and $this->pauseTimeout < $currentTick){
				// paused but timed out
				($this->pauseTimeoutFunc)($this);
				$this->pauseTimeout = $this->pauseTimeoutFunc = null;
			}

			if($this->pauseTimeout === null){
				// continue() or pauseTimeoutFunc has been called,
				next($this->asyncQueue);
				$this->doAsyncLoop();
			}
		}

		if($this->asyncComplete){
			$this->onComplete();
			return true;
		}
		return false;
	}

	private function doAsyncLoop() : void{
		/** @var RegisteredListener $listener */
		while(($listener = current($this->asyncQueue)) !== false and $this->pauseTimeout === null){
			// while has more listener and not paused
			if($listener->getPlugin()->isEnabled()){
				try{
					$listener->callEvent($this);
				}catch(\Throwable $e){
					MainLogger::getLogger()->critical(
						Server::getInstance()->getLanguage()->translateString("pocketmine.plugin.eventError", [
							$this->getEventName(),
							$listener->getPlugin()->getDescription()->getFullName(),
							$e->getMessage(),
							get_class($listener->getListener())
						]));
					MainLogger::getLogger()->logException($e);
				}
			}
		}
		$this->asyncCompleteFunc = current($this->asyncQueue) === false;
	}

	private function onComplete() : void{
		// $this->asyncComplete is true here. Do not try to callAsyncEvent() immediately.
		($this->asyncCompleteFunc)($this);

		$this->async = false;
		$this->asyncComplete = false;
		$this->asyncQueue = null;
		$this->pauseTimeout = null;
		$this->pauseTimeoutFunc = null;
	}

	public function pause(int $ticks = 200, ?callable $onTimeout) : void{
		if(!$this->async){
			throw new \InvalidStateException("Could not pause non-async event; only events called with PluginManager->callAsyncEvent() can be paused");
		}
		if($ticks > 1200){
			throw new \OutOfRangeException("Events must not be paused for more than 1200 ticks");
		}
		if($this->pauseTimeout !== null){
			throw new \InvalidStateException("Event has already been paused");
		}

		$this->pauseTimeout = Server::getInstance()->getTick() + $ticks;
		$this->pauseTimeoutFunc = $onTimeout ?? [$this, "onTimeout"];
	}

	public final function continue() : void{
		if($this->pauseTimeout === null){
			throw new \InvalidStateException("Cannot continue a paused event");
		}
		$this->pauseTimeout = $this->pauseTimeoutFunc = null;
		// do not execute anything directly after this, to prevent recursion stack overflow or concurrency due to the plugin executing something after this call
	}

	public function onTimeout() : void{
		MainLogger::getLogger()->error(
			Server::getInstance()->getLanguage()->translateString("pocketmine.plugin.eventTimeout", [
				$this->getEventName(),
				current($this->asyncQueue)->getPlugin()->getDescription()->getFullName(),
				get_class(current($this->asyncQueue)->getListener())
			]));
	}

	/**
	 * @return bool
	 */
	public final function isAsyncComplete() : bool{
		if(!$this->async){
			throw new \InvalidStateException("isAsyncComplete() is only available for events called asynchronously");
		}
		return $this->asyncComplete;
	}
}
