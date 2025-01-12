/*global VuFind, finna*/
finna.menu = (function finnaMenu() {
  /**
   * Initialize account check events for current user
   */
  function initAccountChecks() {
    VuFind.account.register("profile", {
      selector: ".profile-status",
      ajaxMethod: "getAccountNotifications",
      render: function render($element, status, ICON_LEVELS) {
        if (!status.notifications) {
          $element.addClass("hidden");
          return ICON_LEVELS.NONE;
        }
        $element.html('<span title="' + VuFind.translate('account_has_alerts') + '">' + VuFind.icon('warning', 'warning-icon') + '</span>');
        return ICON_LEVELS.DANGER;
      }
    });
  }

  /**
   * Toggle a sub menu
   * @param {jQuery} a Container to check for toggle
   */
  function toggleSubmenu(a) {
    a.trigger('beforetoggle');
    a.toggleClass('collapsed');
    a.parent().find('ul').first().toggleClass('in', !a.hasClass('collapsed'));
    a.attr("aria-expanded", !a.hasClass("collapsed"));

    if (a.hasClass('sticky-menu')) {
      $('.nav-tabs-personal').toggleClass('move-list');
      if (!$('.nav-tabs-personal').hasClass('move-list')) {
        window.scroll(0, 0);
      }
    }
  }

  /**
   * Initialize menu lists
   */
  function initMenuLists() {
    $('.menu-parent').on('togglesubmenu.finna', function onToggleSubmenu() {
      toggleSubmenu($(this));
    });

    $('.menu-parent > .js-toggle-menu').on('click', function clickLink(e) {
      e.preventDefault();
      $(this).parent().trigger('togglesubmenu');
    });

    if ($('.mylist-bar').children().length === 0) {
      $('#open-list').one('beforetoggle.finna', function loadList() {
        var link = $(this);
        link.data('preload', false);
        $.ajax({
          type: 'GET',
          dataType: 'json',
          async: false,
          url: VuFind.path + '/AJAX/JSON?method=getMyLists',
          data: {'active': null}
        }).done(function onGetMyListsDone(data) {
          $('.mylist-bar').append(data.data);
          $('.add-new-list-holder').hide();
        });
      });
    } else {
      $('#open-list').removeClass('collapsed').siblings('ul').first().addClass('in');
    }
  }

  /**
   * Initialize finna.menu
   */
  function init() {
    initMenuLists();
    initAccountChecks();
  }

  var my = {
    init: init
  };

  return my;
})();
