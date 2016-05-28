define([
    'jquery',
    'underscore',
    'backbone',
    'iznik/base',
    'iznik/views/pages/pages',
    'iznik/views/pages/user/pages',
    'iznik/views/pages/user/post',
    'iznik/views/user/message'
], function($, _, Backbone, Iznik) {
    Iznik.Views.User.Pages.Find.WhereAmI = Iznik.Views.User.Pages.WhereAmI.extend({
        template: "user_find_whereami"
    });

    Iznik.Views.User.Pages.Find.Search = Iznik.Views.Page.extend({
        template: "user_find_search",

        events: {
            'click #searchbutton': 'doSearch',
            'keyup .js-search': 'keyup'
        },

        keyup: function (e) {
            // Search on enter.
            if (e.which == 13) {
                this.$('#searchbutton').click();
            }
        },

        doSearch: function () {
            this.$('h1').slideUp('slow');

            var term = this.$('.js-search').val();

            if (term != '') {
                Router.navigate('/find/search/' + encodeURIComponent(term), true);
            } else {
                Router.navigate('/find/search', true);
            }
        },

        itemSource: function (query, syncResults, asyncResults) {
            var self = this;

            $.ajax({
                type: 'GET',
                url: API + 'item',
                data: {
                    typeahead: query
                }, success: function (ret) {
                    var matches = [];
                    _.each(ret.items, function (item) {
                        matches.push(item.item.name);
                    })

                    asyncResults(matches);
                }
            })
        },

        render: function () {
            var p = Iznik.Views.Page.prototype.render.call(this);
            p.then(function(self) {
                if (self.options.search) {
                    self.$('h1').hide();
                    self.$('.js-search').val(self.options.search);

                    var mylocation = null;
                    try {
                        mylocation = localStorage.getItem('mylocation');

                        if (mylocation) {
                            mylocation = JSON.parse(mylocation);
                        }
                    } catch (e) {}

                    self.collection = new Iznik.Collections.Messages.GeoSearch(null, {
                        modtools: false,
                        searchmess: self.options.search,
                        nearlocation: mylocation ? mylocation : null,
                        collection: 'Approved'
                    });

                    self.collectionView = new Backbone.CollectionView({
                        el: self.$('.js-list'),
                        modelView: Iznik.Views.User.SearchResult,
                        modelViewOptions: {
                            collection: self.collection,
                            page: self
                        },
                        visibleModelsFilter: function(model) {
                            // Only show a search result for an offer which has not been taken.
                            var taken = _.where(model.get('related'), {
                                type: 'Taken'
                            });

                            return (taken.length == 0);
                        },
                        collection: self.collection
                    });
                    
                    self.listenTo(self.collectionView, 'add', function(modelView) {
                        // We might have been trying to reply to a message.
                        //
                        // Listening to the collectionView means that we'll find this, eventually, if we are infinite
                        // scrolling.
                        try {
                            var replyto = localStorage.setItem('replyto');
                            var thisid = modelView.model.get('id');
                            
                            console.log("Check for reply", replyto, thisid);
                            
                            if (replyto == thisid) {
                                var replytext = localStorage.setItem('replytext');

                                if (replyto) {
                                    console.log("Scroll to");
                                    $('html, body').animate({
                                        scrollTop: modelView.$el.offset().top
                                    }, 2000);
                                }
                            }
                        } catch (e) {}
                    })

                    self.collectionView.render();

                    var v = new Iznik.Views.PleaseWait();
                    v.render();

                    self.collection.fetch({
                        remove: true,
                        data: {
                            messagetype: 'Offer',
                            nearlocation: mylocation ? mylocation.id : null,
                            search: self.options.search,
                            subaction: 'searchmess'
                        },
                        success: function (collection) {
                            v.close();
                            var some = false;

                            collection.each(function(msg) {
                                // Get the zoom level for maps and put it somewhere easier.
                                var zoom = 8;
                                var groups = msg.get('groups');
                                if (groups.length > 0) {
                                    zoom = groups[0].settings.map.zoom;
                                }
                                msg.set('zoom', zoom);
                                var related = msg.get('related');

                                var taken = _.where(related, {
                                    type: 'Taken'
                                });

                                if (taken.length == 0) {
                                    some = true;
                                }
                            });

                            if (!some) {
                                self.$('.js-none').fadeIn('slow');
                            } else {
                                self.$('.js-none').hide();
                            }
                            self.$('.js-wanted').fadeIn('slow');

                            self.$('.js-postwantd').fadeIn('slow');
                        }
                    });
                }

                self.$('.js-search').typeahead({
                    minLength: 2,
                    hint: false,
                    highlight: true
                }, {
                    name: 'items',
                    source: self.itemSource
                });
            });

            return (p);
        }
    });

    Iznik.Views.User.SearchResult = Iznik.Views.User.Message.extend({
        template: 'user_find_result',

        events: {
            'click .js-send': 'send'
        },

        initialize: function(){
            this.events = _.extend(this.events, Iznik.Views.User.Message.prototype.events);
        },

        startChat: function() {
            // We start a conversation with the sender.
            var self = this;

            $.ajax({
                type: 'PUT',
                url: API + 'chat/rooms',
                data: {
                    userid: self.model.get('fromuser').id
                }, success: function(ret) {
                    if (ret.ret == 0) {
                        var chatid = ret.id;
                        var msg = self.$('.js-replytext').val();

                        $.ajax({
                            type: 'POST',
                            url: API + 'chat/rooms/' + chatid + '/messages',
                            data: {
                                message: msg,
                                refmsgid: self.model.get('id')
                            }, complete: function() {
                                // Ensure the chat is opened, which shows the user what will happen next.
                                Iznik.Session.chats.fetch().then(function() {
                                    self.$('.js-replybox').slideUp();
                                    var chatmodel = Iznik.Session.chats.get(chatid);
                                    var chatView = Iznik.activeChats.viewManager.findByModel(chatmodel);
                                    chatView.restore();
                                });
                            }
                        });
                    }
                }
            })
        },
        
        send: function() {
            var self = this;

            // Save off details of our reply.
            try {
                localStorage.setItem('replyto', self.model.get('id'));
                localStorage.setItem('replytext', self.$('.js-replytext').val());
            } catch (e) {}

            // If we're not already logged in, we want to be.
            self.listenToOnce(Iznik.Session, 'loggedIn', function (loggedIn) {
                // When we reply to a message on a group, we join the group if we're not already a member.
                var memberofs = Iznik.Session.get('groups');
                var member = false;
                var tojoin = null;
                if (memberofs) {
                    console.log("Member ofs", memberofs);
                    memberofs.each(function(memberof) {
                        console.log("Check member", memberof);
                        var msggroups = self.model.get('groups');
                        _.each(msggroups, function(msggroup) {
                            console.log("Check msg", msggroup);
                            tojoin = msggroup.groupid;
                            if (memberof.id = msggroup.groupid) {
                                member = true;
                            }
                        });
                    });
                }

                if (!member) {
                    // We're not a member of any groups on which this message appears.  Join one.
                } else {
                    self.startChat();
                }
            });

            Iznik.Session.forceLogin({
                modtools: false
            });
        },

        render: function() {
            var self = this;
            var p = null;
            var mylocation = null;
            try {
                mylocation = localStorage.getItem('mylocation');

                if (mylocation) {
                    mylocation = JSON.parse(mylocation);
                }
            } catch (e) {
            }

            this.model.set('mylocation', mylocation);

            // Static map custom markers don't support SSL.
            this.model.set('mapicon', 'http://' + window.location.hostname + '/images/mapmarker.gif');

            p = Iznik.Views.User.Message.prototype.render.call(this);

            return(p);
        }
    });

    Iznik.Views.User.Pages.Find.WhatIsIt = Iznik.Views.User.Pages.WhatIsIt.extend({
        msgType: 'Wanted',
        template: "user_find_whatisit",
        whoami: '/find/whoami',

        render: function() {
            // We want to start the wanted with the last search term.
            try {
                this.options.item = localStorage.getItem('lastsearch');
            } catch (e) {}

            return(Iznik.Views.User.Pages.WhatIsIt.prototype.render.call(this));
        }
    });

    Iznik.Views.User.Pages.Find.WhoAmI = Iznik.Views.User.Pages.WhoAmI.extend({
        whatnext: '/find/whatnext',
        template: "user_find_whoami"
    });

    Iznik.Views.User.Pages.Find.WhatNext = Iznik.Views.Page.extend({
        template: "user_find_whatnext"
    });
});