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

(function() {
	'use strict';

	OCA.SpreedMe = OCA.SpreedMe || {};
	OCA.SpreedMe.Views = OCA.SpreedMe.Views || {};

	// TODO unify with comments templates? Rename CSS classes!
	// TODO replace with default element from Backbone
	var TEMPLATE =
		'<div id="commentsTabView" class="chat">' +
			'<ul class="comments">' +
			'</ul>' +
			'<div class="emptycontent hidden"><div class="icon-comment"></div>' +
			'<p>{{emptyResultLabel}}</p></div>' +
			'<div class="loading hidden" style="height: 50px"></div>' +
		'</div>';

	var ADD_MESSAGE_TEMPLATE =
		'<div class="newCommentRow comment" data-id="{{id}}">' +
		'    <div class="authorRow">' +
		'        <div class="avatar" data-username="{{actorId}}"></div>' +
		'        <div class="author">{{actorDisplayName}}</div>' +
		'    </div>' +
		'    <form class="newCommentForm">' +
		'        <textarea rows="1" class="message" placeholder="{{newMessagePlaceholder}}">{{message}}</textarea>' +
		'        <input class="submit icon-confirm" type="submit" value="" />' +
		'        <div class="submitLoading icon-loading-small hidden"></div>'+
		'    </form>' +
		'</div>';

	var MESSAGE_TEMPLATE =
		'<li class="comment" data-id="{{id}}">' +
		'    <div class="authorRow">' +
		'        <div class="avatar" {{#if actorId}}data-username="{{actorId}}"{{/if}}> </div>' +
		'        <div class="author">{{actorDisplayName}}</div>' +
		'        <div class="date has-tooltip live-relative-timestamp" data-timestamp="{{timestamp}}" title="{{altDate}}">{{date}}</div>' +
		'    </div>' +
		'    <div class="message">{{{formattedMessage}}}</div>' +
		'</li>';

	var ChatView = Backbone.View.extend({

		initialize: function() {
// 			this.listenTo(this.model, 'change', this.render);

			this.collection.on('add', this._onAddModel, this);
		},

		template: function(params) {
			if (!this._template) {
				this._template = Handlebars.compile(TEMPLATE);
			}
			var currentUser = OC.getCurrentUser();
			return this._template(_.extend({
				actorId: currentUser.uid,
				actorDisplayName: currentUser.displayName
			}, params));
		},

		// TODO method names
		editCommentTemplate: function(params) {
			if (!this._editCommentTemplate) {
				this._editCommentTemplate = Handlebars.compile(ADD_MESSAGE_TEMPLATE);
			}
			var currentUser = OC.getCurrentUser();
			return this._editCommentTemplate(_.extend({
				actorId: currentUser.uid,
				actorDisplayName: currentUser.displayName,
				newMessagePlaceholder: t('comments', 'New message…'),
				submitText: t('comments', 'Post')
			}, params));
		},

		commentTemplate: function(params) {
			if (!this._commentTemplate) {
				this._commentTemplate = Handlebars.compile(MESSAGE_TEMPLATE);
			}

			params = _.extend({
				isUserAuthor: OC.getCurrentUser().uid === params.actorId
			}, params);

			return this._commentTemplate(params);
		},

		render: function() {
			this.$el.html(this.template({
				emptyResultLabel: t('comments', 'No messages yet, start the conversation!')
			}));
			this.$el.find('.comments').before(this.editCommentTemplate({}));
			this.$el.find('.has-tooltip').tooltip();
			this.$container = this.$el.find('ul.comments');
			this.$el.find('.avatar').avatar(OC.getCurrentUser().uid, 32);
			this.delegateEvents();
			this.$el.find('.message').on('keydown input change', this._onTypeComment);

			// TODO
			autosize(this.$el.find('.newCommentRow textarea'));
			return this;
		},

		_formatItem: function(commentModel) {
			var timestamp = new Date(commentModel.get('timestamp')).getTime();
			var data = _.extend({
				actorId: commentModel.attributes.userId,
				actorDisplayName: 'User name TODO',
				timestamp: timestamp,
				date: OC.Util.relativeModifiedDate(timestamp),
				altDate: OC.Util.formatDate(timestamp),
				formattedMessage: this._formatMessage(commentModel.get('message'), commentModel.get('mentions'))
			}, commentModel.attributes);
			return data;
		},

		_onAddModel: function(model, collection, options) {
			var $el = $(this.commentTemplate(this._formatItem(model)));
			if (!_.isUndefined(options.at) && collection.length > 1) {
				this.$container.find('li').eq(options.at).before($el);
			} else {
				this.$container.prepend($el);
			}

			this._postRenderItem($el);
		},

		_postRenderItem: function($el) {
			$el.find('.has-tooltip').tooltip();
			$el.find('.avatar').each(function() {
				var $this = $(this);
				$this.avatar($this.attr('data-username'), 32);
			});

			var username = $el.find('.avatar').data('username');
			if (username !== oc_current_user) {
				$el.find('.authorRow .avatar, .authorRow .author').contactsMenu(
					username, 0, $el.find('.authorRow'));
			}

			var $message = $el.find('.message');
			this._postRenderMessage($message);
		},

		_postRenderMessage: function($el) {
			$el.find('.avatar').each(function() {
				var avatar = $(this);
				var strong = $(this).next();
				var appendTo = $(this).parent();

				$.merge(avatar, strong).contactsMenu(avatar.data('user'), 0, appendTo);
			});
		},

		/**
		 * Convert a message to be displayed in HTML,
		 * converts newlines to <br> tags.
		 */
		_formatMessage: function(message, mentions) {
			message = escapeHTML(message).replace(/\n/g, '<br/>');

			for(var i in mentions) {
				var mention = '@' + mentions[i].mentionId;

				var avatar = '<div class="avatar" '
					+ 'data-user="' + _.escape(mentions[i].mentionId) + '"'
					+' data-user-display-name="'
					+ _.escape(mentions[i].mentionDisplayName) + '"></div>';

				// escape possible regex characters in the name
				mention = mention.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
				var displayName = ''
					+ '<span class="avatar-name-wrapper">'
					+ avatar + ' <strong>'+ _.escape(mentions[i].mentionDisplayName)+'</strong>'
					+ '</span>';

				// replace every mention either at the start of the input or after a whitespace
				// followed by a non-word character.
				message = message.replace(new RegExp("(^|\\s)(" + mention + ")\\b", 'g'),
					function(match, p1) {
						// to  get number of whitespaces (0 vs 1) right
						return p1+displayName;
					}
				);
			}

			return message;
		},

	});

	OCA.SpreedMe.Views.ChatView = ChatView;

})(OCA);
