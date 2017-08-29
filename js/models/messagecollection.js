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

(function(OCA, OC, Backbone) {
	'use strict';

	OCA.SpreedMe = OCA.SpreedMe || {};
	OCA.SpreedMe.Models = OCA.SpreedMe.Models || {};

	var MessageCollection = Backbone.Collection.extend({
		model: OCA.SpreedMe.Models.Message,

		initialize: function(options) {
			if (options.chatId === undefined) {
				throw 'Missing parameter chatId';
			}

			this.chatId = options.chatId;

			this.url = OC.linkToOCS('apps/spreed/api/v1', 2) + 'chat/' + this.chatId;
		},

		comparator: function(model) {
			return -(model.get('timestamp'));
		},

		parse: function(result) {
			return result.ocs.data;
		},
	});

	OCA.SpreedMe.Models.MessageCollection = MessageCollection;

})(OCA, OC, Backbone);
