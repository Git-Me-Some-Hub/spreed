<?php
/**
 * @copyright Copyright (c) 2016 Lukas Reschke <lukas@statuscode.ch>
 * @copyright Copyright (c) 2016 Joas Schilling <coding@schilljs.com>
 *
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Joas Schilling <coding@schilljs.com>
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

namespace OCA\Spreed;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\Security\ISecureRandom;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

class Room {
	const ONE_TO_ONE_CALL = 1;
	const GROUP_CALL = 2;
	const PUBLIC_CALL = 3;

	/** @var IDBConnection */
	private $db;
	/** @var ISecureRandom */
	private $secureRandom;
	/** @var EventDispatcherInterface */
	private $dispatcher;

	/** @var int */
	private $id;
	/** @var int */
	private $type;
	/** @var string */
	private $token;
	/** @var string */
	private $name;

	/** @var string */
	protected $currentUser;
	/** @var Participant */
	protected $participant;

	/**
	 * Room constructor.
	 *
	 * @param IDBConnection $db
	 * @param ISecureRandom $secureRandom
	 * @param EventDispatcherInterface $dispatcher
	 * @param int $id
	 * @param int $type
	 * @param string $token
	 * @param string $name
	 */
	public function __construct(IDBConnection $db, ISecureRandom $secureRandom, EventDispatcherInterface $dispatcher, $id, $type, $token, $name) {
		$this->dispatcher = $dispatcher;
		$this->db = $db;
		$this->secureRandom = $secureRandom;
		$this->id = $id;
		$this->type = $type;
		$this->token = $token;
		$this->name = $name;
	}

	/**
	 * @return int
	 */
	public function getId() {
		return $this->id;
	}

	/**
	 * @return int
	 */
	public function getType() {
		return $this->type;
	}

	/**
	 * @return string
	 */
	public function getToken() {
		return $this->token;
	}

	/**
	 * @return string
	 */
	public function getName() {
		return $this->name;
	}

	/**
	 * @param string $userId
	 * @param Participant $participant
	 */
	public function setParticipant($userId, Participant $participant) {
		$this->currentUser = $userId;
		$this->participant = $participant;
	}

	/**
	 * @param string $userId
	 * @return Participant
	 * @throws \RuntimeException When the user is not a participant
	 */
	public function getParticipant($userId) {
		if (!is_string($userId) || $userId === '') {
			throw new \RuntimeException('Not a user');
		}

		if ($this->currentUser === $userId && $this->participant instanceof Participant) {
			return $this->participant;
		}

		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('spreedme_room_participants')
			->where($query->expr()->eq('userId', $query->createNamedParameter($userId)))
			->andWhere($query->expr()->eq('roomId', $query->createNamedParameter($this->getId())));
		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new \RuntimeException('User is not a participant');
		}

		if ($this->currentUser === $userId) {
			$this->participant = new Participant($this->db, $this, $row['userId'], (int) $row['participantType'], (int) $row['lastPing'], $row['sessionId']);
			return $this->participant;
		}

		return new Participant($this->db, $this, $row['userId'], (int) $row['participantType'], (int) $row['lastPing'], $row['sessionId']);
	}

	public function deleteRoom() {
		$this->dispatcher->dispatch(self::class . '::preDeleteRoom',
			new GenericEvent($this));
		$query = $this->db->getQueryBuilder();

		// Delete all participants
		$query->delete('spreedme_room_participants')
			->where($query->expr()->eq('roomId', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));
		$query->execute();

		// Delete room
		$query->delete('spreedme_rooms')
			->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));
		$query->execute();
		$this->dispatcher->dispatch(self::class . '::postDeleteRoom',
			new GenericEvent($this));
	}

	/**
	 * @param string $newName Currently it is only allowed to rename: Room::GROUP_CALL, Room::PUBLIC_CALL
	 * @return bool True when the change was valid, false otherwise
	 */
	public function setName($newName) {
		$oldName = $this->getName();
		if ($newName === $oldName) {
			return true;
		}

		if ($this->getType() === self::ONE_TO_ONE_CALL) {
			return false;
		}

		$this->dispatcher->dispatch(self::class . '::preSetName',
			new GenericEvent($this, [
				'newName' => $newName,
				'oldName' => $oldName,
			]));
		$query = $this->db->getQueryBuilder();
		$query->update('spreedme_rooms')
			->set('name', $query->createNamedParameter($newName))
			->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));
		$query->execute();
		$this->name = $newName;

		$this->dispatcher->dispatch(self::class . '::postSetName',
			new GenericEvent($this, [
				'newName' => $newName,
				'oldName' => $oldName,
			]));
		return true;
	}

	/**
	 * @param int $newType Currently it is only allowed to change to: Room::GROUP_CALL, Room::PUBLIC_CALL
	 * @return bool True when the change was valid, false otherwise
	 */
	public function changeType($newType) {
		$newType = (int) $newType;
		if ($newType === $this->getType()) {
			return true;
		}

		if (!in_array($newType, [Room::GROUP_CALL, Room::PUBLIC_CALL], true)) {
			return false;
		}

		$oldType = $this->getType();

		$this->dispatcher->dispatch(self::class . '::preChangeType',
			new GenericEvent($this, [
				'newType' => $newType,
				'oldType' => $oldType,
			]));
		$query = $this->db->getQueryBuilder();
		$query->update('spreedme_rooms')
			->set('type', $query->createNamedParameter($newType, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('id', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));
		$query->execute();

		$this->type = $newType;

		if ($oldType === Room::PUBLIC_CALL) {
			// Kick all guests and users that were not invited
			$query = $this->db->getQueryBuilder();
			$query->delete('spreedme_room_participants')
				->where($query->expr()->eq('roomId', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
				->andWhere($query->expr()->in('participantType', $query->createNamedParameter([Participant::GUEST, Participant::USER_SELF_JOINED], IQueryBuilder::PARAM_INT_ARRAY)));
			$query->execute();
		}

		$this->dispatcher->dispatch(self::class . '::postChangeType',
			new GenericEvent($this, [
				'newType' => $newType,
				'oldType' => $oldType,
			]));
		return true;
	}

	/**
	 * @param IUser $user
	 */
	public function addUser(IUser $user) {
		$this->addParticipants([$user->getUID()], Participant::USER);
	}

	/**
	 * @param string[] $participants
	 * @param int $participantType
	 * @param string $sessionId
	 */
	public function addParticipants($participants, $participantType, $sessionId = '0') {
		$this->dispatcher->dispatch(self::class . '::preAddParticipants',
			new GenericEvent($this, [
				'participants' => $participants,
				'type' => $participantType,
				'sessionId' => $sessionId,
			]));
		foreach ($participants as &$participant) {
			$query = $this->db->getQueryBuilder();
			$query->insert('spreedme_room_participants')
				->values(
					[
						'userId' => $query->createNamedParameter($participant),
						'roomId' => $query->createNamedParameter($this->getId()),
						'lastPing' => $query->createNamedParameter(0, IQueryBuilder::PARAM_INT),
						'sessionId' => $query->createNamedParameter($sessionId),
						'participantType' => $query->createNamedParameter($participantType, IQueryBuilder::PARAM_INT),
					]
				);
			$query->execute();
		}

		$this->dispatcher->dispatch(self::class . '::postAddParticipants',
			new GenericEvent($this, [
				'participants' => $participants,
				'type' => $participantType,
				'sessionId' => $sessionId,
			]));
	}

	/**
	 * @param string $participant
	 * @param int $participantType
	 */
	public function setParticipantType($participant, $participantType) {
		$query = $this->db->getQueryBuilder();
		$query->update('spreedme_room_participants')
			->set('participantType', $query->createNamedParameter($participantType, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('roomId', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('userId', $query->createNamedParameter($participant)));
		$query->execute();
	}

	/**
	 * @param IUser $user
	 */
	public function removeUser(IUser $user) {
		$this->dispatcher->dispatch(self::class . '::preRemoveUser',
			new GenericEvent($this, [
				'user' => $user,
			]));
		$query = $this->db->getQueryBuilder();
		$query->delete('spreedme_room_participants')
			->where($query->expr()->eq('roomId', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('userId', $query->createNamedParameter($user->getUID())));
		$query->execute();
		$this->dispatcher->dispatch(self::class . '::postRemoveUser',
			new GenericEvent($this, [
				'user' => $user,
			]));
	}

	/**
	 * @param string $userId
	 * @return string
	 */
	public function enterRoomAsUser($userId) {
		$query = $this->db->getQueryBuilder();
		$query->update('spreedme_room_participants')
			->set('sessionId', $query->createParameter('sessionId'))
			->where($query->expr()->eq('roomId', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('userId', $query->createNamedParameter($userId)));

		$sessionId = $this->secureRandom->generate(255);
		$query->setParameter('sessionId', $sessionId);
		$result = $query->execute();

		if ($result === 0) {
			// User joining a public room, without being invited
			$this->addParticipants([$userId], Participant::USER_SELF_JOINED, $sessionId);
		}

		while (!$this->isSessionUnique($sessionId)) {
			$sessionId = $this->secureRandom->generate(255);
			$query->setParameter('sessionId', $sessionId);
			$query->execute();
		}

		$query = $this->db->getQueryBuilder();
		$query->update('spreedme_room_participants')
			->set('sessionId', $query->createNamedParameter('0'))
			->where($query->expr()->neq('roomId', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('userId', $query->createNamedParameter($userId)));
		$query->execute();

		return $sessionId;
	}

	/**
	 * @return string
	 */
	public function enterRoomAsGuest() {
		$sessionId = $this->secureRandom->generate(255);
		while (!$this->db->insertIfNotExist('*PREFIX*spreedme_room_participants', [
			'userId' => '',
			'roomId' => $this->getId(),
			'lastPing' => 0,
			'sessionId' => $sessionId,
			'participantType' => Participant::GUEST,
		], ['sessionId'])) {
			$sessionId = $this->secureRandom->generate(255);
		}

		return $sessionId;
	}

	/**
	 * @param string $sessionId
	 * @return bool
	 */
	protected function isSessionUnique($sessionId) {
		$query = $this->db->getQueryBuilder();
		$query->selectAlias($query->createFunction('COUNT(*)'), 'num_sessions')
			->from('spreedme_room_participants')
			->where($query->expr()->eq('sessionId', $query->createNamedParameter($sessionId)));
		$result = $query->execute();
		$numSessions = (int) $result->fetchColumn();
		$result->closeCursor();

		return $numSessions === 1;
	}

	public function cleanGuestParticipants() {
		$query = $this->db->getQueryBuilder();
		$query->delete('spreedme_room_participants')
			->where($query->expr()->eq('roomId', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->emptyString('userId'))
			->andWhere($query->expr()->lte('lastPing', $query->createNamedParameter(time() - 30, IQueryBuilder::PARAM_INT)));
		$query->execute();
	}

	/**
	 * @param int $lastPing When the last ping is older than the given timestamp, the user is ignored
	 * @return array[] Array of users with [users => [userId => [lastPing, sessionId]], guests => [[lastPing, sessionId]]]
	 */
	public function getParticipants($lastPing = 0) {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('spreedme_room_participants')
			->where($query->expr()->eq('roomId', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));

		if ($lastPing > 0) {
			$query->andWhere($query->expr()->gt('lastPing', $query->createNamedParameter($lastPing, IQueryBuilder::PARAM_INT)));
		}

		$result = $query->execute();

		$users = $guests = [];
		while ($row = $result->fetch()) {
			if ($row['userId'] !== '' && $row['userId'] !== null) {
				$users[$row['userId']] = [
					'lastPing' => (int) $row['lastPing'],
					'sessionId' => $row['sessionId'],
					'participantType' => (int) $row['participantType'],
				];
			} else {
				$guests[] = [
					'lastPing' => (int) $row['lastPing'],
					'sessionId' => $row['sessionId'],
				];
			}
		}
		$result->closeCursor();

		return [
			'users' => $users,
			'guests' => $guests,
		];
	}

	/**
	 * @param int $lastPing When the last ping is older than the given timestamp, the user is ignored
	 * @return int
	 */
	public function getNumberOfParticipants($lastPing = 0) {
		$query = $this->db->getQueryBuilder();
		$query->selectAlias($query->createFunction('COUNT(*)'), 'num_participants')
			->from('spreedme_room_participants')
			->where($query->expr()->eq('roomId', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));

		if ($lastPing > 0) {
			$query->andWhere($query->expr()->gt('lastPing', $query->createNamedParameter($lastPing, IQueryBuilder::PARAM_INT)));
		}

		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		return isset($row['num_participants']) ? (int) $row['num_participants'] : 0;
	}

	/**
	 * @param string $participant
	 * @param string $sessionId
	 * @param int $timestamp
	 */
	public function ping($participant, $sessionId, $timestamp) {
		$query = $this->db->getQueryBuilder();
		$query->update('spreedme_room_participants')
			->set('lastPing', $query->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('userId', $query->createNamedParameter((string) $participant)))
			->andWhere($query->expr()->eq('sessionId', $query->createNamedParameter($sessionId)))
			->andWhere($query->expr()->eq('roomId', $query->createNamedParameter($this->getId(), IQueryBuilder::PARAM_INT)));

		$query->execute();
	}
}
