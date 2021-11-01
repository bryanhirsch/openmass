/* eslint-disable no-unused-vars */
function alerts(path, nodeType, $alertsBlock) {
  'use strict';
  var removeContainer = false;

  function insertBefore(nodeA, nodeBselector) {
    var nodeB = document.querySelector(nodeBselector);
    document.getElementById(nodeA).insertAdjacentElement('beforebegin', nodeB);
  }

  function insertAfter(nodeA, nodeBselector) {
    var nodeB = document.querySelector(nodeBselector);
    document.getElementById(nodeA).insertAdjacentElement('afterend', nodeB);
  }

  if (path !== '/alerts/sitewide') {
    if (nodeType) {
      var positioned = false;

      if (nodeType === 'how_to_page') {
        if (document.querySelector('.mdocument.querySelector__page-header__optional-content') != null) {
          insertBefore($alertsBlock, '.ma__page-header__optional-content');
          removeContainer = true;
          positioned = true;
        }
      }
      else if (nodeType === 'person') {
        if (document.querySelector('.ma__page-intro') != null) {
          insertAfter($alertsBlock, '.ma__page-intro');
          removeContainer = true;
          positioned = true;
        }
      }

      if (!positioned) {

        if (document.querySelector('.ma__illustrated-header') != null) {
          insertAfter($alertsBlock, '.ma__illustrated-header');
        }
        else if (document.querySelector('.ma__page-header') != null) {
          insertAfter($alertsBlock, '.ma__page-header');
        }
        else if (document.querySelector('.ma__organization-navigation') != null) {
          insertAfter($alertsBlock, '.ma__organization-navigation');
        }
        else if (document.querySelector('.ma__page-banner') != null) {
          insertAfter($alertsBlock, '.ma__page-banner');
        }
        else if (document.querySelector('.pre-content') != null) {
          insertAfter($alertsBlock, '.pre-content');
        }
      }
    }
    else {
      // Not a node page.
      path = false;
    }
  }

  if (path) {
    fetch(path).then(function (response) {
      return response.text();
    }).then(function (content) {
      if (!content) {
        $alertsBlock.setAttribute('style', 'display: none');
        return;
      }
      $alertsBlock.innerHTML = content;
      if (removeContainer) {
        $alertsBlock.querySelector('.ma__page-banner__container').classList.remove('ma__page-banner__container');
      }
      // At the moment of fetch, we already have jQuery.
      jQuery(document).trigger('ma:AjaxPattern:Render', [{el: jQuery($alertsBlock)}]);
    });
  }
}
