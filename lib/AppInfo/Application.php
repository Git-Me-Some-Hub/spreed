<?php
/**
 * @author Joachim Bauch <mail@joachim-bauch.de>
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

namespace OCA\Spreed\AppInfo;

use OCA\Spreed\BackendNotifier;
use OCP\AppFramework\App;

class Application extends App {

	public function __construct(array $urlParams = []) {
		parent::__construct('spreed', $urlParams);
	}

	public function registerHooks() {
		$notifier = $this->getContainer()->query(BackendNotifier::class);

		$dispatcher = $this->getContainer()->getServer()->getEventDispatcher();
		$dispatcher->addListener('\OCA\Spreed\Room::postAddParticipants', function($event) use ($notifier) {

			$room = $event->getSubject();
			$participants= $event->getArgument('participants');
			$notifier->roomInvited($room, $participants);
		});
		$dispatcher->addListener('\OCA\Spreed\Room::postSetName', function($event) use ($notifier) {
			$room = $event->getSubject();
			$notifier->roomModified($room);
		});
		$dispatcher->addListener('\OCA\Spreed\Room::preDeleteRoom', function($event) use ($notifier) {
			$room = $event->getSubject();
			$notifier->roomDeleted($room);
		});
		$dispatcher->addListener('\OCA\Spreed\Room::postRemoveUser', function($event) use ($notifier) {
			$room = $event->getSubject();
			$user = $event->getArgument('user');
			$notifier->roomsDisinvited($room, [$user->getUID()]);
		});
	}

}
