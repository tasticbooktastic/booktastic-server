// Terminology:
// - A user corresponds to a real person, or someone pretending to be; that's in here
// - A member is the user's presence on a specific group; that's in membership.js

Iznik.Models.ModTools.User = IznikModel.extend({
    urlRoot: API + '/user',

    parse: function(ret) {
        // We might either be called from a collection, where the user is at the top level, or
        // from getting an individual user, where it's not.
        if (ret.hasOwnProperty('user')) {
            return(ret.user);
        } else {
            return(ret);
        }
    },

    reply: function(subject, body, stdmsgid) {
        var self = this;

        $.ajax({
            type: 'POST',
            url: API + 'user/' + self.get('id'),
            data: {
                subject: subject,
                body: body,
                stdmsgid: stdmsgid,
                groupid: self.get('groupid')
            }, success: function(ret) {
                self.trigger('replied');
            }
        });
    }
});

Iznik.Models.ModTools.User.MessageHistoryEntry = IznikModel.extend({});

Iznik.Collections.ModTools.MessageHistory = IznikCollection.extend({
    model: Iznik.Models.ModTools.User.MessageHistoryEntry,

    initialize: function (options) {
        this.options = options;

        // Use a comparator to show in most recent first order
        this.comparator = function(a) {
            return(-(new Date(a.get('arrival'))).getTime());
        }
    }
});

Iznik.Collections.Members = IznikCollection.extend({
    url: function() {
        return(API + 'group/' + this.options.groupid + '?members=TRUE');
    },

    model: Iznik.Models.ModTools.User,

    initialize: function (models, options) {
        this.options = options;

        // Use a comparator to show in most recent first order
        this.comparator = function(a, b) {
            var epocha = (new Date(a.get('joined'))).getTime();
            var epochb = (new Date(b.get('joined'))).getTime();
            return(epochb - epocha);
        }
    },

    parse: function(ret) {
        // Save off the return in case we need any info from it, e.g. context for searches.
        this.ret = ret;

        return(ret.hasOwnProperty('group') && ret.group.hasOwnProperty('members') ? ret.group.members : null);
    }
});

Iznik.Collections.Members.Search = Iznik.Collections.Members.extend({
    url: function() {
        return(API + 'group/' + this.options.groupid + '?members=TRUE&search=' + encodeURIComponent(this.options.search));
    }
});