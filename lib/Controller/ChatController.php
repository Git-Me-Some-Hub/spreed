<?php

/**
 *
 * @copyright Copyright (c) 2017, Daniel Calviño Sánchez (danxuliu@gmail.com)
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Spreed\Controller;

use OCA\Spreed\Exceptions\RoomNotFoundException;
use OCA\Spreed\Manager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserManager;

class ChatController extends OCSController {

	/** @var string */
	private $userId;
	/** @var ISession */
	private $session;
	/** @var Manager */
	private $manager;

	/**
	 * @param string $appName
	 * @param string $UserId
	 * @param IRequest $request
	 * @param IUserManager $userManager
	 * @param ISession $session
	 * @param ILogger $logger
	 * @param Manager $manager
	 */
	public function __construct($appName,
								$UserId,
								IRequest $request,
								IUserManager $userManager,
								ISession $session,
								ILogger $logger,
								Manager $manager) {
		parent::__construct($appName, $request);
		$this->userId = $UserId;
		$this->session = $session;
		$this->manager = $manager;
	}

	/**
	 * @PublicPage
	 *
	 * Adds a chat message to the given room.
	 *
	 * @param string $token the room token
	 * @return DataResponse
	 */
	public function sendMessage($token) {
		try {
			$room = $this->manager->getRoomForParticipantByToken($token, $this->userId);
		} catch (RoomNotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$sessionId = $this->session->get('spreed-session');

		// TODO

		return new DataResponse();
	}

	/**
	 * @PublicPage
	 *
	 * Returns the chat messages for the given room.
	 *
	 * If an ID is given it returns all the messages with an ID larger than the
	 * given one; otherwise all the messages for the room are returned. 
	 *
	 * @param string $token the room token
	 * @return DataResponse an array of chat messages
	 */
	public function receiveMessages($token) {
		try {
			$room = $this->manager->getRoomForParticipantByToken($token, $this->userId);
		} catch (RoomNotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$sessionId = $this->session->get('spreed-session');

		// TODO

		return new DataResponse([
			[ 'id' => 0, 'userId' => 0, 'timestamp' => 0, 'message' => '' ]
		]);
	}

}
